<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\Tests\Unit\Content;

use PHPUnit\Framework\TestCase;
use Stonewright\WpMcp\Abilities\Content\BulkCreate;
use Stonewright\WpMcp\Abilities\Content\BulkUpsertPosts;
use Stonewright\WpMcp\Abilities\Content\CreatePage;
use Stonewright\WpMcp\Abilities\Content\CreatePost;
use Stonewright\WpMcp\Abilities\Content\DuplicatePage;
use Stonewright\WpMcp\Abilities\Content\UpdatePage;
use Stonewright\WpMcp\Abilities\Content\UpdatePost;
use Stonewright\WpMcp\CustomCode\OwnsPostTypesInterface;
use Stonewright\WpMcp\CustomCode\ProviderInterface;
use Stonewright\WpMcp\CustomCode\ProviderRegistry;

/**
 * Content writes: slug lookups stay inside one post type, a scheduled status needs a real future date,
 * a bad item stops a bulk call before anything is written, protected template and Elementor meta is copied
 * through an allowlist, and a write without a stored snapshot does not run.
 *
 * @covers \Stonewright\WpMcp\Abilities\Content\BulkUpsertPosts
 * @covers \Stonewright\WpMcp\Abilities\Content\BulkCreate
 * @covers \Stonewright\WpMcp\Abilities\Content\CreatePage
 * @covers \Stonewright\WpMcp\Abilities\Content\CreatePost
 * @covers \Stonewright\WpMcp\Abilities\Content\DuplicatePage
 * @covers \Stonewright\WpMcp\Abilities\Content\UpdatePage
 * @covers \Stonewright\WpMcp\Abilities\Content\UpdatePost
 * @covers \Stonewright\WpMcp\Abilities\Content\PostDate
 * @covers \Stonewright\WpMcp\Abilities\Content\PostTypeGuard
 * @covers \Stonewright\WpMcp\Abilities\Content\ProtectedMeta
 */
final class ContentWriteFixesTest extends TestCase {

	private const FUTURE = '2099-06-01T09:30:00';

	protected function setUp(): void {
		$GLOBALS['stonewright_test_user_logged_in']        = true;
		$GLOBALS['stonewright_test_user_caps']             = [ 'edit_posts' => true, 'publish_posts' => true, 'edit_pages' => true ];
		// Every capability except the protected-meta gate, which core denies for a key that starts with an underscore.
		$GLOBALS['stonewright_test_user_can_callback']     = static function ( string $cap, mixed ...$args ): bool {
			if ( 'edit_post_meta' === $cap ) {
				return ! str_starts_with( (string) ( $args[1] ?? '' ), '_' );
			}
			return true;
		};
		$GLOBALS['stonewright_test_options']               = [ 'stonewright_mode' => 'development' ];
		$GLOBALS['stonewright_test_post_types']            = [
			'page'             => (object) [ 'cap' => (object) [ 'create_posts' => 'edit_pages', 'publish_posts' => 'publish_pages' ] ],
			'post'             => (object) [ 'cap' => (object) [ 'create_posts' => 'edit_posts', 'publish_posts' => 'publish_posts' ] ],
			'homepage_section' => (object) [ 'cap' => (object) [ 'create_posts' => 'edit_posts', 'publish_posts' => 'publish_posts' ] ],
		];
		$GLOBALS['stonewright_test_posts']                 = [];
		$GLOBALS['stonewright_test_post_meta_calls']       = [];
		$GLOBALS['stonewright_test_next_post_id']          = 1001;
		$GLOBALS['stonewright_test_inserted_posts']        = [];
		$GLOBALS['stonewright_test_wp_insert_post_return'] = null;
		$GLOBALS['stonewright_test_wp_update_post_return'] = null;
		$GLOBALS['stonewright_test_wp_insert_post_calls']  = [];
		$GLOBALS['stonewright_test_wp_update_post_calls']  = [];
		unset( $GLOBALS['stonewright_test_update_post_meta_returns'], $GLOBALS['stonewright_test_timezone'] );
		ProviderRegistry::reset_for_tests();
	}

