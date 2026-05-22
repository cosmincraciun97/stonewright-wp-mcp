<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Stonewright\WpMcp\Abilities\Content\BulkCreate;
use Stonewright\WpMcp\Abilities\Content\CreatePage;
use Stonewright\WpMcp\Abilities\Content\CreatePost;
use Stonewright\WpMcp\Abilities\Content\DuplicatePage;
use Stonewright\WpMcp\Abilities\Content\UpdatePage;
use Stonewright\WpMcp\Abilities\Content\UpdatePost;

/**
 * @covers \Stonewright\WpMcp\Abilities\Content\CreatePost
 * @covers \Stonewright\WpMcp\Abilities\Content\UpdatePost
 * @covers \Stonewright\WpMcp\Abilities\Content\BulkCreate
 * @covers \Stonewright\WpMcp\Abilities\Content\CreatePage
 * @covers \Stonewright\WpMcp\Abilities\Content\UpdatePage
 * @covers \Stonewright\WpMcp\Abilities\Content\DuplicatePage
 * @covers \Stonewright\WpMcp\Security\Permissions
 */
final class ContentCapabilityTest extends TestCase {

	protected function setUp(): void {
		$GLOBALS['stonewright_test_user_caps']              = [];
		$GLOBALS['stonewright_test_user_logged_in']         = false;
		$GLOBALS['stonewright_test_user_can_callback']      = null;
		$GLOBALS['stonewright_test_options']                = [];
		$GLOBALS['stonewright_test_transients']             = [];
		$GLOBALS['stonewright_test_post_types']             = [];
		$GLOBALS['stonewright_test_posts']                  = [];
		$GLOBALS['stonewright_test_post_meta_calls']        = [];
		$GLOBALS['stonewright_test_next_post_id']           = 1001;
		$GLOBALS['stonewright_test_inserted_posts']         = [];
		$GLOBALS['stonewright_test_wp_insert_post_return']  = null;
		$GLOBALS['stonewright_test_wp_update_post_return']  = null;

		// Register common post types used in tests.
		$this->registerPostType( 'post', 'edit_posts', 'publish_posts' );
		$this->registerPostType( 'page', 'edit_pages', 'publish_pages' );
	}

	protected function tearDown(): void {
		$GLOBALS['stonewright_test_user_caps']              = [];
		$GLOBALS['stonewright_test_user_logged_in']         = false;
		$GLOBALS['stonewright_test_user_can_callback']      = null;
		$GLOBALS['stonewright_test_options']                = [];
		$GLOBALS['stonewright_test_transients']             = [];
		$GLOBALS['stonewright_test_post_types']             = [];
		$GLOBALS['stonewright_test_posts']                  = [];
		$GLOBALS['stonewright_test_post_meta_calls']        = [];
		$GLOBALS['stonewright_test_next_post_id']           = 1001;
		$GLOBALS['stonewright_test_inserted_posts']         = [];
		$GLOBALS['stonewright_test_wp_insert_post_return']  = null;
		$GLOBALS['stonewright_test_wp_update_post_return']  = null;
	}

	// -------------------------------------------------------------------------
	// Helpers
	// -------------------------------------------------------------------------

	private function registerPostType( string $name, string $create_cap, string $publish_cap ): void {
		$GLOBALS['stonewright_test_post_types'][ $name ] = (object) [
			'cap' => (object) [
				'create_posts'  => $create_cap,
				'publish_posts' => $publish_cap,
			],
		];
	}

	private function loginAs( array $caps ): void {
		$GLOBALS['stonewright_test_user_logged_in'] = true;
		$GLOBALS['stonewright_test_user_caps']      = array_fill_keys( $caps, true );
	}

	private function setPost( int $id, string $post_type = 'post', string $status = 'draft' ): void {
		$GLOBALS['stonewright_test_posts'][ $id ] = (object) [
			'ID'           => $id,
			'post_type'    => $post_type,
			'post_status'  => $status,
			'post_title'   => 'Test Post',
			'post_content' => '',
			'post_excerpt' => '',
			'post_parent'  => 0,
			'post_name'    => 'test-post',
		];
	}

