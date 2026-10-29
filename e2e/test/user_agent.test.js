'use strict';

const test = require('node:test');
const assert = require('node:assert');
const { chromeUserAgent, resolveUserAgent, detectUserAgent } = require('../lib/user_agent');
const { parsePagesArgs } = require('../lib/pages_command');
const { parseArgs } = require('../lib/args');
const { evaluatePage, isAllowedConsole, failedRequests } = require('../lib/detect');

const fakePlaywright = (version) => ({ chromium: { launch: async () => ({ version: () => version, close: async () => {} }) } });

test('default UA is desktop Chrome derived from the Chromium version', () => {
  const ua = chromeUserAgent('130.0.6723.31');

  assert.strictEqual(ua, 'Mozilla/5.0 (X11; Linux x86_64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/130.0.6723.31 Safari/537.36');
  assert.doesNotMatch(ua, /Headless/);
});

test('override wins over the derived UA', async () => {
  assert.strictEqual(resolveUserAgent('Custom/1', '130.0'), 'Custom/1');
  assert.match(resolveUserAgent('', '130.0'), /Chrome\/130\.0 Safari/);
  assert.strictEqual(await detectUserAgent(fakePlaywright('99.1'), 'Custom/2'), 'Custom/2');
  assert.match(await detectUserAgent(fakePlaywright('99.1'), ''), /Chrome\/99\.1 Safari/);
});

test('--user-agent and MMWP_E2E_USER_AGENT are parsed by pages and crawl', () => {
  const base = ['--url=http://x.test', '--file=f.json', '--user=a', '--password=b'];

  assert.strictEqual(parsePagesArgs(base.concat('--user-agent=UA1'), {}).options.userAgent, 'UA1');
  assert.strictEqual(parsePagesArgs(base, { MMWP_E2E_USER_AGENT: 'UA2' }).options.userAgent, 'UA2');
  assert.deepStrictEqual(parsePagesArgs(base.concat('--user-agent=UA1'), {}).errors, []);
  assert.strictEqual(parseArgs(['--url=http://x.test', '--user=a', '--password=b', '--user-agent=UA3'], {}).options.userAgent, 'UA3');
  assert.strictEqual(parseArgs(['--url=http://x.test', '--user=a', '--password=b'], {}).options.userAgent, '');
});

const REPORTED = 'Failed to load resource: the server responded with a status of 404 (Not Found) @ http://localhost:8080/cart/undefinedwc/store/v1/cart';

test('--allow-console matches the URL part of the reported string', () => {
  assert.ok(isAllowedConsole(REPORTED, ['undefinedwc/store/v1/cart']));
  assert.ok(isAllowedConsole(REPORTED, ['Not Found\\) @ http://localhost']));
  assert.ok(isAllowedConsole(REPORTED, ['status of 404 \\(Not Found\\)$']), 'message-anchored patterns still match');
  assert.ok(!isAllowedConsole(REPORTED, ['somethingelse']));
  assert.deepStrictEqual(evaluatePage({ status: 200, html: '', console: [REPORTED], pageErrors: [], requests: [] }, { console: ['/cart/undefinedwc/'] }), []);
  assert.strictEqual(evaluatePage({ status: 200, html: '', console: [REPORTED], pageErrors: [], requests: [] }, {}).length, 1);
});

test('failed non-document requests matching --allow-console by URL do not fail the page', () => {
  const asset = { url: 'http://localhost:8080/cart/undefinedwc/store/v1/cart', status: 502, resourceType: 'fetch' };
  const api = { url: 'http://localhost:8080/wp-json/a/b', status: 500, resourceType: 'fetch' };
  const doc = { url: 'http://localhost:8080/cart/undefinedwc/', status: 500, resourceType: 'document' };
  const allow = { console: ['undefinedwc'] };

  assert.deepStrictEqual(failedRequests([asset, api], [], allow.console), ['500 http://localhost:8080/wp-json/a/b']);
  assert.deepStrictEqual(failedRequests([doc], [], allow.console), ['500 http://localhost:8080/cart/undefinedwc/']);
  assert.deepStrictEqual(evaluatePage({ status: 200, html: '', console: [], pageErrors: [], requests: [asset] }, allow), []);
  assert.strictEqual(evaluatePage({ status: 200, html: '', console: [], pageErrors: [], requests: [asset] }, {}).length, 1);
});
