'use strict';

/**
 * Login and menu inventory.
 *
 * Two ways in: the single-use token the PHP runner issues (default), or an
 * admin user and password for a throwaway CI site, which is refused unless
 * MMWP_E2E_ALLOW_PASSWORD_LOGIN=1. Either way the cookies are saved as a
 * Playwright storage state and the page inventory comes from the site's
 * `admin-menu` route, or from the admin menu HTML when the route is missing.
 *
 * @module session
 */

const fs = require('fs');
const { adminUrlFor } = require('./env');
const { DEFAULT_EXCLUSIONS, pagesFromAdminMenuHtml, filterByPlugin, frontPage } = require('./inventory');

/**
 * Whether TLS errors are ignored (DDEV's local CA is often not trusted).
 *
 * @returns {boolean}
 */
function ignoreTls() {
  return process.env.MMWP_E2E_STRICT_TLS !== '1';
}

/**
 * Exchange the login token for auth cookies.
 *
 * @param {import('@playwright/test').APIRequestContext} context Request context.
 * @param {object} config Config.
 * @param {string} token  Single-use token.
 * @returns {Promise<void>}
 */
async function loginWithToken(context, config, token) {
  const login = await context.post(`${config.rest_url}browser-login`, { form: { token } });
  if (!login.ok()) {
    throw new Error(`Login token exchange failed (HTTP ${login.status()}): ${(await login.text()).slice(0, 200)}`);
  }
}

/**
 * Log in through wp-login.php. Only for throwaway CI sites.
 *
 * @param {import('@playwright/test').APIRequestContext} context Request context.
 * @param {object} config Config.
 * @param {{user: string, password: string}} credentials Admin credentials.
 * @param {NodeJS.ProcessEnv} [env] Environment.
 * @returns {Promise<void>}
 */
async function loginWithPassword(context, config, credentials, env = process.env) {
  if (env.MMWP_E2E_ALLOW_PASSWORD_LOGIN !== '1') {
    throw new Error('Password login is refused: set MMWP_E2E_ALLOW_PASSWORD_LOGIN=1 (CI-only throwaway sites; never a real site).');
  }

  const loginUrl = config.site_url.replace(/\/?$/, '/') + 'wp-login.php';
  await context.get(loginUrl);
  const response = await context.post(loginUrl, {
    form: { log: credentials.user, pwd: credentials.password, 'wp-submit': 'Log In', redirect_to: config.admin_url, testcookie: '1' },
    maxRedirects: 0,
  });
  const state = await context.storageState();

  if (!state.cookies.some((c) => c.name.startsWith('wordpress_logged_in'))) {
    throw new Error(`Password login failed (HTTP ${response.status()}): no logged-in cookie was set.`);
  }
}

/**
 * Fetch the page inventory.
 *
 * @param {import('@playwright/test').APIRequestContext} context Request context.
 * @param {object} config Config.
 * @returns {Promise<{pages: object[], source: string}>}
 */
async function fetchInventory(context, config) {
  // Admin requests build the menu; this one primes the snapshot and is the scrape source.
  const index = await context.get(adminUrlFor(config.admin_url, 'index.php'));
  const html = await index.text();

  const nonceResponse = await context.get(adminUrlFor(config.admin_url, 'admin-ajax.php?action=rest-nonce'));
  const nonce = nonceResponse.ok() ? (await nonceResponse.text()).trim() : '';
  const response = await context.get(`${config.rest_url}admin-menu`, { headers: { 'X-WP-Nonce': nonce } });

  if (response.ok()) {
    return { ...(await response.json()), source: 'rest' };
  }

  if (response.status() !== 404) {
    throw new Error(`Admin menu inventory failed (HTTP ${response.status()}): ${(await response.text()).slice(0, 200)}`);
  }

  return { pages: pagesFromAdminMenuHtml(html, config.admin_url.replace(/\/?$/, '/'), DEFAULT_EXCLUSIONS), source: 'html' };
}

/**
 * Log in and write the storage state and the inventory (admin pages,
 * filtered by `config.only_plugin`, plus the front page).
 *
 * @param {object} playwright The `@playwright/test` module.
 * @param {object} config     Config from the PHP runner or the CLI.
 * @param {{token?: string, user?: string, password?: string}} login Credentials.
 * @param {{storageState: string, inventory: string}} files Output files.
 * @returns {Promise<{pages: object[], source: string}>} Inventory.
 */
async function establishSession(playwright, config, login, files) {
  const context = await playwright.request.newContext({ ignoreHTTPSErrors: ignoreTls() });

  try {
    if (login.token) {
      await loginWithToken(context, config, login.token);
    } else {
      await loginWithPassword(context, config, login);
    }

    const inventory = await fetchInventory(context, config);
    inventory.pages = filterByPlugin(inventory.pages, config.only_plugin).concat([frontPage(config.site_url)]);
    inventory.matched = inventory.pages.length - 1;
    fs.writeFileSync(files.inventory, JSON.stringify(inventory));
    await context.storageState({ path: files.storageState });

    return inventory;
  } finally {
    await context.dispose();
  }
}

module.exports = { establishSession, ignoreTls, loginWithPassword };