	protected function tearDown(): void {
		$GLOBALS['stonewright_test_user_can_callback']     = null;
		$GLOBALS['stonewright_test_user_caps']             = [];
		$GLOBALS['stonewright_test_options']               = [];
		$GLOBALS['stonewright_test_post_types']            = [];
		$GLOBALS['stonewright_test_posts']                 = [];
		$GLOBALS['stonewright_test_wp_insert_post_calls']  = [];
		$GLOBALS['stonewright_test_wp_update_post_calls']  = [];
		unset( $GLOBALS['stonewright_test_update_post_meta_returns'], $GLOBALS['stonewright_test_timezone'] );
		ProviderRegistry::reset_for_tests();
	}

	private function post( int $id, string $type, string $slug, string $status = 'draft', array $meta = [] ): void {
		$GLOBALS['stonewright_test_posts'][ $id ] = (object) [
			'ID'           => $id,
			'post_type'    => $type,
			'post_status'  => $status,
			'post_title'   => 'Title ' . $id,
			'post_content' => 'Body',
			'post_excerpt' => '',
			'post_parent'  => 0,
			'post_name'    => $slug,
			'post_date'    => '2020-01-01 00:00:00',
			'post_date_gmt' => '2020-01-01 00:00:00',
			'meta'         => $meta,
		];
	}

	// --- P0: a slug lookup never matches a post of another type -------------------------------------

	public function test_bulk_upsert_does_not_turn_an_attachment_into_a_post(): void {
		$this->post( 77, 'attachment', 'hero', 'inherit' );

		$result = ( new BulkUpsertPosts() )->execute(
			[
				'post_type' => 'post',
				'items'     => [ [ 'slug' => 'hero', 'title' => 'Hero' ] ],
			]
		);

		self::assertIsArray( $result );
		self::assertSame( 1, $result['created'] );
		self::assertSame( 0, $result['updated'] );
		self::assertSame( [], $GLOBALS['stonewright_test_wp_update_post_calls'] );
		self::assertSame( 'attachment', $GLOBALS['stonewright_test_posts'][77]->post_type );
		self::assertNotSame( 77, $result['items'][0]['id'] );
	}

	public function test_bulk_upsert_still_updates_a_post_of_the_same_type(): void {
		$this->post( 42, 'post', 'hero' );

		$result = ( new BulkUpsertPosts() )->execute(
			[
				'post_type' => 'post',
				'items'     => [ [ 'slug' => 'hero', 'title' => 'Hero New' ] ],
			]
		);

		self::assertIsArray( $result );
		self::assertSame( 1, $result['updated'] );
		self::assertSame( 42, $result['items'][0]['id'] );
	}

	// --- P1: validate every item before writing any -------------------------------------------------

	public function test_bulk_upsert_writes_nothing_when_a_later_item_is_invalid(): void {
		$result = ( new BulkUpsertPosts() )->execute(
			[
				'post_type' => 'post',
				'items'     => [
					[ 'slug' => 'good', 'title' => 'Good' ],
					[ 'slug' => 'bad', 'title' => '' ],
				],
			]
		);

		self::assertInstanceOf( \WP_Error::class, $result );
		self::assertSame( 'stonewright_invalid_content_item', $result->get_error_code() );
		self::assertSame( 1, $result->get_error_data()['failed_index'] );
		self::assertSame( [], $GLOBALS['stonewright_test_wp_insert_post_calls'] );
		self::assertSame( [], $GLOBALS['stonewright_test_wp_update_post_calls'] );
	}

	public function test_bulk_upsert_writes_nothing_when_a_later_item_schedules_without_a_date(): void {
		$result = ( new BulkUpsertPosts() )->execute(
			[
				'post_type' => 'post',
				'items'     => [
					[ 'slug' => 'good', 'title' => 'Good' ],
					[ 'slug' => 'later', 'title' => 'Later', 'status' => 'future' ],
				],
			]
		);

		self::assertInstanceOf( \WP_Error::class, $result );
		self::assertSame( 'stonewright_future_date_required', $result->get_error_code() );
		self::assertSame( [], $GLOBALS['stonewright_test_wp_insert_post_calls'] );
	}

