<?php
/**
 * Shared fixture for the post family ledger tests: fake posts, a ledger table and the rescue guard.
 * SPDX-License-Identifier: GPL-2.0-or-later
 *
 * @package Stonewright
 */

declare( strict_types=1 );

namespace Stonewright\WpMcp\Tests\Unit\Security\Adapters;

use PHPUnit\Framework\TestCase;
use Stonewright\WpMcp\Security\Adapters\PostAdapter;
use Stonewright\WpMcp\Security\ChangeJournal;
use Stonewright\WpMcp\Security\ChangeLedger;
use Stonewright\WpMcp\Security\HealthProbe;
use Stonewright\WpMcp\Security\RescueGuard;
use Stonewright\WpMcp\Support\AgentNotices;

require_once dirname( __DIR__, 3 ) . '/Support/post-adapter-wp-stubs.php';

/**
 * Posts are the fakes of the shared bootstrap. The ledger is the real ChangeLedger over an in-memory
 * table, with its blobs in a temporary uploads folder. The site always answers healthy unless a test
 * says otherwise.
 */
abstract class PostLedgerTestCase extends TestCase {

	protected mixed $original_wpdb;

	protected PostLedgerWpdb $db;

	protected string $uploads = '';

	protected string $site = 'healthy';

	protected function setUp(): void {
		$this->original_wpdb = $GLOBALS['wpdb'] ?? null;
		$this->db            = new PostLedgerWpdb();
		$this->db->unique[ $this->db->prefix . 'stonewright_changes' ] = [ 'change_id' ];
		$GLOBALS['wpdb']     = $this->db;
		$this->uploads       = str_replace( '\\', '/', sys_get_temp_dir() ) . '/sw-post-ledger-' . bin2hex( random_bytes( 5 ) );
		mkdir( $this->uploads, 0777, true );
		$GLOBALS['stonewright_test_upload_dir']      = [ 'basedir' => $this->uploads, 'baseurl' => 'https://example.test/wp-content/uploads', 'error' => false ];
		$GLOBALS['stonewright_test_options']         = [ 'stonewright_mode' => 'development' ];
		$GLOBALS['stonewright_test_posts']           = [];
		$GLOBALS['stonewright_test_terms']           = [];
		$GLOBALS['stonewright_test_object_terms']    = [];
		$GLOBALS['stonewright_test_object_taxonomies'] = [];
		$GLOBALS['stonewright_test_trashed_posts']   = [];
		$GLOBALS['stonewright_test_post_meta_calls'] = [];
		$GLOBALS['stonewright_test_transients']      = [];
		$GLOBALS['stonewright_test_filters']         = [];
		$GLOBALS['stonewright_test_active_plugins']  = [];
		$GLOBALS['stonewright_test_wpdb_inserts']    = [];
		$GLOBALS['stonewright_test_next_post_id']    = 1001;
		$GLOBALS['stonewright_test_inserted_posts']  = [];
		$GLOBALS['stonewright_test_deleted_posts']   = [];
		$GLOBALS['stonewright_test_search_posts']    = [];
		$GLOBALS['stonewright_test_search_found_posts'] = 0;
		$GLOBALS['stonewright_test_wp_insert_post_return'] = null;
		$GLOBALS['stonewright_test_wp_update_post_return'] = null;
		$GLOBALS['stonewright_test_wp_insert_post_calls']  = [];
		$GLOBALS['stonewright_test_wp_update_post_calls']  = [];
		$GLOBALS['stonewright_test_user_logged_in']  = true;
		$GLOBALS['stonewright_test_current_user_id'] = 7;
		$GLOBALS['stonewright_test_user_caps_by_id'] = [ 7 => [ 'manage_options' => true, 'read' => true, 'edit_post' => true ] ];
		$GLOBALS['stonewright_test_user_can_callback'] = static fn ( string $cap ): bool => true;
		$_SERVER['HTTP_USER_AGENT']                  = 'claude-code/1.4.2 (synthetic)';
		$this->site                                  = 'healthy';
		ChangeLedger::reset_schema_health_cache_for_tests();
		ChangeLedger::use_blob_store_for_tests( null );
		ChangeJournal::reset_for_tests();
		RescueGuard::reset_for_tests();
		PostAdapter::reset_for_tests();
		AgentNotices::reset_for_tests();
		HealthProbe::set_transport(
			function ( string $url ) {
				return match ( $this->site ) {
					'broken' => [ 'response' => [ 'code' => 500 ], 'body' => '<body id="error-page"><p>There has been a critical error on this website.</p></body>', 'headers' => [] ],
					default  => [
						'response' => [ 'code' => 200 ],
						'body'     => str_contains( $url, 'wp-json' ) ? '{"namespaces":["wp/v2"]}' : ( str_contains( $url, 'stonewright-rescue' ) ? 'data-sw-rescue-probe="ok"' : 'ok' ),
						'headers'  => [],
					],
				};
			}
		);
	}

