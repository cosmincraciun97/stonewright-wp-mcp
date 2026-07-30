<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Stonewright\WpMcp\Security\Permissions;

/**
 * @covers \Stonewright\WpMcp\Security\Permissions
 */
final class PermissionsTest extends TestCase {

	protected function setUp(): void {
		$GLOBALS['stonewright_test_user_caps']         = [];
		$GLOBALS['stonewright_test_user_logged_in']    = false;
		$GLOBALS['stonewright_test_user_can_callback'] = null;
		$GLOBALS['stonewright_test_post_types']        = [];
		$GLOBALS['stonewright_test_posts']             = [];
	}

	protected function tearDown(): void {
		$GLOBALS['stonewright_test_user_caps']         = [];
		$GLOBALS['stonewright_test_user_logged_in']    = false;
		$GLOBALS['stonewright_test_user_can_callback'] = null;
		$GLOBALS['stonewright_test_post_types']        = [];
		$GLOBALS['stonewright_test_posts']             = [];
	}

	// -------------------------------------------------------------------------
	// create_cap_for_post_type
	// -------------------------------------------------------------------------

	public function test_create_cap_for_post_type_returns_post_type_cap(): void {
		$GLOBALS['stonewright_test_post_types']['event'] = (object) [
			'cap' => (object) [
				'create_posts'  => 'edit_events',
				'publish_posts' => 'publish_events',
			],
		];

		$this->assertSame( 'edit_events', Permissions::create_cap_for_post_type( 'event' ) );
	}

	public function test_create_cap_for_post_type_falls_back_to_edit_posts_for_unknown_type(): void {
		// 'unknown_type' is not registered in stonewright_test_post_types.
		$this->assertSame( 'edit_posts', Permissions::create_cap_for_post_type( 'unknown_type' ) );
	}

	public function test_create_cap_for_post_type_falls_back_when_cap_property_missing(): void {
		// Post type object exists but has no 'create_posts' cap.
		$GLOBALS['stonewright_test_post_types']['barebones'] = (object) [
			'cap' => (object) [],
		];

		$this->assertSame( 'edit_posts', Permissions::create_cap_for_post_type( 'barebones' ) );
	}

	// -------------------------------------------------------------------------
	// publish_cap_for_status
	// -------------------------------------------------------------------------

	public function test_publish_cap_for_status_returns_null_for_draft(): void {
		$this->assertNull( Permissions::publish_cap_for_status( 'post', 'draft' ) );
	}

	public function test_publish_cap_for_status_returns_null_for_pending(): void {
		$this->assertNull( Permissions::publish_cap_for_status( 'post', 'pending' ) );
	}

	public function test_publish_cap_for_status_returns_publish_posts_for_publish(): void {
		$GLOBALS['stonewright_test_post_types']['post'] = (object) [
			'cap' => (object) [
				'create_posts'  => 'edit_posts',
				'publish_posts' => 'publish_posts',
			],
		];

		$this->assertSame( 'publish_posts', Permissions::publish_cap_for_status( 'post', 'publish' ) );
	}

	public function test_publish_cap_for_status_returns_publish_posts_for_private(): void {
		$GLOBALS['stonewright_test_post_types']['post'] = (object) [
			'cap' => (object) [
				'create_posts'  => 'edit_posts',
				'publish_posts' => 'publish_posts',
			],
		];

		$this->assertSame( 'publish_posts', Permissions::publish_cap_for_status( 'post', 'private' ) );
	}

	public function test_publish_cap_for_status_returns_publish_posts_for_future(): void {
		$GLOBALS['stonewright_test_post_types']['post'] = (object) [
			'cap' => (object) [
				'create_posts'  => 'edit_posts',
				'publish_posts' => 'publish_posts',
			],
		];

		$this->assertSame( 'publish_posts', Permissions::publish_cap_for_status( 'post', 'future' ) );
	}

	public function test_publish_cap_for_status_falls_back_when_post_type_unknown(): void {
		// Falls back to the generic 'publish_posts'.
		$this->assertSame( 'publish_posts', Permissions::publish_cap_for_status( 'unknown_type', 'publish' ) );
	}

	public function test_publish_cap_for_status_uses_custom_type_publish_cap(): void {
		$GLOBALS['stonewright_test_post_types']['event'] = (object) [
			'cap' => (object) [
				'create_posts'  => 'edit_events',
				'publish_posts' => 'publish_events',
			],
		];

		$this->assertSame( 'publish_events', Permissions::publish_cap_for_status( 'event', 'publish' ) );
	}

	// -------------------------------------------------------------------------
	// can_create_post_type
	// -------------------------------------------------------------------------

	public function test_can_create_post_type_returns_true_when_user_has_cap(): void {
		$GLOBALS['stonewright_test_post_types']['page'] = (object) [
			'cap' => (object) [
				'create_posts'  => 'edit_pages',
				'publish_posts' => 'publish_pages',
			],
		];
		$GLOBALS['stonewright_test_user_caps'] = [ 'edit_pages' => true ];

		$this->assertTrue( Permissions::can_create_post_type( 'page' ) );
	}

	public function test_can_create_post_type_returns_false_when_user_lacks_cap(): void {
		$GLOBALS['stonewright_test_post_types']['page'] = (object) [
			'cap' => (object) [
				'create_posts'  => 'edit_pages',
				'publish_posts' => 'publish_pages',
			],
		];
		$GLOBALS['stonewright_test_user_caps'] = [ 'edit_posts' => true ]; // has edit_posts, not edit_pages

		$this->assertFalse( Permissions::can_create_post_type( 'page' ) );
	}

	// -------------------------------------------------------------------------
	// can_edit_post_meta
	// -------------------------------------------------------------------------

	public function test_can_edit_post_meta_rejects_invalid_post_id(): void {
		$this->assertFalse( Permissions::can_edit_post_meta( 0, 'some_key' ) );
		$this->assertFalse( Permissions::can_edit_post_meta( -1, 'some_key' ) );
	}

	public function test_can_edit_post_meta_returns_true_when_user_has_cap(): void {
		$calls = [];
		$GLOBALS['stonewright_test_user_can_callback'] = static function ( string $cap, mixed ...$args ) use ( &$calls ): bool {
			$calls[] = [ $cap, ...$args ];
			return true;
		};

		$post_id  = 42;
		$meta_key = 'my_key';

		$this->assertTrue( Permissions::can_edit_post_meta( $post_id, $meta_key ) );
		$this->assertSame( [ [ 'edit_post_meta', $post_id, $meta_key ] ], $calls );
	}

	public function test_can_edit_post_meta_returns_false_when_user_lacks_cap(): void {
		$GLOBALS['stonewright_test_user_caps'] = [];

		$this->assertFalse( Permissions::can_edit_post_meta( 42, 'my_key' ) );
	}
}
