#!/usr/bin/env node
'use strict';

/**
 * Entry point the PHP runner starts (`node e2e/bin/run.js`).
 *
 * Environment: MMWP_E2E_CONFIG (case config JSON), MMWP_E2E_RESULTS (where
 * to write the results), MMWP_E2E_LOGIN_TOKEN (single-use login token).
 * Always writes the results file, also when something crashes, and exits 1
 * when any check failed.
 */

const fs = require('fs');
const os = require('os');
const path = require('path');
const { spawnSync } = require('child_process');
const { readConfig } = require('../lib/env');
const { establishSession } = require('../lib/session');
const { buildResults } = require('../lib/results');

/**
 * Write the results file the PHP runner folds into its report.
 *
 * @param {string} file    Output path.
 * @param {object} results {checks, pages}.
 * @returns {void}
 */
function writeResults(file, results) {
  fs.writeFileSync(file, JSON.stringify(results));
}

/**
 * Run the whole browser layer for one case.
 *
 * @returns {Promise<number>} Exit code.
 */
async function main() {
  const resultsFile = process.env.MMWP_E2E_RESULTS;
  const started = Date.now();
  const work = fs.mkdtempSync(path.join(os.tmpdir(), 'mmwp-e2e-'));
  const files = {
    storageState: path.join(work, 'state.json'),
    inventory: path.join(work, 'inventory.json'),
    report: path.join(work, 'report.json'),
  };

  try {
    const config = readConfig();
    // The consumer's specs and the Playwright copy are resolved from the plugin root.
    const playwright = require(require.resolve('@playwright/test', { paths: [config.root] }));
    await establishSession(playwright, config, process.env.MMWP_E2E_LOGIN_TOKEN || '', files);

    const cli = require.resolve('@playwright/test/cli', { paths: [config.root] });
    const run = spawnSync(process.execPath, [cli, 'test', '--config', path.join(__dirname, '..', 'playwright.config.js')], {
      cwd: config.root,
      stdio: ['ignore', 2, 2],
      env: Object.assign({}, process.env, {
        MMWP_E2E_LOGIN_TOKEN: '',
        MMWP_E2E_STORAGE_STATE: files.storageState,
        MMWP_E2E_INVENTORY: files.inventory,
        MMWP_E2E_PW_REPORT: files.report,
      }),
    });

    if (!fs.existsSync(files.report)) {
      throw new Error(`Playwright produced no report (exit ${run.status}${run.error ? `, ${run.error.message}` : ''}).`);
    }

    const results = buildResults(JSON.parse(fs.readFileSync(files.report, 'utf8')));
    results.duration_ms = Date.now() - started;
    writeResults(resultsFile, results);

    return results.checks.some((c) => c.status === 'fail') ? 1 : 0;
  } catch (error) {
    writeResults(resultsFile, { checks: [{ label: 'Browser runner', status: 'fail', detail: String(error.message || error).slice(0, 600) }], pages: 0 });

    return 1;
  } finally {
    fs.rmSync(work, { recursive: true, force: true });
  }
}

main().then((code) => process.exit(code));
