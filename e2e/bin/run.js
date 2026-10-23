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

const { orchestrate } = require('../lib/orchestrate');

orchestrate({ token: process.env.MMWP_E2E_LOGIN_TOKEN || '' }, { results: process.env.MMWP_E2E_RESULTS }).then((code) => process.exit(code));
