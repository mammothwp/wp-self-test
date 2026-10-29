'use strict';

/**
 * Playwright configuration of the browser layer. Built from the config the
 * PHP runner wrote (MMWP_E2E_CONFIG) and the files bin/run.js prepared.
 */

const os = require('os');
const path = require('path');
const { defineConfig } = require('@playwright/test');
const { readConfig } = require('./lib/env');
const { ignoreTls } = require('./lib/session');

const config = readConfig();
const browser = config.browser || {};
const escape = (value) => value.replace(/[.*+?^${}()|[\]\\]/g, '\\$&');

const projects = [];

if (browser.crawl) {
  projects.push({ name: 'crawl', testDir: path.join(__dirname, 'tests'), testMatch: /crawler\.spec\.js$/ });
}

if ((browser.specs || []).length) {
  projects.push({
    name: 'specs',
    testDir: config.root,
    testMatch: browser.specs.map((spec) => new RegExp(`${escape(path.resolve(config.root, spec))}$`)),
  });
}

module.exports = defineConfig({
  projects,
  fullyParallel: true,
  workers: Number(process.env.MMWP_E2E_WORKERS || 4),
  retries: 0,
  outputDir: path.join(os.tmpdir(), 'mmwp-e2e-output'),
  timeout: 30000,
  reporter: [['json', { outputFile: process.env.MMWP_E2E_PW_REPORT }], ['line']],
  use: {
    baseURL: config.site_url,
    storageState: process.env.MMWP_E2E_STORAGE_STATE,
    ignoreHTTPSErrors: ignoreTls(),
    userAgent: process.env.MMWP_E2E_USER_AGENT || undefined,
    trace: 'off',
  },
});
