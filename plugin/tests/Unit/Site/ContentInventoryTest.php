<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\Tests\Unit\Site;

use PHPUnit\Framework\TestCase;
use Stonewright\WpMcp\Abilities\Site\ContentInventory;
use Stonewright\WpMcp\Core\AbilityRegistry;

/**
 * @covers \Stonewright\WpMcp\Abilities\Site\ContentInventory
 */
final class ContentInventoryTest extends TestCase {

	protected function setUp(): void {
		$GLOBALS['stonewright_test_user_logged_in'] = true;
		$GLOBALS['stonewright_test_user_caps']      = [ 'edit_posts' => true ];
		$GLOBALS['stonewright_test_options']        = [ 'stonewright_mode' => 'development' ];
	}

	protected function tearDown(): void {
		$GLOBALS['stonewright_test_user_caps']      = [];
		$GLOBALS['stonewright_test_user_logged_in'] = false;
		$GLOBALS['stonewright_test_options']        = [];
	}

	public function test_registry_exposes_content_inventory(): void {
		$ability = AbilityRegistry::ability_by_name( 'stonewright/content-inventory' );
		self::assertInstanceOf( ContentInventory::class, $ability );
		self::assertSame( 'site', $ability->category() );
	}

	public function test_permission_requires_edit_posts(): void {
		$ability = new ContentInventory();
		self::assertTrue( $ability->permission_callback( [] ) );

		$GLOBALS['stonewright_test_user_caps'] = [ 'read' => true ];
		self::assertFalse( $ability->permission_callback( [] ) );
	}

	public function test_execute_returns_types_with_counts(): void {
		$result = ( new ContentInventory() )->execute( [ 'public_only' => true ] );

		self::assertIsArray( $result );
		self::assertArrayHasKey( 'types', $result );
		self::assertArrayHasKey( 'total_types', $result );
		self::assertIsArray( $result['types'] );
		self::assertSame( count( $result['types'] ), $result['total_types'] );

		foreach ( $result['types'] as $type ) {
			self::assertArrayHasKey( 'slug', $type );
			self::assertArrayHasKey( 'counts', $type );
			self::assertArrayHasKey( 'publish', $type['counts'] );
			self::assertArrayHasKey( 'total', $type['counts'] );
			self::assertNotSame( 'attachment', $type['slug'] );
		}
	}
}
