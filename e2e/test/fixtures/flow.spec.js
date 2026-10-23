'use strict';

const { test, expect, openAdminPage, expectNoBrowserErrors } = require('../../index');

test('flow: opens a page through the helpers and sees no browser errors', async ({ page, collector }) => {
  await openAdminPage(page, 'clean.php');
  await expect(page.locator('h1')).toHaveText('Clean');
  expectNoBrowserErrors(collector);
});

test('flow: a page with PHP errors fails openAdminPage', async ({ page }) => {
  await openAdminPage(page, 'warn.php');
});
