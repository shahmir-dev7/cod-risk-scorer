<?php
/**
 * Settings screen and the order-screen risk box.
 *
 * @package CodRisk
 */

namespace CodRisk;

defined( 'ABSPATH' ) || exit;

/**
 * Admin.
 */
class Admin {

	const CAP = 'manage_woocommerce';

	/**
	 * Register hooks.
	 */
	public static function init(): void {
		add_action( 'admin_menu', array( __CLASS__, 'menu' ) );
		add_action( 'admin_init', array( __CLASS__, 'register_settings' ) );
		add_action( 'add_meta_boxes', array( __CLASS__, 'add_box' ) );
		// Shop managers hold manage_woocommerce, not manage_options, which is what options.php requires by default.
		add_filter( 'option_page_capability_codrisk', array( __CLASS__, 'option_capability' ) );
	}

	/**
	 * Capability for saving this plugin's settings.
	 *
	 * @return string
	 */
	public static function option_capability() {
		return self::CAP;
	}

	/**
	 * WooCommerce > COD Risk.
	 */
	public static function menu(): void {
		add_submenu_page( 'woocommerce', __( 'COD Risk', 'cod-risk-scorer' ), __( 'COD Risk', 'cod-risk-scorer' ), self::CAP, 'codrisk', array( __CLASS__, 'render' ) );
	}

	/**
	 * Settings: hold threshold and blocklist.
	 */
	public static function register_settings(): void {
		register_setting(
			'codrisk',
			'codrisk_threshold',
			array(
				'type'              => 'integer',
				'default'           => 50,
				'sanitize_callback' => static function ( $value ) {
					return max( 1, min( 100, absint( $value ) ) );
				},
			)
		);
		register_setting(
			'codrisk',
			'codrisk_blocklist',
			array(
				'type'              => 'string',
				'default'           => '',
				'sanitize_callback' => array( __CLASS__, 'sanitize_blocklist' ),
			)
		);
	}

	/**
	 * Keep one entry per line, drop anything that is not a phone, email or IP.
	 *
	 * @param mixed $value Raw textarea.
	 * @return string
	 */
	public static function sanitize_blocklist( $value ) {
		$keep = array();
		foreach ( (array) preg_split( '/\R/', (string) $value ) as $line ) {
			$line = trim( sanitize_text_field( $line ) );
			if ( '' === $line ) {
				continue;
			}
			$parsed = Blocklist::parse( $line );
			if ( $parsed['phones'] || $parsed['emails'] || $parsed['ips'] ) {
				$keep[] = $line;
			}
		}
		return implode( "\n", array_slice( array_unique( $keep ), 0, 5000 ) );
	}

	/**
	 * Human label for a reason code.
	 *
	 * @param string $code Reason code from Scorer.
	 * @return string
	 */
	public static function reason_label( $code ) {
		$n    = (int) substr( (string) strstr( $code, ':' ), 1 );
		$base = false === strpos( $code, ':' ) ? $code : strstr( $code, ':', true );
		switch ( $base ) {
			case 'blocked':
				return __( 'Phone, email or IP is on the blocklist', 'cod-risk-scorer' );
			case 'otp_failed':
				return __( 'One-time password not verified', 'cod-risk-scorer' );
			case 'phone_burst':
			case 'phone_repeat':
				/* translators: %d: number of other orders. */
				return sprintf( _n( '%d other order with this phone in 24 hours', '%d other orders with this phone in 24 hours', $n, 'cod-risk-scorer' ), $n );
			case 'ip_burst':
			case 'ip_repeat':
				/* translators: %d: number of other orders. */
				return sprintf( _n( '%d other order from this IP in 24 hours', '%d other orders from this IP in 24 hours', $n, 'cod-risk-scorer' ), $n );
			case 'addr_repeat':
				/* translators: %d: number of other orders. */
				return sprintf( _n( '%d other order to this address in 7 days', '%d other orders to this address in 7 days', $n, 'cod-risk-scorer' ), $n );
			case 'phone_bad':
				/* translators: %d: number of earlier orders. */
				return sprintf( _n( '%d earlier order with this phone was cancelled, failed or refunded', '%d earlier orders with this phone were cancelled, failed or refunded', $n, 'cod-risk-scorer' ), $n );
		}
		return '';
	}

