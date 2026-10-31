'use strict';

/**
 * Runs bin/run.js against the fake site with a real Chromium. Skipped when
 * @playwright/test or its Chromium is not installed (e.g. plain unit CI).
 */

const test = require('node:test');
const assert = require('node:assert');
const fs = require('fs');
const os = require('os');
const path = require('path');
const { spawn } = require('child_process');
const fake = require('./fake-wp');

const root = path.join(__dirname, '..', '..');

/**
 * Why the integration test cannot run here, or '' when it can.
 *
 * @returns {string}
 */
function unavailable() {
  try {
    const { chromium } = require(require.resolve('@playwright/test', { paths: [root] }));
    return fs.existsSync(chromium.executablePath()) ? '' : 'Chromium is not installed (npx playwright install chromium)';
  } catch (error) {
    return '@playwright/test is not installed (npm i --no-save @playwright/test)';
  }
}

/**
 * Run the Node runner against a site and return its results file.
 *
 * @param {string} siteUrl Fake site URL.
 * @param {object} browser Case `browser` declaration.
 * @param {string} token   Login token to present.
 * @returns {Promise<{status: number, results: object, stderr: string}>}
 */
async function runRunner(siteUrl, browser, token) {
  const work = fs.mkdtempSync(path.join(os.tmpdir(), 'mmwp-int-'));
  const config = path.join(work, 'config.json');
  const results = path.join(work, 'results.json');

  fs.writeFileSync(config, JSON.stringify({
    case: 'smoke', plugin: 'demo', root,
    site_url: siteUrl, admin_url: `${siteUrl}/wp-admin/`, rest_url: `${siteUrl}/wp-json/mmwp/v1/self-test/`,
    browser: Object.assign({ crawl: true, specs: [], allow: { console: [], requests: [], pages: [] }, timeout: 120 }, browser),
  }));

  // Async: the fake site lives in this process and must keep answering.
  const child = spawn(process.execPath, [path.join(root, 'e2e', 'bin', 'run.js')], {
    env: Object.assign({}, process.env, { MMWP_E2E_CONFIG: config, MMWP_E2E_RESULTS: results, MMWP_E2E_LOGIN_TOKEN: token, MMWP_E2E_WORKERS: '2' }),
  });
  let stderr = '';
  child.stderr.on('data', (chunk) => { stderr += chunk; });
  const status = await new Promise((resolve) => child.on('close', resolve));

  return { status, results: JSON.parse(fs.readFileSync(results, 'utf8')), stderr };
}

const skip = unavailable();

test('crawler reports the broken pages and passes the clean ones', { skip: skip || false, timeout: 120000 }, async () => {
  const site = await fake.start({ token: 'good-token' });

  try {
    const { status, results } = await runRunner(site.url, {}, 'good-token');
    const failed = results.checks.filter((c) => c.status === 'fail').map((c) => c.label).sort();

    assert.strictEqual(status, 1);
    assert.strictEqual(results.pages, 6);
    assert.deepStrictEqual(failed, ['Admin crawl: 6 page(s)', 'crawl console.php', 'crawl noisy.php', 'crawl rest.php', 'crawl warn.php']);
    assert.match(results.checks.find((c) => c.label === 'crawl warn.php').detail, /Warning: Undefined variable/);
    assert.match(results.checks.find((c) => c.label === 'crawl rest.php').detail, /failed request: 404 .*\/wp-json\/demo\/v1\/missing/);
  } finally {
    site.close();
  }
});

test('allow-lists silence known noise and pages', { skip: skip || false, timeout: 120000 }, async () => {
  const site = await fake.start({ token: 'good-token' });

  try {
    const allow = { console: ['known noise', 'boom from plugin'], requests: ['demo/v1/missing'], pages: ['^warn\\.php$'] };
    const { status, results } = await runRunner(site.url, { allow }, 'good-token');

    assert.strictEqual(status, 0, JSON.stringify(results));
    assert.strictEqual(results.checks[0].status, 'pass');
  } finally {
    site.close();
  }
});