	protected function tearDown(): void {
		HealthProbe::set_transport( null );
		RescueGuard::reset_for_tests();
		ChangeJournal::reset_for_tests();
		PostAdapter::reset_for_tests();
		AgentNotices::reset_for_tests();
		ChangeLedger::use_blob_store_for_tests( null );
		$GLOBALS['wpdb'] = $this->original_wpdb;
		unset( $GLOBALS['stonewright_test_upload_dir'], $_SERVER['HTTP_USER_AGENT'] );
		$GLOBALS['stonewright_test_options']           = [];
		$GLOBALS['stonewright_test_posts']             = [];
		$GLOBALS['stonewright_test_terms']             = [];
		$GLOBALS['stonewright_test_object_terms']      = [];
		$GLOBALS['stonewright_test_object_taxonomies'] = [];
		$GLOBALS['stonewright_test_filters']           = [];
		$GLOBALS['stonewright_test_transients']        = [];
		$GLOBALS['stonewright_test_user_can_callback'] = null;
		$GLOBALS['stonewright_test_current_user_id']   = 0;
		self::remove_tree( $this->uploads );
	}

	protected static function remove_tree( string $path ): void {
		if ( is_link( $path ) || is_file( $path ) ) {
			@unlink( $path );
			return;
		}
		if ( ! is_dir( $path ) ) {
			return;
		}
		foreach ( scandir( $path ) ?: [] as $item ) {
			if ( '.' !== $item && '..' !== $item ) {
				self::remove_tree( $path . '/' . $item );
			}
		}
		@rmdir( $path );
	}

	/**
	 * @param array<string, mixed> $overrides Fields of the fake post; meta is its meta map.
	 */
	protected function make_post( int $id, array $overrides = [] ): void {
		$GLOBALS['stonewright_test_posts'][ $id ] = (object) array_merge(
			[
				'ID'            => $id,
				'post_type'     => 'page',
				'post_status'   => 'publish',
				'post_title'    => 'Synthetic page',
				'post_content'  => 'Synthetic body',
				'post_excerpt'  => 'Synthetic excerpt',
				'post_name'     => 'synthetic-page',
				'post_parent'   => 0,
				'menu_order'    => 0,
				'post_date'     => '2026-01-02 03:04:05',
				'post_date_gmt' => '2026-01-02 03:04:05',
				'meta'          => [],
			],
			$overrides
		);
	}

	/**
	 * @param list<array{0:int,1:string,2:string}> $terms term id, slug, name
	 */
	protected function set_terms( int $post_id, string $taxonomy, array $terms ): void {
		$slugs = [];
		foreach ( $terms as [ $term_id, $slug, $name ] ) {
			$GLOBALS['stonewright_test_terms'][ $taxonomy ][ $slug ] = (object) [ 'term_id' => $term_id, 'slug' => $slug, 'name' => $name ];
			$slugs[]                                                  = $slug;
		}
		$GLOBALS['stonewright_test_object_terms'][ $post_id ][ $taxonomy ] = $slugs;
	}

	protected function set_meta( int $post_id, string $key, mixed $value ): void {
		$GLOBALS['stonewright_test_posts'][ $post_id ]->meta[ $key ] = $value;
	}

	protected function meta( int $post_id, string $key ): mixed {
		return $GLOBALS['stonewright_test_posts'][ $post_id ]->meta[ $key ] ?? null;
	}

	/** @return list<array<string, mixed>> Ledger rows, oldest first. */
	protected function ledger_rows(): array {
		return array_reverse( ChangeLedger::list( [], 100 )['items'] );
	}

	/** @return array<string, mixed> */
	protected function only_row(): array {
		$rows = $this->ledger_rows();
		self::assertCount( 1, $rows, 'One ledger row was expected.' );
		return $rows[0];
	}
}
