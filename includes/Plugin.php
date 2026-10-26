<?php
/**
 * Hook wiring.
 *
 * @package CodRisk
 */

namespace CodRisk;

defined( 'ABSPATH' ) || exit;

/**
 * Plugin bootstrap. The only front-end hook is the COD gateway's status filter, which runs at checkout.
 */
class Plugin {

	/**
	 * Register hooks.
	 */
	public static function init(): void {
		add_filter( 'woocommerce_cod_process_payment_order_status', array( Evaluator::class, 'cod_status' ), 10, 2 );
		if ( is_admin() ) {
			Admin::init();
		}
	}
}
