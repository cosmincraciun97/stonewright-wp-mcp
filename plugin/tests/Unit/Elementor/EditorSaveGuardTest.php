<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\Tests\Unit\Elementor;

use PHPUnit\Framework\TestCase;
use Stonewright\WpMcp\Elementor\EditorSaveGuard;
use Stonewright\WpMcp\Elementor\Write\TreeHasher;
use Stonewright\WpMcp\Security\Adapters\PostAdapter;
use Stonewright\WpMcp\Security\Backup;
use Stonewright\WpMcp\Support\ElementorData;
use Stonewright\WpMcp\Tests\Unit\Core\Fixtures\UninstallWpdb;

require_once dirname( __DIR__, 2 ) . '/Support/post-adapter-wp-stubs.php';

/**
 * @covers \Stonewright\WpMcp\Elementor\EditorSaveGuard
 */
final class EditorSaveGuardTest extends TestCase {

	protected function setUp(): void {
		$GLOBALS['stonewright_test_user_logged_in']  = true;
		$GLOBALS['stonewright_test_current_user_id'] = 1;
		$GLOBALS['stonewright_test_options']         = [];
		$GLOBALS['stonewright_test_transients']      = [];
		$GLOBALS['stonewright_test_transient_ttls']  = [];
		$GLOBALS['stonewright_test_actions']         = [];
		$GLOBALS['stonewright_test_posts'][ 501 ]    = (object) [
			'ID'           => 501,
			'post_type'    => 'page',
			'post_status'  => 'draft',
			'post_title'   => 'Editor target',
			'post_content' => '',
			'post_excerpt' => '',
			'meta'         => [
				'_elementor_data'      => '[{"id":"root","elType":"container","settings":{"container_type":"flex"},"elements":[]}]',
				'_elementor_edit_mode' => 'builder',
			],
		];
		EditorSaveGuard::reset_for_tests();
	}

	protected function tearDown(): void {
		EditorSaveGuard::reset_for_tests();
		unset( $GLOBALS['stonewright_test_posts'][ 501 ] );
		$GLOBALS['stonewright_test_options']         = [];
		$GLOBALS['stonewright_test_actions']         = [];
		$GLOBALS['stonewright_test_user_logged_in']  = false;
		$GLOBALS['stonewright_test_current_user_id'] = 0;
		unset( $_REQUEST['editor_post_id'], $_REQUEST['post'] );
	}

	public function test_register_hooks_editor_init_and_document_save(): void {
		EditorSaveGuard::register();
		self::assertNotEmpty( $GLOBALS['stonewright_test_actions']['elementor/editor/init'] ?? [] );
		self::assertNotEmpty( $GLOBALS['stonewright_test_actions']['elementor/document/before_save'] ?? [] );
		self::assertNotEmpty( $GLOBALS['stonewright_test_actions']['elementor/document/after_save'] ?? [] );
	}

	public function test_stale_editor_save_does_not_overwrite_a_later_mcp_write(): void {
		$baseline = EditorSaveGuard::capture( 501 );
		self::assertIsArray( $baseline );
		$original = ElementorData::read( 501 );

		self::write_tree(
			501,
			[
				[
					'id'       => 'root',
					'elType'   => 'container',
					'settings' => [ 'container_type' => 'flex', 'mcp_marker' => 'server' ],
					'elements' => [],
				],
			]
		);

		$error = EditorSaveGuard::assert_persist( 501 );
		self::assertInstanceOf( \WP_Error::class, $error );
		self::assertSame( 'stonewright_elementor_stale_editor', $error->get_error_code() );
		self::assertSame( 'draft', get_post_status( 501 ) );
		self::assertSame( 'server', ElementorData::read( 501 )[0]['settings']['mcp_marker'] ?? null );
		self::assertNotSame( TreeHasher::hash( $original ), TreeHasher::hash( ElementorData::read( 501 ) ) );
		self::assertSame( 'not_attempted', $error->get_error_data()['rollback_status'] ?? null );
	}

