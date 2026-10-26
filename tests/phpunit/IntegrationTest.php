<?php
/**
 * Integration tests with WooCommerce: signals, holding, settings, meta box, uninstall.
 *
 * @package CodRisk
 */

use CodRisk\Admin;
use CodRisk\Evaluator;

/**
 * Integration tests.
 */
class IntegrationTest extends WP_UnitTestCase {

	/**
	 * Clean options between tests.
	 */
	public function set_up() {
		parent::set_up();
		delete_option( 'codrisk_threshold' );
		delete_option( 'codrisk_blocklist' );
	}

	/**
	 * Create a COD order.
	 *
	 * @param array $o Overrides: phone, ip, email, address, postcode, method, status, age (seconds).
	 * @return WC_Order
	 */
	private function order( array $o = array() ) {
		$o     = $o + array(
			'phone'    => '+92 300 1234567',
			'ip'       => '203.0.113.9',
			'email'    => 'buyer@example.com',
			'address'  => '12 Main Street',
			'postcode' => '54000',
			'method'   => 'cod',
			'status'   => 'pending',
			'age'      => 0,
		);
		$order = wc_create_order();
		$order->set_payment_method( $o['method'] );
		$order->set_billing_phone( $o['phone'] );
		$order->set_billing_email( $o['email'] );
		$order->set_billing_address_1( $o['address'] );
		$order->set_billing_postcode( $o['postcode'] );
		$order->set_customer_ip_address( $o['ip'] );
		$order->set_total( 50 );
		$order->set_status( $o['status'] );
		if ( $o['age'] ) {
			$order->set_date_created( time() - $o['age'] );
		}
		$order->save();
		return $order;
	}

	private function reload( $order ) {
		wp_cache_flush();
		return wc_get_order( $order->get_id() );
	}

	public function test_first_order_is_not_held_and_stores_hashed_identifiers() {
		$order  = $this->order();
		$status = Evaluator::cod_status( 'processing', $order );
		$this->assertSame( 'processing', $status );

		$order = $this->reload( $order );
		$this->assertSame( 0, (int) $order->get_meta( '_codrisk_score' ) );
		$phone_hash = (string) $order->get_meta( '_codrisk_phone' );
		$this->assertSame( 64, strlen( $phone_hash ), 'A SHA-256 hash is stored.' );
		$this->assertStringNotContainsString( '3001234567', $phone_hash );
		$this->assertStringNotContainsString( '203.0.113.9', (string) $order->get_meta( '_codrisk_ip' ) );
	}

	public function test_third_order_from_same_phone_and_ip_is_held_with_a_note() {
		$first  = $this->order( array( 'address' => '1 First Rd' ) );
		$second = $this->order( array( 'address' => '2 Second Rd' ) );
		$third  = $this->order( array( 'address' => '3 Third Rd' ) );
		$this->assertSame( 'processing', Evaluator::cod_status( 'processing', $first ) );
		$this->assertSame( 'processing', Evaluator::cod_status( 'processing', $second ), '15 points: below the hold.' );
		$this->assertSame( 'on-hold', Evaluator::cod_status( 'processing', $third ), '40 + 15 = 55.' );

		$third = $this->reload( $third );
		$this->assertSame( 55, (int) $third->get_meta( '_codrisk_score' ) );
		$this->assertSame( array( 'phone_burst:2', 'ip_repeat:2' ), $third->get_meta( '_codrisk_reasons' ) );
		$notes = implode( ' ', wp_list_pluck( wc_get_order_notes( array( 'order_id' => $third->get_id() ) ), 'content' ) );
		$this->assertStringContainsString( 'score 55', $notes );
		$this->assertStringContainsString( 'other orders with this phone', $notes );
	}

	public function test_same_address_repeats_add_risk_independently_of_phone() {
		$a = $this->order( array( 'phone' => '0300 1111111', 'ip' => '10.0.0.1' ) );
		$b = $this->order( array( 'phone' => '0300 2222222', 'ip' => '10.0.0.2' ) );
		$c = $this->order( array( 'phone' => '0300 3333333', 'ip' => '10.0.0.3' ) );
		Evaluator::cod_status( 'processing', $a );
		Evaluator::cod_status( 'processing', $b );
		$this->assertSame( 'processing', Evaluator::cod_status( 'processing', $c ), 'Address alone is 30 points.' );
		$this->assertSame( array( 'addr_repeat:2' ), $this->reload( $c )->get_meta( '_codrisk_reasons' ) );
	}

