<?php
/**
 * Pure-logic tests: scoring rules and blocklist.
 *
 * @package CodRisk
 */

use CodRisk\Blocklist;
use CodRisk\Scorer;
use PHPUnit\Framework\TestCase;

/**
 * @covers \CodRisk\Scorer
 * @covers \CodRisk\Blocklist
 */
class PureLogicTest extends TestCase {

	public function test_clean_order_scores_zero() {
		$this->assertSame( array( 'score' => 0, 'reasons' => array() ), Scorer::score( array() ) );
	}

	public function test_each_rule_and_its_boundaries() {
		$this->assertSame( 15, Scorer::score( array( 'phone_24h' => 1 ) )['score'] );
		$this->assertSame( 40, Scorer::score( array( 'phone_24h' => 2 ) )['score'] );
		$this->assertSame( 0, Scorer::score( array( 'ip_24h' => 1 ) )['score'] );
		$this->assertSame( 15, Scorer::score( array( 'ip_24h' => 2 ) )['score'] );
		$this->assertSame( 30, Scorer::score( array( 'ip_24h' => 4 ) )['score'] );
		$this->assertSame( 0, Scorer::score( array( 'addr_7d' => 1 ) )['score'] );
		$this->assertSame( 30, Scorer::score( array( 'addr_7d' => 2 ) )['score'] );
		$this->assertSame( 25, Scorer::score( array( 'phone_bad' => 1 ) )['score'] );
		$this->assertSame( 40, Scorer::score( array( 'phone_bad' => 2 ) )['score'] );
		$this->assertSame( 60, Scorer::score( array( 'otp_failed' => true ) )['score'] );
		$this->assertSame( 100, Scorer::score( array( 'blocked' => true ) )['score'] );
	}

	public function test_reasons_are_coded_and_score_is_capped() {
		$r = Scorer::score( array( 'blocked' => true, 'phone_24h' => 3, 'ip_24h' => 5, 'addr_7d' => 2, 'phone_bad' => 2 ) );
		$this->assertSame( 100, $r['score'] );
		$this->assertSame( array( 'blocked', 'phone_burst:3', 'ip_burst:5', 'addr_repeat:2', 'phone_bad:2' ), $r['reasons'] );
	}

	public function test_two_signals_that_individually_pass_can_hold_together() {
		// A third order from the same phone and IP: 40 + 15 = 55, over the default hold of 50.
		$this->assertSame( 55, Scorer::score( array( 'phone_24h' => 2, 'ip_24h' => 2 ) )['score'] );
	}

	public function test_phone_normalisation_matches_formats() {
		$this->assertSame( '3001234567', Blocklist::normalize_phone( '+92 300 1234567' ) );
		$this->assertSame( '3001234567', Blocklist::normalize_phone( '0300-123-4567' ) );
		$this->assertSame( '', Blocklist::normalize_phone( '12345' ) );
		$this->assertSame( '', Blocklist::normalize_phone( 'call me' ) );
	}

	public function test_blocklist_parse_and_match() {
		$list = Blocklist::parse( "# comment\n+92 300 1234567\nBad@Example.com\n10.0.0.5\n\nnot-a-thing\n" );
		$this->assertSame( array( '3001234567' ), $list['phones'] );
		$this->assertSame( array( 'bad@example.com' ), $list['emails'] );
		$this->assertSame( array( '10.0.0.5' ), $list['ips'] );

		$this->assertTrue( Blocklist::matches( $list, '0300 1234567', '', '' ) );
		$this->assertTrue( Blocklist::matches( $list, '', ' BAD@example.com ', '' ) );
		$this->assertTrue( Blocklist::matches( $list, '', '', '10.0.0.5' ) );
		$this->assertFalse( Blocklist::matches( $list, '0301 0000000', 'ok@example.com', '10.0.0.6' ) );
		$this->assertFalse( Blocklist::matches( $list, '', '', '' ), 'Empty identifiers never match.' );
	}
}