	public function test_server_change_between_precheck_and_persist_is_blocked(): void {
		EditorSaveGuard::capture( 501 );
		self::assertNull( EditorSaveGuard::assert_persist( 501 ) );

		self::write_tree(
			501,
			[
				[
					'id'       => 'root',
					'elType'   => 'container',
					'settings' => [ 'container_type' => 'flex', 'mcp_marker' => 'between' ],
					'elements' => [],
				],
			]
		);

		$error = EditorSaveGuard::assert_persist( 501 );
		self::assertInstanceOf( \WP_Error::class, $error );
		self::assertSame( 'between', ElementorData::read( 501 )[0]['settings']['mcp_marker'] ?? null );
		self::assertSame( 'draft', get_post_status( 501 ) );
	}

	public function test_visual_command_and_native_button_share_the_server_guard(): void {
		EditorSaveGuard::capture( 501 );
		self::write_tree(
			501,
			[
				[
					'id'       => 'root',
					'elType'   => 'container',
					'settings' => [ 'container_type' => 'flex', 'mcp_marker' => 'mcp' ],
					'elements' => [],
				],
			]
		);

		$document = new class() {
			public function get_main_id(): int {
				return 501;
			}
		};

		$visual = null;
		try {
			EditorSaveGuard::on_before_save( $document, [ 'source' => 'document/save/default' ] );
		} catch ( \RuntimeException $caught ) {
			$visual = $caught;
		}
		self::assertInstanceOf( \RuntimeException::class, $visual );

		$native = null;
		try {
			EditorSaveGuard::on_before_save( $document, [ 'source' => 'editor-publish-button' ] );
		} catch ( \RuntimeException $caught ) {
			$native = $caught;
		}
		self::assertInstanceOf( \RuntimeException::class, $native );
		self::assertSame( 'mcp', ElementorData::read( 501 )[0]['settings']['mcp_marker'] ?? null );
		self::assertSame( 'draft', get_post_status( 501 ) );
	}

	public function test_incoming_save_payload_is_not_used_as_the_server_baseline(): void {
		EditorSaveGuard::capture( 501 );
		self::write_tree(
			501,
			[
				[
					'id'       => 'root',
					'elType'   => 'container',
					'settings' => [ 'container_type' => 'flex', 'mcp_marker' => 'server' ],
					'elements' => [],
				],
			]
		);
		$document = new class() {
			public function get_main_id(): int {
				return 501;
			}
		};
		try {
			EditorSaveGuard::on_before_save(
				$document,
				[ 'elements' => [ [ 'id' => 'local-dirty-tree' ] ] ]
			);
			self::fail( 'Stale persist must throw.' );
		} catch ( \RuntimeException $caught ) {
			self::assertStringContainsString( 'changed on the server', $caught->getMessage() );
		}
		self::assertSame( 'server', ElementorData::read( 501 )[0]['settings']['mcp_marker'] ?? null );
	}

	public function test_matching_baseline_allows_persist_and_keeps_draft(): void {
		EditorSaveGuard::capture( 501 );
		self::assertNull( EditorSaveGuard::assert_persist( 501 ) );
		self::assertSame( 'draft', get_post_status( 501 ) );
	}

	public function test_missing_baseline_allows_vanilla_elementor_persist(): void {
		self::assertNull( EditorSaveGuard::assert_persist( 501 ) );
	}

	public function test_successful_save_refreshes_baseline(): void {
		EditorSaveGuard::capture( 501 );
		self::write_tree(
			501,
			[
				[
					'id'       => 'root',
					'elType'   => 'container',
					'settings' => [ 'container_type' => 'flex', 'mcp_marker' => 'saved' ],
					'elements' => [],
				],
			]
		);
		$document = new class() {
			public function get_main_id(): int {
				return 501;
			}
		};
		EditorSaveGuard::on_after_save( $document, [] );
		self::assertNull( EditorSaveGuard::assert_persist( 501 ) );
		self::assertSame( 'draft', get_post_status( 501 ) );
	}

