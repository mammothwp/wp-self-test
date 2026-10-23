'use strict';

/**
 * Collects console errors, uncaught page errors, REST/ajax responses and
 * any >= 500 response of a Playwright page.
 *
 * @module collector
 */

const { isApiRequest } = require('./detect');

/**
 * Attach listeners to a page.
 *
 * @param {import('@playwright/test').Page} page Page to observe.
 * @returns {{console: string[], pageErrors: string[], requests: {url: string, status: number}[], reset: Function}}
 */
function attachCollector(page) {
  const collector = {
    console: [],
    pageErrors: [],
    requests: [],
    reset() {
      this.console.length = 0;
      this.pageErrors.length = 0;
      this.requests.length = 0;
    },
  };

  page.on('console', (message) => {
    if (message.type() === 'error') {
      const url = message.location() && message.location().url;
      // The browser's own "Failed to load resource" text names no URL; add it so allow-lists can match.
      collector.console.push(/^Failed to load resource/.test(message.text()) && url ? `${message.text()} @ ${url}` : message.text());
    }
  });

  page.on('pageerror', (error) => collector.pageErrors.push(String(error && error.message ? error.message : error)));

  page.on('response', (response) => {
    if (isApiRequest(response.url()) || response.status() >= 500) {
      collector.requests.push({ url: response.url(), status: response.status() });
    }
  });

  page.on('requestfailed', (request) => {
    const reason = request.failure() ? request.failure().errorText : '';
    if (isApiRequest(request.url()) && !/ERR_ABORTED/.test(reason)) {
      collector.requests.push({ url: request.url(), status: 599 });
    }
  });

  return collector;
}

module.exports = { attachCollector };
