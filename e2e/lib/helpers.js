'use strict';

/**
 * Helpers for plugin flow tests: open a page, fill and submit an admin
 * form, assert notices, and assert the page stayed free of console and
 * REST/ajax errors.
 *
 * @module helpers
 */

const { expect } = require('@playwright/test');
const { readConfig, adminUrlFor } = require('./env');
const { evaluatePage } = require('./detect');

/**
 * Open a wp-admin page and fail on a non-2xx response or PHP error markup.
 *
 * @param {import('@playwright/test').Page} page Page.
 * @param {string} path Path relative to wp-admin/, e.g. `admin.php?page=ewp-coupons`.
 * @returns {Promise<import('@playwright/test').Response>}
 */
async function openAdminPage(page, path) {
  const response = await page.goto(adminUrlFor(readConfig().admin_url, path), { waitUntil: 'load' });
  expect(response && response.status(), `HTTP status of ${path}`).toBe(200);

  const problems = evaluatePage({ status: 200, html: await page.content(), console: [], pageErrors: [], requests: [] }, {});
  expect(problems, `PHP/WordPress errors on ${path}`).toEqual([]);

  return response;
}

/**
 * Locate a form control by `name`, then `id`, then label text.
 *
 * @param {import('@playwright/test').Locator|import('@playwright/test').Page} scope Page or form locator.
 * @param {string} key Control name, id or label.
 * @returns {import('@playwright/test').Locator}
 */
function control(scope, key) {
  const escaped = String(key).replace(/"/g, '\\"');
  return scope.locator(`[name="${escaped}"], [id="${escaped}"]`).first().or(scope.getByLabel(key, { exact: true }).first());
}

/**
 * Fill a form from `{name: value}`. Strings go to text inputs, textareas
 * and selects, booleans to checkboxes, arrays to multi-selects.
 *
 * @param {import('@playwright/test').Page} page Page.
 * @param {Object<string, string|boolean|string[]>} fields Values by control name, id or label.
 * @param {import('@playwright/test').Locator} [form] Restrict to one form.
 * @returns {Promise<void>}
 */
async function fillForm(page, fields, form) {
  const scope = form || page;

  for (const [key, value] of Object.entries(fields)) {
    const field = control(scope, key);
    const tag = await field.evaluate((el) => `${el.tagName.toLowerCase()}:${el.type || ''}`);

    if (tag.startsWith('select')) {
      await field.selectOption(value);
    } else if (/:(checkbox|radio)$/.test(tag)) {
      await field.setChecked(Boolean(value));
    } else {
      await field.fill(String(value));
    }
  }
}

/**
 * Click a submit button (default: the first one in the scope) and wait for
 * the resulting navigation or network to settle.
 *
 * @param {import('@playwright/test').Page} page Page.
 * @param {{form?: import('@playwright/test').Locator, button?: string}} [options] Scope and button name/label.
 * @returns {Promise<void>}
 */
async function submitForm(page, options = {}) {
  const scope = options.form || page;
  const button = options.button
    ? scope.getByRole('button', { name: options.button }).or(scope.locator(`input[type="submit"][value="${options.button}"]`)).first()
    : scope.locator('button[type="submit"], input[type="submit"]').first();

  await button.click();
  await page.waitForLoadState('networkidle').catch(() => {});
}

/**
 * Assert an admin notice is visible.
 *
 * @param {import('@playwright/test').Page} page Page.
 * @param {{type?: 'success'|'error'|'warning'|'info', text?: string|RegExp}} [options] Notice type and text.
 * @returns {Promise<void>}
 */
async function expectNotice(page, options = {}) {
  const type = options.type || 'success';
  const selector = type === 'success' ? '.notice-success, div.updated' : `.notice-${type}`;
  let notice = page.locator(selector).filter({ visible: true });

  if (options.text) {
    notice = notice.filter({ hasText: options.text });
  }

  await expect(notice.first(), `${type} notice${options.text ? ` containing ${options.text}` : ''}`).toBeVisible();
}

/**
 * Assert the collector saw no console errors and no failed REST/ajax calls.
 *
 * @param {object} collector From the `collector` fixture.
 * @param {{console?: string[], requests?: string[]}} [allow] Allow-list regex sources.
 * @returns {void}
 */
function expectNoBrowserErrors(collector, allow) {
  const problems = evaluatePage({ status: 200, html: '', console: collector.console, pageErrors: collector.pageErrors, requests: collector.requests }, allow);
  expect(problems, 'browser errors').toEqual([]);
}

module.exports = { openAdminPage, fillForm, submitForm, expectNotice, expectNoBrowserErrors };
