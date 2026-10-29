'use strict';

const test = require('node:test');
const assert = require('node:assert');
const fs = require('fs');
const path = require('path');
const { parsePagesFile, parseVars, resolvePage, substitute } = require('../lib/pages');
const { parsePagesArgs, loadInputs } = require('../lib/pages_command');
const { rowsToChecks, pageProblems } = require('../lib/pages_runner');

const fixture = path.join(__dirname, 'fixtures', 'browser-pages.json');
const entry = (extra) => JSON.stringify({ version: 1, pages: [Object.assign({ path: '/', as: 'guest' }, extra)] });

test('parses the fixture with defaults', () => {
  const result = parsePagesFile(fs.readFileSync(fixture, 'utf8'));

  assert.deepStrictEqual(result.errors, []);
  assert.deepStrictEqual(result.warnings, []);
  assert.strictEqual(result.pages.length, 4);
  assert.strictEqual(result.pages[0].status, 200);
  assert.strictEqual(result.pages[3].status, 404);
  assert.strictEqual(result.pages[2].expect_url, '');
});

test('area defaults from the path and name from the path', () => {
  const [admin] = parsePagesFile(entry({ path: '/wp-admin/x.php', as: 'admin' })).pages;
  const [front] = parsePagesFile(entry({})).pages;

  assert.strictEqual(admin.area, 'admin');
  assert.strictEqual(front.area, 'frontend');
  assert.strictEqual(front.name, '/');
});

test('missing path or as fail fast', () => {
  const result = parsePagesFile(JSON.stringify({ version: 1, pages: [{ name: 'A', as: 'guest' }, { name: 'B', path: '/x' }, { path: 'x', as: 'root' }] }));

  assert.deepStrictEqual(result.pages, []);
  assert.strictEqual(result.errors.length, 4);
  assert.match(result.errors[0], /"A": "path" is required/);
  assert.match(result.errors[1], /"B": "as" is required/);
});

test('unknown keys warn but do not fail', () => {
  const result = parsePagesFile(JSON.stringify({ version: 1, extra: 1, pages: [{ path: '/', as: 'guest', colour: 'red' }] }));

  assert.deepStrictEqual(result.errors, []);
  assert.strictEqual(result.pages.length, 1);
  assert.deepStrictEqual(result.warnings, ['file: unknown key "extra" ignored.', 'pages[0]: unknown key "colour" ignored.']);
});

test('rejects bad JSON, version, status and empty pages', () => {
  assert.match(parsePagesFile('{').errors[0], /Invalid JSON/);
  assert.match(parsePagesFile('{"version":2,"pages":[]}').errors[0], /version/);
  assert.match(parsePagesFile('{"version":1,"pages":[]}').errors[0], /non-empty/);
  assert.match(parsePagesFile(entry({ status: 'ok' })).errors[0], /status/);
});

test('substitutes ${VAR} and reports unknown variables', () => {
  assert.deepStrictEqual(substitute('/a/${ID}/${ID}', { ID: 7 }), { value: '/a/7/7', missing: [] });
  assert.deepStrictEqual(substitute('${A}${B}', { A: 1 }).missing, ['B']);

  const ok = resolvePage({ path: '/p/${ID}', expect: '', expect_url: 'p=${ID}' }, { ID: 3 });
  assert.strictEqual(ok.page.path, '/p/3');
  assert.strictEqual(ok.page.expect_url, 'p=3');
  assert.strictEqual(ok.error, '');

  const bad = resolvePage({ path: '/p/${NOPE}', expect: '', expect_url: '' }, {});
  assert.match(bad.error, /Unknown variable\(s\) \$\{NOPE\}/);
});

test('parseVars accepts an object only', () => {
  assert.deepStrictEqual(parseVars('').vars, {});
  assert.deepStrictEqual(parseVars('{"A":1}').vars, { A: 1 });
  assert.strictEqual(parseVars('[1]').errors.length, 1);
  assert.strictEqual(parseVars('{').errors.length, 1);
});

test('argument parsing requires url, file and login and applies defaults', () => {
  const bad = parsePagesArgs(['--bogus'], {});
  assert.strictEqual(bad.errors.length, 4);

  const { options, errors } = parsePagesArgs(['--url=http://x.test', `--file=${fixture}`, '--user=a', '--password=b', '--allow-console=foo', '--allow-console=bar'], {});
  assert.deepStrictEqual(errors, []);
  assert.strictEqual(options.workers, 4);
  assert.strictEqual(options.timeoutMs, 30000);
  assert.deepStrictEqual(options.allow.console, ['foo', 'bar']);
});

