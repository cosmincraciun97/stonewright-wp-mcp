<?php
/**
 * Ledger rows with real images for the tests of the Changes page and the diff of a change.
 * SPDX-License-Identifier: GPL-2.0-or-later
 *
 * @package Stonewright
 */

declare( strict_types=1 );

namespace Stonewright\WpMcp\Tests\Unit\Admin\Fixtures;

use Stonewright\WpMcp\Security\ChangeLedger;
use Stonewright\WpMcp\Tests\Unit\Security\Fixtures\LedgerWpdb;

/**
 * Gives a test case an in-memory ledger table, a blob folder under the system temp folder and a clock. Rows
 * are written through ChangeLedger::record() and settle(), so what a test reads is what an adapter would have
 * written. Call ledger_up() from setUp() and ledger_down() from tearDown().
 */
trait LedgerFixture {

	/** 2026-09-13 12:00:00 UTC. */
	protected int $now = 1789300800;

	private mixed $ledger_original_wpdb = null;

	protected ?LedgerWpdb $db = null;

	private string $ledger_uploads = '';

	protected function ledger_up(): void {
		$this->ledger_original_wpdb = $GLOBALS['wpdb'] ?? null;
		$this->db                   = new LedgerWpdb();
		$this->db->unique[ $this->db->prefix . 'stonewright_changes' ] = [ 'change_id' ];
		$GLOBALS['wpdb']            = $this->db;
		$this->ledger_uploads       = str_replace( '\\', '/', sys_get_temp_dir() ) . '/sw-changes-' . bin2hex( random_bytes( 5 ) );
		mkdir( $this->ledger_uploads, 0777, true );
		$GLOBALS['stonewright_test_upload_dir']                        = [
			'basedir' => $this->ledger_uploads,
			'baseurl' => 'https://example.test/wp-content/uploads',
			'error'   => false,
		];
		$GLOBALS['stonewright_test_filters']['stonewright_rescue_now'] = fn(): int => $this->now;
		$GLOBALS['stonewright_test_current_user_id']                   = 7;
		unset( $_SERVER['HTTP_USER_AGENT'] );
		ChangeLedger::reset_schema_health_cache_for_tests();
		ChangeLedger::use_blob_store_for_tests( null );
	}

	protected function ledger_down(): void {
		$GLOBALS['wpdb'] = $this->ledger_original_wpdb;
		if ( null === $this->ledger_original_wpdb ) {
			unset( $GLOBALS['wpdb'] );
		}
		unset( $GLOBALS['stonewright_test_upload_dir'] );
		$GLOBALS['stonewright_test_filters']         = [];
		$GLOBALS['stonewright_test_current_user_id'] = 0;
		ChangeLedger::use_blob_store_for_tests( null );
		self::remove_tree( $this->ledger_uploads );
	}

	/**
	 * One settled change.
	 *
	 * @param array<string, mixed>     $spec   Keys of ChangeLedger::record(): ability, family, resource_type, resource_id, kind, parent_id, summary, actor, client, restorable.
	 * @param string|array<mixed>|null $before Image before the write.
	 * @param string|array<mixed>|null $after  Image after the write; null leaves the row without one.
	 * @return array<string, mixed> The row as ChangeLedger::get() returns it.
	 */
	protected function seed_change( array $spec = [], string|array|null $before = null, string|array|null $after = null, string $status = 'verified' ): array {
		$row = ChangeLedger::record(
			array_merge(
				[
					'ability'       => 'stonewright/content-update-page',
					'family'        => 'post',
					'resource_type' => 'post',
					'resource_id'   => '42',
					'actor'         => 7,
					'client'        => 'example-client',
					'summary'       => 'Updated the page.',
					'before'        => $before,
				],
				$spec
			)
		);
		self::assertIsArray( $row, is_wp_error( $row ) ? $row->get_error_message() : '' );
		$this->now += 60;
		$result = [ 'status' => $status ];
		if ( null !== $after ) {
			$result['after'] = $after;
		}
		$settled = ChangeLedger::settle( (string) $row['change_id'], $result );
		self::assertIsArray( $settled, is_wp_error( $settled ) ? $settled->get_error_message() : '' );
		$this->now += 60;

		return $settled;
	}

	/** Delete every stored blob, as retention would. */
	protected function drop_blobs(): void {
		foreach ( glob( $this->ledger_uploads . '/stonewright-state/blobs/*.gz' ) ?: [] as $file ) {
			unlink( $file );
		}
	}

	private static function remove_tree( string $path ): void {
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
}