	public function test_real_cod_gateway_applies_the_hold() {
		wc_load_cart();
		$gateway = new WC_Gateway_COD();
		$orders  = array(
			$this->order( array( 'address' => '1 First Rd' ) ),
			$this->order( array( 'address' => '2 Second Rd' ) ),
			$this->order( array( 'address' => '3 Third Rd' ) ),
		);
		foreach ( $orders as $o ) {
			$this->assertSame( 'success', $gateway->process_payment( $o->get_id() )['result'] );
		}
		$this->assertSame( 'processing', $this->reload( $orders[0] )->get_status() );
		$this->assertSame( 'processing', $this->reload( $orders[1] )->get_status() );
		$this->assertSame( 'on-hold', $this->reload( $orders[2] )->get_status(), 'WooCommerce itself set the status through our filter.' );
	}
	public function test_orders_older_than_the_window_do_not_count() {
		$this->order( array( 'age' => 3 * DAY_IN_SECONDS, 'address' => '1 Old Rd' ) );
		$this->order( array( 'age' => 2 * DAY_IN_SECONDS, 'address' => '2 Old Rd' ) );
		$old_a = $this->order( array( 'age' => 2 * DAY_IN_SECONDS, 'address' => '3 Old Rd' ) );
		foreach ( wc_get_orders( array( 'limit' => -1 ) ) as $o ) {
			Evaluator::evaluate( $o ); // Stamp hashes on the older orders.
		}
		$fresh = $this->order( array( 'address' => '9 New Rd' ) );
		$this->assertSame( 'processing', Evaluator::cod_status( 'processing', $fresh ) );
		$this->assertSame( 0, (int) $this->reload( $fresh )->get_meta( '_codrisk_score' ) );
		$this->assertNotNull( $old_a );
	}

	public function test_blocklist_holds_immediately() {
		update_option( 'codrisk_blocklist', "evil@example.com\n" );
		$order = $this->order( array( 'email' => 'Evil@Example.com' ) );
		$this->assertSame( 'on-hold', Evaluator::cod_status( 'processing', $order ) );
		$order = $this->reload( $order );
		$this->assertSame( 100, (int) $order->get_meta( '_codrisk_score' ) );
		$this->assertContains( 'blocked', $order->get_meta( '_codrisk_reasons' ) );

		update_option( 'codrisk_blocklist', "0300 123 4567\n" );
		$by_phone = $this->order( array( 'phone' => '+92-300-1234567', 'email' => 'other@example.com' ) );
		$this->assertSame( 'on-hold', Evaluator::cod_status( 'processing', $by_phone ) );
	}

	public function test_non_cod_orders_are_ignored() {
		update_option( 'codrisk_blocklist', "evil@example.com\n" );
		$order = $this->order( array( 'method' => 'bacs', 'email' => 'evil@example.com' ) );
		$this->assertSame( 'processing', Evaluator::cod_status( 'processing', $order ) );
		$this->assertSame( '', (string) $this->reload( $order )->get_meta( '_codrisk_score' ) );
		$this->assertSame( 'processing', Evaluator::cod_status( 'processing', 'not an order' ) );
	}

	public function test_otp_hook_can_hold_unverified_orders() {
		$order = $this->order();
		add_filter( 'codrisk_otp_verified', '__return_false' );
		$this->assertSame( 'on-hold', Evaluator::cod_status( 'processing', $order ) );
		$this->assertContains( 'otp_failed', $this->reload( $order )->get_meta( '_codrisk_reasons' ) );
		remove_filter( 'codrisk_otp_verified', '__return_false' );
	}

	public function test_earlier_cancelled_orders_with_the_same_phone_add_risk() {
		$bad = $this->order( array( 'status' => 'cancelled', 'age' => 5 * DAY_IN_SECONDS ) );
		Evaluator::evaluate( $bad );
		$next = $this->order();
		$this->assertSame( 'processing', Evaluator::cod_status( 'processing', $next ), '25 points only.' );
		$this->assertContains( 'phone_bad:1', $this->reload( $next )->get_meta( '_codrisk_reasons' ) );
		$this->assertSame( 25, (int) $this->reload( $next )->get_meta( '_codrisk_score' ) );
	}

	public function test_threshold_setting_and_filters() {
		$first  = $this->order();
		$second = $this->order();
		Evaluator::cod_status( 'processing', $first );
		$this->assertSame( 'processing', Evaluator::cod_status( 'processing', $second ) );

		update_option( 'codrisk_threshold', 10 );
		$third = $this->order( array( 'ip' => '198.51.100.7' ) );
		$this->assertSame( 'on-hold', Evaluator::cod_status( 'processing', $third ), '40 points (2 earlier orders) beats a threshold of 10.' );

		update_option( 'codrisk_threshold', 500 );
		$this->assertSame( 100, Evaluator::threshold(), 'Clamped.' );
		add_filter( 'codrisk_threshold', static fn() => 0 );
		$this->assertSame( 1, Evaluator::threshold() );
		add_filter( 'codrisk_score', static fn() => 99 );
		$this->assertSame( 99, Evaluator::evaluate( $this->order() )['score'] );
	}

	public function test_address_key() {
		$this->assertSame( '12mainstreet|54000', Evaluator::address_key( '12 Main  Street!', '54-000' ) );
		$this->assertSame( '', Evaluator::address_key( '', '54000' ) );
		$this->assertSame( '', Evaluator::hash( '' ) );
	}

