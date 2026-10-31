'use strict';

/**
 * Error detection for the admin crawler. Pure functions: no browser, no
 * network, so they are unit-tested on saved HTML and console fixtures.
 *
 * @module detect
 */

/** PHP error output as printed by display_errors, with or without HTML tags. */
const PHP_ERROR = /(?:<b>)?(?:(Fatal error|Parse error)(?:<\/b>)?:\s+\S[^\n]{0,300}|(Warning|Notice|Deprecated)(?:<\/b>)?:\s+[^\n]{1,400}? in \S+ on line (?:<b>)?\d+)/;

/** WordPress' own error markers. */
const WP_ERRORS = [
  { name: 'WordPress database error', pattern: /WordPress database error/i },
  { name: 'critical error', pattern: /There has been a critical error|critical error on this website/i },
];

/**
 * Whether a URL is a REST or admin-ajax call.
 *
 * @param {string} url Request URL.
 * @returns {boolean}
 */
function isApiRequest(url) {
  return /\/wp-json\/|[?&]rest_route=|\/admin-ajax\.php/.test(url);
}

/**
 * Whether a value matches any allow-list pattern.
 *
 * @param {string}   value    Text to test.
 * @param {string[]} patterns Regular-expression sources.
 * @returns {boolean}
 */
function isAllowed(value, patterns) {
  return (patterns || []).some((source) => new RegExp(source).test(value));
}

/**
 * PHP and WordPress error markup found in a page.
 *
 * @param {string} html Response body.
 * @returns {string[]} One short description per marker found.
 */
function findPhpErrors(html) {
  const found = [];
  const php = PHP_ERROR.exec(html);

  if (php) {
    found.push(php[0].replace(/<[^>]+>/g, '').replace(/\s+/g, ' ').slice(0, 160));
  }

  WP_ERRORS.forEach(({ name, pattern }) => {
    if (pattern.test(html)) {
      found.push(name);
    }
  });

  return found;
}

/**
 * Whether a reported console error is allow-listed. Shared by `crawl` and
 * `pages`. A pattern may match the full reported string ("<message> @ <url>",
 * the URL part exists for resource-load failures) or just the message text,
 * so patterns anchored to the message keep working.
 *
 * @param {string}   reported Console or page error as reported.
 * @param {string[]} patterns `--allow-console` regex sources.
 * @returns {boolean}
 */
function isAllowedConsole(reported, patterns) {
  const message = reported.replace(/ @ \S+$/, '');

  return isAllowed(reported, patterns) || isAllowed(message, patterns);
}

/**
 * Failed requests, minus the allow-lists: any response >= 500, and REST or
 * ajax responses >= 400. A non-document response is also ignored when its
 * URL (or "STATUS URL") matches an `--allow-console` pattern.
 *
 * @param {{url: string, status: number, resourceType?: string}[]} requests Observed responses.
 * @param {string[]}                                                allow    Regex sources matched against "STATUS URL".
 * @param {string[]}                                                [allowConsole] `--allow-console` regex sources.
 * @returns {string[]}
 */
function failedRequests(requests, allow, allowConsole) {
  return requests
    .filter((r) => r.status >= 500 || (r.status >= 400 && isApiRequest(r.url)))
    .filter((r) => r.resourceType === 'document' || !isAllowedConsole(r.url, allowConsole))
    .map((r) => `${r.status} ${r.url}`)
    .filter((line) => !isAllowed(line, allow));
}

/**
 * Whether a "Failed to load resource ... @ <url>" console error is for a
 * request the plugin allow-listed.
 *
 * @param {string}   message  Console message.
 * @param {string[]} patterns Allowed request regex sources.
 * @returns {boolean}
 */
function isAllowedResourceError(message, patterns) {
  const match = /^Failed to load resource.* @ (\S+)$/.exec(message);

  return Boolean(match) && isAllowed(match[1], patterns);
}

/**
 * Everything wrong with one crawled page.
 *
 * @param {{status: number, html: string, console: string[], pageErrors: string[], requests: {url: string, status: number}[]}} page  Observations.
 * @param {{console?: string[], requests?: string[]}}                                                                           allow Per-plugin allow-lists.
 * @returns {string[]} Problems; empty when the page is clean.
 */
function evaluatePage(page, allow) {
  const rules = allow || {};
  const problems = [];

  if (page.status !== 200) {
    problems.push(`HTTP ${page.status}`);
  }

  findPhpErrors(page.html || '').forEach((p) => problems.push(`PHP/WordPress error: ${p}`));

  [...(page.console || []), ...(page.pageErrors || [])]
    .filter((message) => !isAllowedConsole(message, rules.console) && !isAllowedResourceError(message, rules.requests))
    .forEach((message) => problems.push(`console error: ${message.slice(0, 200)}`));

  failedRequests(page.requests || [], rules.requests, rules.console).forEach((line) => problems.push(`failed request: ${line}`));

  return problems;
}

module.exports = { evaluatePage, findPhpErrors, failedRequests, isApiRequest, isAllowed, isAllowedConsole };
