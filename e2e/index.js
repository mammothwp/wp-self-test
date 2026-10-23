'use strict';

/**
 * Public API of @mammothwp/wp-self-test-e2e, for plugin flow tests:
 *
 *     const { test, expect, openAdminPage, fillForm, submitForm, expectNotice } = require('@mammothwp/wp-self-test-e2e');
 */

const { test, expect } = require('./lib/fixtures');
const helpers = require('./lib/helpers');
const detect = require('./lib/detect');
const { readConfig } = require('./lib/env');

module.exports = Object.assign({ test, expect, readConfig, evaluatePage: detect.evaluatePage }, helpers);