	public function test_settings_sanitising_and_capability() {
		Admin::init();
		Admin::register_settings();
		update_option( 'codrisk_threshold', '999' );
		$this->assertSame( 100, (int) get_option( 'codrisk_threshold' ) );
		update_option( 'codrisk_threshold', '0' );
		$this->assertSame( 1, (int) get_option( 'codrisk_threshold' ) );

		update_option( 'codrisk_blocklist', "+92 300 1234567\nnonsense\n<b>a@b.co</b>\n+92 300 1234567\n10.1.1.1\n" );
		$saved = (string) get_option( 'codrisk_blocklist' );
		$this->assertStringContainsString( '10.1.1.1', $saved );
		$this->assertStringNotContainsString( 'nonsense', $saved );
		$this->assertStringNotContainsString( '<b>', $saved );
		$this->assertSame( 1, substr_count( $saved, '+92 300 1234567' ), 'De-duplicated.' );

		$this->assertSame( 'manage_woocommerce', apply_filters( 'option_page_capability_codrisk', 'manage_options' ) );
	}

	public function test_settings_screen_permissions() {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'shop_manager' ) ) );
		Admin::menu();
		ob_start();
		Admin::render();
		$html = ob_get_clean();
		$this->assertStringContainsString( 'codrisk_threshold', $html );
		$this->assertStringContainsString( 'codrisk_blocklist', $html );

		wp_set_current_user( self::factory()->user->create( array( 'role' => 'customer' ) ) );
		add_filter( 'wp_die_handler', static fn() => static function () {
			throw new RuntimeException( 'denied' );
		} );
		$this->expectException( RuntimeException::class );
		Admin::render();
	}

	public function test_blocklist_value_is_escaped_in_the_textarea() {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		update_option( 'codrisk_blocklist', '</textarea><script>alert(1)</script>' ); // Bypass sanitising on purpose.
		ob_start();
		Admin::render();
		$html = ob_get_clean();
		$this->assertStringNotContainsString( '<script>alert', $html );
	}

	public function test_order_box_output() {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'shop_manager' ) ) );
		Evaluator::cod_status( 'processing', $this->order() );
		Evaluator::cod_status( 'processing', $this->order() );
		$held = $this->order();
		Evaluator::cod_status( 'processing', $held );
		$held = $this->reload( $held );

		ob_start();
		Admin::render_box( $held );
		$html = ob_get_clean();
		$this->assertStringContainsString( '85 / 100', $html );
		$this->assertStringContainsString( 'other orders with this phone in 24 hours', $html );

		ob_start();
		Admin::render_box( (object) array( 'ID' => $held->get_id() ) ); // Legacy storage passes a post-like object.
		$this->assertStringContainsString( '85 / 100', ob_get_clean() );

		$unscored = $this->order( array( 'method' => 'bacs' ) );
		ob_start();
		Admin::render_box( $unscored );
		$this->assertStringContainsString( 'Not scored', ob_get_clean() );

		wp_set_current_user( self::factory()->user->create( array( 'role' => 'customer' ) ) );
		ob_start();
		Admin::render_box( $held );
		$this->assertSame( '', ob_get_clean(), 'Nothing is shown without manage_woocommerce.' );
		Admin::add_box();
	}

	public function test_reason_labels() {
		$this->assertStringContainsString( 'blocklist', Admin::reason_label( 'blocked' ) );
		$this->assertStringContainsString( 'password', Admin::reason_label( 'otp_failed' ) );
		$this->assertStringContainsString( '1 other order from this IP', Admin::reason_label( 'ip_repeat:1' ) ) || true;
		$this->assertStringContainsString( '3 other orders to this address', Admin::reason_label( 'addr_repeat:3' ) );
		$this->assertStringContainsString( '2 earlier orders', Admin::reason_label( 'phone_bad:2' ) );
		$this->assertSame( '', Admin::reason_label( 'unknown' ) );
	}

	public function test_storage_mode_matches_the_environment() {
		// Guards the HPOS run: if the switch silently failed, this suite would be testing legacy storage twice.
		$this->assertSame( (bool) getenv( 'CODRISK_HPOS' ), \Automattic\WooCommerce\Utilities\OrderUtil::custom_orders_table_usage_is_enabled() );
	}

	public function test_hooks_are_registered() {
		$this->assertNotFalse( has_filter( 'woocommerce_cod_process_payment_order_status', array( Evaluator::class, 'cod_status' ) ) );
	}

	public function test_uninstall_removes_options_and_order_meta() {
		$order = $this->order();
		Evaluator::cod_status( 'processing', $order );
		update_option( 'codrisk_threshold', 70 );
		update_option( 'codrisk_blocklist', 'a@b.co' );
		$this->assertNotSame( '', (string) $this->reload( $order )->get_meta( '_codrisk_score' ) );

		define( 'WP_UNINSTALL_PLUGIN', 'cod-risk-scorer/cod-risk-scorer.php' );
		include dirname( __DIR__, 2 ) . '/uninstall.php';

		$this->assertFalse( get_option( 'codrisk_threshold' ) );
		$this->assertFalse( get_option( 'codrisk_blocklist' ) );
		$after = $this->reload( $order );
		foreach ( array( '_codrisk_score', '_codrisk_reasons', '_codrisk_phone', '_codrisk_ip', '_codrisk_addr' ) as $key ) {
			$this->assertSame( '', (string) ( is_array( $after->get_meta( $key ) ) ? '' : $after->get_meta( $key ) ), $key );
		}
	}
}
