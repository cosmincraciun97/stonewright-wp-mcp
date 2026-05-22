<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Stonewright\WpMcp\Abilities\Design\SpecToElementorV3;
use Stonewright\WpMcp\Abilities\Design\SpecToGutenberg;

/**
 * @covers \Stonewright\WpMcp\Abilities\Design\SpecToGutenberg
 * @covers \Stonewright\WpMcp\Abilities\Design\SpecToElementorV3
 * @covers \Stonewright\WpMcp\Support\ElementorData
 */
final class ReplacementWriteSafetyTest extends TestCase {

	protected function setUp(): void {
		$GLOBALS['stonewright_test_options'] = [ 'stonewright_mode' => 'production-safe' ];
		$GLOBALS['stonewright_test_posts']   = [
			1 => (object) [
				'ID'           => 1,
				'post_type'    => 'page',
				'post_status'  => 'draft',
				'post_title'   => 'Contract Page',
				'post_content' => '<p>Old</p>',
				'post_excerpt' => '',
				'post_parent'  => 0,
				'post_name'    => 'contract-page',
				'meta'         => [
					'_elementor_data' => '[]',
				],
			],
		];
		unset( $GLOBALS['stonewright_test_update_post_meta_return'] );
	}

	protected function tearDown(): void {
		unset( $GLOBALS['stonewright_test_update_post_meta_return'] );
		$GLOBALS['stonewright_test_options'] = [];
		$GLOBALS['stonewright_test_posts']   = [];
	}

	public function test_gutenberg_full_replacement_requires_token_in_production_safe_mode(): void {
		$result = ( new SpecToGutenberg() )->execute(
			[
				'post_id' => 1,
				'append'  => false,
				'spec'    => $this->spec(),
			]
		);

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'stonewright_confirmation_required', $result->get_error_code() );
	}

	public function test_elementor_full_replacement_requires_token_in_production_safe_mode(): void {
		$result = ( new SpecToElementorV3() )->execute(
			[
				'post_id' => 1,
				'replace' => true,
				'spec'    => $this->spec(),
			]
		);

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'stonewright_confirmation_required', $result->get_error_code() );
	}

	public function test_elementor_write_failure_returns_wp_error(): void {
		$GLOBALS['stonewright_test_options']                 = [];
		$GLOBALS['stonewright_test_update_post_meta_return'] = false;

		$result = ( new SpecToElementorV3() )->execute(
			[
				'post_id' => 1,
				'replace' => false,
				'spec'    => $this->spec(),
			]
		);

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'stonewright_write_failed', $result->get_error_code() );
	}

	private function spec(): array {
		return [
			'page'     => [ 'title' => 'Contract Page' ],
			'sections' => [
				[
					'id'     => 'hero',
					'blocks' => [
						[
							'type' => 'heading',
							'text' => 'Hello',
						],
					],
				],
			],
		];
	}
}
