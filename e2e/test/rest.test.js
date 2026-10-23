'use strict';

const test = require('node:test');
const assert = require('node:assert');
const { restEndpoint, readJson } = require('../lib/rest');

/**
 * Minimal Playwright APIResponse stand-in.
 *
 * @param {number} status HTTP status.
 * @param {string} type   Content-Type.
 * @param {string} body   Body.
 * @returns {object}
 */
function response(status, type, body) {
  return { status: () => status, headers: () => ({ 'content-type': type }), text: async () => body, url: () => 'http://x.test/?rest_route=/a' };
}

test('restEndpoint uses ?rest_route= whatever the config rest_url says', () => {
  const expected = 'http://x.test/?rest_route=/mmwp/v1/self-test/admin-menu';

  assert.strictEqual(restEndpoint({ site_url: 'http://x.test/' }, 'admin-menu'), expected);
  assert.strictEqual(restEndpoint({ site_url: 'http://x.test', rest_url: 'http://x.test/wp-json/mmwp/v1/self-test/' }, 'admin-menu'), expected);
});

test('restEndpoint honours an explicit non-wp-json base', () => {
  assert.strictEqual(restEndpoint({ site_url: 'http://x.test', rest_url: 'http://x.test/?rest_route=/mmwp/v1/self-test/' }, 'admin-menu'), 'http://x.test/?rest_route=/mmwp/v1/self-test/admin-menu');
  assert.strictEqual(restEndpoint({ site_url: 'http://x.test', rest_url: 'http://x.test/api/st' }, 'login'), 'http://x.test/api/st/login');
});

test('readJson parses JSON, flags HTML and missing routes without throwing', async () => {
  const ok = await readJson(response(200, 'application/json; charset=UTF-8', '{"pages":[]}'));
  assert.ok(ok.isJson && !ok.missing);

  const html = await readJson(response(200, 'text/html', '<!DOCTYPE html><html>'));
  assert.ok(!html.isJson);
  assert.match(html.description, /http:\/\/x\.test\/\?rest_route=\/a \(HTTP 200, content-type: text\/html\)/);

  assert.ok((await readJson(response(404, 'application/json', '{"code":"rest_no_route"}'))).missing);
  assert.ok((await readJson(response(200, 'application/json', 'not json'))).isJson === false);
});
