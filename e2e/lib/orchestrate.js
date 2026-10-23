'use strict';

/**
 * Runs the browser layer for one config: log in, build the inventory, run
 * Playwright, and write the results (JSON for the PHP runner, optionally
 * JUnit XML for CI). Shared by bin/run.js (PHP runner) and bin/cli.js
 * (`mmwp-e2e crawl`).
 *
 * @module orchestrate
 */

const fs = require('fs');
const os = require('os');
const path = require('path');
const { spawnSync } = require('child_process');
const { readConfig } = require('./env');
const { establishSession } = require('./session');
const { buildResults } = require('./results');
const { toJunit } = require('./junit');

/**
 * Write the results files.
 *
 * @param {{results: string, junit?: string}} outputs Output paths.
 * @param {{checks: object[]}} results Results.
 * @param {string} name JUnit suite name.
 * @returns {void}
 */
function writeResults(outputs, results, name) {
  fs.writeFileSync(outputs.results, JSON.stringify(results));

  if (outputs.junit) {
    fs.writeFileSync(outputs.junit, toJunit(results.checks, name, results.duration_ms || 0));
  }
}

/**
 * Run everything for the config in `env.MMWP_E2E_CONFIG`.
 *
 * @param {{token?: string, user?: string, password?: string}} login Credentials.
 * @param {{results: string, junit?: string}} outputs Output paths.
 * @returns {Promise<number>} Exit code: 1 when a check failed or the run broke.
 */
async function orchestrate(login, outputs) {
  const started = Date.now();
  const work = fs.mkdtempSync(path.join(os.tmpdir(), 'mmwp-e2e-'));
  const files = { storageState: path.join(work, 'state.json'), inventory: path.join(work, 'inventory.json'), report: path.join(work, 'report.json') };
  let name = 'mmwp-e2e';

  try {
    const config = readConfig();
    name = `mmwp-e2e ${config.plugin || config.case || ''}`.trim();
    // The consumer's specs and the Playwright copy are resolved from the root.
    const playwright = require(require.resolve('@playwright/test', { paths: [config.root] }));
    const inventory = await establishSession(playwright, config, login, files);

    const cli = require.resolve('@playwright/test/cli', { paths: [config.root] });
    const run = spawnSync(process.execPath, [cli, 'test', '--config', path.join(__dirname, '..', 'playwright.config.js')], {
      cwd: config.root,
      stdio: ['ignore', 2, 2],
      env: Object.assign({}, process.env, {
        MMWP_E2E_LOGIN_TOKEN: '',
        MMWP_E2E_PASSWORD: '',
        MMWP_E2E_STORAGE_STATE: files.storageState,
        MMWP_E2E_INVENTORY: files.inventory,
        MMWP_E2E_PW_REPORT: files.report,
      }),
    });

    if (!fs.existsSync(files.report)) {
      throw new Error(`Playwright produced no report (exit ${run.status}${run.error ? `, ${run.error.message}` : ''}).`);
    }

    const results = buildResults(JSON.parse(fs.readFileSync(files.report, 'utf8')));
    if (config.only_plugin && inventory.matched === 0) {
      results.checks.unshift({ label: `Admin crawl: plugin "${config.only_plugin}"`, status: 'skip', detail: 'No admin menu page matched the plugin slug; only the front page was crawled.' });
    }
    results.duration_ms = Date.now() - started;
    writeResults(outputs, results, name);

    return results.checks.some((c) => c.status === 'fail') ? 1 : 0;
  } catch (error) {
    writeResults(outputs, { checks: [{ label: 'Browser runner', status: 'fail', detail: String(error.message || error).slice(0, 600) }], pages: 0, duration_ms: Date.now() - started }, name);

    return 1;
  } finally {
    fs.rmSync(work, { recursive: true, force: true });
  }
}

module.exports = { orchestrate };
