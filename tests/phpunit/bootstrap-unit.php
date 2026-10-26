<?php
/**
 * Bootstrap for pure-logic unit tests (no WordPress needed).
 *
 * @package CodRisk
 */

define( 'ABSPATH', __DIR__ . '/' );

require_once dirname( __DIR__, 2 ) . '/includes/Scorer.php';
require_once dirname( __DIR__, 2 ) . '/includes/Blocklist.php';