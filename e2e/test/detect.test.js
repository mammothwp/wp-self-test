'use strict';

const test = require('node:test');
const assert = require('node:assert');
const fs = require('fs');
const path = require('path');
const { evaluatePage, findPhpErrors, isApiRequest } = require('../lib/detect');

const fixture = (name) => fs.readFileSync(path.join(__dirname, 'fixtures', name), 'utf8');
const observed = JSON.parse(fixture('console.json'));
const page = (overrides) => Object.assign({ status: 200, html: fixture('clean.html'), console: [], pageErrors: [], requests: [] }, overrides);

test('a clean page, even with warning/notice in prose, has no problems', () => {
  assert.deepStrictEqual(evaluatePage(page({}), {}), []);
});

test('PHP warning markup is found', () => {
  const found = findPhpErrors(fixture('php-warning.html'));
  assert.strictEqual(found.length, 1);
  assert.match(found[0], /^Warning: Undefined variable \$coupon in .*Screen\.php on line 42$/);
});

test('plain-text fatal errors, database errors and the critical error screen are found', () => {
  assert.match(findPhpErrors(fixture('php-fatal-text.html'))[0], /^Fatal error/);
  assert.deepStrictEqual(findPhpErrors(fixture('db-error.html')), ['WordPress database error']);
  assert.deepStrictEqual(findPhpErrors(fixture('critical.html')), ['critical error']);
});

test('Notice and Deprecated markers are found', () => {
  assert.match(findPhpErrors('<b>Notice</b>:  x in /a.php on line 3')[0], /^Notice/);
  assert.match(findPhpErrors('Deprecated: Function foo is deprecated in /a.php on line 9')[0], /^Deprecated/);
});

test('non-200 status is a problem', () => {
  assert.deepStrictEqual(evaluatePage(page({ status: 404 }), {}), ['HTTP 404']);
});

test('console errors are reported unless allow-listed', () => {
  const problems = evaluatePage(page({ console: observed.console }), {});
  assert.strictEqual(problems.length, 2);

  const allowed = evaluatePage(page({ console: observed.console }), { console: ['Failed to load resource', 'reading .map.'] });
  assert.deepStrictEqual(allowed, []);
});

test('failed wp-json/admin-ajax calls and any 5xx count, not other 4xx', () => {
  const problems = evaluatePage(page({ requests: observed.requests }), {});
  assert.deepStrictEqual(problems, [
    'failed request: 404 https://demo.test/wp-json/ewp/v1/items/9',
    'failed request: 500 https://demo.test/wp-admin/admin-ajax.php',
    'failed request: 502 https://demo.test/wp-content/plugins/demo/render.php',
  ]);

  assert.deepStrictEqual(evaluatePage(page({ requests: observed.requests }), { requests: ['items/9', 'admin-ajax', 'render\\.php'] }), []);
});

test('isApiRequest recognises pretty and plain REST routes', () => {
  assert.ok(isApiRequest('https://a.test/wp-json/x'));
  assert.ok(isApiRequest('https://a.test/?rest_route=/x'));
  assert.ok(!isApiRequest('https://a.test/wp-admin/index.php'));
});

test('a resource-load console error is silenced by the allow-list of its request', () => {
  const console = ['Failed to load resource: the server responded with a status of 404 (Not Found) @ https://demo.test/wp-json/ewp/v1/items/9'];

  assert.strictEqual(evaluatePage(page({ console }), {}).length, 1);
  assert.deepStrictEqual(evaluatePage(page({ console }), { requests: ['items/9'] }), []);
});