	public function test_bulk_upsert_refuses_an_internal_post_type(): void {
		$GLOBALS['stonewright_test_post_types']['attachment'] = (object) [ 'cap' => (object) [ 'create_posts' => 'upload_files', 'publish_posts' => 'publish_posts' ] ];

		$result = ( new BulkUpsertPosts() )->execute(
			[
				'post_type' => 'attachment',
				'items'     => [ [ 'slug' => 'x', 'title' => 'X' ] ],
			]
		);

		self::assertInstanceOf( \WP_Error::class, $result );
		self::assertSame( 'stonewright_invalid_post_type', $result->get_error_code() );
		self::assertSame( [], $GLOBALS['stonewright_test_wp_insert_post_calls'] );
	}

	// --- P1: status future needs a future date ------------------------------------------------------

	public function test_future_status_without_a_date_is_refused_by_every_create_and_update_ability(): void {
		$this->post( 50, 'page', 'p' );
		$this->post( 51, 'post', 'q' );

		$cases = [
			[ new CreatePage(), [ 'title' => 'T', 'status' => 'future' ] ],
			[ new CreatePost(), [ 'title' => 'T', 'status' => 'future' ] ],
			[ new UpdatePage(), [ 'id' => 50, 'status' => 'future' ] ],
			[ new UpdatePost(), [ 'id' => 51, 'status' => 'future' ] ],
			[ new BulkCreate(), [ 'items' => [ [ 'title' => 'T', 'status' => 'future' ] ] ] ],
		];
		foreach ( $cases as [ $ability, $args ] ) {
			$result = $ability->execute( $args );
			self::assertInstanceOf( \WP_Error::class, $result, get_class( $ability ) );
			self::assertSame( 'stonewright_future_date_required', $result->get_error_code(), get_class( $ability ) );
		}
		self::assertSame( [], $GLOBALS['stonewright_test_wp_insert_post_calls'] );
		self::assertSame( [], $GLOBALS['stonewright_test_wp_update_post_calls'] );
	}

	public function test_future_status_with_a_past_date_is_refused(): void {
		$result = ( new CreatePost() )->execute( [ 'title' => 'T', 'status' => 'future', 'date' => '2001-01-01T00:00:00' ] );

		self::assertInstanceOf( \WP_Error::class, $result );
		self::assertSame( 'stonewright_future_date_required', $result->get_error_code() );
	}

	public function test_an_unreadable_date_is_refused(): void {
		$result = ( new CreatePost() )->execute( [ 'title' => 'T', 'date' => 'next tuesday' ] );

		self::assertInstanceOf( \WP_Error::class, $result );
		self::assertSame( 'stonewright_invalid_date', $result->get_error_code() );
	}

	public function test_create_post_schedules_with_a_site_timezone_date(): void {
		$GLOBALS['stonewright_test_timezone'] = 'Europe/Bucharest';

		$result = ( new CreatePost() )->execute( [ 'title' => 'T', 'status' => 'future', 'date' => self::FUTURE ] );

		self::assertIsArray( $result );
		$payload = $GLOBALS['stonewright_test_wp_insert_post_calls'][0];
		self::assertSame( 'future', $payload['post_status'] );
		self::assertSame( '2099-06-01 09:30:00', $payload['post_date'] );
		// Bucharest is UTC+3 in June, so the GMT date is three hours earlier.
		self::assertSame( '2099-06-01 06:30:00', $payload['post_date_gmt'] );
	}

