'use strict';

const test = require('node:test');
const assert = require('node:assert');
const { pagesFromAdminMenuHtml, filterByPlugin, frontPage, DEFAULT_EXCLUSIONS } = require('../lib/inventory');
const { parseArgs } = require('../lib/args');
const { toJunit } = require('../lib/junit');
const { loginWithPassword } = require('../lib/session');

const html = `<ul id="adminmenu">
<li><a href="index.php" class="menu-top"><div class="wp-menu-name">Dashboard</div></a></li>
<li><a href="admin.php?page=ewp-coupons&#038;tab=a" class="menu-top"><div class="wp-menu-name">Coupons <span class="n">3</span></div></a></li>
<li><a href="admin.php?page=ewp-coupons&#038;tab=a">dup</a></li>
<li><a href="plugins.php?action=activate&#038;_wpnonce=abc">Activate</a></li>
<li><a href="customize.php?return=x">Customize</a></li>
<li><a href="https://example.org/docs">Docs</a></li>
<li><a href="post-new.php">New</a></li>
<li id="collapse-menu"><a href="http://localhost/wp-admin/outside-menu.php">collapse</a></li></ul>`;

test('admin menu HTML is turned into safe, de-duplicated pages', () => {
  const pages = pagesFromAdminMenuHtml(html, 'http://localhost/wp-admin/', DEFAULT_EXCLUSIONS);

  assert.deepStrictEqual(pages.map((p) => p.path), ['index.php', 'admin.php?page=ewp-coupons&tab=a']);
  assert.strictEqual(pages[1].title, 'Coupons 3');
  assert.strictEqual(pages[1].url, 'http://localhost/wp-admin/admin.php?page=ewp-coupons&tab=a');
});

test('filterByPlugin matches slug in URL or parent, - and _ alike', () => {
  const pages = [{ path: 'admin.php?page=my_plugin-x' }, { path: 'edit.php', parent: 'my-plugin' }, { path: 'tools.php' }];

  assert.strictEqual(filterByPlugin(pages, 'my-plugin').length, 2);
  assert.strictEqual(filterByPlugin(pages, '').length, 3);
});

test('front page entry', () => {
  assert.strictEqual(frontPage('https://a.test').url, 'https://a.test/');
});

test('args: flags, env fallbacks, repeatable lists and validation', () => {
  const { options, errors } = parseArgs(['--url=https://a.test', '--user=admin', '--password=p', '--only-plugin=x', '--allow-console=a', '--allow-console=b', '--junit=j.xml'], { MMWP_E2E_ALLOW_REQUESTS: 'r1,r2' });

  assert.deepStrictEqual(errors, []);
  assert.deepStrictEqual(options.allow, { console: ['a', 'b'], requests: ['r1', 'r2'], pages: [] });
  assert.strictEqual(options.junit, 'j.xml');
  assert.strictEqual(options.workers, '4');
  assert.strictEqual(parseArgs([], {}).errors.length, 2);
  assert.strictEqual(parseArgs(['--url=https://a.test'], {}).errors.length, 1);
});

test('junit marks failures and skips and escapes XML', () => {
  const xml = toJunit([{ label: 'a<b', status: 'pass' }, { label: 'c', status: 'fail', detail: 'x & y\nmore' }, { label: 'd', status: 'skip', detail: 'why' }], 'suite', 1500);

  assert.match(xml, /tests="3" failures="1" skipped="1" time="1.500"/);
  assert.match(xml, /name="a&lt;b"/);
  assert.match(xml, /<failure message="x &amp; y">/);
});

test('password login is refused without MMWP_E2E_ALLOW_PASSWORD_LOGIN=1', async () => {
  await assert.rejects(loginWithPassword({}, { site_url: 'https://a.test' }, { user: 'a', password: 'b' }, {}), /MMWP_E2E_ALLOW_PASSWORD_LOGIN=1/);
});
