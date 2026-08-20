<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\Tests\Unit\Elementor;

use PHPUnit\Framework\TestCase;
use Stonewright\WpMcp\Elementor\V4\V4FeatureGate;

/**
 * @covers \Stonewright\WpMcp\Elementor\V4\V4FeatureGate
 */
final class V4FeatureGateTest extends TestCase {

	protected function tearDown(): void {
		V4FeatureGate::set_atomic_module_present_for_tests( null );
		$GLOBALS['stonewright_test_options'] = [];
	}

	public function test_disabled_option_blocks_v4_abilities(): void {
		$GLOBALS['stonewright_test_options']['stonewright_elementor_v4_atomic'] = false;

		$result = V4FeatureGate::check();

		self::assertInstanceOf( \WP_Error::class, $result );
		self::assertSame( 'feature_disabled', $result->get_error_code() );
	}

	public function test_enabled_option_allows_v4_abilities(): void {
		$GLOBALS['stonewright_test_options']['stonewright_elementor_v4_atomic'] = true;
		V4FeatureGate::set_atomic_module_present_for_tests( true );

		self::assertTrue( V4FeatureGate::check() );
	}

	public function test_enabled_flag_without_atomic_module_returns_v4_unavailable(): void {
		$GLOBALS['stonewright_test_options']['stonewright_elementor_v4_atomic'] = true;

		self::assertFalse(
			class_exists( '\\Elementor\\Modules\\AtomicWidgets\\Module' ),
			'Unit tests must not stub AtomicWidgets\\Module; this gate should fail closed.'
		);

		$result = V4FeatureGate::check();

		self::assertInstanceOf( \WP_Error::class, $result );
		self::assertSame( 'stonewright_v4_unavailable', $result->get_error_code() );
		self::assertStringNotContainsString( 'architecture_mismatch', (string) $result->get_error_code() );
		self::assertStringContainsString( 'Atomic Widgets', $result->get_error_message() );
		self::assertMatchesRegularExpression( '/3\.31|4\.0/', $result->get_error_message() );
	}
}
