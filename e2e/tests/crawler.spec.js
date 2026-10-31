'use strict';

/**
 * Generic admin crawler: GET every page of the menu inventory (read-only)
 * and fail on a non-200 status, PHP error markup, console errors and failed
 * (>= 400) wp-json / admin-ajax calls. Pages and noise can be allow-listed
 * per plugin in the manifest (`browser.allow.pages|console|requests`).
 */

const fs = require('fs');
const { test } = require('../lib/fixtures');
const { evaluatePage, isAllowed } = require('../lib/detect');
const { readConfig } = require('../lib/env');

const config = readConfig();
const allow = (config.browser && config.browser.allow) || {};
const inventory = JSON.parse(fs.readFileSync(process.env.MMWP_E2E_INVENTORY, 'utf8'));

for (const entry of inventory.pages) {
  test(`crawl ${entry.path}`, async ({ page, collector }) => {
    test.skip(isAllowed(entry.path, allow.pages), 'allow-listed page');

    const response = await page.goto(entry.url, { waitUntil: 'load' });
    await page.waitForLoadState('networkidle', { timeout: 5000 }).catch(() => {});

    const problems = evaluatePage({
      status: response ? response.status() : 0,
      html: await page.content(),
      console: collector.console,
      pageErrors: collector.pageErrors,
      requests: collector.requests,
    }, allow);

    if (problems.length) {
      throw new Error(problems.join('\n'));
    }
  });
}
