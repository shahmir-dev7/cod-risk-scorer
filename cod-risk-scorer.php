<?php
/**
 * Plugin Name:          COD Risk Scorer
 * Description:          Scores cash-on-delivery orders for fraud risk (phone, IP and address velocity, blocklist) and holds suspicious ones. Free, local, no SMS provider.
 * Version:              1.0.0
 * Requires at least:    6.5
 * Requires PHP:         7.4
 * Requires Plugins:     woocommerce
 * Author:               shahmir-dev7
 * License:              GPL-2.0-or-later
 * License URI:          https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:          cod-risk-scorer
 * Domain Path:          /languages
 * WC requires at least: 8.0
 *
 * @package CodRisk
 */

defined( 'ABSPATH' ) || exit;

define( 'CODRISK_VERSION', '1.0.0' );
define( 'CODRISK_FILE', __FILE__ );

spl_autoload_register(
	static function ( $class_name ) {
		$prefix = 'CodRisk\\';
		if ( 0 !== strpos( $class_name, $prefix ) ) {
			return;
		}
		$file = __DIR__ . '/includes/' . str_replace( '\\', '/', substr( $class_name, strlen( $prefix ) ) ) . '.php';
		if ( is_readable( $file ) ) {
			require_once $file;
		}
	}
);

// Declare compatibility with High-Performance Order Storage and the checkout block.
add_action(
	'before_woocommerce_init',
	static function () {
		if ( class_exists( '\Automattic\WooCommerce\Utilities\FeaturesUtil' ) ) {
			\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'custom_order_tables', CODRISK_FILE, true );
			\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'cart_checkout_blocks', CODRISK_FILE, true );
		}
	}
);

CodRisk\Plugin::init();
