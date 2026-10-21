# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.0.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [2.1.0] - 2026-10-21

Originating request: add a `browser` layer so plugins (motivar/extend-wp, filox) get repeatable Playwright admin smoke tests through the existing manifest, runner and pre-push hook. wp-admin was never loaded in a browser by the rest/cli/ability/core layers, so JavaScript errors, screens that fail to render and broken form flows went unnoticed (found by hand on the filox DDEV site on 2026-10-21).

### Added
- `browser` layer. A manifest case may declare `"browser": {"crawl": true, "specs": [...], "allow": {"console": [], "requests": [], "pages": []}, "timeout": 300}`; the case then lists, counts and filters the `browser` layer like `rest`/`cli`/`ability`/`core` (dashboard, `wp mmwp self-test list|run|report`, abilities). Existing manifests stay valid. `MammothWP\SelfTest\Browser_Case` is a ready-made class for browser-only cases.
- `--layer=<layer>` on `wp mmwp self-test run`, `bin/run.php`, the `layer` argument of `POST mmwp/v1/self-test/run` and of the `mmwp/self-test-run` ability. `--layer=browser` runs only the Playwright checks; any other layer skips them.
- `Browser\Browser_Runner` shells out to the Node runner only when Node and the installed e2e package are found next to the manifest; otherwise (and on production) the browser checks are reported as skipped with the reason, never as failures. A crashed runner without results is a failure.
- `GET mmwp/v1/self-test/admin-menu`: read-only inventory of the current user's admin menu and submenu URLs (same permission and `mmwp_self_test_ui_enabled` gating as the dashboard routes). Excludes action URLs (`action=`, nonces, `customize.php`, update/install screens, `post-new.php`, logout); extend or trim the list with the `mmwp_self_test_admin_menu_exclusions` filter.
- Passwordless browser login: `Browser\Login_Token` issues a hashed, single-use token (120 s) that `POST mmwp/v1/self-test/browser-login` exchanges once for auth cookies; the runner revokes every session it created after the run. Never issued or redeemed when the environment is `production`, whatever the filters return. Security model documented in the README.
- `mmwp_self_test_browser_command` filter to change how the Node runner is started.
- Node package `@mammothwp/wp-self-test-e2e` at the repo root (`package.json` with a `files` whitelist, code in `e2e/`): Playwright config, the generic read-only admin crawler (HTTP 200, no PHP/WordPress error markup, no console errors, no failed wp-json/admin-ajax calls, per-plugin allow-lists), flow-test helpers (`openAdminPage`, `fillForm`, `submitForm`, `expectNotice`, `collector`, `expectNoBrowserErrors`) and JSON results folded into the normal report. Plugins install it from git; every Node file is `export-ignore`d so the Composer dist archive never contains it.
- `bin/pre-push` runs `wp mmwp self-test run --layer=browser` through DDEV (or `MMWP_SELF_TEST_BROWSER_CMD`) for plugins that depend on the e2e package; a stopped DDEV or missing Node warns and skips.
- GitLab `e2e` job: Node unit tests on saved HTML/console fixtures and the crawler against a fake site in Chromium.
- Example flow test in `docs/examples/coupon-flow.spec.js`.
- PHPUnit tests for browser manifest parsing, the menu inventory and the login token lifecycle.

## [2.0.0] - 2026-10-20

### Changed
- CI runs on GitLab only (`.gitlab-ci.yml`, one `check` job: `php -l`, `composer validate --strict`, PHPUnit, PHPCS, PHPStan) on the public `composer:2` image; pipelines run for merge requests and the `develop` and `main` branches, never tags.

### Removed
- Removed the 1.x compatibility layer for pre-release names.

## [1.0.0] - 2026-10-20

Initial Mammoth WP release. Source of truth is GitLab `mammothwp/wp-self-test`; `github.com/mammothwp/wp-self-test` is a push mirror.

### Added
- Manifest-driven self-test suite for WordPress plugins: `Registry`, `Runner` and `Case_Base` with `rest()`, `cli()`, `ability()` helpers and assertion helpers.
- Surfaces: Tools › Self-test dashboard, REST routes under `mmwp/v1/self-test/*`, `wp mmwp self-test` commands (`wp mmwp self-test <slug> --format=table` runs one plugin's cases), abilities `mmwp/self-test-*`.
- Production gate: nothing boots on `production` unless `mmwp_self_test_enabled` returns true.
- Version-gated `Loader` so several bundled copies coexist.
- `bin/run.php`, `bin/pre-push`, `bin/install-hooks`, `phpunit/bootstrap.php`.
- Dev tooling: PHPUnit suite with a WordPress stub bootstrap (`tests/`), PHPCS (WPCS, `PrefixAllGlobals` = `mmwp`/`MammothWP`) and PHPStan.
- README, `HOOKS.md` and brand guidelines in `docs/BRAND.md`.

## [0.x]

0.x — pre-release development.