	/**
	 * Register the box on the order screen (works with HPOS and legacy storage).
	 */
	public static function add_box(): void {
		if ( ! function_exists( 'wc_get_page_screen_id' ) ) {
			return;
		}
		add_meta_box( 'codrisk', __( 'COD risk', 'cod-risk-scorer' ), array( __CLASS__, 'render_box' ), wc_get_page_screen_id( 'shop-order' ), 'side', 'default' );
	}

	/**
	 * Render the risk box.
	 *
	 * @param \WP_Post|\WC_Order $post_or_order Legacy storage passes a post, HPOS passes the order.
	 */
	public static function render_box( $post_or_order ): void {
		$order = $post_or_order instanceof \WC_Order ? $post_or_order : wc_get_order( $post_or_order->ID );
		if ( ! $order instanceof \WC_Order || ! current_user_can( self::CAP ) ) {
			return;
		}
		$score = $order->get_meta( Evaluator::META_SCORE );
		if ( '' === $score ) {
			echo '<p>' . esc_html__( 'Not scored. Only cash-on-delivery orders are scored at checkout.', 'cod-risk-scorer' ) . '</p>';
			return;
		}
		$reasons = array_filter( array_map( array( __CLASS__, 'reason_label' ), (array) $order->get_meta( Evaluator::META_REASONS ) ) );
		echo '<p><strong>' . esc_html__( 'Score:', 'cod-risk-scorer' ) . '</strong> ' . (int) $score . ' / 100 ';
		/* translators: %d: hold threshold. */
		echo '<span class="description">' . esc_html( sprintf( __( '(hold at %d)', 'cod-risk-scorer' ), Evaluator::threshold() ) ) . '</span></p>';
		if ( $reasons ) {
			echo '<ul style="list-style:disc;margin-left:1.5em">';
			foreach ( $reasons as $reason ) {
				echo '<li>' . esc_html( $reason ) . '</li>';
			}
			echo '</ul>';
		} else {
			echo '<p>' . esc_html__( 'No risk signals.', 'cod-risk-scorer' ) . '</p>';
		}
	}

	/**
	 * Render the settings screen.
	 */
	public static function render(): void {
		if ( ! current_user_can( self::CAP ) ) {
			wp_die( esc_html__( 'You do not have permission to do this.', 'cod-risk-scorer' ), '', array( 'response' => 403 ) );
		}
		echo '<div class="wrap"><h1>' . esc_html__( 'COD Risk Scorer', 'cod-risk-scorer' ) . '</h1>';
		echo '<form method="post" action="options.php">';
		settings_fields( 'codrisk' );
		echo '<table class="form-table" role="presentation"><tr><th scope="row"><label for="codrisk_threshold">' . esc_html__( 'Hold orders at score', 'cod-risk-scorer' ) . '</label></th><td>';
		echo '<input type="number" min="1" max="100" id="codrisk_threshold" name="codrisk_threshold" value="' . esc_attr( (string) Evaluator::threshold() ) . '">';
		echo '<p class="description">' . esc_html__( 'Cash-on-delivery orders scoring at or above this are set to On hold with an order note. 1-100.', 'cod-risk-scorer' ) . '</p></td></tr>';
		echo '<tr><th scope="row"><label for="codrisk_blocklist">' . esc_html__( 'Blocklist', 'cod-risk-scorer' ) . '</label></th><td>';
		echo '<textarea id="codrisk_blocklist" name="codrisk_blocklist" rows="8" cols="50" class="large-text code">' . esc_textarea( (string) get_option( 'codrisk_blocklist', '' ) ) . '</textarea>';
		echo '<p class="description">' . esc_html__( 'One phone number, email address or IP address per line. A match scores 100.', 'cod-risk-scorer' ) . '</p></td></tr></table>';
		submit_button();
		echo '</form></div>';
	}
}