	public function test_capture_from_editor_request_id(): void {
		$_REQUEST['editor_post_id'] = '501';
		EditorSaveGuard::capture_from_editor();
		self::assertNull( EditorSaveGuard::assert_persist( 501 ) );
		self::write_tree(
			501,
			[
				[
					'id'       => 'root',
					'elType'   => 'container',
					'settings' => [ 'container_type' => 'grid' ],
					'elements' => [],
				],
			]
		);
		self::assertInstanceOf( \WP_Error::class, EditorSaveGuard::assert_persist( 501 ) );
	}

	public function test_refreshing_after_a_restore_makes_every_users_baseline_match_the_document(): void {
		foreach ( [ 1, 2, 3 ] as $user ) {
			$GLOBALS['stonewright_test_current_user_id'] = $user;
			EditorSaveGuard::capture( 501 );
		}
		$GLOBALS['stonewright_test_posts'][ 502 ] = (object) [ 'ID' => 502, 'post_type' => 'page', 'post_status' => 'draft', 'post_title' => 'Other', 'post_content' => '', 'post_excerpt' => '', 'meta' => [ '_elementor_data' => '[]' ] ];
		EditorSaveGuard::capture( 502 );
		self::write_tree( 501, [ [ 'id' => 'root', 'elType' => 'container', 'settings' => [ 'restored' => 'yes' ], 'elements' => [] ] ] );
		self::write_tree( 502, [ [ 'id' => 'other', 'elType' => 'container', 'settings' => [], 'elements' => [] ] ] );
		$GLOBALS['stonewright_test_current_user_id'] = 1;
		self::assertInstanceOf( \WP_Error::class, EditorSaveGuard::assert_persist( 501 ), 'the restore left every baseline behind' );

		EditorSaveGuard::refresh_all_users( 501 );

		foreach ( [ 1, 2, 3 ] as $user ) {
			$GLOBALS['stonewright_test_current_user_id'] = $user;
			self::assertNull( EditorSaveGuard::assert_persist( 501 ), 'user ' . $user );
		}
		self::assertInstanceOf( \WP_Error::class, EditorSaveGuard::assert_persist( 502 ), 'another post keeps its baseline' );
		unset( $GLOBALS['stonewright_test_posts'][ 502 ] );
	}

	public function test_a_refresh_still_catches_a_later_change_and_creates_no_baseline_of_its_own(): void {
		EditorSaveGuard::refresh_all_users( 501 );
		self::assertFalse( get_transient( 'stonewright_elementor_editor_baseline_501_1' ), 'no baseline existed, so none is made' );

		EditorSaveGuard::capture( 501 );
		self::write_tree( 501, [ [ 'id' => 'root', 'elType' => 'container', 'settings' => [ 'restored' => 'yes' ], 'elements' => [] ] ] );
		EditorSaveGuard::refresh_all_users( 501 );
		self::write_tree( 501, [ [ 'id' => 'root', 'elType' => 'container', 'settings' => [ 'later' => 'change' ], 'elements' => [] ] ] );

		self::assertInstanceOf( \WP_Error::class, EditorSaveGuard::assert_persist( 501 ), 'a change after the refresh is still a stale editor' );
	}

	public function test_a_legacy_baseline_of_the_current_user_is_refreshed_too(): void {
		$GLOBALS['stonewright_test_options']['stonewright_elementor_editor_baseline_501_1'] = [ 'hash' => str_repeat( 'a', 64 ), 'post_status' => 'draft', 'user_id' => 1, 'captured_at' => 1 ];

		EditorSaveGuard::refresh_all_users( 501 );

		self::assertNull( EditorSaveGuard::assert_persist( 501 ) );
		self::assertArrayNotHasKey( 'stonewright_elementor_editor_baseline_501_1', $GLOBALS['stonewright_test_options'], 'the option row is gone once the baseline is a transient' );
		self::assertIsArray( get_transient( 'stonewright_elementor_editor_baseline_501_1' ) );
	}

