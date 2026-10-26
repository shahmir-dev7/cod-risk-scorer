# Testing: COD Risk Scorer 1.0.0

Runs on 2026-10-26, Windows host, Docker Desktop, wp-env, WordPress 7.1.3, WooCommerce 11.2.1.

| Check | Result |
|---|---|
| `php -l` | clean |
| PHPCS (WordPress-Extra, Docs, PHPCompatibilityWP `7.4-`) | 0 errors, 0 warnings (`tests/` excluded) |
| PHPStan level 6, with WooCommerce stubs | 0 errors (level 8 not attempted) |
| Unit, no WordPress (`phpunit.unit.xml`) | 6 tests, 27 assertions OK |
| WordPress + WooCommerce suite, PHP 7.4.33, legacy order storage | 25 tests, 102 assertions OK |
| Same suite, PHP 7.4.33, **HPOS** (`CODRISK_HPOS=1`) | 25 tests, 102 assertions OK |
| Same suite, PHP 8.3, legacy storage, with coverage | 25 tests, 102 assertions OK; **98.64% line coverage** on `includes/` (218/221) |
| Plugin Check on the release zip (PHP 8.3 site) | 0 errors, 0 warnings |
| Playwright, Chromium: orders placed through WooCommerce's real Store API checkout and COD gateway | 1 passed on legacy storage, 1 passed on HPOS |
| Queries per front-end request, plugin off vs on (5 samples each) | `/shop/` 56 vs 56 (+0); `/sample-page/` and `/missing-xyz/` were lower with the plugin on (warm-up noise from WooCommerce, not a saving). The plugin adds no front-end queries; it registers one filter. |

Release zip SHA-256: `76357700783D3AB0E1039E735C7CA9E50E7B95EADF2BDF2003DA359EF09F1798`.

## What the browser test covers
Three orders with the same phone and IP go through the real COD gateway: the first two are processing, the third is On hold at score 55. The order screen shows the score and reasons. Raising the threshold lets the next order through. A blocklisted phone is held at 100. The settings sanitiser drops a secret-looking key. The plugin is then deactivated and deleted through the Plugins screen, and the test confirms the plugin is gone, no options remain and no order meta remains in either storage. A positive control proves the clean-database query can see the data before uninstall.

## Problems found while testing (all fixed)
- Blocklisted phone numbers never matched (PHP turns numeric array keys into integers). Caught by a unit test.
- Order signals were wrong on legacy storage because `meta_query` is HPOS-only. Fixed with `meta_key`/`meta_value`.
- One test run failed once on a freshly installed WooCommerce (administrator without WooCommerce capabilities). Not reproduced in 5 later runs; cause not determined. The test bootstrap now creates the roles explicitly.
- My browser test's delete step had a race and a false-pass check. It was fixed and the test now verifies deletion from the plugin list.

## Not verified (honest limits)
- **Multisite:** not run.
- **PHP × WordPress matrix:** only PHP 7.4.33 and 8.3 on WordPress 7.1.3 and WooCommerce 11.2.1. WordPress latest-1, PHP 8.4+ and older WooCommerce versions are not tested. The GitHub Actions workflow has not been run yet.
- **PHPStan level 8** not attempted.
- **Classic (shortcode) checkout:** only the block checkout's Store API was exercised in the browser; the classic path goes through the same gateway filter but has no browser test.
- **Race:** two simultaneous orders can both be scored before either stamps its meta. Not tested.
- **Proxy/CDN IPs:** behind a proxy the IP signal may flag everyone (documented).
- **Coverage** was measured on PHP 8.3; pcov is not available on the PHP 7.4 container.
- Chromium only, one scenario per storage mode. Checkout costs per order (extra order queries and meta writes) were not measured.