	public function test_a_date_with_an_offset_is_converted_to_the_site_timezone(): void {
		$result = ( new CreatePage() )->execute( [ 'title' => 'T', 'status' => 'future', 'date' => '2099-06-01T09:30:00+02:00' ] );

		self::assertIsArray( $result );
		$payload = $GLOBALS['stonewright_test_wp_insert_post_calls'][0];
		self::assertSame( '2099-06-01 07:30:00', $payload['post_date'] );
		self::assertSame( '2099-06-01 07:30:00', $payload['post_date_gmt'] );
	}

	public function test_update_post_sets_the_date_and_asks_core_to_edit_it(): void {
		$this->post( 51, 'post', 'q' );

		$result = ( new UpdatePost() )->execute( [ 'id' => 51, 'status' => 'future', 'date' => self::FUTURE ] );

		self::assertIsArray( $result );
		$payload = $GLOBALS['stonewright_test_wp_update_post_calls'][0];
		self::assertSame( 'future', $payload['post_status'] );
		self::assertSame( '2099-06-01 09:30:00', $payload['post_date'] );
		self::assertTrue( $payload['edit_date'] );
	}

	public function test_update_post_keeps_a_stored_future_date_when_only_the_status_is_sent(): void {
		$this->post( 51, 'post', 'q', 'draft' );
		$GLOBALS['stonewright_test_posts'][51]->post_date_gmt = '2099-01-01 00:00:00';

		$result = ( new UpdatePost() )->execute( [ 'id' => 51, 'status' => 'future' ] );

		self::assertIsArray( $result );
	}

	public function test_bulk_create_and_bulk_upsert_carry_the_date_per_item(): void {
		$bulk = ( new BulkCreate() )->execute( [ 'items' => [ [ 'title' => 'A', 'post_type' => 'post', 'status' => 'future', 'date' => self::FUTURE ] ] ] );
		self::assertIsArray( $bulk );
		self::assertTrue( $bulk['ok'] );
		self::assertSame( '2099-06-01 09:30:00', $GLOBALS['stonewright_test_wp_insert_post_calls'][0]['post_date'] );

		$upsert = ( new BulkUpsertPosts() )->execute(
			[
				'post_type' => 'post',
				'items'     => [ [ 'slug' => 'b', 'title' => 'B', 'status' => 'future', 'date' => self::FUTURE ] ],
			]
		);
		self::assertIsArray( $upsert );
		self::assertSame( '2099-06-01 09:30:00', $GLOBALS['stonewright_test_wp_insert_post_calls'][1]['post_date'] );
	}

	public function test_the_date_is_part_of_every_schema(): void {
		foreach ( [ new CreatePage(), new CreatePost(), new UpdatePage(), new UpdatePost() ] as $ability ) {
			self::assertArrayHasKey( 'date', $ability->input_schema()['properties'], get_class( $ability ) );
		}
		self::assertArrayHasKey( 'date', ( new BulkCreate() )->input_schema()['properties']['items']['items']['properties'] );
		self::assertArrayHasKey( 'date', ( new BulkUpsertPosts() )->input_schema()['properties']['items']['items']['properties'] );
	}

	// --- P1: update-page and update-post ------------------------------------------------------------

	public function test_update_page_refuses_a_post_type_owned_by_a_code_provider(): void {
		$this->post( 60, 'synthetic-code-type', 'snippet' );
		ProviderRegistry::set_for_tests( [ 'synthetic-provider' => $this->ownedProvider() ] );

		$result = ( new UpdatePage() )->permission_callback( [ 'id' => 60, 'content' => '<?php run();' ] );

		self::assertInstanceOf( \WP_Error::class, $result );
		self::assertSame( 'stonewright_custom_code_provider_required', $result->get_error_code() );
	}

	public function test_update_page_refuses_when_the_snapshot_cannot_be_stored(): void {
		$this->post( 50, 'page', 'p' );
		$GLOBALS['stonewright_test_update_post_meta_returns'] = [ '_stonewright_backups' => true ];

		$result = ( new UpdatePage() )->execute( [ 'id' => 50, 'title' => 'New' ] );

		self::assertInstanceOf( \WP_Error::class, $result );
		self::assertSame( 'stonewright_backup_failed', $result->get_error_code() );
		self::assertSame( [], $GLOBALS['stonewright_test_wp_update_post_calls'] );
	}

