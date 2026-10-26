<?php
/**
 * Collects signals for an order, scores it and decides whether to hold it.
 *
 * @package CodRisk
 */

namespace CodRisk;

defined( 'ABSPATH' ) || exit;

/**
 * Evaluator.
 */
class Evaluator {

	const META_SCORE   = '_codrisk_score';
	const META_REASONS = '_codrisk_reasons';
	const META_PHONE   = '_codrisk_phone';
	const META_IP      = '_codrisk_ip';
	const META_ADDR    = '_codrisk_addr';

	/**
	 * Hold threshold (1-100).
	 *
	 * @return int
	 */
	public static function threshold() {
		/**
		 * Filters the score at which a COD order is held.
		 *
		 * @param int $threshold Default 50, or the saved setting.
		 */
		return max( 1, min( 100, (int) apply_filters( 'codrisk_threshold', (int) get_option( 'codrisk_threshold', 50 ) ) ) );
	}

	/**
	 * One-way hash for matching without storing raw identifiers in order meta.
	 *
	 * @param string $value Normalised value.
	 * @return string Empty for empty input.
	 */
	public static function hash( $value ) {
		return '' === $value ? '' : hash_hmac( 'sha256', $value, wp_salt( 'auth' ) );
	}

	/**
	 * Address key: lowercase letters and digits of line 1 plus postcode.
	 *
	 * @param string $address_1 Address line 1.
	 * @param string $postcode  Postcode.
	 * @return string Empty when there is no address line.
	 */
	public static function address_key( $address_1, $postcode ) {
		$line = (string) preg_replace( '/[^a-z0-9]+/', '', strtolower( (string) $address_1 ) );
		if ( '' === $line ) {
			return '';
		}
		return $line . '|' . (string) preg_replace( '/[^a-z0-9]+/', '', strtolower( (string) $postcode ) );
	}

	/**
	 * Count other orders carrying the same hashed identifier.
	 *
	 * @param string   $meta_key Meta key.
	 * @param string   $hash     Hash to match.
	 * @param int      $since    Only orders created after this timestamp.
	 * @param int      $exclude  Order ID to exclude.
	 * @param string[] $statuses Order statuses (with wc- prefix); empty for all.
	 * @return int Capped at 50.
	 */
	public static function count_orders( $meta_key, $hash, $since, $exclude, array $statuses = array() ) {
		if ( '' === $hash ) {
			return 0;
		}
		$args = array(
			'limit'        => 51, // One more than the cap: the order being scored may be among the results.
			'return'       => 'ids',
			'date_created' => '>' . (int) $since,
			'status'       => $statuses ? $statuses : array_keys( wc_get_order_statuses() ),
			// meta_key/meta_value work on both legacy and HPOS order storage; meta_query is HPOS-only.
			'meta_key'     => $meta_key, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
			'meta_value'   => $hash, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value
		);
		$ids  = array_diff( array_map( 'intval', (array) wc_get_orders( $args ) ), array( (int) $exclude ) );
		return count( $ids );
	}

	/**
	 * Score an order, store the result on it and add an order note when held.
	 *
	 * @param \WC_Order $order Order.
	 * @return array{score:int,reasons:string[],held:bool}
	 */
	public static function evaluate( \WC_Order $order ) {
		$id    = $order->get_id();
		$phone = Blocklist::normalize_phone( $order->get_billing_phone() );
		$ip    = (string) $order->get_customer_ip_address();
		$addr  = self::address_key( $order->get_billing_address_1(), $order->get_billing_postcode() );

		$phone_h = self::hash( $phone );
		$ip_h    = self::hash( $ip );
		$addr_h  = self::hash( $addr );
		$order->update_meta_data( self::META_PHONE, $phone_h );
		$order->update_meta_data( self::META_IP, $ip_h );
		$order->update_meta_data( self::META_ADDR, $addr_h );

		$now = time();
		$bad = array( 'wc-cancelled', 'wc-failed', 'wc-refunded' );

		/**
		 * Filters whether the customer passed one-time-password verification.
		 * Return false to treat the order as unverified (adds 60 points).
		 *
		 * @param bool      $verified Default true: no OTP provider is bundled.
		 * @param \WC_Order $order    Order.
		 */
		$otp_ok = (bool) apply_filters( 'codrisk_otp_verified', true, $order );

		$result = Scorer::score(
			array(
				'blocked'    => Blocklist::matches( Blocklist::parse( (string) get_option( 'codrisk_blocklist', '' ) ), $order->get_billing_phone(), (string) $order->get_billing_email(), $ip ),
				'otp_failed' => ! $otp_ok,
				'phone_24h'  => self::count_orders( self::META_PHONE, $phone_h, $now - DAY_IN_SECONDS, $id ),
				'ip_24h'     => self::count_orders( self::META_IP, $ip_h, $now - DAY_IN_SECONDS, $id ),
				'addr_7d'    => self::count_orders( self::META_ADDR, $addr_h, $now - WEEK_IN_SECONDS, $id ),
				'phone_bad'  => self::count_orders( self::META_PHONE, $phone_h, $now - 90 * DAY_IN_SECONDS, $id, $bad ),
			)
		);

		/**
		 * Filters the final risk score of an order.
		 *
		 * @param int       $score Score 0-100.
		 * @param \WC_Order $order Order.
		 */
		$score = max( 0, min( 100, (int) apply_filters( 'codrisk_score', $result['score'], $order ) ) );
		$held  = $score >= self::threshold();

		$order->update_meta_data( self::META_SCORE, (string) $score );
		$order->update_meta_data( self::META_REASONS, $result['reasons'] );
		$order->save_meta_data();

		if ( $held ) {
			$labels = array_filter( array_map( array( Admin::class, 'reason_label' ), $result['reasons'] ) );
			$order->add_order_note(
				sprintf(
					/* translators: 1: risk score, 2: hold threshold, 3: list of reasons. */
					__( 'COD Risk Scorer: score %1$d (hold at %2$d), order held for review. Reasons: %3$s', 'cod-risk-scorer' ),
					$score,
					self::threshold(),
					$labels ? implode( '; ', $labels ) : __( 'none listed', 'cod-risk-scorer' )
				)
			);
		}

		return array(
			'score'   => $score,
			'reasons' => $result['reasons'],
			'held'    => $held,
		);
	}

	/**
	 * Filter for `woocommerce_cod_process_payment_order_status`. WooCommerce's COD gateway sets the final
	 * status after the order is created, so this is the one place a hold cannot be overridden.
	 *
	 * @param string $status Status the gateway is about to set.
	 * @param mixed  $order  Order.
	 * @return string
	 */
	public static function cod_status( $status, $order ) {
		if ( ! $order instanceof \WC_Order || 'cod' !== $order->get_payment_method() ) {
			return $status;
		}
		return self::evaluate( $order )['held'] ? 'on-hold' : $status;
	}
}
