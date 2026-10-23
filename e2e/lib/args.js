'use strict';

/**
 * Command line and environment parsing of `mmwp-e2e crawl`.
 *
 * @module args
 */

const USAGE = `Usage: mmwp-e2e crawl --url=<site> [options]

Crawls the front page and the wp-admin menu pages of a site (GET only).

Site and login (flags win over environment variables):
  --url=<url>             Site URL                         (MMWP_E2E_SITE_URL)
  --token=<token>         Single-use login token           (MMWP_E2E_LOGIN_TOKEN)
  --user=<user>           Admin user, CI-only throwaway    (MMWP_E2E_USER)
  --password=<password>   Admin password                   (MMWP_E2E_PASSWORD)
                          Password login is refused unless MMWP_E2E_ALLOW_PASSWORD_LOGIN=1.

Scope:
  --only-plugin=<slug>    Only admin pages whose URL or parent menu slug contains <slug>
                          (the front page is always crawled)   (MMWP_E2E_ONLY_PLUGIN)
  --rest-url=<url>        mmwp/v1/self-test/ REST base (default <url>/wp-json/mmwp/v1/self-test/)
  --allow-console=<re>    Ignore console errors matching the regex (repeatable)   (MMWP_E2E_ALLOW_CONSOLE, comma-separated)
  --allow-requests=<re>   Ignore failed "STATUS URL" requests matching (repeatable) (MMWP_E2E_ALLOW_REQUESTS)
  --allow-pages=<re>      Skip inventory paths matching (repeatable)                (MMWP_E2E_ALLOW_PAGES)
  --spec=<file>           Also run a Playwright flow spec, relative to the cwd (repeatable)

Output:
  --json=<file>           Results JSON  (default mmwp-e2e-results.json; MMWP_E2E_RESULTS)
  --junit=<file>          JUnit XML     (MMWP_E2E_JUNIT; omitted when unset)
  --workers=<n>           Parallel pages (default 4; MMWP_E2E_WORKERS)

Exit code: 0 all checks passed, 1 a check failed or the run broke.
`;

const LISTS = ['allow-console', 'allow-requests', 'allow-pages', 'spec'];

/**
 * Parse argv and environment.
 *
 * @param {string[]}          argv Arguments after the command name.
 * @param {NodeJS.ProcessEnv} env  Environment.
 * @returns {{options: object, errors: string[]}}
 */
function parseArgs(argv, env = process.env) {
  const flags = {};
  const errors = [];

  argv.forEach((arg) => {
    const match = /^--([a-z-]+)(?:=(.*))?$/.exec(arg);
    if (!match) {
      errors.push(`Unexpected argument "${arg}".`);
      return;
    }
    if (LISTS.includes(match[1])) {
      (flags[match[1]] = flags[match[1]] || []).push(match[2] || '');
    } else {
      flags[match[1]] = match[2] === undefined ? '1' : match[2];
    }
  });

  const list = (name, envName) => flags[name] || (env[envName] ? env[envName].split(',').filter(Boolean) : []);
  const options = {
    url: flags.url || env.MMWP_E2E_SITE_URL || '',
    token: flags.token || env.MMWP_E2E_LOGIN_TOKEN || '',
    user: flags.user || env.MMWP_E2E_USER || '',
    password: flags.password || env.MMWP_E2E_PASSWORD || '',
    onlyPlugin: flags['only-plugin'] || env.MMWP_E2E_ONLY_PLUGIN || '',
    restUrl: flags['rest-url'] || '',
    allow: { console: list('allow-console', 'MMWP_E2E_ALLOW_CONSOLE'), requests: list('allow-requests', 'MMWP_E2E_ALLOW_REQUESTS'), pages: list('allow-pages', 'MMWP_E2E_ALLOW_PAGES') },
    specs: flags.spec || [],
    json: flags.json || env.MMWP_E2E_RESULTS || 'mmwp-e2e-results.json',
    junit: flags.junit || env.MMWP_E2E_JUNIT || '',
    workers: flags.workers || env.MMWP_E2E_WORKERS || '4',
    help: Boolean(flags.help),
  };

  if (!options.help) {
    if (!/^https?:\/\//.test(options.url)) {
      errors.push('--url (or MMWP_E2E_SITE_URL) must be an http(s) URL.');
    }
    if (!options.token && !(options.user && options.password)) {
      errors.push('Provide --token, or --user and --password (password login also needs MMWP_E2E_ALLOW_PASSWORD_LOGIN=1).');
    }
  }

  return { options, errors };
}

module.exports = { parseArgs, USAGE };
