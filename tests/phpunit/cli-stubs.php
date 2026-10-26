<?php
/**
 * Minimal WP-CLI stand-ins so CodRisk\Cli can be tested without WP-CLI.
 *
 * @package CodRisk
 */

namespace WP_CLI\Utils {
	/**
	 * Capture what would be printed.
	 *
	 * @param string $format Format.
	 * @param array  $items  Items.
	 * @param array  $fields Fields.
	 */
	function format_items( $format, $items, $fields ) {
		$GLOBALS['codrisk_cli_items'] = $items;
	}
}

namespace {
	/**
	 * Stand-in.
	 */
	class WP_CLI {
		public static $log = array();

		public static function success( $message ) {
			self::$log[] = array( 'success', $message );
		}

		public static function error( $message ) {
			self::$log[] = array( 'error', $message );
			throw new RuntimeException( $message );
		}
	}
}
