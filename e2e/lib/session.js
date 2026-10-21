'use strict';

/**
 * Passwordless login and menu inventory.
 *
 * Exchanges the single-use token for auth cookies, saves them as a
 * Playwright storage state, loads one admin page so the site snapshots its
 * menu, then fetches the inventory.
 *
 * @module session
 */

const fs = require('fs');
const { adminUrlFor } = require('./env');

/**
 * Log in with the token and write the storage state and the inventory.
 *
 * @param {object} playwright    The `@playwright/test` module.
 * @param {object} config        Config from the PHP runner.
 * @param {string} token         Single-use login token.
 * @param {{storageState: string, inventory: string}} files Output files.
 * @returns {Promise<{pages: object[]}>} Inventory.
 */
async function establishSession(playwright, config, token, files) {
  const context = await playwright.request.newContext({ ignoreHTTPSErrors: ignoreTls() });

  try {
    const login = await context.post(`${config.rest_url}browser-login`, { form: { token } });
    if (!login.ok()) {
      throw new Error(`Login token exchange failed (HTTP ${login.status()}): ${(await login.text()).slice(0, 200)}`);
    }

    // Admin requests build the menu; this one primes the snapshot.
    await context.get(adminUrlFor(config.admin_url, 'index.php'));

    const nonceResponse = await context.get(adminUrlFor(config.admin_url, 'admin-ajax.php?action=rest-nonce'));
    const nonce = (await nonceResponse.text()).trim();

    const response = await context.get(`${config.rest_url}admin-menu`, { headers: { 'X-WP-Nonce': nonce } });
    if (!response.ok()) {
      throw new Error(`Admin menu inventory failed (HTTP ${response.status()}): ${(await response.text()).slice(0, 200)}`);
    }

    const inventory = await response.json();
    fs.writeFileSync(files.inventory, JSON.stringify(inventory));
    await context.storageState({ path: files.storageState });

    return inventory;
  } finally {
    await context.dispose();
  }
}

/**
 * Whether TLS errors are ignored (DDEV's local CA is often not trusted).
 *
 * @returns {boolean}
 */
function ignoreTls() {
  return process.env.MMWP_E2E_STRICT_TLS !== '1';
}

module.exports = { establishSession, ignoreTls };