	public function test_update_post_refuses_when_the_snapshot_cannot_be_stored(): void {
		$this->post( 51, 'post', 'q' );
		$GLOBALS['stonewright_test_update_post_meta_returns'] = [ '_stonewright_backups' => true ];

		$result = ( new UpdatePost() )->execute( [ 'id' => 51, 'title' => 'New' ] );

		self::assertInstanceOf( \WP_Error::class, $result );
		self::assertSame( 'stonewright_backup_failed', $result->get_error_code() );
		self::assertSame( [], $GLOBALS['stonewright_test_wp_update_post_calls'] );
	}

	public function test_update_post_refuses_the_id_of_a_revision(): void {
		$this->post( 52, 'revision', '51-revision-v1', 'inherit' );

		$permission = ( new UpdatePost() )->permission_callback( [ 'id' => 52, 'title' => 'x' ] );
		self::assertInstanceOf( \WP_Error::class, $permission );
		self::assertSame( 'stonewright_invalid_post_type', $permission->get_error_code() );

		$result = ( new UpdatePost() )->execute( [ 'id' => 52, 'title' => 'x' ] );
		self::assertInstanceOf( \WP_Error::class, $result );
		self::assertSame( 'stonewright_invalid_post_type', $result->get_error_code() );
		self::assertSame( [], $GLOBALS['stonewright_test_wp_update_post_calls'] );
	}

	// --- P1: protected meta through an allowlist ----------------------------------------------------

	public function test_the_template_field_is_written_although_the_key_is_protected(): void {
		$create = ( new CreatePage() )->execute( [ 'title' => 'T', 'template' => 'elementor_canvas' ] );
		self::assertIsArray( $create );
		self::assertSame( [], $create['meta_skipped'] );
		self::assertSame( 'elementor_canvas', get_post_meta( $create['id'], '_wp_page_template', true ) );

		$this->post( 50, 'page', 'p' );
		$update = ( new UpdatePage() )->execute( [ 'id' => 50, 'template' => 'elementor_header_footer' ] );
		self::assertIsArray( $update );
		self::assertSame( [], $update['meta_skipped'] );
		self::assertSame( 'elementor_header_footer', get_post_meta( 50, '_wp_page_template', true ) );
	}

	public function test_duplicate_copies_the_allowlisted_protected_meta_and_skips_the_rest(): void {
		$json = '{"id":"a","settings":{"title":"He said \"hi\""}}';
		$this->post(
			40,
			'page',
			'source',
			'publish',
			[
				'_elementor_data'      => $json,
				'_elementor_edit_mode' => 'builder',
				'_wp_page_template'    => 'elementor_canvas',
				'_thumbnail_id'        => '12',
				'_edit_lock'           => '1:1',
				'_secret_internal'     => 'x',
				'public_field'         => 'y',
			]
		);

		$result = ( new DuplicatePage() )->execute( [ 'id' => 40 ] );

		self::assertIsArray( $result );
		$copied = [];
		foreach ( $GLOBALS['stonewright_test_post_meta_calls'] as $call ) {
			if ( in_array( $call['action'], [ 'add', 'update' ], true ) && (int) $call['post_id'] === $result['new_id'] ) {
				$copied[ $call['meta_key'] ] = $call['value'];
			}
		}
		self::assertSame( $json, wp_unslash( $copied['_elementor_data'] ) );
		self::assertSame( 'builder', wp_unslash( $copied['_elementor_edit_mode'] ) );
		self::assertSame( 'elementor_canvas', wp_unslash( $copied['_wp_page_template'] ) );
		self::assertSame( '12', wp_unslash( $copied['_thumbnail_id'] ) );
		self::assertSame( 'y', wp_unslash( $copied['public_field'] ) );
		self::assertArrayNotHasKey( '_secret_internal', $copied );
		self::assertArrayNotHasKey( '_edit_lock', $copied );
		self::assertSame( [ '_secret_internal' ], $result['meta_skipped'] );
	}