test('a refused login token produces a failing check, not a crash', { skip: skip || false, timeout: 60000 }, async () => {
  const site = await fake.start({ token: 'good-token' });

  try {
    const { status, results } = await runRunner(site.url, {}, 'wrong');

    assert.strictEqual(status, 1);
    assert.match(results.checks[0].detail, /Login token exchange failed: .*\(HTTP 403, content-type: application\/json\)/);
  } finally {
    site.close();
  }
});

test('plugin flow specs run through the helpers and fold into the results', { skip: skip || false, timeout: 120000 }, async () => {
  const site = await fake.start({ token: 'good-token' });

  try {
    const { results } = await runRunner(site.url, { crawl: false, specs: ['e2e/test/fixtures/flow.spec.js'] }, 'good-token');
    const byLabel = Object.fromEntries(results.checks.map((c) => [c.label, c]));

    assert.strictEqual(byLabel['flow: opens a page through the helpers and sees no browser errors'].status, 'pass');
    assert.strictEqual(byLabel['flow: a page with PHP errors fails openAdminPage'].status, 'fail');
    assert.match(byLabel['flow: a page with PHP errors fails openAdminPage'].detail, /PHP\/WordPress errors/);
  } finally {
    site.close();
  }
});

/**
 * Run `mmwp-e2e crawl` against a site and return its exit code and outputs.
 *
 * @param {string[]} args Extra arguments.
 * @param {object}   env  Extra environment.
 * @returns {Promise<{status: number, results: object, junit: string, stderr: string}>}
 */
async function runCli(args, env) {
  const work = fs.mkdtempSync(path.join(os.tmpdir(), 'mmwp-cli-'));
  const json = path.join(work, 'out.json');
  const junit = path.join(work, 'out.xml');
  const child = spawn(process.execPath, [path.join(root, 'e2e', 'bin', 'cli.js'), 'crawl', `--json=${json}`, `--junit=${junit}`, ...args], {
    cwd: root,
    env: Object.assign({}, process.env, { MMWP_E2E_WORKERS: '2' }, env),
  });
  let stderr = '';
  child.stderr.on('data', (chunk) => { stderr += chunk; });
  const status = await new Promise((resolve) => child.on('close', resolve));

  return { status, results: JSON.parse(fs.readFileSync(json, 'utf8')), junit: fs.existsSync(junit) ? fs.readFileSync(junit, 'utf8') : '', stderr };
}

test('CLI: password login is refused unless explicitly allowed', { skip: skip || false, timeout: 60000 }, async () => {
  const site = await fake.start({ token: 'x' });

  try {
    const { status, results } = await runCli([`--url=${site.url}`, '--user=admin', '--password=secret'], { MMWP_E2E_ALLOW_PASSWORD_LOGIN: '' });

    assert.strictEqual(status, 1);
    assert.match(results.checks[0].detail, /MMWP_E2E_ALLOW_PASSWORD_LOGIN=1/);
  } finally {
    site.close();
  }
});

