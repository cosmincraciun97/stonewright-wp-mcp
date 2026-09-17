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

	public function test_v3_architecture_mismatch_names_concrete_tools(): void {
		$hint = RemediationHints::for_code( 'stonewright_v3_architecture_mismatch', 'stonewright/elementor-v3-batch-mutate' );
		self::assertStringContainsString( 'elementor-v4-read-atomic-tree', $hint );
		self::assertStringContainsString( 'elementor-v4-update-node', $hint );
		self::assertStringContainsString( 'do not use php-execute', strtolower( $hint ) );
	}

	public function test_v4_architecture_mismatch_points_to_v3_tools(): void {
		$hint = RemediationHints::for_code( 'stonewright_v4_architecture_mismatch', 'stonewright/elementor-v4-update-node' );
		self::assertStringContainsString( 'elementor-v3-update-element', $hint );
		self::assertStringContainsString( 'elementor-v3-batch-mutate', $hint );
	}

	public function test_raw_write_blocked_hint_names_batch_mutate(): void {
		$hint = RemediationHints::for_code( 'stonewright_php_elementor_raw_write_blocked', 'stonewright/php-execute' );
		self::assertStringContainsString( 'elementor-v3-batch-mutate', $hint );
	}

	/**
	 * @dataProvider recurring_live_codes
	 */
	public function test_recurring_failures_have_specific_non_bypass_repairs( string $code, string $needle ): void {
		$hint = RemediationHints::for_code( $code );

		self::assertStringContainsString( $needle, $hint );
		self::assertStringNotContainsString( 'learning-record', $hint );
	}

	/**
	 * @return array<string, array{string, string}>
	 */
	public static function recurring_live_codes(): array {
		return [
			'elementor settings' => [ 'stonewright_elementor_settings_invalid', 'exact live control schema' ],
			'readback restored'  => [ 'stonewright_elementor_readback_failed_restored', 'previous document was restored' ],
			'size collapse'      => [ 'stonewright_elementor_size_collapse', 'surgical element mutation' ],
			'grant invalid'      => [ 'stonewright_custom_code_grant_invalid', 'fresh one-time grant' ],
			'native gap'         => [ 'stonewright_native_gap_required', 'Native implementation has not been disproved' ],
			'read only'          => [ 'stonewright_php_read_only_violation', 'appropriate typed ability' ],
		];
	}

	public function test_cause_code_is_resolved_before_wrapper(): void {
		$hint = RemediationHints::for_code(
			'stonewright_php_parse_error',
			'stonewright/php-execute',
			'stonewright_structured_failure'
		);
		self::assertStringContainsString( 'parse', strtolower( $hint ) );
		self::assertStringContainsString( 'no dry_run', $hint );
		self::assertStringNotContainsString( 'dry_run:true', $hint );
	}

	public function test_batch_operation_hint_includes_supported_patch_repeater_row(): void {
		$hint = RemediationHints::for_code( 'stonewright_batch_operation_failed', 'stonewright/elementor-v3-batch-mutate' );
		self::assertStringContainsString( 'patch_repeater_row', $hint );
	}

	public function test_php_execute_and_css_regenerate_hints_do_not_recommend_dry_run(): void {
		$php = RemediationHints::for_code( 'totally_unknown', 'stonewright/php-execute' );
		$css = RemediationHints::for_code( 'totally_unknown', 'stonewright/elementor-css-regenerate' );

		self::assertStringContainsString( 'no dry_run', $php );
		self::assertStringNotContainsString( 'dry_run:true', $php );
		self::assertStringContainsString( 'no dry_run', $css );
		self::assertStringNotContainsString( 'dry_run:true', $css );
	}

	public function test_generic_hint_still_mentions_dry_run_where_supported(): void {
		$hint = RemediationHints::for_code( 'totally_unknown', 'stonewright/not-a-mapped-ability' );
		self::assertStringContainsString( 'dry_run:true where supported', $hint );
	}

	public function test_write_busy_hint_is_retryable_with_interval_and_limit(): void {
		$hint = RemediationHints::for_code( 'stonewright_elementor_write_busy' );
		self::assertStringContainsString( 'retry', strtolower( $hint ) );
		self::assertMatchesRegularExpression( '/\b(limit|retry_after|seconds)\b/i', $hint );
	}

	public function test_non_transient_codes_do_not_recommend_identical_auto_retry(): void {
		$schema   = RemediationHints::for_code( 'stonewright_elementor_settings_invalid' );
		$css      = RemediationHints::for_code( 'stonewright_css_classes_not_approved' );
		$readonly = RemediationHints::for_code( 'stonewright_php_read_only_violation' );
		$parse    = RemediationHints::for_code( 'stonewright_php_parse_error' );

		foreach ( [ $schema, $css, $readonly, $parse ] as $hint ) {
			self::assertStringNotContainsString( 'auto-retry the identical', strtolower( $hint ) );
		}
		self::assertStringContainsString( 'live control schema', $schema );
		self::assertStringContainsString( 'approved_css_classes', $css );
		self::assertStringContainsString( 'typed ability', $readonly );
		self::assertStringContainsString( 'no dry_run', $parse );
	}

	public function test_rollback_failed_hint_is_stop_not_generic_retry(): void {
		$hint = RemediationHints::for_code( 'stonewright_rollback_failed' );
		self::assertStringContainsString( 'stop', strtolower( $hint ) );
		self::assertStringNotContainsString( 'dry_run:true where supported', $hint );
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

	public function test_unchanged_execution_does_not_write_a_draft_lesson(): void {
		for ( $i = 0; $i < 12; $i++ ) {
			ErrorPatterns::observe(
				'stonewright/acf-value-update',
				'error',
				[
					'_meta' => [
						'error_code'       => 'stonewright_acf_update_false',
						'execution_status' => 'unchanged',
						'error_message'    => 'Value already stored.',
					],
				]
			);
		}

		self::assertSame( [], ErrorPatterns::recurring( 20 ) );
	}
}