	public function test_duplicate_does_not_write_protected_meta_without_edit_access_to_the_copy(): void {
		$this->post( 40, 'page', 'source', 'publish', [ '_elementor_data' => '[]' ] );
		$GLOBALS['stonewright_test_user_can_callback'] = static function ( string $cap, mixed ...$args ): bool {
			if ( 'edit_post' === $cap ) {
				return 40 === (int) ( $args[0] ?? 0 );
			}
			return 'edit_post_meta' !== $cap;
		};

		$result = ( new DuplicatePage() )->execute( [ 'id' => 40 ] );

		self::assertIsArray( $result );
		self::assertSame( [ '_elementor_data' ], $result['meta_skipped'] );
	}

	// --- P2: post type allowlist and revisions ------------------------------------------------------

	public function test_create_post_and_bulk_create_refuse_internal_post_types(): void {
		foreach ( [ 'attachment', 'revision', 'nav_menu_item' ] as $type ) {
			$GLOBALS['stonewright_test_post_types'][ $type ] = (object) [ 'cap' => (object) [ 'create_posts' => 'edit_posts', 'publish_posts' => 'publish_posts' ] ];
			$permission = ( new CreatePost() )->permission_callback( [ 'title' => 'T', 'post_type' => $type ] );
			self::assertInstanceOf( \WP_Error::class, $permission, $type );
			self::assertSame( 'stonewright_invalid_post_type', $permission->get_error_code(), $type );

			$result = ( new CreatePost() )->execute( [ 'title' => 'T', 'post_type' => $type ] );
			self::assertInstanceOf( \WP_Error::class, $result, $type );

			$bulk = ( new BulkCreate() )->permission_callback( [ 'items' => [ [ 'title' => 'T', 'post_type' => $type ] ] ] );
			self::assertInstanceOf( \WP_Error::class, $bulk, $type );
			self::assertSame( 'stonewright_invalid_post_type', $bulk->get_error_code(), $type );
		}
		self::assertSame( [], $GLOBALS['stonewright_test_wp_insert_post_calls'] );
	}

	public function test_create_post_refuses_an_unregistered_post_type(): void {
		$result = ( new CreatePost() )->permission_callback( [ 'title' => 'T', 'post_type' => 'not_registered' ] );

		self::assertInstanceOf( \WP_Error::class, $result );
		self::assertSame( 'stonewright_invalid_post_type', $result->get_error_code() );
	}

	private function ownedProvider(): ProviderInterface {
		return new class() implements ProviderInterface, OwnsPostTypesInterface {
			public function id(): string {
				return 'synthetic-provider';
			}

			public function label(): string {
				return 'Synthetic';
			}

			/** @return list<string> */
			public function owned_post_types(): array {
				return [ 'synthetic-code-type' ];
			}

			public function discover(): array {
				return [
					'id'           => 'synthetic-provider',
					'label'        => 'Synthetic',
					'available'    => true,
					'active'       => true,
					'version'      => '0.0.0',
					'supported'    => true,
					'plugin_file'  => '',
					'capabilities' => [],
					'notes'        => '',
				];
			}

			public function list( array $args = [] ) {
				return [ 'ok' => true, 'items' => [] ];
			}

			public function read( string $target_id ) {
				return new \WP_Error( 'stonewright_unsupported', 'unused' );
			}

			public function dry_run( array $args ) {
				return new \WP_Error( 'stonewright_unsupported', 'unused' );
			}

			public function apply( array $args ) {
				return new \WP_Error( 'stonewright_unsupported', 'unused' );
			}

			public function verify( array $args ) {
				return new \WP_Error( 'stonewright_unsupported', 'unused' );
			}

			public function rollback( array $args ) {
				return new \WP_Error( 'stonewright_unsupported', 'unused' );
			}
		};
	}
}