	public function test_a_legacy_baseline_still_guards_the_save_and_moves_to_a_transient_on_first_read(): void {
		EditorSaveGuard::capture( 501 );
		$baseline = get_transient( 'stonewright_elementor_editor_baseline_501_1' );
		delete_transient( 'stonewright_elementor_editor_baseline_501_1' );
		$GLOBALS['stonewright_test_options']['stonewright_elementor_editor_baseline_501_1'] = $baseline;
		self::write_tree( 501, [ [ 'id' => 'root', 'elType' => 'container', 'settings' => [ 'later' => 'change' ], 'elements' => [] ] ] );

		self::assertInstanceOf( \WP_Error::class, EditorSaveGuard::assert_persist( 501 ), 'an editor opened before the update is still refused' );

		self::assertArrayNotHasKey( 'stonewright_elementor_editor_baseline_501_1', $GLOBALS['stonewright_test_options'] );
		self::assertSame( EditorSaveGuard::BASELINE_TTL, $GLOBALS['stonewright_test_transient_ttls']['stonewright_elementor_editor_baseline_501_1'] ?? null );
	}

	public function test_capturing_over_a_legacy_baseline_removes_the_option_so_an_expired_transient_is_not_followed_by_a_stale_one(): void {
		$GLOBALS['stonewright_test_options']['stonewright_elementor_editor_baseline_501_1']     = [ 'hash' => str_repeat( 'a', 64 ), 'post_status' => 'draft', 'user_id' => 1, 'captured_at' => 1 ];
		$GLOBALS['stonewright_test_options']['stonewright_elementor_editor_baseline_users_501'] = [ 1 ];

		EditorSaveGuard::capture( 501 );

		self::assertArrayNotHasKey( 'stonewright_elementor_editor_baseline_501_1', $GLOBALS['stonewright_test_options'] );
		self::assertArrayNotHasKey( 'stonewright_elementor_editor_baseline_users_501', $GLOBALS['stonewright_test_options'] );
		delete_transient( 'stonewright_elementor_editor_baseline_501_1' );
		self::assertNull( EditorSaveGuard::assert_persist( 501 ), 'an expired baseline counts as missing, not as the old option' );
	}

	public function test_baseline_is_a_transient_with_a_seven_day_ttl(): void {
		EditorSaveGuard::capture( 501 );

		self::assertSame( 7 * DAY_IN_SECONDS, EditorSaveGuard::BASELINE_TTL );
		self::assertIsArray( get_transient( 'stonewright_elementor_editor_baseline_501_1' ) );
		self::assertSame( EditorSaveGuard::BASELINE_TTL, $GLOBALS['stonewright_test_transient_ttls']['stonewright_elementor_editor_baseline_501_1'] ?? null );
		self::assertSame( EditorSaveGuard::BASELINE_TTL, $GLOBALS['stonewright_test_transient_ttls']['stonewright_elementor_editor_baseline_users_501'] ?? null, 'the user list expires with its baselines' );
		self::assertSame( [ 1 ], get_transient( 'stonewright_elementor_editor_baseline_users_501' ) );
		self::assertSame( [], array_filter( array_keys( $GLOBALS['stonewright_test_options'] ), static fn ( $key ): bool => str_starts_with( (string) $key, 'stonewright_elementor_editor_baseline_' ) ), 'nothing is written to wp_options any more' );
	}

	public function test_capture_refreshes_the_ttl(): void {
		EditorSaveGuard::capture( 501 );
		$GLOBALS['stonewright_test_transient_ttls']['stonewright_elementor_editor_baseline_501_1']     = 60;
		$GLOBALS['stonewright_test_transient_ttls']['stonewright_elementor_editor_baseline_users_501'] = 60;

		EditorSaveGuard::capture( 501 );

		self::assertSame( EditorSaveGuard::BASELINE_TTL, $GLOBALS['stonewright_test_transient_ttls']['stonewright_elementor_editor_baseline_501_1'] );
		self::assertSame( EditorSaveGuard::BASELINE_TTL, $GLOBALS['stonewright_test_transient_ttls']['stonewright_elementor_editor_baseline_users_501'], 'a user already listed keeps the list alive too' );
	}