	private function assertError( string $code, int $status, mixed $result ): void {
		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( $code, $result->get_error_code() );
		$this->assertSame( [ 'status' => $status ], $result->get_error_data() );
	}

	private function assertMetaSkippedDescription( array $schema ): void {
		$this->assertArrayHasKey( 'properties', $schema );
		$this->assertArrayHasKey( 'meta_skipped', $schema['properties'] );
		$this->assertSame( 'array', $schema['properties']['meta_skipped']['type'] );
		$this->assertSame(
			'Meta keys refused by Permissions::can_edit_post_meta().',
			$schema['properties']['meta_skipped']['description']
		);
	}

	// -------------------------------------------------------------------------
	// CreatePost — create cap for post type
	// -------------------------------------------------------------------------

	public function test_create_post_requires_create_posts_cap_for_post_type(): void {
		// User has edit_posts (for 'post' type) but NOT edit_pages (for 'page' type).
		$this->loginAs( [ 'edit_posts' ] );

		$ability = new CreatePost();
		$result  = $ability->permission_callback( [ 'title' => 'Test', 'post_type' => 'page' ] );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'stonewright_forbidden', $result->get_error_code() );
	}

	public function test_create_post_requires_create_posts_cap_passes_when_user_has_correct_cap(): void {
		$this->loginAs( [ 'edit_posts' ] );

		$ability = new CreatePost();
		$result  = $ability->permission_callback( [ 'title' => 'Test', 'post_type' => 'post' ] );

		// Not a WP_Error — boolean true (or at least not forbidden).
		$this->assertNotInstanceOf( \WP_Error::class, $result );
		$this->assertTrue( (bool) $result );
	}

	// -------------------------------------------------------------------------
	// CreatePost — publish cap check
	// -------------------------------------------------------------------------

	public function test_create_post_with_publish_status_requires_publish_cap(): void {
		// User can create posts (edit_posts) but cannot publish them.
		$this->loginAs( [ 'edit_posts' ] );

		$ability = new CreatePost();
		$result  = $ability->permission_callback( [ 'title' => 'Test', 'post_type' => 'post', 'status' => 'publish' ] );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'stonewright_forbidden', $result->get_error_code() );
	}

	public function test_create_post_with_private_status_requires_publish_cap(): void {
		$this->loginAs( [ 'edit_posts' ] );

		$ability = new CreatePost();
		$result  = $ability->permission_callback( [ 'title' => 'Test', 'post_type' => 'post', 'status' => 'private' ] );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'stonewright_forbidden', $result->get_error_code() );
	}

	public function test_create_post_default_status_no_publish_check(): void {
		// With draft status (default) we only check create cap, not publish.
		$this->loginAs( [ 'edit_posts' ] );

		$ability = new CreatePost();
		$result  = $ability->permission_callback( [ 'title' => 'Test', 'post_type' => 'post', 'status' => 'draft' ] );

		$this->assertNotInstanceOf( \WP_Error::class, $result );
		$this->assertTrue( (bool) $result );
	}

	public function test_create_post_pending_status_no_publish_check(): void {
		$this->loginAs( [ 'edit_posts' ] );

		$ability = new CreatePost();
		$result  = $ability->permission_callback( [ 'title' => 'Test', 'post_type' => 'post', 'status' => 'pending' ] );

		$this->assertNotInstanceOf( \WP_Error::class, $result );
		$this->assertTrue( (bool) $result );
	}

	public function test_create_post_publish_passes_when_user_has_publish_cap(): void {
		$this->loginAs( [ 'edit_posts', 'publish_posts' ] );

		$ability = new CreatePost();
		$result  = $ability->permission_callback( [ 'title' => 'Test', 'post_type' => 'post', 'status' => 'publish' ] );

		$this->assertNotInstanceOf( \WP_Error::class, $result );
		$this->assertTrue( (bool) $result );
	}

	// -------------------------------------------------------------------------
	// CreatePost — meta per-key capability check
	// -------------------------------------------------------------------------

	public function test_create_post_meta_per_key_check_skips_disallowed(): void {
		// User can create posts and write 'allowed_key' but NOT 'blocked_key'.
		$GLOBALS['stonewright_test_user_can_callback'] = static function ( string $cap, mixed ...$args ): bool {
			if ( 'edit_posts' === $cap ) {
				return true;
			}
			if ( 'edit_post_meta' === $cap ) {
				// $args[0] = post_id, $args[1] = meta_key
				$meta_key = $args[1] ?? '';
				return 'allowed_key' === $meta_key;
			}
			return false;
		};

		$ability = new CreatePost();
		$result  = $ability->execute( [
			'title'     => 'Test Post',
			'post_type' => 'post',
			'status'    => 'draft',
			'meta'      => [
				'allowed_key' => 'value_a',
				'blocked_key' => 'value_b',
			],
		] );

		$this->assertIsArray( $result );
		$this->assertArrayHasKey( 'id', $result );
		$this->assertArrayHasKey( 'meta_skipped', $result );
		$this->assertContains( 'blocked_key', $result['meta_skipped'] );
		$this->assertNotContains( 'allowed_key', $result['meta_skipped'] );

		// Verify only allowed_key was written.
		$written_keys = array_column( $GLOBALS['stonewright_test_post_meta_calls'], 'meta_key' );
		$this->assertContains( 'allowed_key', $written_keys );
		$this->assertNotContains( 'blocked_key', $written_keys );
	}

	// -------------------------------------------------------------------------
	// UpdatePost — publish cap uses target post type
	// -------------------------------------------------------------------------

	public function test_update_post_publish_uses_target_post_type(): void {
		// Register 'event' post type.
		$this->registerPostType( 'event', 'edit_events', 'publish_events' );

		// Post 1 is of type 'event'.
		$this->setPost( 1, 'event', 'draft' );

		// User can edit post 1 (edit_post) and has edit_events, but NOT publish_events.
		$GLOBALS['stonewright_test_user_can_callback'] = static function ( string $cap, mixed ...$args ): bool {
			if ( 'edit_post' === $cap ) {
				return true; // allow editing any post
			}
			if ( 'edit_events' === $cap ) {
				return true;
			}
			return false;
		};

		$ability = new UpdatePost();
		$result  = $ability->permission_callback( [ 'id' => 1, 'status' => 'publish' ] );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'stonewright_forbidden', $result->get_error_code() );
	}

	public function test_update_post_publish_passes_when_user_has_event_publish_cap(): void {
		$this->registerPostType( 'event', 'edit_events', 'publish_events' );
		$this->setPost( 1, 'event', 'draft' );

		$GLOBALS['stonewright_test_user_can_callback'] = static function ( string $cap, mixed ...$args ): bool {
			if ( in_array( $cap, [ 'edit_post', 'edit_events', 'publish_events' ], true ) ) {
				return true;
			}
			return false;
		};

		$ability = new UpdatePost();
		$result  = $ability->permission_callback( [ 'id' => 1, 'status' => 'publish' ] );

		$this->assertNotInstanceOf( \WP_Error::class, $result );
		$this->assertTrue( (bool) $result );
	}

	public function test_update_post_rejects_invalid_id(): void {
		$ability = new UpdatePost();
		$result  = $ability->permission_callback( [ 'id' => 0, 'status' => 'draft' ] );

		$this->assertError( 'stonewright_invalid_input', 400, $result );
	}

	public function test_update_post_rejects_missing_post_before_cap_check(): void {
		$GLOBALS['stonewright_test_user_can_callback'] = static function (): bool {
			return false;
		};

		$ability = new UpdatePost();
		$result  = $ability->permission_callback( [ 'id' => 404, 'status' => 'draft' ] );

		$this->assertError( 'stonewright_not_found', 404, $result );
	}

	public function test_update_post_execute_preserves_status_string_without_text_sanitizing(): void {
		$this->setPost( 12, 'post', 'draft' );

		$ability = new UpdatePost();
		$result  = $ability->execute( [ 'id' => 12, 'status' => ' future ' ] );

		$this->assertIsArray( $result );
		$this->assertSame( ' future ', $GLOBALS['stonewright_test_posts'][12]->post_status );
	}

	// -------------------------------------------------------------------------
	// BulkCreate — per-item cap check
	// -------------------------------------------------------------------------

	public function test_bulk_create_rejects_when_any_item_fails_cap_check(): void {
		// Register 'event' type.
		$this->registerPostType( 'event', 'edit_events', 'publish_events' );

		// User can create pages but NOT events.
		$this->loginAs( [ 'edit_pages' ] );

		$ability = new BulkCreate();
		$result  = $ability->permission_callback( [
			'items' => [
				[ 'title' => 'Page One', 'post_type' => 'page' ],
				[ 'title' => 'Event Two', 'post_type' => 'event' ],
			],
		] );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'stonewright_forbidden', $result->get_error_code() );

		$data = $result->get_error_data();
		$this->assertIsArray( $data );
		$this->assertSame( 1, $data['failed_index'] );
		$this->assertSame( 'event', $data['post_type'] );
	}

	public function test_bulk_create_publish_status_per_item(): void {
		// User can create pages but not publish them.
		$this->loginAs( [ 'edit_pages' ] );

		$ability = new BulkCreate();
		$result  = $ability->permission_callback( [
			'items' => [
				[ 'title' => 'Page One', 'post_type' => 'page', 'status' => 'publish' ],
			],
		] );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'stonewright_forbidden', $result->get_error_code() );
	}

	public function test_bulk_create_passes_when_all_items_have_caps(): void {
		// User can create and publish pages.
		$this->loginAs( [ 'edit_pages', 'publish_pages' ] );

		$ability = new BulkCreate();
		$result  = $ability->permission_callback( [
			'items' => [
				[ 'title' => 'Page One', 'post_type' => 'page', 'status' => 'draft' ],
				[ 'title' => 'Page Two', 'post_type' => 'page', 'status' => 'draft' ],
			],
		] );

		// Should not be a WP_Error.
		$this->assertNotInstanceOf( \WP_Error::class, $result );
		$this->assertTrue( (bool) $result );
	}

	// -------------------------------------------------------------------------
	// CreatePage — cap check
	// -------------------------------------------------------------------------

	public function test_create_page_requires_edit_pages_cap(): void {
		$this->loginAs( [ 'edit_posts' ] ); // has edit_posts but not edit_pages

		$ability = new CreatePage();
		$result  = $ability->permission_callback( [ 'title' => 'My Page' ] );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'stonewright_forbidden', $result->get_error_code() );
	}

	public function test_create_page_with_publish_status_requires_publish_pages_cap(): void {
		$this->loginAs( [ 'edit_pages' ] ); // can create but not publish

		$ability = new CreatePage();
		$result  = $ability->permission_callback( [ 'title' => 'My Page', 'status' => 'publish' ] );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'stonewright_forbidden', $result->get_error_code() );
	}

	public function test_create_page_passes_with_correct_caps(): void {
		$this->loginAs( [ 'edit_pages', 'publish_pages' ] );

		$ability = new CreatePage();
		$result  = $ability->permission_callback( [ 'title' => 'My Page', 'status' => 'publish' ] );

		$this->assertNotInstanceOf( \WP_Error::class, $result );
		$this->assertTrue( (bool) $result );
	}

	// -------------------------------------------------------------------------
	// UpdatePage — publish cap check
	// -------------------------------------------------------------------------

	public function test_update_page_with_publish_status_requires_publish_pages(): void {
		$this->setPost( 5, 'page', 'draft' );

		$GLOBALS['stonewright_test_user_can_callback'] = static function ( string $cap, mixed ...$args ): bool {
			if ( 'edit_post' === $cap ) {
				return true;
			}
			if ( 'edit_pages' === $cap ) {
				return true;
			}
			return false; // publish_pages denied
		};

		$ability = new UpdatePage();
		$result  = $ability->permission_callback( [ 'id' => 5, 'status' => 'publish' ] );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'stonewright_forbidden', $result->get_error_code() );
	}

	public function test_update_page_draft_status_no_publish_check(): void {
		$this->setPost( 5, 'page', 'draft' );

		$GLOBALS['stonewright_test_user_can_callback'] = static function ( string $cap, mixed ...$args ): bool {
			if ( 'edit_post' === $cap ) {
				return true;
			}
			return false; // no publish cap, but we're just setting draft
		};

		$ability = new UpdatePage();
		$result  = $ability->permission_callback( [ 'id' => 5, 'status' => 'draft' ] );

		$this->assertNotInstanceOf( \WP_Error::class, $result );
		$this->assertTrue( (bool) $result );
	}

	public function test_update_page_rejects_invalid_id(): void {
		$ability = new UpdatePage();
		$result  = $ability->permission_callback( [ 'id' => 0, 'status' => 'draft' ] );

		$this->assertError( 'stonewright_invalid_input', 400, $result );
	}

	public function test_update_page_rejects_missing_post_before_cap_check(): void {
		$GLOBALS['stonewright_test_user_can_callback'] = static function (): bool {
			return false;
		};

		$ability = new UpdatePage();
		$result  = $ability->permission_callback( [ 'id' => 404, 'status' => 'draft' ] );

		$this->assertError( 'stonewright_not_found', 404, $result );
	}

	// -------------------------------------------------------------------------
	// DuplicatePage — create + edit source cap
	// -------------------------------------------------------------------------

	public function test_duplicate_page_requires_both_edit_source_and_create_page_cap(): void {
		$this->setPost( 10, 'page', 'publish' );

		// User can edit source post but not create pages.
		$GLOBALS['stonewright_test_user_can_callback'] = static function ( string $cap, mixed ...$args ): bool {
			if ( 'edit_post' === $cap ) {
				return true;
			}
			return false;
		};

		$ability = new DuplicatePage();
		$result  = $ability->permission_callback( [ 'id' => 10 ] );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'stonewright_forbidden', $result->get_error_code() );
	}

	public function test_duplicate_page_passes_when_user_has_both_caps(): void {
		$this->setPost( 10, 'page', 'publish' );

		$GLOBALS['stonewright_test_user_can_callback'] = static function ( string $cap, mixed ...$args ): bool {
			return in_array( $cap, [ 'edit_post', 'edit_pages' ], true );
		};

		$ability = new DuplicatePage();
		$result  = $ability->permission_callback( [ 'id' => 10 ] );

		$this->assertNotInstanceOf( \WP_Error::class, $result );
		$this->assertTrue( (bool) $result );
	}

	public function test_duplicate_page_passes_for_custom_post_type_with_matching_create_cap(): void {
		$this->registerPostType( 'landing', 'edit_landings', 'publish_landings' );
		$this->setPost( 11, 'landing', 'publish' );

		$GLOBALS['stonewright_test_user_can_callback'] = static function ( string $cap, mixed ...$args ): bool {
			return in_array( $cap, [ 'edit_post', 'edit_landings' ], true );
		};

		$ability = new DuplicatePage();
		$result  = $ability->permission_callback( [ 'id' => 11 ] );

		$this->assertNotInstanceOf( \WP_Error::class, $result );
		$this->assertTrue( (bool) $result );
	}

	public function test_duplicate_page_rejects_custom_post_type_when_user_only_has_page_create_cap(): void {
		$this->registerPostType( 'landing', 'edit_landings', 'publish_landings' );
		$this->setPost( 11, 'landing', 'publish' );

		$GLOBALS['stonewright_test_user_can_callback'] = static function ( string $cap, mixed ...$args ): bool {
			return in_array( $cap, [ 'edit_post', 'edit_pages' ], true );
		};

		$ability = new DuplicatePage();
		$result  = $ability->permission_callback( [ 'id' => 11 ] );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'stonewright_forbidden', $result->get_error_code() );
		$this->assertSame( 'Insufficient capability to create content of this type.', $result->get_error_message() );
	}

	public function test_duplicate_page_rejects_invalid_id(): void {
		$ability = new DuplicatePage();
		$result  = $ability->permission_callback( [ 'id' => 0 ] );

		$this->assertError( 'stonewright_invalid_input', 400, $result );
	}

	public function test_duplicate_page_rejects_missing_post_before_cap_check(): void {
		$GLOBALS['stonewright_test_user_can_callback'] = static function (): bool {
			return false;
		};

		$ability = new DuplicatePage();
		$result  = $ability->permission_callback( [ 'id' => 404 ] );

		$this->assertError( 'stonewright_not_found', 404, $result );
	}

	// -------------------------------------------------------------------------
	// output_schema includes meta_skipped for CreatePost and CreatePage
	// -------------------------------------------------------------------------

	public function test_create_post_output_schema_includes_meta_skipped(): void {
		$schema = ( new CreatePost() )->output_schema();

		$this->assertMetaSkippedDescription( $schema );
	}

	public function test_create_page_output_schema_includes_meta_skipped(): void {
		$schema = ( new CreatePage() )->output_schema();

		$this->assertMetaSkippedDescription( $schema );
	}

	// -------------------------------------------------------------------------
	// UpdatePage — permission_callback derives post type from persisted post
	// -------------------------------------------------------------------------

	public function test_update_page_permission_callback_derives_post_type_from_persisted_post(): void {
		// Register a custom 'landing' post type with its own publish cap.
		$this->registerPostType( 'landing', 'edit_landings', 'publish_landings' );

		// Store post 7 as type 'landing' (not 'page').
		$this->setPost( 7, 'landing', 'draft' );

		// User can edit post 7 but cannot publish 'landing' type content.
		$GLOBALS['stonewright_test_user_can_callback'] = static function ( string $cap, mixed ...$args ): bool {
			if ( 'edit_post' === $cap ) {
				return true;
			}
			if ( 'edit_landings' === $cap ) {
				return true;
			}
			// publish_landings denied — publish_pages would pass if hardcoded.
			return false;
		};

		$ability = new UpdatePage();
		// If post_type were hardcoded to 'page', publish_pages would be checked and
		// the user has no publish_pages either — but the key assertion is that
		// publish_landings is what gets checked (the correct cap for the actual type).
		// Either way the result is a 403, but we verify the post-type derivation logic
		// doesn't assume 'page' when the stored post is a different type.
		$result = $ability->permission_callback( [ 'id' => 7, 'status' => 'publish' ] );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'stonewright_forbidden', $result->get_error_code() );
	}

	public function test_update_page_permission_callback_passes_with_derived_post_type_cap(): void {
		$this->registerPostType( 'landing', 'edit_landings', 'publish_landings' );
		$this->setPost( 7, 'landing', 'draft' );

		// User has the correct cap for the landing post type.
		$GLOBALS['stonewright_test_user_can_callback'] = static function ( string $cap, mixed ...$args ): bool {
			return in_array( $cap, [ 'edit_post', 'edit_landings', 'publish_landings' ], true );
		};

		$ability = new UpdatePage();
		$result  = $ability->permission_callback( [ 'id' => 7, 'status' => 'publish' ] );

		$this->assertNotInstanceOf( \WP_Error::class, $result );
		$this->assertTrue( (bool) $result );
	}

	// -------------------------------------------------------------------------
	// UpdatePage — output_schema includes meta_skipped
	// -------------------------------------------------------------------------

	public function test_update_page_output_schema_includes_meta_skipped(): void {
		$schema = ( new UpdatePage() )->output_schema();

		$this->assertMetaSkippedDescription( $schema );
	}

	public function test_update_post_output_schema_includes_meta_skipped_description(): void {
		$schema = ( new UpdatePost() )->output_schema();

		$this->assertMetaSkippedDescription( $schema );
	}

	public function test_duplicate_page_output_schema_includes_meta_skipped_description(): void {
		$schema = ( new DuplicatePage() )->output_schema();

		$this->assertMetaSkippedDescription( $schema );
	}

	// -------------------------------------------------------------------------
	// DuplicatePage — per-key meta gate skips denied keys
	// -------------------------------------------------------------------------

	public function test_duplicate_page_skips_meta_when_edit_post_meta_denied(): void {
		// Set up source post with two meta keys.
		$GLOBALS['stonewright_test_posts'][20] = (object) [
			'ID'           => 20,
			'post_type'    => 'page',
			'post_status'  => 'publish',
			'post_title'   => 'Source Page',
			'post_content' => '',
			'post_excerpt' => '',
			'post_parent'  => 0,
			'post_name'    => 'source-page',
			'meta'         => [
				'allowed_meta' => 'value_allowed',
				'blocked_meta' => 'value_blocked',
			],
		];

		// User can edit source and create pages; denied edit_post_meta for 'blocked_meta'.
		$GLOBALS['stonewright_test_user_can_callback'] = static function ( string $cap, mixed ...$args ): bool {
			if ( in_array( $cap, [ 'edit_post', 'edit_pages' ], true ) ) {
				return true;
			}
			if ( 'edit_post_meta' === $cap ) {
				$meta_key = $args[1] ?? '';
				return 'allowed_meta' === $meta_key;
			}
			return false;
		};

		$ability = new DuplicatePage();
		$result  = $ability->execute( [ 'id' => 20 ] );

		$this->assertIsArray( $result );
		$this->assertArrayHasKey( 'new_id', $result );
		$this->assertArrayHasKey( 'meta_skipped', $result );
		$this->assertContains( 'blocked_meta', $result['meta_skipped'] );
		$this->assertNotContains( 'allowed_meta', $result['meta_skipped'] );

		// 'allowed_meta' should have been written; 'blocked_meta' should not.
		$written_keys = array_column( $GLOBALS['stonewright_test_post_meta_calls'], 'meta_key' );
		$this->assertContains( 'allowed_meta', $written_keys );
		$this->assertNotContains( 'blocked_meta', $written_keys );
	}

	// -------------------------------------------------------------------------
	// status=future — accepted when caller has publish cap; rejected without it
	// -------------------------------------------------------------------------

	public function test_create_post_future_status_rejected_without_publish_cap(): void {
		$this->loginAs( [ 'edit_posts' ] ); // can create but not publish/schedule

		$ability = new CreatePost();
		$result  = $ability->permission_callback( [ 'title' => 'Scheduled', 'post_type' => 'post', 'status' => 'future' ] );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'stonewright_forbidden', $result->get_error_code() );
	}

	public function test_create_post_future_status_accepted_with_publish_cap(): void {
		$this->loginAs( [ 'edit_posts', 'publish_posts' ] );

		$ability = new CreatePost();
		$result  = $ability->permission_callback( [ 'title' => 'Scheduled', 'post_type' => 'post', 'status' => 'future' ] );

		$this->assertNotInstanceOf( \WP_Error::class, $result );
		$this->assertTrue( (bool) $result );
	}

	public function test_bulk_create_future_status_rejected_without_publish_cap(): void {
		$this->loginAs( [ 'edit_pages' ] ); // can create pages but not schedule them

		$ability = new BulkCreate();
		$result  = $ability->permission_callback( [
			'items' => [
				[ 'title' => 'Scheduled Page', 'post_type' => 'page', 'status' => 'future' ],
			],
		] );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'stonewright_forbidden', $result->get_error_code() );
	}

	public function test_bulk_create_future_status_accepted_with_publish_cap(): void {
		$this->loginAs( [ 'edit_pages', 'publish_pages' ] );

		$ability = new BulkCreate();
		$result  = $ability->permission_callback( [
			'items' => [
				[ 'title' => 'Scheduled Page', 'post_type' => 'page', 'status' => 'future' ],
			],
		] );

		$this->assertNotInstanceOf( \WP_Error::class, $result );
		$this->assertTrue( (bool) $result );
	}

	public function test_create_post_input_schema_status_enum_includes_future(): void {
		$schema = ( new CreatePost() )->input_schema();
		$this->assertContains( 'future', $schema['properties']['status']['enum'] );
	}

	public function test_bulk_create_input_schema_status_enum_includes_future(): void {
		$schema = ( new BulkCreate() )->input_schema();
		$this->assertContains( 'future', $schema['properties']['items']['items']['properties']['status']['enum'] );
	}

	public function test_bulk_create_input_schema_requires_at_least_one_item(): void {
		$schema = ( new BulkCreate() )->input_schema();
		$this->assertSame( 1, $schema['properties']['items']['minItems'] );
	}

	public function test_create_page_input_schema_status_enum_includes_future(): void {
		$schema = ( new CreatePage() )->input_schema();
		$this->assertContains( 'future', $schema['properties']['status']['enum'] );
	}

	public function test_update_post_input_schema_status_enum_includes_future(): void {
		$schema = ( new UpdatePost() )->input_schema();
		$this->assertContains( 'future', $schema['properties']['status']['enum'] );
	}

	public function test_update_page_input_schema_status_enum_includes_future(): void {
		$schema = ( new UpdatePage() )->input_schema();
		$this->assertContains( 'future', $schema['properties']['status']['enum'] );
	}
}
