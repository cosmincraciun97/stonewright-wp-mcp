<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\Tests\Unit\Security;

use PHPUnit\Framework\TestCase;
use Stonewright\WpMcp\Security\ErrorPatterns;
use Stonewright\WpMcp\Security\RemediationHints;

/**
 * @covers \Stonewright\WpMcp\Security\RemediationHints
 * @covers \Stonewright\WpMcp\Security\ErrorPatterns
 */
final class RemediationHintsTest extends TestCase {

	protected function setUp(): void {
		$GLOBALS['stonewright_test_options'] = [];
	}

	public function test_for_code_specific_error_code(): void {
		$hint = RemediationHints::for_code( 'stonewright_confirmation_required' );
		$this->assertStringContainsString( 'confirmation-token', $hint );
	}

	public function test_for_code_falls_back_to_ability_name(): void {
		$hint = RemediationHints::for_code( 'some_unknown_code', 'stonewright/elementor-v3-batch-mutate' );
		$this->assertStringContainsString( 'page-structure', $hint );
	}

	public function test_for_code_generic_fallback(): void {
		$hint = RemediationHints::for_code( 'totally_unknown', 'stonewright/not-a-mapped-ability' );
		$this->assertStringContainsString( 'learning-record', $hint );
	}

	public function test_error_patterns_persist_error_code_and_repair(): void {
		ErrorPatterns::observe(
			'stonewright/elementor-v3-batch-mutate',
			'error',
			[
				'_meta' => [
					'error_code'    => 'stonewright_tree_hash_mismatch',
					'error_message' => 'Tree hash mismatch for page 12',
				],
			]
		);
		ErrorPatterns::observe(
			'stonewright/elementor-v3-batch-mutate',
			'error',
			[
				'_meta' => [
					'error_code'    => 'stonewright_tree_hash_mismatch',
					'error_message' => 'Tree hash mismatch for page 12',
				],
			]
		);
		$rows = ErrorPatterns::recurring( 5 );
		$this->assertNotEmpty( $rows );
		$this->assertSame( 'stonewright_tree_hash_mismatch', $rows[0]['error_code'] );
		$this->assertStringContainsString( 'Tree hash', $rows[0]['message'] );
		$this->assertNotSame( '', $rows[0]['repair'] );
		$this->assertStringContainsString( 'stale', strtolower( $rows[0]['repair'] ) );
	}
}
