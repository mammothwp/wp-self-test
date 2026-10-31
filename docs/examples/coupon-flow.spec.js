'use strict';

/**
 * Example plugin flow test for the browser layer: create, update, list and
 * delete a coupon through a plugin's admin form, asserting notices and
 * that the page raised no console errors or failed REST/ajax calls.
 *
 * Copy it into the plugin (e.g. self-test/e2e/coupon-flow.spec.js), adapt the
 * page slug and field names, and declare it in manifest.json:
 *
 *     "browser": { "specs": ["self-test/e2e/coupon-flow.spec.js"] }
 *
 * The browser is already logged in (storage state from the login token) and
 * `collector` records console errors and wp-json / admin-ajax responses.
 */

const { test, expect, openAdminPage, fillForm, submitForm, expectNotice, expectNoBrowserErrors } = require('@mammothwp/wp-self-test-e2e');

const CODE = `MMWP-E2E-${Date.now()}`;

test.describe.configure({ mode: 'serial' });

test('coupon: create, update, list and delete round trip', async ({ page, collector }) => {
  // Create.
  await openAdminPage(page, 'admin.php?page=ewp-coupons&action=new');
  await fillForm(page, { code: CODE, amount: '10', 'Discount type': 'percent' });
  await submitForm(page, { button: 'Save' });
  await expectNotice(page, { type: 'success', text: /saved|created/i });

  // Update.
  await fillForm(page, { amount: '15' });
  await submitForm(page, { button: 'Save' });
  await expectNotice(page, { type: 'success' });

  // List shows it.
  await openAdminPage(page, 'admin.php?page=ewp-coupons');
  await expect(page.getByText(CODE)).toBeVisible();

  // Delete, so the site is left as found.
  const row = page.locator('tr', { hasText: CODE });
  await row.hover();
  await row.getByRole('link', { name: /delete|trash/i }).click();
  await page.getByRole('button', { name: /confirm|yes|delete/i }).click();
  await expectNotice(page, { type: 'success', text: /deleted|trashed/i });
  await expect(page.getByText(CODE)).toHaveCount(0);

  expectNoBrowserErrors(collector);
});
