'use strict';

/**
 * Playwright `test` with the self-test fixtures: `collector` (console and
 * REST/ajax error collection for the page) and `selfTest` (the case config).
 *
 * @module fixtures
 */

const base = require('@playwright/test');
const { attachCollector } = require('./collector');
const { readConfig } = require('./env');

const test = base.test.extend({
  collector: async ({ page }, use) => {
    await use(attachCollector(page));
  },
  selfTest: async ({}, use) => { // eslint-disable-line no-empty-pattern
    await use(readConfig());
  },
});

module.exports = { test, expect: base.expect };
