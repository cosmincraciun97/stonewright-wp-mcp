<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\Tests\Unit\Core;

use PHPUnit\Framework\TestCase;
use Stonewright\WpMcp\Core\McpUsePolicy;
use Stonewright\WpMcp\Core\MethodRouter;

/**
 * @covers \Stonewright\WpMcp\Core\McpUsePolicy
 * @covers \Stonewright\WpMcp\Core\MethodRouter
 */
final class CanonicalOperatingRulesTest extends TestCase {

	public function test_canonical_rules_have_stable_ids(): void {
		$rules = McpUsePolicy::canonical_operating_rules();
		self::assertCount( 8, $rules );
		self::assertArrayHasKey( 'elementor_responsive_preview', $rules );
		self::assertArrayHasKey( 'separate_verification_tab', $rules );
		self::assertArrayHasKey( 'design_section_isolation', $rules );
		self::assertArrayHasKey( 'breakpoint_isolation', $rules );
		self::assertArrayHasKey( 'native_first_styling', $rules );
		self::assertArrayHasKey( 'fastest_safe_interface', $rules );
		self::assertArrayHasKey( 'verified_learning', $rules );
		self::assertArrayHasKey( 'custom_code_operator_grant', $rules );
		self::assertStringContainsString( 'php-execute', $rules['custom_code_operator_grant'] );
	}

	public function test_fingerprint_is_stable_sha256(): void {
		$fp = McpUsePolicy::canonical_rules_fingerprint();
		self::assertSame( 64, strlen( $fp ) );
		self::assertSame( $fp, McpUsePolicy::canonical_rules_fingerprint() );
	}

	public function test_permanent_rules_include_canonical_texts(): void {
		$permanent = McpUsePolicy::permanent_operating_rules();
		foreach ( McpUsePolicy::canonical_operating_rules() as $text ) {
			self::assertContains( $text, $permanent );
		}
	}

	public function test_method_router_prefers_typed_api(): void {
		$choice = MethodRouter::select( 'wordpress_content', [ 'has_typed_ability' => true ] );
		self::assertSame( MethodRouter::TYPED_API, $choice['method'] );
		self::assertArrayHasKey( 'reason', $choice );
	}

	public function test_method_router_falls_back_to_command_bus_for_editor_only(): void {
		$choice = MethodRouter::select(
			'elementor_editor_only',
			[ 'has_typed_ability' => false, 'editor_loaded' => true ]
		);
		self::assertSame( MethodRouter::EDITOR_COMMAND_BUS, $choice['method'] );
	}

	public function test_method_router_browser_ui_last(): void {
		$choice = MethodRouter::select(
			'admin_only_setting',
			[
				'has_typed_ability' => false,
				'editor_loaded'     => false,
				'has_admin_form'    => false,
				'browser_available' => true,
			]
		);
		self::assertSame( MethodRouter::BROWSER_UI, $choice['method'] );
	}
}
