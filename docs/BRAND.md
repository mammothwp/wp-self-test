# Mammoth WP brand

Short version for people writing code or docs for a Mammoth WP product. The
brand repo is the source of truth for tokens, logos and plugin asset templates
(`gitlab.motivar.io/mammothwp/brand`, mirrored to `github.com/mammothwp/brand`).
Never hand-copy assets into product code.

## Names

- Brand: **Mammoth WP** (two words, capital M, capital WP). Never "Mammoth"
  alone as a product name. `MammothWP` appears only as the PHP namespace
  `MammothWP\`.
- Products: **Mammoth WP \<Function\>**, up to four words (Mammoth WP Share
  Checkout, Mammoth WP Acceptance Logs). The slug follows the name; the text
  domain equals the slug.
- "WP" is fine in names and slugs. "WordPress" never is.

## Handles and domains

| Thing | Value |
|---|---|
| GitHub org, Packagist vendor, wordpress.org user | `mammothwp` |
| Composer packages | `mammothwp/<slug>` |
| Primary domain | mammothwp.com (the only one) |
| mammoth-wp.com | 301 redirect only; never linked |
| Contact | info@mammothwp.com |

## Code identifiers

| Kind | Rule | Example |
|---|---|---|
| PHP namespace | `MammothWP\` | `MammothWP\SelfTest\Registry` |
| PHP prefix (functions, options, transients, cron, meta, hooks) | `mmwp_` | `mmwp_self_test_register` |
| CSS classes, JS/CSS handles, HTML ids, admin page slugs | `mmwp-` | `mmwp-self-test` |
| REST namespace | `mmwp/v1` | `mmwp/v1/self-test/run` |
| WP-CLI | `wp mmwp` | `wp mmwp self-test` |
| Abilities | `mmwp/*` | `mmwp/self-test-run` |

Never a three-letter prefix.

## Visual identity

- Warm ivory background; espresso/umber brown is the brand colour: umber
  `#4e2d16` on light, `#a87a52` on dark.
- Type: Commissioner for UI and headings, JetBrains Mono for code. Both
  self-hosted, never Google Fonts.
- Logo: a geometric block mammoth in profile plus the "Mammoth WP" wordmark.
- Plugin icons and banners use the "split panel" layout: brown square with the
  mark, name on ivory.

## wp-admin

Plugins use core WordPress admin UI only: no brand colours, fonts or styling in
wp-admin. The brand appears only in the shared "Mammoth WP → Support" screen
and the Mammoth WP menu icon. (This package's dashboard uses core admin markup; its
CSS only styles pass/fail states and carries no brand colours.)

## Tone

Plain, direct, technical; light dry humour is allowed. No hype, no
"AI-powered". Plugins for people who read the source. Heavy name, light
plugins.