	public function test_a_restore_refresh_renews_the_ttl_of_every_baseline(): void {
		foreach ( [ 1, 2 ] as $user ) {
			$GLOBALS['stonewright_test_current_user_id'] = $user;
			EditorSaveGuard::capture( 501 );
			$GLOBALS['stonewright_test_transient_ttls'][ 'stonewright_elementor_editor_baseline_501_' . $user ] = 60;
		}
		$GLOBALS['stonewright_test_transient_ttls']['stonewright_elementor_editor_baseline_users_501'] = 60;

		EditorSaveGuard::refresh_all_users( 501 );

		foreach ( [ 1, 2 ] as $user ) {
			self::assertSame( EditorSaveGuard::BASELINE_TTL, $GLOBALS['stonewright_test_transient_ttls'][ 'stonewright_elementor_editor_baseline_501_' . $user ] );
		}
		self::assertSame( EditorSaveGuard::BASELINE_TTL, $GLOBALS['stonewright_test_transient_ttls']['stonewright_elementor_editor_baseline_users_501'], 'the user list is kept as long as the baselines it names, or a later restore would miss a user' );
		self::assertSame( [ 1, 2 ], get_transient( 'stonewright_elementor_editor_baseline_users_501' ) );
	}

	public function test_an_expired_baseline_allows_the_save_like_a_missing_one(): void {
		EditorSaveGuard::capture( 501 );
		self::write_tree( 501, [ [ 'id' => 'root', 'elType' => 'container', 'settings' => [ 'later' => 'change' ], 'elements' => [] ] ] );
		self::assertInstanceOf( \WP_Error::class, EditorSaveGuard::assert_persist( 501 ) );

		delete_transient( 'stonewright_elementor_editor_baseline_501_1' );

		self::assertNull( EditorSaveGuard::assert_persist( 501 ) );
	}

	public function test_forget_all_removes_baselines_users_lists_and_legacy_options(): void {
		$GLOBALS['stonewright_test_options'] = [
			'_transient_stonewright_elementor_editor_baseline_501_1'             => [ 'hash' => 'a' ],
			'_transient_timeout_stonewright_elementor_editor_baseline_501_1'     => 1900000000,
			'_transient_stonewright_elementor_editor_baseline_users_501'         => [ 1 ],
			'_transient_timeout_stonewright_elementor_editor_baseline_users_501' => 1900000000,
			'stonewright_elementor_editor_baseline_502_1'                        => [ 'hash' => 'b' ],
			'stonewright_elementor_editor_baseline_users_502'                    => [ 1 ],
			'_transient_stonewright_elementor_other'                             => 'kept',
			'stonewright_elementor_editor_baselineX'                             => 'kept',
			'blogname'                                                           => 'kept',
		];
		$previous_wpdb   = $GLOBALS['wpdb'] ?? null;
		$GLOBALS['wpdb'] = new UninstallWpdb();

		try {
			$removed = EditorSaveGuard::forget_all();
		} finally {
			$GLOBALS['wpdb'] = $previous_wpdb;
		}

		self::assertSame( 6, $removed );
		self::assertSame(
			[ '_transient_stonewright_elementor_other', 'stonewright_elementor_editor_baselineX', 'blogname' ],
			array_keys( $GLOBALS['stonewright_test_options'] )
		);
	}

