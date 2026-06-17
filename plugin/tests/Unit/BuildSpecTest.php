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

	public function test_builds_validator_valid_blocks_from_section_shorthand(): void {
		$ability = new BuildSpec();

		$result = $ability->execute(
			[
				'page'     => [ 'title' => 'Landing page' ],
				'sections' => [
					[
						'id'          => 'hero',
						'type'        => 'hero',
						'heading'     => 'Launch fast',
						'paragraph'   => 'Native widgets first.',
						'button_text' => 'Start',
						'button_url'  => 'https://example.test/start',
					],
				],
			]
		);

		$this->assertNotInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'hero', $result['sections'][0]['id'] );
		$this->assertSame( 'heading', $result['sections'][0]['blocks'][0]['type'] );
		$this->assertSame( 'Launch fast', $result['sections'][0]['blocks'][0]['text'] );
		$this->assertSame( 'paragraph', $result['sections'][0]['blocks'][1]['type'] );
		$this->assertSame( 'button', $result['sections'][0]['blocks'][2]['type'] );
	}
}
