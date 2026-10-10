<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\Tests\Unit\Elementor\Provider;

use PHPUnit\Framework\TestCase;
use Stonewright\WpMcp\Elementor\Provider\ProviderRouter;
use Stonewright\WpMcp\Elementor\V4\AtomicReadbackVerifier;

/**
 * Every Elementor route that reads or compares a tree stops at the element and depth
 * caps of the V4 readback verifier, and Section reuse takes the same caps.
 *
 * @covers \Stonewright\WpMcp\Elementor\Provider\ProviderRouter
 */
final class ProviderRouterElementLimitsTest extends TestCase {

	public function test_the_caps_are_the_readback_verifier_bounds(): void {
		self::assertSame(
			[ 'max_elements' => AtomicReadbackVerifier::MAX_NODES, 'max_depth' => AtomicReadbackVerifier::MAX_DEPTH ],
			ProviderRouter::element_limits()
		);
	}

	public function test_the_caps_are_positive_bounds(): void {
		$limits = ProviderRouter::element_limits();

		self::assertSame( [ 'max_elements', 'max_depth' ], array_keys( $limits ) );
		self::assertGreaterThan( 0, $limits['max_elements'] );
		self::assertGreaterThan( 0, $limits['max_depth'] );
		self::assertLessThanOrEqual( 5000, $limits['max_elements'], 'A walk of a document stays bounded.' );
		self::assertLessThanOrEqual( 64, $limits['max_depth'] );
	}
}