	public function test_a_backup_restore_leaves_the_next_native_write_unrefused(): void {
		EditorSaveGuard::capture( 501 );
		$snapshot = Backup::snapshot_post( 501, [], false );
		self::assertNotSame( '', $snapshot );
		self::write_tree( 501, [ [ 'id' => 'root', 'elType' => 'container', 'settings' => [ 'native' => 'write' ], 'elements' => [] ] ] );
		EditorSaveGuard::on_after_save( new class() { public function get_main_id(): int { return 501; } } );
		self::assertNull( EditorSaveGuard::assert_persist( 501 ) );

		self::assertTrue( Backup::restore( 501, $snapshot ) );

		self::assertNull( EditorSaveGuard::assert_persist( 501 ), 'the restore did not leave a stale baseline behind' );
	}

	public function test_a_ledger_undo_of_the_post_leaves_the_next_native_write_unrefused(): void {
		$image = PostAdapter::image( 501 );
		self::assertIsArray( $image );
		EditorSaveGuard::capture( 501 );
		self::write_tree( 501, [ [ 'id' => 'root', 'elType' => 'container', 'settings' => [ 'native' => 'write' ], 'elements' => [] ] ] );
		EditorSaveGuard::on_after_save( new class() { public function get_main_id(): int { return 501; } } );
		self::assertNull( EditorSaveGuard::assert_persist( 501 ) );

		$result = PostAdapter::restore( 501, $image );

		self::assertIsArray( $result );
		self::assertNull( EditorSaveGuard::assert_persist( 501 ), 'the undo did not leave a stale baseline behind' );
	}

	public function test_a_stonewright_write_refreshes_the_writers_own_baseline_so_a_native_write_right_after_is_not_refused(): void {
		EditorSaveGuard::capture( 501 );
		self::assertNull( EditorSaveGuard::assert_persist( 501 ) );
		$tree = [ [ 'id' => 'root', 'elType' => 'container', 'settings' => [ 'container_type' => 'grid' ], 'elements' => [] ] ];

		self::assertTrue( ElementorData::write( 501, $tree, [ 'skip_integrity' => true ] ), ElementorData::last_write_error()?->get_error_message() ?? '' );

		self::assertNull( EditorSaveGuard::assert_persist( 501 ), 'the writer is not refused as a stale editor by its own earlier write' );
	}

	public function test_a_stonewright_write_keeps_the_baseline_of_another_user_who_has_the_editor_open(): void {
		$GLOBALS['stonewright_test_current_user_id'] = 2;
		EditorSaveGuard::capture( 501 );
		$GLOBALS['stonewright_test_current_user_id'] = 1;
		EditorSaveGuard::capture( 501 );
		$tree = [ [ 'id' => 'root', 'elType' => 'container', 'settings' => [ 'container_type' => 'grid' ], 'elements' => [] ] ];

		self::assertTrue( ElementorData::write( 501, $tree, [ 'skip_integrity' => true ] ) );

		self::assertNull( EditorSaveGuard::assert_persist( 501 ), 'the writer' );
		$GLOBALS['stonewright_test_current_user_id'] = 2;
		self::assertInstanceOf( \WP_Error::class, EditorSaveGuard::assert_persist( 501 ), 'the editor of another user is still told the document changed' );
	}

	public function test_a_failed_stonewright_write_leaves_the_baseline_alone(): void {
		EditorSaveGuard::capture( 501 );
		$before = get_transient( 'stonewright_elementor_editor_baseline_501_1' );
		$GLOBALS['stonewright_test_update_post_meta_return'] = false;

		self::assertFalse( ElementorData::write( 501, [ [ 'id' => 'root', 'elType' => 'container', 'settings' => [ 'container_type' => 'grid' ], 'elements' => [] ] ], [ 'skip_integrity' => true ] ) );
		unset( $GLOBALS['stonewright_test_update_post_meta_return'] );

		self::assertSame( $before, get_transient( 'stonewright_elementor_editor_baseline_501_1' ) );
	}

	/** @param array<int, array<string, mixed>> $tree */
	private static function write_tree( int $post_id, array $tree ): void {
		$GLOBALS['stonewright_test_posts'][ $post_id ]->meta['_elementor_data'] = wp_json_encode( $tree );
	}
}
