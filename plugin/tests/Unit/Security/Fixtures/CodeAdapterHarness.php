<?php
/**
 * A throw-away site for the code adapter tests: a theme folder, an uploads folder, a sandbox
 * folder, an in-memory change ledger and a site that answers its own health checks.
 * SPDX-License-Identifier: GPL-2.0-or-later
 *
 * @package Stonewright
 */

declare( strict_types=1 );

namespace Stonewright\WpMcp\Tests\Unit\Security\Fixtures;

use Stonewright\WpMcp\Security\ChangeJournal;
use Stonewright\WpMcp\Security\ChangeLedger;
use Stonewright\WpMcp\Security\HealthProbe;
use Stonewright\WpMcp\Security\RescueGuard;

trait CodeAdapterHarness {

	private string $harness_base = '';

	private mixed $harness_wpdb = null;

	private mixed $harness_stylesheet_dir = null;

	protected LedgerWpdb $ledger_db;

	/** @var callable():string */
	private $harness_site_state;

	protected function set_up_harness(): void {
		$this->harness_base = str_replace( '\\', '/', sys_get_temp_dir() ) . '/sw-code-' . bin2hex( random_bytes( 5 ) );
		mkdir( $this->harness_base . '/uploads', 0777, true );
		mkdir( $this->harness_base . '/themes/site-a', 0777, true );

		$this->harness_wpdb       = $GLOBALS['wpdb'] ?? null;
		$this->ledger_db          = new LedgerWpdb();
		$this->ledger_db->unique[ $this->ledger_db->prefix . 'stonewright_changes' ] = [ 'change_id' ];
		$GLOBALS['wpdb']          = $this->ledger_db;
		$this->harness_stylesheet_dir = $GLOBALS['stonewright_test_stylesheet_directory'] ?? null;

		$GLOBALS['stonewright_test_stylesheet_directory'] = $this->harness_base . '/themes/site-a';
		$GLOBALS['stonewright_test_upload_dir']           = [
			'basedir' => $this->harness_base . '/uploads',
			'baseurl' => 'https://example.test/uploads',
			'error'   => false,
		];
		$GLOBALS['stonewright_test_options']              = [ 'stonewright_mode' => 'development' ];
		$GLOBALS['stonewright_test_transients']           = [];
		$GLOBALS['stonewright_test_filters']              = [];
		$GLOBALS['stonewright_test_wpdb_inserts']         = [];
		$GLOBALS['stonewright_test_custom_css']           = '';
		$GLOBALS['stonewright_test_current_user_id']      = 7;
		$GLOBALS['stonewright_test_user_caps']            = [ 'manage_options' => true, 'read' => true ];
		$GLOBALS['stonewright_test_user_logged_in']       = true;
		ChangeJournal::reset_for_tests();
		RescueGuard::reset_for_tests();
		ChangeLedger::reset_schema_health_cache_for_tests();
		ChangeLedger::use_blob_store_for_tests( null );
		$this->site( static fn (): string => 'healthy' );
	}

	protected function tear_down_harness(): void {
		HealthProbe::set_transport( null );
		RescueGuard::reset_for_tests();
		ChangeJournal::reset_for_tests();
		ChangeLedger::use_blob_store_for_tests( null );
		$GLOBALS['wpdb'] = $this->harness_wpdb;
		if ( null === $this->harness_stylesheet_dir ) {
			unset( $GLOBALS['stonewright_test_stylesheet_directory'] );
		} else {
			$GLOBALS['stonewright_test_stylesheet_directory'] = $this->harness_stylesheet_dir;
		}
		unset( $GLOBALS['stonewright_test_upload_dir'], $GLOBALS['stonewright_test_custom_css'] );
		$GLOBALS['stonewright_test_options']         = [];
		$GLOBALS['stonewright_test_transients']      = [];
		$GLOBALS['stonewright_test_filters']         = [];
		$GLOBALS['stonewright_test_user_caps']       = [];
		$GLOBALS['stonewright_test_current_user_id'] = 0;
		self::harness_remove_tree( $this->harness_base );
	}

	/** @param callable():string $state healthy, broken or unavailable. */
	protected function site( callable $state ): void {
		$this->harness_site_state = $state;
		HealthProbe::set_transport(
			function ( string $url, array $args ) {
				unset( $args );
				return match ( ( $this->harness_site_state )() ) {
					'broken'      => [ 'response' => [ 'code' => 500 ], 'body' => '<body id="error-page"></body>', 'headers' => [] ],
					'unavailable' => new \WP_Error( 'http_request_failed', 'cURL error 7' ),
					default       => [
						'response' => [ 'code' => 200 ],
						'body'     => str_contains( $url, 'wp-json' ) ? '{"namespaces":["wp/v2"]}' : ( str_contains( $url, 'stonewright-rescue' ) ? 'data-sw-rescue-probe="ok"' : 'ok' ),
						'headers'  => [],
					],
				};
			}
		);
	}

	protected function theme_dir(): string {
		return $this->harness_base . '/themes/site-a';
	}

	protected function uploads_dir(): string {
		return $this->harness_base . '/uploads';
	}

	/** @return list<array<string, mixed>> Every ledger row, oldest first. */
	protected function ledger_rows(): array {
		return $this->ledger_db->tables[ $this->ledger_db->prefix . 'stonewright_changes' ] ?? [];
	}

	/** @return array<string, mixed> The only ledger row. */
	protected function only_row(): array {
		$rows = $this->ledger_rows();
		self::assertCount( 1, $rows, 'Exactly one change is recorded.' );
		return ChangeLedger::get( (string) $rows[0]['change_id'] ) ?? [];
	}

	/** @return list<string> Names of the blob files in the store. */
	protected function blob_files(): array {
		return array_map( 'basename', glob( $this->uploads_dir() . '/stonewright-state/blobs/*.gz' ) ?: [] );
	}

	/** Make every write to the ledger table fail the way a missing table does. */
	protected function break_ledger(): void {
		$GLOBALS['wpdb'] = new class() extends LedgerWpdb {
			public function insert( $table, $data, $format = null ) {
				unset( $table, $data, $format );
				$this->last_error = 'Table does not exist';
				return false;
			}
		};
	}

	/** Make the ledger throw on every call. */
	protected function explode_ledger(): void {
		$GLOBALS['wpdb'] = new class() extends LedgerWpdb {
			public function insert( $table, $data, $format = null ) {
				if ( str_ends_with( (string) $table, 'stonewright_changes' ) ) {
					throw new \RuntimeException( 'database gone' );
				}
				return parent::insert( $table, $data, $format );
			}
		};
	}

	protected static function harness_remove_tree( string $path ): void {
		if ( '' === $path ) {
			return;
		}
		if ( is_link( $path ) || is_file( $path ) ) {
			@unlink( $path );
			return;
		}
		if ( ! is_dir( $path ) ) {
			return;
		}
		foreach ( scandir( $path ) ?: [] as $item ) {
			if ( '.' !== $item && '..' !== $item ) {
				self::harness_remove_tree( $path . '/' . $item );
			}
		}
		@rmdir( $path );
	}
}
