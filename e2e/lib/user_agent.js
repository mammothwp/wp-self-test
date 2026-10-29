'use strict';

/**
 * Browser user agent of `crawl` and `pages`. Playwright's default headless UA
 * contains "HeadlessChrome", which plugins' bot and prefetch guards rightly
 * ignore, so link-handling pages would never run. Only the UA is changed: no
 * `Sec-Purpose` or other header is added.
 *
 * @module user_agent
 */

/**
 * Desktop Chrome user agent for a Chromium version.
 *
 * @param {string} version Chromium version, e.g. "130.0.6723.31".
 * @returns {string}
 */
function chromeUserAgent(version) {
  return `Mozilla/5.0 (X11; Linux x86_64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/${version} Safari/537.36`;
}

/**
 * The override when given, else the desktop Chrome UA of the version.
 *
 * @param {string} override Value of --user-agent / MMWP_E2E_USER_AGENT, may be empty.
 * @param {string} version  Chromium version.
 * @returns {string}
 */
function resolveUserAgent(override, version) {
  return override || chromeUserAgent(version);
}

/**
 * Resolve the UA, launching Chromium briefly only when there is no override.
 *
 * @param {object} playwright The `@playwright/test` module.
 * @param {string} override   Override, may be empty.
 * @returns {Promise<string>}
 */
async function detectUserAgent(playwright, override) {
  if (override) {
    return override;
  }
  const browser = await playwright.chromium.launch();
  try {
    return chromeUserAgent(browser.version());
  } finally {
    await browser.close();
  }
}

module.exports = { chromeUserAgent, resolveUserAgent, detectUserAgent };
