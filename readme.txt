=== COD Risk Scorer ===
Contributors: shahmir-dev7
Tags: woocommerce, cash on delivery, fraud, fake orders, cod
Requires at least: 6.5
Tested up to: 7.1
Requires PHP: 7.4
Requires Plugins: woocommerce
WC requires at least: 8.0
Stable tag: 1.0.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Scores cash-on-delivery orders for fraud risk and holds suspicious ones. Free, local, no SMS provider.

== Description ==
Fake cash-on-delivery orders cost shipping and returns. COD Risk Scorer gives every COD order a 0-100 risk score from signals you can see and explain, and sets orders over your threshold to On hold.

* Phone, IP and address velocity (repeat orders within 24 hours / 7 days)
* Earlier cancelled, failed or refunded orders with the same phone
* Blocklist of phone numbers, emails and IP addresses
* Order screen box listing the score and the reasons
* A filter to plug in your own one-time-password check (no SMS provider is bundled)
* Works with the checkout block and the classic checkout; compatible with High-Performance Order Storage
* No external services; identifiers are stored only as salted hashes in order meta

== Installation ==
Install WooCommerce, then upload and activate this plugin. Settings: WooCommerce > COD Risk.

== Frequently Asked Questions ==
= Does it send SMS codes? =
No. Use the `codrisk_otp_verified` filter to connect your own provider.

= Why are many orders flagged for the same IP? =
Behind a proxy or CDN (for example Cloudflare) WooCommerce may record the proxy's address for every customer. Configure your host or CDN to pass the real visitor IP to PHP, or raise the threshold. The IP signal contributes at most 30 points.

= Which orders are scored? =
Orders paid with the built-in Cash on Delivery gateway only.

== Changelog ==
= 1.0.0 =
* Initial release.