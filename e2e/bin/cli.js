#!/usr/bin/env node
'use strict';

/**
 * `mmwp-e2e crawl` / `mmwp-e2e pages`: run the admin crawler or an explicit page list without the PHP runner, e.g. from
 * a CI job against a throwaway WordPress. See lib/args.js for the options.
 */

const fs = require('fs');
const os = require('os');
const path = require('path');
const { parseArgs, USAGE } = require('../lib/args');
const { orchestrate } = require('../lib/orchestrate');
const { pagesCommand, PAGES_USAGE } = require('../lib/pages_command');

/**
 * Entry point.
 *
 * @returns {Promise<number>} Exit code.
 */
async function main() {
  const [command, ...rest] = process.argv.slice(2);

  if (command === 'pages') {
    if (rest.includes('--help')) {
      process.stdout.write(PAGES_USAGE);
      return 0;
    }

    return pagesCommand(rest);
  }

  if (command !== 'crawl' || rest.includes('--help')) {
    process.stdout.write(USAGE);
    return command === 'crawl' || command === '--help' ? 0 : 1;
  }

  const { options, errors } = parseArgs(rest);
  if (errors.length) {
    process.stderr.write(`${errors.join('\n')}\n\n${USAGE}`);
    return 1;
  }

  const site = options.url.replace(/\/+$/, '');
  const work = fs.mkdtempSync(path.join(os.tmpdir(), 'mmwp-e2e-cfg-'));
  const configFile = path.join(work, 'config.json');
  const config = {
    case: 'crawl',
    plugin: options.onlyPlugin,
    only_plugin: options.onlyPlugin,
    root: process.cwd(),
    site_url: site,
    admin_url: `${site}/wp-admin/`,
    rest_url: options.restUrl,
    browser: { crawl: true, specs: options.specs, allow: options.allow, timeout: 300 },
  };

  fs.writeFileSync(configFile, JSON.stringify(config));
  process.env.MMWP_E2E_CONFIG = configFile;
  process.env.MMWP_E2E_WORKERS = String(options.workers);

  try {
    return await orchestrate(
      options.token ? { token: options.token } : { user: options.user, password: options.password },
      { results: path.resolve(options.json), junit: options.junit ? path.resolve(options.junit) : '' },
    );
  } finally {
    fs.rmSync(work, { recursive: true, force: true });
  }
}

main().then((code) => process.exit(code));
