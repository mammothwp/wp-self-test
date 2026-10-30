# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## What this is

`mammothwp/wp-self-test` is a Composer **library** (not a plugin) that other WordPress plugins bundle in their vendor directory. A consumer plugin registers a `manifest.json` of case classes; this package runs those cases on a real site and exposes the same runner on five surfaces: wp-admin dashboard, REST (`mmwp/v1`), WP-CLI (`wp mmwp self-test`), the Abilities API (`mmwp/self-test-*`), and a pre-push hook (`bin/pre-push`, `bin/run.php`). The README is the consumer-facing contract (manifest schema, `Case_Base` helpers, hook table) and must stay in sync with code changes.

## Commands

`composer install` pulls the dev tools. Checks (mirrors `.gitlab-ci.yml`, the only CI; includes `composer validate --strict`):

```bash
git ls-files -z -- "*.php" | xargs -0 -n1 php -l >/dev/null
vendor/bin/phpunit      # tests/ uses an in-memory WordPress stub (tests/wp-stubs.php)
vendor/bin/phpcs        # WPCS, PrefixAllGlobals = mmwp / MammothWP
vendor/bin/phpstan analyse --memory-limit=512M
npm test                # Node side of the browser layer (e2e/); needs @playwright/test for the integration tests
```

Cases are exercised from a consumer plugin, e.g. `php bin/run.php --bootstrap=tests/bootstrap.php --autoload=tests/vendor/autoload.php [--plugin=<slug>] [case,ids]` run from the consumer's root, or `wp mmwp self-test run` on a site.

`assets/self-test.css` is compiled from `assets/sass/self-test.scss`; no build tooling is committed, so keep both in sync by hand.

## Architecture

**Multi-copy loading.** Several active plugins may each ship a copy. Composer's `autoload.files` includes `bootstrap.php` from every copy → each calls `Loader::register($version, $dir)` → on `plugins_loaded` (-100) `Loader::boot()` picks the highest `version.php` and installs its own PSR-4 autoloader for `MammothWP\SelfTest\` from that copy's `src/`. Consequences:
- `src/Loader.php` is declared by whichever plugin loads first, possibly an **older** copy. It must stay backwards compatible, and `bootstrap.php` guards with `method_exists`. Any new `Loader` method that `boot()` calls must actually ship in the release — earlier 0.x releases shipped broken because of this.
- `Loader::register()` can run before `wp-includes/plugin.php` (Composer `autoload.files` from `wp-config.php`: Bedrock root installs, every WP-CLI command). Then it pre-seeds `$wp_filter['plugins_loaded'][-100]`; it must never boot there, because `boot()` needs the Plugin API (0.6.3 fixed a fatal on every WP-CLI command).
- Composer's `autoload-dev` PSR-4 is for development only; at runtime `Loader::autoload()` does the class loading.
- Every PHP file bails on `!defined('ABSPATH')`; `version.php` returns `'0.0.0'` outside WordPress.

**Gates (two layers).** `Loader::enabled()` (`mmwp_self_test_enabled`, default false on `production` since 0.7.0) decides whether anything boots; `Self_Test::boot()` re-applies it through `Config::enabled()` when an older Loader without `GATES_PRODUCTION` is the declared one. `Config::ui_enabled()` (`mmwp_self_test_ui_enabled`, false when `Loader::environment()` is `production`; environment = `WP_ENV` if defined else `wp_get_environment_type()`) gates only the dashboard, its REST routes and the WP_CLI shim. Outside production, CLI commands and abilities always register. Capability: `Config::capability()`.

**Boot flow.** `Self_Test::boot()` loads `Wp_Cli_Shim.php` when needed, fires `mmwp_self_test_register` at `init` 0 (consumers call `Registry::register()`), attaches `Surfaces\Cli` and `Surfaces\Abilities` always, and `Surfaces\Rest` + `Admin` (renders `templates/dashboard.php` with `assets/`) only when the UI is enabled.

**Execution.** `Registry` holds `Manifest`s (each validates entries; case ids are unique across plugins) and instantiates case classes. `Runner` drives the four `Case_Base` phases — `preview()`, `run()` → JSON-serialisable context, `validate($context)` → `check()`/`skip()` rows, `cleanup($context)` — and persists the last report plus contexts awaiting cleanup in the `mmwp_self_test_state` option so cleanup can happen in a later request. All surfaces are thin wrappers over `Runner`.

**Browser layer (2.1.0).** A case may declare `browser` (crawl/specs/allow/timeout; validated and normalised in `Manifest`, which also adds the `browser` layer). `Runner::run()` appends the checks from `Browser\Browser_Runner`, which shells out to the Node package `@mammothwp/wp-self-test-e2e` (repo-root `package.json`, code in `e2e/`, all `export-ignore`d) when Node and the package exist, else reports a skip. Login is `Browser\Login_Token` (hashed, single use, revoked after the run, never on `production`) redeemed at `POST mmwp/v1/self-test/browser-login`; the crawler's page list comes from `GET .../admin-menu` (`Browser\Menu_Inventory`). Node tests: `npm i --no-save @playwright/test && npm test` (integration tests skip without Chromium).

**WP_CLI shim.** Cases call CLI handlers in-process. Outside a real `wp` process (dashboard, PHPUnit, `bin/run.php`) `src/Wp_Cli_Shim.php` provides a minimal `WP_CLI` that records output and throws instead of exiting; consumers' `cli_loaders` re-declare command classes that returned early when `WP_CLI` was missing.

## Conventions

- PHP ≥ 7.4, namespace `MammothWP\SelfTest`, class files named `Snake_Case.php`, WordPress-style docblocks with `@since` on every method and above every `do_action`/`apply_filters`.
- Public names are stable API: hooks `mmwp_self_test_*`, command `wp mmwp self-test`, routes `mmwp/v1/self-test/*`, abilities `mmwp/self-test-*`, option `mmwp_self_test_state`. New hooks go in `HOOKS.md` (full reference) and the README "Filters and actions" table.
- Versioning: bump `version.php` together with a CHANGELOG.md entry (Keep a Changelog, SemVer). The newest-copy-wins loader depends on `version.php` being correct. Releases are tagged `vX.Y.Z` by the maintainer after merge; do not create tags yourself.
- The package intentionally ships no CI template for consumers (removed in 0.5.0); the consumer's pre-push hook is the gate.
- Hosting: published on Packagist as `mammothwp/wp-self-test`; releases are tagged `vX.Y.Z`.
- Branches: `develop` is the working branch (default); `main` is release-only. CI runs for merge requests and on `develop` and `main`, never on tags.
- `.gitattributes` keeps dev-only files (`CLAUDE.md`, `.gitlab-ci.yml`, `assets/sass/`) out of Composer dist archives; add new dev-only paths there.
