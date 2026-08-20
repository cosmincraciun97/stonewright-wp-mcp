<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\Tests\Unit\Abilities\Blocks;

use PHPUnit\Framework\TestCase;
use Stonewright\WpMcp\Abilities\Blocks\LibraryCheckSetup;
use Stonewright\WpMcp\Abilities\Blocks\LibraryGetBlockSchema;
use Stonewright\WpMcp\Abilities\Blocks\LibraryListBlocks;
use Stonewright\WpMcp\Core\AbilityRegistry;

/**
 * @covers \Stonewright\WpMcp\Abilities\Blocks\LibraryCheckSetup
 * @covers \Stonewright\WpMcp\Abilities\Blocks\LibraryListBlocks
 * @covers \Stonewright\WpMcp\Abilities\Blocks\LibraryGetBlockSchema
 */
final class LibraryIntrospectionTest extends TestCase {

	protected function setUp(): void {
		$GLOBALS['stonewright_test_user_caps'] = [ 'edit_posts' => true, 'read' => true ];
	}

	protected function tearDown(): void {
		unset( $GLOBALS['stonewright_test_registered_blocks'] );
		$GLOBALS['stonewright_test_user_caps'] = [];
	}

	public function test_check_setup_reports_inactive_library(): void {
		$result = ( new LibraryCheckSetup() )->execute( [ 'library' => 'generateblocks' ] );

		self::assertIsArray( $result );
		self::assertSame( 'generateblocks', $result['library'] );
		self::assertFalse( $result['active'] );
		self::assertSame( '', $result['version'] );
	}

	public function test_list_blocks_returns_empty_when_library_unregistered(): void {
		$result = ( new LibraryListBlocks() )->execute( [ 'library' => 'kadence' ] );

		self::assertIsArray( $result );
		self::assertSame( [], $result['blocks'] );
		self::assertSame( 0, $result['count'] );
	}

	public function test_get_schema_rejects_cross_library_name(): void {
		$result = ( new LibraryGetBlockSchema() )->execute(
			[
				'library' => 'spectra',
				'name'    => 'core/paragraph',
			]
		);

		self::assertInstanceOf( \WP_Error::class, $result );
		self::assertSame( 'stonewright_invalid_block', $result->get_error_code() );
	}

	public function test_registry_exposes_the_three_library_abilities(): void {
		$names = array_map(
			static fn( string $class ): string => ( new $class() )->name(),
			AbilityRegistry::list()
		);

		self::assertContains( 'stonewright/blocks-library-check-setup', $names );
		self::assertContains( 'stonewright/blocks-library-list-blocks', $names );
		self::assertContains( 'stonewright/blocks-library-get-schema', $names );
	}

	public function test_list_blocks_returns_registered_prefix_names(): void {
		$GLOBALS['stonewright_test_registered_blocks'] = [
			'kadence/row'     => (object) [ 'title' => 'Row' ],
			'core/paragraph'  => (object) [ 'title' => 'Paragraph' ],
			'uagb/container'  => (object) [ 'title' => 'Container' ],
		];

		$result = ( new LibraryListBlocks() )->execute( [ 'library' => 'kadence' ] );

		self::assertIsArray( $result );
		self::assertSame( [ 'kadence/row' ], $result['blocks'] );
		self::assertSame( 1, $result['count'] );
	}

	public function test_get_schema_converts_live_attributes_without_inventing_keys(): void {
		$GLOBALS['stonewright_test_registered_blocks'] = [
			'uagb/container' => (object) [
				'title'      => 'Container',
				'category'   => 'uag',
				'attributes' => [
					'block_id' => [
						'type'    => 'string',
						'default' => '',
					],
				],
			],
		];

		$result = ( new LibraryGetBlockSchema() )->execute(
			[
				'library' => 'spectra',
				'name'    => 'uagb/container',
			]
		);

		self::assertIsArray( $result );
		self::assertSame( 'uagb/container', $result['name'] );
		self::assertArrayHasKey( 'block_id', $result['attributes'] );
		self::assertArrayNotHasKey( 'inventedPadding', $result['attributes'] );
	}
}
