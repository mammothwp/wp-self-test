'use strict';

/**
 * `mmwp-e2e pages`: open an explicit list of admin and frontend pages, each in
 * its own fresh browser context (admin pages reuse the logged-in storage
 * state, guests get no cookies), and apply the crawler's failure checks plus
 * the page's `expect` / `expect_url`.
 *
 * @module pages_runner
 */

const { evaluatePage } = require('./detect');
const { attachCollector } = require('./collector');
const { resolvePage } = require('./pages');

/**
 * Absolute URL for a page path.
 *
 * @param {string} site Site URL.
 * @param {string} path Path starting with "/".
 * @returns {string}
 */
function urlFor(site, path) {
  return site.replace(/\/+$/, '') + path;
}

/**
 * Problems of one observed page against its expectations. Pure: no browser.
 * The main document's own response is judged only by the page's `status`, so
 * an expected 4xx/5xx does not also fail the generic failed-request check;
 * every other response is still checked.
 *
 * @param {{observed: number, documentUrl: string, html: string, text: string, console: string[], pageErrors: string[], requests: {url: string, status: number}[]}} seen Observations.
 * @param {{status: number, expect_text: string}} page Resolved page.
 * @param {object} allow Allow-lists.
 * @returns {string[]}
 */
function pageProblems(seen, page, allow) {
  const matches = seen.observed === page.status;
  const requests = (seen.requests || []).filter((r) => !(matches && r.url === seen.documentUrl && r.status === page.status));
  // The expected status is the page's "200"; evaluatePage flags anything else.
  const problems = evaluatePage({
    status: matches ? 200 : seen.observed,
    html: seen.html,
    console: seen.console,
    pageErrors: seen.pageErrors,
    requests,
  }, allow);

  if (!matches && page.status !== 200) {
    problems.push(`expected HTTP ${page.status}`);
  }
  if (page.expect_text && !String(seen.text || '').includes(page.expect_text)) {
    problems.push(`expected text not found: "${page.expect_text}"`);
  }

  return problems;
}

/**
 * Open one page and collect its problems.
 *
 * @param {import('@playwright/test').Browser} browser Browser.
 * @param {object} page Resolved page.
 * @param {{site: string, storageState: string, allow: object, timeoutMs: number, ignoreTls: boolean, userAgent: string}} settings Settings.
 * @returns {Promise<{url: string, problems: string[]}>}
 */
async function openPage(browser, page, settings) {
  const context = await browser.newContext({
    ignoreHTTPSErrors: settings.ignoreTls,
    userAgent: settings.userAgent,
    storageState: page.as === 'admin' ? settings.storageState : undefined,
  });

  try {
    const tab = await context.newPage();
    tab.setDefaultTimeout(settings.timeoutMs);
    const collector = attachCollector(tab);
    const response = await tab.goto(urlFor(settings.site, page.path), { waitUntil: 'load', timeout: settings.timeoutMs });
    await tab.waitForLoadState('networkidle', { timeout: 5000 }).catch(() => {});

    const observed = response ? response.status() : 0;
    const problems = pageProblems({
      observed,
      documentUrl: response ? response.url() : '',
      html: await tab.content(),
      text: await tab.evaluate(() => document.body.innerText).catch(() => ''),
      console: collector.console,
      pageErrors: collector.pageErrors,
      requests: collector.requests,
    }, page, settings.allow);

    if (page.expect && (await tab.locator(page.expect).count()) === 0) {
      problems.push(`expected selector not found: ${page.expect}`);
    }
    if (page.expect_url && !tab.url().includes(page.expect_url)) {
      problems.push(`final URL "${tab.url()}" does not contain "${page.expect_url}"`);
    }

    return { url: tab.url(), problems };
  } finally {
    await context.close();
  }
}

/**
 * Run one page and build its report row.
 *
 * @param {import('@playwright/test').Browser} browser Browser.
 * @param {object} entry Validated page.
 * @param {Object<string, *>} vars Resolved variables.
 * @param {object} settings Settings for openPage().
 * @returns {Promise<{name: string, area: string, as: string, path: string, url: string, duration_ms: number, result: string, problems: string[]}>}
 */
async function runPage(browser, entry, vars, settings) {
  const started = Date.now();
  const { page, error } = resolvePage(entry, vars);
  const row = { name: entry.name, area: entry.area, as: entry.as, path: page.path, url: '', duration_ms: 0, result: 'fail', problems: [] };

  if (error) {
    row.problems = [error];
  } else {
    try {
      const outcome = await openPage(browser, page, settings);
      row.url = outcome.url;
      row.problems = outcome.problems;
    } catch (e) {
      row.problems = [`navigation failed: ${String(e.message || e).split('\n')[0].slice(0, 300)}`];
    }
  }

  row.result = row.problems.length ? 'fail' : 'pass';
  row.duration_ms = Date.now() - started;

  return row;
}

/**
 * Run all pages with a bounded number of parallel contexts; rows keep the file order.
 *
 * @param {import('@playwright/test').Browser} browser Browser.
 * @param {object[]} pages Validated pages.
 * @param {Object<string, *>} vars Resolved variables.
 * @param {object} settings Settings for openPage().
 * @param {number} workers Parallel pages.
 * @returns {Promise<object[]>}
 */
async function runPages(browser, pages, vars, settings, workers) {
  const rows = new Array(pages.length);
  let next = 0;

  const worker = async () => {
    while (next < pages.length) {
      const index = next++;
      rows[index] = await runPage(browser, pages[index], vars, settings);
    }
  };

  await Promise.all(Array.from({ length: Math.max(1, Math.min(workers, pages.length)) }, worker));

  return rows;
}

/**
 * Convert report rows to the checks the results/JUnit writers use.
 *
 * @param {object[]} rows Rows from runPages().
 * @returns {{label: string, status: string, detail: string}[]}
 */
function rowsToChecks(rows) {
  return rows.map((r) => ({
    label: `${r.area}/${r.as}: ${r.name}`,
    status: r.result,
    detail: r.problems.join('\n'),
  }));
}

module.exports = { pageProblems, runPages, runPage, rowsToChecks, urlFor };
