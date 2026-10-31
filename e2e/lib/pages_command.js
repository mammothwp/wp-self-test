'use strict';

/**
 * `mmwp-e2e pages`: argument parsing and the run itself (login, open the
 * listed pages, write JSON and JUnit).
 *
 * @module pages_command
 */

const fs = require('fs');
const os = require('os');
const path = require('path');
const { parsePagesFile, parseVars } = require('./pages');
const { runPages, rowsToChecks } = require('./pages_runner');
const { loginWithPassword, ignoreTls } = require('./session');
const { toJunit } = require('./junit');
const { resolveUserAgent } = require('./user_agent');

const PAGES_USAGE = `Usage: mmwp-e2e pages --url=<site> --file=<browser-pages.json> --user=<user> --password=<password> [options]

Opens an explicit list of admin and frontend pages (GET only), each in a fresh browser context.

  --url=<url>             Site URL                         (MMWP_E2E_SITE_URL)
  --file=<path>           browser-pages.json (version 1)
  --vars=<path>           JSON object with the values of \${VAR} placeholders
  --user, --password      Admin login (refused unless MMWP_E2E_ALLOW_PASSWORD_LOGIN=1)
  --allow-console=<re>    Ignore console errors matching the regex (repeatable)
  --allow-requests=<re>   Ignore failed "STATUS URL" requests matching the regex (repeatable), as in crawl
  --user-agent=<ua>       Browser user agent (MMWP_E2E_USER_AGENT; default: desktop Chrome UA of the bundled Chromium)
  --workers=<n>           Parallel pages (default 4)
  --timeout-ms=<n>        Per-page navigation timeout (default 30000)
  --json=<file>           Results JSON (default mmwp-e2e-results.json)
  --junit=<file>          JUnit XML

Exit code: 0 all pages passed, 1 a page failed or the run broke.
`;

/** Options that may be repeated; collected into arrays. */
const LIST_OPTIONS = ['allow-console', 'allow-requests'];

/** Every option `pages` accepts. */
const VALID_OPTIONS = ['url', 'file', 'vars', 'user', 'password', 'workers', 'timeout-ms', 'json', 'junit', 'user-agent', 'help'].concat(LIST_OPTIONS);

/**
 * Parse the arguments of `mmwp-e2e pages`.
 *
 * @param {string[]} argv Arguments after the command name.
 * @param {NodeJS.ProcessEnv} [env] Environment.
 * @returns {{options: object, errors: string[]}}
 */
function parsePagesArgs(argv, env = process.env) {
  const flags = {};
  const errors = [];

  argv.forEach((arg) => {
    const match = /^--([a-z-]+)(?:=(.*))?$/.exec(arg);
    if (!match) {
      errors.push(`Unexpected argument "${arg}".`);
    } else if (LIST_OPTIONS.includes(match[1])) {
      (flags[match[1]] = flags[match[1]] || []).push(match[2] || '');
    } else {
      flags[match[1]] = match[2] === undefined ? '1' : match[2];
    }
  });

  const list = (name, envName) => flags[name] || (env[envName] ? env[envName].split(',').filter(Boolean) : []);
  const options = {
    url: flags.url || env.MMWP_E2E_SITE_URL || '',
    file: flags.file || '',
    vars: flags.vars || '',
    user: flags.user || env.MMWP_E2E_USER || '',
    password: flags.password || env.MMWP_E2E_PASSWORD || '',
    allow: { console: list('allow-console', 'MMWP_E2E_ALLOW_CONSOLE'), requests: list('allow-requests', 'MMWP_E2E_ALLOW_REQUESTS'), pages: [] },
    userAgent: flags['user-agent'] || env.MMWP_E2E_USER_AGENT || '',
    workers: Number(flags.workers || env.MMWP_E2E_WORKERS || 4),
    timeoutMs: Number(flags['timeout-ms'] || 30000),
    json: flags.json || env.MMWP_E2E_RESULTS || 'mmwp-e2e-results.json',
    junit: flags.junit || env.MMWP_E2E_JUNIT || '',
  };

  Object.keys(flags).filter((k) => !VALID_OPTIONS.includes(k))
    .forEach((k) => errors.push(`Unknown option --${k}. Valid options: ${VALID_OPTIONS.map((o) => `--${o}`).join(', ')}.`));
  if (!/^https?:\/\//.test(options.url)) {
    errors.push('--url (or MMWP_E2E_SITE_URL) must be an http(s) URL.');
  }
  if (!options.file) {
    errors.push('--file is required.');
  }
  if (!options.user || !options.password) {
    errors.push('Provide --user and --password (password login also needs MMWP_E2E_ALLOW_PASSWORD_LOGIN=1).');
  }
  if (!Number.isInteger(options.workers) || options.workers < 1 || !Number.isInteger(options.timeoutMs) || options.timeoutMs < 1) {
    errors.push('--workers and --timeout-ms must be positive integers.');
  }

  return { options, errors };
}

