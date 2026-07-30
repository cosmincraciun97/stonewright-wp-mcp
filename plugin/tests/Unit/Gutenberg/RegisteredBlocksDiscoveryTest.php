<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\Tests\Unit\Gutenberg;

use PHPUnit\Framework\TestCase;
use Stonewright\WpMcp\Abilities\Gutenberg\GetBlockSchema;
use Stonewright\WpMcp\Abilities\Gutenberg\ListRegisteredBlocks;

/**
 * @covers \Stonewright\WpMcp\Abilities\Gutenberg\ListRegisteredBlocks
 * @covers \Stonewright\WpMcp\Abilities\Gutenberg\GetBlockSchema
 */
final class RegisteredBlocksDiscoveryTest extends TestCase {

	protected function setUp(): void {
		$GLOBALS['stonewright_test_registered_blocks'] = [
			'vendor/card' => (object) [
				'title'           => 'Vendor Card',
				'category'        => 'widgets',
				'description'     => 'Third-party card block.',
				'icon'            => 'index-card',
				'keywords'        => [ 'card', 'vendor' ],
				'attributes'      => [
					'title' => [
						'type'    => 'string',
						'default' => 'Card title',
					],
					'tone'  => [
						'type' => 'string',
						'enum' => [ 'light', 'dark' ],
					],
				],
				'supports'        => [
					'align'   => [ 'wide', 'full' ],
					'spacing' => [
						'margin'  => true,
						'padding' => true,
					],
				],
				'example'         => [
					'attributes' => [
						'title' => 'Example card',
						'tone'  => 'dark',
					],
				],
				'variations'      => [
					[
						'name'  => 'feature',
						'title' => 'Feature card',
					],
				],
				'render_callback' => static fn(): string => '<div></div>',
			],
		];
	}

	protected function tearDown(): void {
		unset( $GLOBALS['stonewright_test_registered_blocks'] );
	}

	public function test_lists_registered_third_party_blocks_with_inserter_metadata(): void {
		$result = ( new ListRegisteredBlocks() )->execute( [ 'namespace' => 'vendor' ] );

		self::assertIsArray( $result );
		self::assertSame( 'vendor/card', $result['blocks'][0]['name'] );
		self::assertSame( 'Vendor Card', $result['blocks'][0]['title'] );
		self::assertSame( 'widgets', $result['blocks'][0]['category'] );
		self::assertSame( 'index-card', $result['blocks'][0]['icon'] );
		self::assertTrue( $result['blocks'][0]['is_dynamic'] );
		self::assertSame( [ 'card', 'vendor' ], $result['blocks'][0]['keywords'] );
		self::assertSame( [ 'attributes' => [ 'title' => 'Example card', 'tone' => 'dark' ] ], $result['blocks'][0]['example'] );
	}

	public function test_get_schema_returns_attributes_supports_and_variations(): void {
		$result = ( new GetBlockSchema() )->execute( [ 'name' => 'vendor/card' ] );

		self::assertIsArray( $result );
		self::assertSame( 'string', $result['attributes']['title']['type'] );
		self::assertSame( [ 'wide', 'full' ], $result['supports']['align'] );
		self::assertSame( 'feature', $result['variations'][0]['name'] );
		self::assertSame( [ 'attributes' => [ 'title' => 'Example card', 'tone' => 'dark' ] ], $result['example'] );
	}
}
