<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Stonewright\WpMcp\Abilities\Design\BuildSpec;

/**
 * @covers \Stonewright\WpMcp\Abilities\Design\BuildSpec
 */
final class BuildSpecTest extends TestCase {

	public function test_returns_normalized_spec_from_validator(): void {
		$ability = new BuildSpec();

		$result = $ability->execute(
			[
				'page'     => [ 'title' => 'Contract page' ],
				'sections' => [
					[
						'blocks' => [
							[
								'type' => 'heading',
								'text' => 'Hello',
							],
						],
					],
				],
			]
		);

		$this->assertNotInstanceOf( \WP_Error::class, $result );
		$this->assertSame( '1.0.0', $result['version'] );
		$this->assertSame( 'section_0', $result['sections'][0]['id'] );
	}

	public function test_returns_validator_error_for_invalid_spec(): void {
		$ability = new BuildSpec();

		$result = $ability->execute(
			[
				'page'     => [],
				'sections' => [],
			]
		);

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'stonewright_spec_invalid', $result->get_error_code() );
	}
}
