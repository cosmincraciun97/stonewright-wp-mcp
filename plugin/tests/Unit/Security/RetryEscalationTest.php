<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\Tests\Unit\Security;

use PHPUnit\Framework\TestCase;
use Stonewright\WpMcp\Security\ErrorPatterns;

/**
 * @covers \Stonewright\WpMcp\Security\ErrorPatterns::occurrence_count
 * @covers \Stonewright\WpMcp\Security\ErrorPatterns::escalate_error
 */
final class RetryEscalationTest extends TestCase {

	protected function setUp(): void {
		$GLOBALS['stonewright_test_options'] = [];
		delete_option( 'stonewright_error_patterns' );
	}

	protected function tearDown(): void {
		delete_option( 'stonewright_error_patterns' );
		$GLOBALS['stonewright_test_options'] = [];
	}

	public function test_repeated_error_gets_escalation_data(): void {
		delete_option( 'stonewright_error_patterns' );
		$args = [
			'error_code' => 'stonewright_php_elementor_raw_write_blocked',
			'message'    => 'Raw Elementor document writes are blocked in php-execute.',
		];
		ErrorPatterns::observe( 'stonewright/php-execute', 'error', $args );
		ErrorPatterns::observe( 'stonewright/php-execute', 'error', $args );
		$count = ErrorPatterns::occurrence_count( 'stonewright/php-execute', $args );
		self::assertGreaterThanOrEqual( 2, $count );

		$err       = new \WP_Error(
			'stonewright_php_elementor_raw_write_blocked',
			'Raw Elementor document writes are blocked in php-execute.'
		);
		$escalated = ErrorPatterns::escalate_error( 'stonewright/php-execute', $err, $args );
		self::assertStringContainsString( 'STOP', $escalated->get_error_message() );
		$data = (array) $escalated->get_error_data();
		self::assertArrayHasKey( 'occurrences', $data );
		self::assertArrayHasKey( 'repair', $data );
		self::assertGreaterThanOrEqual( 2, (int) $data['occurrences'] );
		self::assertStringContainsString( 'elementor-v3-batch-mutate', (string) $data['repair'] );
	}

	public function test_first_error_is_not_escalated(): void {
		$args = [
			'error_code' => 'stonewright_php_elementor_raw_write_blocked',
			'message'    => 'Raw Elementor document writes are blocked in php-execute.',
		];
		ErrorPatterns::observe( 'stonewright/php-execute', 'error', $args );
		$err = new \WP_Error(
			'stonewright_php_elementor_raw_write_blocked',
			'Raw Elementor document writes are blocked in php-execute.'
		);
		$out = ErrorPatterns::escalate_error( 'stonewright/php-execute', $err, $args );
		self::assertStringNotContainsString( 'STOP', $out->get_error_message() );
		self::assertSame( $err->get_error_message(), $out->get_error_message() );
	}
}