/**
 * Read and validate the pages and vars files.
 *
 * @param {{file: string, vars: string}} options Options.
 * @returns {{pages: object[], vars: object, errors: string[], warnings: string[]}}
 */
function loadInputs(options) {
  const read = (file) => {
    try {
      return { text: fs.readFileSync(file, 'utf8') };
    } catch (error) {
      return { error: `Cannot read ${file}: ${error.message}` };
    }
  };
  const pagesFile = read(options.file);
  const varsFile = options.vars ? read(options.vars) : { text: '' };
  const fileErrors = [pagesFile.error, varsFile.error].filter(Boolean);
  if (fileErrors.length) {
    return { pages: [], vars: {}, errors: fileErrors, warnings: [] };
  }

  const parsed = parsePagesFile(pagesFile.text);
  const vars = parseVars(varsFile.text);

  return { pages: parsed.pages, vars: vars.vars, errors: parsed.errors.concat(vars.errors), warnings: parsed.warnings };
}

/**
 * Write the JSON and JUnit outputs.
 *
 * @param {object} options Options (json, junit).
 * @param {object[]} rows Report rows.
 * @param {{checks?: object[], duration: number}} extra Checks override and duration.
 * @returns {void}
 */
function writeOutputs(options, rows, extra) {
  const checks = extra.checks || rowsToChecks(rows);
  fs.writeFileSync(path.resolve(options.json), JSON.stringify({ checks, pages: rows.length, rows, duration_ms: extra.duration }));
  if (options.junit) {
    fs.writeFileSync(path.resolve(options.junit), toJunit(checks, 'mmwp-e2e pages', extra.duration));
  }
}

/**
 * Log in and return a Playwright storage state file path.
 *
 * @param {object} playwright The `@playwright/test` module.
 * @param {object} options Options.
 * @param {string} file Output path.
 * @returns {Promise<void>}
 */
async function saveLogin(playwright, options, file) {
  const site = options.url.replace(/\/+$/, '');
  const context = await playwright.request.newContext({ ignoreHTTPSErrors: ignoreTls() });
  try {
    await loginWithPassword(context, { site_url: site, admin_url: `${site}/wp-admin/` }, options);
    await context.storageState({ path: file });
  } finally {
    await context.dispose();
  }
}

/**
 * Run `mmwp-e2e pages`.
 *
 * @param {string[]} argv Arguments after the command.
 * @returns {Promise<number>} Exit code.
 */
async function pagesCommand(argv) {
  const { options, errors } = parsePagesArgs(argv);
  if (errors.length) {
    process.stderr.write(`${errors.join('\n')}\n\n${PAGES_USAGE}`);
    return 1;
  }

  const inputs = loadInputs(options);
  inputs.warnings.forEach((w) => process.stderr.write(`warning: ${w}\n`));
  if (inputs.errors.length) {
    process.stderr.write(`${inputs.errors.join('\n')}\n`);
    return 1;
  }

  const started = Date.now();
  const work = fs.mkdtempSync(path.join(os.tmpdir(), 'mmwp-e2e-pages-'));
  const storageState = path.join(work, 'state.json');
  let browser;

  try {
    const playwright = require(require.resolve('@playwright/test', { paths: [process.cwd()] }));
    await saveLogin(playwright, options, storageState);
    browser = await playwright.chromium.launch();
    const userAgent = resolveUserAgent(options.userAgent, browser.version());
    process.stderr.write(`User agent: ${userAgent}\n`);
    const settings = { site: options.url, storageState, allow: options.allow, timeoutMs: options.timeoutMs, ignoreTls: ignoreTls(), userAgent };
    const rows = await runPages(browser, inputs.pages, inputs.vars, settings, options.workers);
    rows.forEach((r) => process.stderr.write(`${r.result.toUpperCase()} ${r.area}/${r.as} ${r.name} ${r.url} ${r.duration_ms}ms${r.problems.length ? `\n  ${r.problems.join('\n  ')}` : ''}\n`));
    writeOutputs(options, rows, { duration: Date.now() - started });

    return rows.some((r) => r.result === 'fail') ? 1 : 0;
  } catch (error) {
    const checks = [{ label: 'Browser runner', status: 'fail', detail: String(error.message || error).slice(0, 600) }];
    writeOutputs(options, [], { checks, duration: Date.now() - started });
    process.stderr.write(`${checks[0].detail}\n`);

    return 1;
  } finally {
    if (browser) {
      await browser.close();
    }
    fs.rmSync(work, { recursive: true, force: true });
  }
}

module.exports = { pagesCommand, parsePagesArgs, loadInputs, PAGES_USAGE };
