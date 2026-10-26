# Security review: COD Risk Scorer 1.0.0

Manual review by the author; not independent.

## Superglobals
None are read by this plugin. Settings are saved by WordPress's own `options.php` (nonce and capability handled by core, see below). Customer-supplied data (phone, email, address, IP) arrives only through WooCommerce order getters.

## Customer input handling
| Data | Use | Handling |
|---|---|---|
| Billing phone | Matching | Digits only, last 10, then HMAC-SHA256 with `wp_salt( 'auth' )` before it is stored in order meta. |
| Customer IP | Matching | Hashed the same way. |
| Billing address and postcode | Matching | Lower-case alphanumerics only, then hashed. |
| Billing email | Blocklist match only | Lower-cased and compared; never stored by this plugin. |

Raw identifiers are never written to the plugin's meta or to order notes. Order notes contain the score and static reason text only.

## Endpoints and capabilities
No REST routes, no AJAX, no `admin_post_*`. The only front-end hook is the COD gateway's status filter, which runs inside WooCommerce's checkout. The settings screen requires `manage_woocommerce`; saving goes through `options.php`, whose required capability is raised from `manage_options` to `manage_woocommerce` by the `option_page_capability_codrisk` filter (tested) so shop managers can save. Both settings have sanitise callbacks (threshold clamped 1-100; blocklist keeps only valid phone/email/IP lines, de-duplicated, max 5000).

## Output
Order box: score cast to `int`, reasons are static translated strings passed through `esc_html`. Settings page: `esc_attr`, `esc_textarea`. A test stores a `</textarea><script>` value directly in the option and confirms it is not rendered raw.

## Queries
Order lookups use `wc_get_orders` (WooCommerce prepares them) with `meta_key`/`meta_value`, capped at 51 rows. Uninstall uses `$wpdb->prepare` for the table check and `$wpdb->delete` with formats.

## Files / network
None.

## Findings
1. **Fixed during build: blocklisted phone numbers never matched.** PHP turns numeric-looking array keys into integers, so strict comparison against the normalised string failed. A unit test caught it; the parser now casts keys back to strings.
2. **Fixed during build: signals were wrong on legacy order storage.** `meta_query` is not supported by the legacy order data store; WooCommerce raised a notice and the query returned unfiltered results. Switched to `meta_key`/`meta_value`, which both stores support. Suite passes on both.
3. **Limitation: proxy/CDN IPs.** If the site records the proxy's IP for every visitor, the IP signal (max 30 points) fires for everyone. Documented in the readme.
4. **Limitation: false positives.** Households sharing a phone or address can trip the velocity rules. Held orders are only set to On hold, never cancelled.
5. **Limitation: race.** Two simultaneous orders can both be scored before either stamps its meta, so neither sees the other. Low impact; not tested.
6. **Privacy note:** salted hashes of phone, IP and address are pseudonymous personal data. They are deleted with the order and on uninstall. Rotating the `AUTH_SALT` makes old hashes unmatchable (history is lost, nothing breaks).
7. **Not covered:** the score is not a fraud guarantee; no card-payment logic.