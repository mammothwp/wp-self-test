'use strict';

/**
 * Configuration the PHP runner hands over: the case's browser declaration,
 * site URLs and file locations. The login token is in MMWP_E2E_LOGIN_TOKEN
 * and is only read by bin/run.js.
 *
 * @module env
 */

const fs = require('fs');

/**
 * Read the JSON config written by the PHP runner.
 *
 * @param {NodeJS.ProcessEnv} [env] Environment to read from.
 * @returns {object}
 */
function readConfig(env = process.env) {
  if (!env.MMWP_E2E_CONFIG) {
    throw new Error('MMWP_E2E_CONFIG is not set: run this through `wp mmwp self-test run --layer=browser`.');
  }

  return JSON.parse(fs.readFileSync(env.MMWP_E2E_CONFIG, 'utf8'));
}

/**
 * Absolute wp-admin URL for a path.
 *
 * @param {string} adminUrl Admin URL from the config (with trailing slash).
 * @param {string} path     Path relative to wp-admin/.
 * @returns {string}
 */
function adminUrlFor(adminUrl, path) {
  return adminUrl.replace(/\/?$/, '/') + String(path || '').replace(/^\//, '');
}

module.exports = { readConfig, adminUrlFor };
