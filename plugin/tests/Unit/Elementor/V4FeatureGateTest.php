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

		self::assertTrue( V4FeatureGate::check() );
	}
}