test('CLI: crawls front page and scraped menu, fails on 5xx, page errors, writes JSON + JUnit', { skip: skip || false, timeout: 120000 }, async () => {
  const pages = {
    'boom.php': '<h1>Boom</h1><script>fetch("/api/boom")</script>',
    'throws.php': '<h1>Throws</h1><script>setTimeout(() => { throw new Error("uncaught in page"); }, 0)</script>',
  };
  const site = await fake.start({ token: 'x', plain: true, noRest: 'html', pages, front: '<html><body><h1>Front</h1><script>console.error("front broke")</script></body></html>' });

  try {
    const { status, results, junit } = await runCli([`--url=${site.url}`, '--user=admin', '--password=secret', '--allow-pages=^(warn|console|noisy|rest)\\.php$'], { MMWP_E2E_ALLOW_PASSWORD_LOGIN: '1' });
    const failed = results.checks.filter((c) => c.status === 'fail' && c.label.startsWith('crawl ')).map((c) => c.label).sort();

    assert.strictEqual(status, 1);
    assert.deepStrictEqual(failed, ['crawl (front page)', 'crawl boom.php', 'crawl throws.php']);
    assert.match(results.checks.find((c) => c.label === 'crawl boom.php').detail, /failed request: 500 .*\/api\/boom/);
    assert.match(results.checks.find((c) => c.label === 'crawl throws.php').detail, /uncaught in page/);
    assert.match(results.checks.find((c) => c.label === 'crawl (front page)').detail, /front broke/);
    assert.match(junit, /<failure message=/);
    assert.ok(site.state.requests.some((r) => r.includes('rest_route=/mmwp/v1/self-test/admin-menu')), 'tries the rest_route inventory');
    assert.ok(!site.state.requests.some((r) => r.startsWith('/wp-json/mmwp')), 'never uses /wp-json/ for self-test routes');
    assert.ok(!site.state.requests.some((r) => r.includes('browser-login')), 'password login never touches the self-test REST route');
    assert.ok(!results.checks.some((c) => c.label.includes('plugins.php') || c.label.includes('example.org')));
  } finally {
    site.close();
  }
});

test('CLI: --only-plugin narrows the admin pages; token login works', { skip: skip || false, timeout: 120000 }, async () => {
  const site = await fake.start({ token: 'good-token' });

  try {
    const { status, results } = await runCli([`--url=${site.url}`, '--token=good-token', '--only-plugin=clean'], {});

    assert.strictEqual(status, 0, JSON.stringify(results));
    assert.strictEqual(results.pages, 2);
  } finally {
    site.close();
  }
});

test('CLI: plain permalinks, routes missing (JSON 404): password login and HTML-scrape inventory', { skip: skip || false, timeout: 120000 }, async () => {
  const site = await fake.start({ token: 'x', plain: true, noRest: true });

  try {
    const { status, results } = await runCli([`--url=${site.url}`, '--user=admin', '--password=secret', '--only-plugin=clean'], { MMWP_E2E_ALLOW_PASSWORD_LOGIN: '1' });

    assert.strictEqual(status, 0, JSON.stringify(results));
    assert.strictEqual(results.pages, 2);
    assert.ok(!site.state.requests.some((r) => r.includes('browser-login')));
  } finally {
    site.close();
  }
});

test('CLI: plain permalinks with routes: token login and REST inventory through ?rest_route=', { skip: skip || false, timeout: 120000 }, async () => {
  const site = await fake.start({ token: 'good-token', plain: true });

  try {
    const { status, results } = await runCli([`--url=${site.url}`, '--token=good-token', '--only-plugin=clean'], {});

    assert.strictEqual(status, 0, JSON.stringify(results));
    assert.strictEqual(results.pages, 2);
    assert.ok(site.state.requests.includes('/?rest_route=/mmwp/v1/self-test/browser-login'));
    assert.ok(!site.state.requests.some((r) => r.startsWith('/wp-json/mmwp')));
  } finally {
    site.close();
  }
});

test('CLI: a token login against a site without the routes names URL, status and content-type', { skip: skip || false, timeout: 60000 }, async () => {
  const site = await fake.start({ token: 'x', plain: true, noRest: 'html' });

  try {
    const { status, results } = await runCli([`--url=${site.url}`, '--token=x'], {});

    assert.strictEqual(status, 1);
    assert.match(results.checks[0].detail, /Login token exchange failed: .*rest_route=\/mmwp\/v1\/self-test\/browser-login \(HTTP 200, content-type: text\/html/);
    assert.doesNotMatch(results.checks[0].detail, /Unexpected token/);
  } finally {
    site.close();
  }
});
