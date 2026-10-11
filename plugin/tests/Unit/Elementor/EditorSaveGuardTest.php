<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\Tests\Unit\Elementor;

use PHPUnit\Framework\TestCase;
use Stonewright\WpMcp\Elementor\EditorSaveGuard;
use Stonewright\WpMcp\Elementor\Write\TreeHasher;
use Stonewright\WpMcp\Security\Adapters\PostAdapter;
use Stonewright\WpMcp\Security\Backup;
use Stonewright\WpMcp\Support\ElementorData;

require_once dirname( __DIR__, 2 ) . '/Support/post-adapter-wp-stubs.php';

/**
 * @covers \Stonewright\WpMcp\Elementor\EditorSaveGuard
 */
final class EditorSaveGuardTest extends TestCase {

	protected function setUp(): void {
		$GLOBALS['stonewright_test_user_logged_in']  = true;
		$GLOBALS['stonewright_test_current_user_id'] = 1;
		$GLOBALS['stonewright_test_options']         = [];
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
		self::assertArrayNotHasKey( 'stonewright_elementor_editor_baseline_501_1', $GLOBALS['stonewright_test_options'], 'no baseline existed, so none is made' );

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

	/** @param array<int, array<string, mixed>> $tree */
	private static function write_tree( int $post_id, array $tree ): void {
		$GLOBALS['stonewright_test_posts'][ $post_id ]->meta['_elementor_data'] = wp_json_encode( $tree );
	}
}
