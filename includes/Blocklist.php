<?php
/**
 * Blocklist parsing and matching (pure).
 *
 * @package CodRisk
 */

namespace CodRisk;

defined( 'ABSPATH' ) || exit;

/**
 * Blocklist. One entry per line: a phone number, an email address or an IP address.
 */
class Blocklist {

	/**
	 * Last 10 digits of a phone number, so +92 300 1234567 and 0300-1234567 compare equal.
	 *
	 * @param string $phone Raw phone.
	 * @return string Empty when fewer than 7 digits.
	 */
	public static function normalize_phone( $phone ) {
		$digits = (string) preg_replace( '/\D+/', '', (string) $phone );
		if ( strlen( $digits ) < 7 ) {
			return '';
		}
		return strlen( $digits ) > 10 ? substr( $digits, -10 ) : $digits;
	}

	/**
	 * Parse the textarea into typed lookup sets.
	 *
	 * @param string $text Raw blocklist.
	 * @return array{phones:string[],emails:string[],ips:string[]}
	 */
	public static function parse( $text ) {
		$out = array(
			'phones' => array(),
			'emails' => array(),
			'ips'    => array(),
		);
		foreach ( (array) preg_split( '/\R/', (string) $text ) as $line ) {
			$line = trim( $line );
			if ( '' === $line || '#' === $line[0] ) {
				continue;
			}
			if ( false !== strpos( $line, '@' ) ) {
				$out['emails'][ strtolower( $line ) ] = true;
			} elseif ( false !== filter_var( $line, FILTER_VALIDATE_IP ) ) {
				$out['ips'][ $line ] = true;
			} else {
				$phone = self::normalize_phone( $line );
				if ( '' !== $phone ) {
					$out['phones'][ $phone ] = true;
				}
			}
		}
		// Numeric-looking keys (phone numbers) become integers in PHP, so cast back before strict comparisons.
		return array(
			'phones' => array_map( 'strval', array_keys( $out['phones'] ) ),
			'emails' => array_map( 'strval', array_keys( $out['emails'] ) ),
			'ips'    => array_map( 'strval', array_keys( $out['ips'] ) ),
		);
	}

	/**
	 * Is any of the order's identifiers on the list?
	 *
	 * @param array{phones:string[],emails:string[],ips:string[]} $entries Parsed list.
	 * @param string                                              $phone Raw billing phone.
	 * @param string                                              $email Billing email.
	 * @param string                                              $ip    Customer IP.
	 * @return bool
	 */
	public static function matches( array $entries, $phone, $email, $ip ) {
		$phone = self::normalize_phone( $phone );
		return ( '' !== $phone && in_array( $phone, $entries['phones'], true ) )
			|| ( '' !== $email && in_array( strtolower( trim( $email ) ), $entries['emails'], true ) )
			|| ( '' !== $ip && in_array( trim( $ip ), $entries['ips'], true ) );
	}
}
