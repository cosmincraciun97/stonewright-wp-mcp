<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\Tests\Unit\Design;

use PHPUnit\Framework\TestCase;
use Stonewright\WpMcp\Design\Direction\DialTranslator;

/**
 * @covers \Stonewright\WpMcp\Design\Direction\DialTranslator
 */
final class DialTranslatorTest extends TestCase {

	public function test_translates_low_dials_into_quiet_spacious_elementor_rules(): void {
		$result = DialTranslator::translate(
			[
				'dials' => [ 'variance' => 12, 'density' => 20, 'motion' => 10 ],
				'tokens' => [ 'spacing' => [] ],
			]
		);

		self::assertSame( 'symmetric', $result['variance']['layout_rhythm'] );
		self::assertSame( 96, $result['density']['section_padding_px']['desktop'] );
		self::assertSame( 32, $result['density']['default_container_gap'] );
		self::assertSame( 'blocked', $result['motion']['entrance_animation'] );
		self::assertSame( 'blocked', $result['motion']['motion_fx'] );
	}

	public function test_declared_spacing_tokens_override_dense_defaults(): void {
		$result = DialTranslator::translate(
			[
				'dials' => [ 'variance' => 90, 'density' => 90, 'motion' => 90 ],
				'tokens' => [ 'spacing' => [ 'section' => '80px' ] ],
			]
		);

		self::assertSame( 'asymmetric_preferred', $result['variance']['layout_rhythm'] );
		self::assertSame( 48, $result['density']['section_padding_px']['desktop'] );
		self::assertSame( [ 'section' => '80px' ], $result['density']['declared_spacing_tokens'] );
		self::assertSame( 'allowed', $result['motion']['motion_fx'] );
		self::assertArrayHasKey( 'reduced_motion_rule', $result['motion'] );
	}
}
