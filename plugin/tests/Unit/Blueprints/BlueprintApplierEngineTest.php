<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\Tests\Unit\Blueprints;

use PHPUnit\Framework\TestCase;
use Stonewright\WpMcp\Blueprints\BlueprintApplier;

/**
 * @covers \Stonewright\WpMcp\Blueprints\BlueprintApplier
 */
final class BlueprintApplierEngineTest extends TestCase {

	protected function tearDown(): void {
		BlueprintApplier::$test_elementor_available = null;
		parent::tearDown();
	}

	public function test_explicit_elementor_without_plugin_returns_engine_unavailable(): void {
		// Unit bootstrap stubs Elementor\Plugin; override detection for this gate.
		BlueprintApplier::$test_elementor_available = false;

		$result = BlueprintApplier::apply(
			[
				'blueprint_id' => 'dental',
				'engine'       => 'elementor',
				'page_title'   => 'Engine gate test',
				'mode'         => 'draft',
			]
		);

		self::assertInstanceOf( \WP_Error::class, $result );
		self::assertSame( 'stonewright_engine_unavailable', $result->get_error_code() );
		$data = $result->get_error_data();
		self::assertIsArray( $data );
		self::assertSame( 'elementor', $data['engine_requested'] ?? null );
	}
}