test('loadInputs reads the files and reports unreadable ones', () => {
  assert.strictEqual(loadInputs({ file: fixture, vars: '' }).pages.length, 4);
  assert.match(loadInputs({ file: '/nonexistent.json', vars: '' }).errors[0], /Cannot read/);
});

test('rowsToChecks labels rows and joins problems', () => {
  const [check] = rowsToChecks([{ area: 'admin', as: 'admin', name: 'Dash', result: 'fail', problems: ['a', 'b'] }]);

  assert.deepStrictEqual(check, { label: 'admin/admin: Dash', status: 'fail', detail: 'a\nb' });
});

test('expect_text is parsed, substituted and must be a string', () => {
  assert.strictEqual(parsePagesFile(entry({ expect_text: 'Link expired' })).pages[0].expect_text, 'Link expired');
  assert.match(parsePagesFile(entry({ expect_text: 5 })).errors[0], /expect_text/);
  assert.strictEqual(resolvePage({ path: '/', expect: '', expect_url: '', expect_text: 'Order #${ID}' }, { ID: 7 }).page.expect_text, 'Order #7');
});

test('expect_text fails with the expected text when missing', () => {
  const seen = { observed: 200, documentUrl: 'http://x/', html: '', text: 'Hello world', console: [], pageErrors: [], requests: [] };

  assert.deepStrictEqual(pageProblems(seen, { status: 200, expect_text: 'world' }, {}), []);
  assert.deepStrictEqual(pageProblems(seen, { status: 200, expect_text: 'Thank you' }, {}), ['expected text not found: "Thank you"']);
});

test('a page expecting 410 passes when the document returns 410', () => {
  const seen = { observed: 410, documentUrl: 'http://x/gone', html: '<p>Gone</p>', text: 'Gone', console: [], pageErrors: [], requests: [{ url: 'http://x/gone', status: 410 }] };

  assert.deepStrictEqual(pageProblems(seen, { status: 410, expect_text: '' }, {}), []);
  assert.deepStrictEqual(pageProblems({ ...seen, observed: 200, requests: [] }, { status: 410, expect_text: '' }, {}), ['expected HTTP 410']);
});

test('an expected error status does not hide other failing responses or PHP errors', () => {
  const seen = {
    observed: 500, documentUrl: 'http://x/e', html: '<b>Warning</b>: Undefined in /a.php on line 3', text: '', console: [], pageErrors: [],
    requests: [{ url: 'http://x/e', status: 500 }, { url: 'http://x/other.js', status: 503 }],
  };
  const problems = pageProblems(seen, { status: 500, expect_text: '' }, {});

  assert.strictEqual(problems.length, 2);
  assert.ok(problems.some((p) => p.startsWith('PHP/WordPress error')));
  assert.ok(problems.includes('failed request: 503 http://x/other.js'));
});

test('--allow-requests is repeatable and rejects unknown options with the valid list', () => {
  const base = ['--url=http://x', '--file=f.json', '--user=u', '--password=p'];
  const ok = parsePagesArgs(base.concat(['--allow-requests=^a', '--allow-requests=^b']), {});
  const bad = parsePagesArgs(base.concat(['--bogus=1']), {});

  assert.deepStrictEqual(ok.errors, []);
  assert.deepStrictEqual(ok.options.allow.requests, ['^a', '^b']);
  assert.strictEqual(bad.errors.length, 1);
  assert.ok(bad.errors[0].startsWith('Unknown option --bogus. Valid options: --url'));
  assert.ok(bad.errors[0].includes('--allow-requests'));
});

const external = { observed: 200, documentUrl: 'http://x/', html: '', text: '', console: [], pageErrors: [], requests: [{ url: 'https://ext.example/a.js', status: 503 }] };
const externalAllow = { requests: ['^([0-9]+ )?https?://(?!localhost[:/])'] };

test('a failed external request matching --allow-requests passes', () => {
  assert.deepStrictEqual(pageProblems(external, { status: 200, expect_text: '' }, externalAllow), []);
});

test('a failed request not matching --allow-requests still fails', () => {
  const seen = { ...external, requests: [{ url: 'http://localhost:8080/x.js', status: 503 }] };

  assert.deepStrictEqual(pageProblems(seen, { status: 200, expect_text: '' }, externalAllow), ['failed request: 503 http://localhost:8080/x.js']);
});

test('a matching request does not hide a PHP error', () => {
  const seen = { ...external, html: '<b>Warning</b>: Undefined in /a.php on line 3' };
  const problems = pageProblems(seen, { status: 200, expect_text: '' }, externalAllow);

  assert.strictEqual(problems.length, 1);
  assert.ok(problems[0].startsWith('PHP/WordPress error'));
});
