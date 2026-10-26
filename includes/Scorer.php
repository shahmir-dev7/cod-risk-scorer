<?php
/**
 * Risk score (pure, no WordPress or WooCommerce calls).
 *
 * @package CodRisk
 */

namespace CodRisk;

defined( 'ABSPATH' ) || exit;

/**
 * Scorer.
 */
class Scorer {

	/**
	 * Score an order from signals. 0 is clean, 100 is certain.
	 *
	 * "Other orders" always excludes the order being scored.
	 *
	 * @param array<string,int|bool> $s Signals: blocked (bool), otp_failed (bool), phone_24h, ip_24h, addr_7d, phone_bad (ints).
	 * @return array{score:int,reasons:string[]} Reason codes: blocked, otp_failed, phone_burst:N, phone_repeat:N, ip_burst:N, ip_repeat:N, addr_repeat:N, phone_bad:N.
	 */
	public static function score( array $s ) {
		$score   = 0;
		$reasons = array();
		$get     = static function ( $key ) use ( $s ) {
			return isset( $s[ $key ] ) ? (int) $s[ $key ] : 0;
		};

		if ( ! empty( $s['blocked'] ) ) {
			$score    += 100;
			$reasons[] = 'blocked';
		}
		if ( ! empty( $s['otp_failed'] ) ) {
			$score    += 60;
			$reasons[] = 'otp_failed';
		}

		$phone = $get( 'phone_24h' );
		if ( $phone >= 2 ) {
			$score    += 40;
			$reasons[] = 'phone_burst:' . $phone;
		} elseif ( 1 === $phone ) {
			$score    += 15;
			$reasons[] = 'phone_repeat:1';
		}

		$ip = $get( 'ip_24h' );
		if ( $ip >= 4 ) {
			$score    += 30;
			$reasons[] = 'ip_burst:' . $ip;
		} elseif ( $ip >= 2 ) {
			$score    += 15;
			$reasons[] = 'ip_repeat:' . $ip;
		}

		$addr = $get( 'addr_7d' );
		if ( $addr >= 2 ) {
			$score    += 30;
			$reasons[] = 'addr_repeat:' . $addr;
		}

		$bad = $get( 'phone_bad' );
		if ( $bad >= 2 ) {
			$score    += 40;
			$reasons[] = 'phone_bad:' . $bad;
		} elseif ( 1 === $bad ) {
			$score    += 25;
			$reasons[] = 'phone_bad:1';
		}

		return array(
			'score'   => min( 100, $score ),
			'reasons' => $reasons,
		);
	}
}
