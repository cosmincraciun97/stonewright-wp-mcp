<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\Tests\Unit\ThemeBuilder;

use PHPUnit\Framework\TestCase;
use Stonewright\WpMcp\ThemeBuilder\TemplateStore;

/**
 * @covers \Stonewright\WpMcp\ThemeBuilder\TemplateStore
 */
final class TemplateStoreStagingTest extends TestCase {

	protected function setUp(): void {
		$GLOBALS['stonewright_test_posts']         = [];
		$GLOBALS['stonewright_test_inserted_posts'] = [];
		$GLOBALS['stonewright_test_deleted_posts'] = [];
		$GLOBALS['stonewright_test_next_post_id']  = 7000;
	}

	protected function tearDown(): void {
		$GLOBALS['stonewright_test_posts']          = [];
		$GLOBALS['stonewright_test_inserted_posts'] = [];
		$GLOBALS['stonewright_test_deleted_posts']  = [];
	}

	public function test_staged_loop_item_remains_owned_until_finalized(): void {
		$id = TemplateStore::create_staged( 'Project card', 'loop-item', 'txn-abc' );

		self::assertIsInt( $id );
		self::assertSame( 'draft', get_post_status( $id ) );
		self::assertSame( 'txn-abc', get_post_meta( $id, '_stonewright_transaction_owner', true ) );
		self::assertTrue( TemplateStore::publish_staged( $id, 'txn-abc' ) );
		self::assertSame( 'publish', get_post_status( $id ) );
		self::assertSame( 'txn-abc', get_post_meta( $id, '_stonewright_transaction_owner', true ) );
		self::assertTrue( TemplateStore::finalize_staged( $id, 'txn-abc' ) );
		self::assertSame( '', get_post_meta( $id, '_stonewright_transaction_owner', true ) );
	}

	public function test_wrong_owner_cannot_publish_finalize_or_delete(): void {
		$id = TemplateStore::create_staged( 'Project card', 'loop-item', 'txn-abc' );

		self::assertIsInt( $id );
		self::assertInstanceOf( \WP_Error::class, TemplateStore::publish_staged( $id, 'txn-other' ) );
		self::assertInstanceOf( \WP_Error::class, TemplateStore::finalize_staged( $id, 'txn-other' ) );
		self::assertInstanceOf( \WP_Error::class, TemplateStore::delete_staged( $id, 'txn-other' ) );
		self::assertNotNull( get_post( $id ) );
	}

	public function test_owner_can_force_delete_transaction_created_template(): void {
		$id = TemplateStore::create_staged( 'Project card', 'loop-item', 'txn-abc' );

		self::assertTrue( TemplateStore::delete_staged( $id, 'txn-abc' ) );
		self::assertNull( get_post( $id ) );
		self::assertSame( [ [ 'post_id' => $id, 'force' => true ] ], $GLOBALS['stonewright_test_deleted_posts'] );
	}
}
