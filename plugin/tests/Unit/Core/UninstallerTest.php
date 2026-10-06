<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\Tests\Unit\Core;

use PHPUnit\Framework\TestCase;
use Stonewright\WpMcp\Authorization\WordPress\CredentialKeys;
use Stonewright\WpMcp\Authorization\WordPress\Database;
use Stonewright\WpMcp\Authorization\WordPress\Housekeeping;
use Stonewright\WpMcp\Authorization\WordPress\StorageTables;
use Stonewright\WpMcp\Core\Uninstaller;
use Stonewright\WpMcp\Design\Direction\DesignDirectionsTable;
use Stonewright\WpMcp\Design\Direction\DesignDirectionVersionsTable;
use Stonewright\WpMcp\Expertise\ExpertiseTable;
use Stonewright\WpMcp\Knowledge\Lifecycle\CandidateTable;
use Stonewright\WpMcp\Memory\Memory;
use Stonewright\WpMcp\Security\AuditLog;
use Stonewright\WpMcp\Security\IncidentStore;
use Stonewright\WpMcp\SkillLibrary\Site\SkillTables;
use Stonewright\WpMcp\Tests\Unit\Authorization\WordPress\Fixtures\StorageRig;
use Stonewright\WpMcp\Tests\Unit\Core\Fixtures\UninstallSite;
use Stonewright\WpMcp\Tests\Unit\Core\Fixtures\UninstallWpdb;

/**
 * What the uninstall handler removes, on one site and on a network, and the guards that
 * keep its list in step with the code that creates the data.
 *
 * @covers \Stonewright\WpMcp\Core\Uninstaller
 */
final class UninstallerTest extends TestCase {

	private const KEPT_TABLES = [ 'options', 'postmeta', 'posts', 'stonewright_not_in_the_list', 'users' ];

	protected function setUp(): void {
		StorageRig::reset_globals();
	}

	protected function tearDown(): void {
		StorageRig::reset_globals();
	}

	public function test_removal_is_not_requested_without_the_constant(): void {
		self::assertFalse( Uninstaller::requested() );
	}

	public function test_a_single_site_loses_exactly_the_listed_tables_options_transients_and_events(): void {
		$wpdb = UninstallSite::reset();

		( new Uninstaller( $wpdb ) )->remove_everything();

		self::assertSame( self::KEPT_TABLES, UninstallSite::remaining_tables( 1 ) );
		self::assertSame( UninstallSite::FOREIGN_OPTIONS, array_keys( UninstallSite::options( 1 ) ) );
		self::assertSame( UninstallSite::FOREIGN_HOOKS, array_keys( UninstallSite::hooks( 1 ) ) );
		self::assertSame( [ 'flush' ], UninstallSite::$log, 'no site switching on a single site, one cache flush at the end' );
		self::assertSame( [], UninstallSite::$site_queries );
	}

	public function test_every_listed_table_is_dropped_by_its_full_quoted_name(): void {
		$wpdb = UninstallSite::reset();

		( new Uninstaller( $wpdb ) )->remove_everything();

		$expected = array_map( static fn ( string $table ): string => 'DROP TABLE IF EXISTS `wptests_' . $table . '`', Uninstaller::TABLES );
		$drops = array_values( array_filter( $wpdb->statements, static fn ( string $sql ): bool => str_starts_with( $sql, 'DROP TABLE' ) ) );
		sort( $expected );
		sort( $drops );
		self::assertSame( $expected, $drops );
		self::assertCount( 18, $drops );
	}

	public function test_the_oauth_signing_and_encryption_keys_go_with_the_other_options(): void {
		$wpdb = UninstallSite::reset();
		update_option( CredentialKeys::PRIVATE_KEY_OPTION, 'synthetic signing key', false );
		update_option( CredentialKeys::ENCRYPTION_KEY_OPTION, 'synthetic encryption key', false );
		update_option( CredentialKeys::ERROR_OPTION, 'synthetic error', false );
		update_option( StorageTables::VERSION_OPTION, '5', false );
		set_transient( StorageTables::BACKOFF_TRANSIENT, time(), StorageTables::BACKOFF_SECONDS );

		( new Uninstaller( $wpdb ) )->remove_everything();

		foreach ( [ CredentialKeys::PRIVATE_KEY_OPTION, CredentialKeys::ENCRYPTION_KEY_OPTION, CredentialKeys::ERROR_OPTION, StorageTables::VERSION_OPTION ] as $option ) {
			self::assertArrayNotHasKey( $option, $GLOBALS['stonewright_test_options'], $option );
		}
	}

	public function test_option_searches_escape_the_wildcards_of_like(): void {
		$wpdb = UninstallSite::reset();

		( new Uninstaller( $wpdb ) )->remove_everything();

		$searches = array_values( array_filter( $wpdb->statements, static fn ( string $sql ): bool => str_starts_with( $sql, 'SELECT' ) ) );
		self::assertContains( 'SELECT option_name FROM wptests_options WHERE option_name LIKE \'stonewright\\\\_%\'', $searches );
		self::assertContains( 'SELECT option_name FROM wptests_options WHERE option_name LIKE \'\\\\_transient\\\\_timeout\\\\_sw\\\\_cc\\\\_%\'', $searches );
	}

	public function test_a_network_is_cleaned_site_by_site_and_each_switch_is_undone(): void {
		$wpdb = UninstallSite::reset( 1, 2, 3 );

		( new Uninstaller( $wpdb, true ) )->remove_everything();

		self::assertSame( [ 'switch:1', 'restore', 'switch:2', 'restore', 'switch:3', 'restore', 'flush' ], UninstallSite::$log );
		foreach ( [ 1, 2, 3 ] as $site ) {
			self::assertSame( self::KEPT_TABLES, UninstallSite::remaining_tables( $site ), 'tables of site ' . $site );
			self::assertSame( UninstallSite::FOREIGN_OPTIONS, array_keys( UninstallSite::options( $site ) ), 'options of site ' . $site );
			self::assertSame( UninstallSite::FOREIGN_HOOKS, array_keys( UninstallSite::hooks( $site ) ), 'events of site ' . $site );
		}
		self::assertSame( 1, UninstallSite::$current, 'the run ends on the site it started on' );
		self::assertSame( 'wptests_', $wpdb->prefix );
		self::assertSame( 'wptests_options', $wpdb->options );
	}

	public function test_the_site_is_restored_when_one_site_fails(): void {
		$wpdb = UninstallSite::reset( 1, 2, 3 );
		$wpdb->fail = static fn ( string $sql ): bool => str_contains( $sql, 'wptests_2_' );

		try {
			( new Uninstaller( $wpdb, true ) )->remove_everything();
			self::fail( 'The failure must reach the caller.' );
		} catch ( \RuntimeException $failure ) {
			self::assertSame( 'Synthetic failure.', $failure->getMessage() );
		}

		self::assertSame( [ 'switch:1', 'restore', 'switch:2', 'restore' ], UninstallSite::$log );
		self::assertSame( 1, UninstallSite::$current );
		self::assertSame( 'wptests_', $wpdb->prefix );
	}

	public function test_sites_are_read_in_pages_of_one_hundred(): void {
		$wpdb = UninstallSite::reset( ...range( 1, 205 ) );

		( new Uninstaller( $wpdb, true ) )->remove_everything();

		self::assertSame(
			[ [ 'number' => 100, 'offset' => 0 ], [ 'number' => 100, 'offset' => 100 ], [ 'number' => 100, 'offset' => 200 ] ],
			UninstallSite::$site_queries
		);
		$switches = array_filter( UninstallSite::$log, static fn ( string $entry ): bool => str_starts_with( $entry, 'switch:' ) );
		self::assertCount( 205, $switches );
		self::assertSame( self::KEPT_TABLES, UninstallSite::remaining_tables( 205 ) );
	}

	public function test_the_table_list_matches_the_tables_the_plugin_defines(): void {
		$wpdb = $GLOBALS['wpdb'];
		$defined = [
			Memory::table_name(),
			AuditLog::table_name(),
			IncidentStore::table_name(),
			CandidateTable::table_name(),
			DesignDirectionsTable::table_name(),
			DesignDirectionVersionsTable::table_name(),
			ExpertiseTable::table_name(),
			ExpertiseTable::scorecard_table_name(),
			SkillTables::skills_table(),
			SkillTables::versions_table(),
		];
		$database = new Database( $wpdb );
		foreach ( array_keys( StorageTables::REQUIRED_COLUMNS ) as $suffix ) {
			$defined[] = $database->table( $suffix );
		}
		$listed = array_map( static fn ( string $table ): string => $wpdb->prefix . $table, Uninstaller::TABLES );
		$fixture = UninstallSite::PLUGIN_TABLES;
		sort( $defined );
		sort( $listed );
		sort( $fixture );

		self::assertSame( $defined, $listed );
		self::assertSame( $fixture, array_values( array_intersect( $fixture, Uninstaller::TABLES ) ), 'the test fixture lists the same tables' );
		self::assertCount( count( Uninstaller::TABLES ), $fixture );
	}

	public function test_no_file_creates_a_table_the_list_does_not_know(): void {
		$creating = [];
		foreach ( self::sources() as $file => $code ) {
			$statements = (int) preg_match_all( '/[\'"]CREATE TABLE /', $code );
			if ( $statements > 0 ) {
				$creating[ $file ] = $statements;
			}
		}

		// StorageTables builds its eight tables in one loop; the table accessors cover those.
		self::assertSame(
			[
				'Authorization/WordPress/StorageTables.php'         => 1,
				'Design/Direction/DesignDirectionVersionsTable.php' => 1,
				'Design/Direction/DesignDirectionsTable.php'        => 1,
				'Expertise/ExpertiseTable.php'                      => 2,
				'Knowledge/Lifecycle/CandidateTable.php'            => 1,
				'Memory/Memory.php'                                 => 1,
				'Security/AuditLog.php'                             => 1,
				'Security/IncidentStore.php'                        => 1,
				'SkillLibrary/Site/SkillTables.php'                 => 2,
			],
			$creating,
			'A CREATE TABLE statement is new or gone: update Uninstaller::TABLES, UninstallSite::PLUGIN_TABLES and this list.'
		);
	}

	public function test_the_event_list_matches_the_events_the_plugin_schedules(): void {
		$scheduling = array_keys( array_filter( self::sources(), static fn ( string $code ): bool => 1 === preg_match( '/\bwp_schedule_(single_)?event\(/', $code ) ) );
		$hooks = Uninstaller::SCHEDULED_HOOKS;
		$known = [ Housekeeping::HOOK, AuditLog::RETENTION_HOOK ];
		sort( $hooks );
		sort( $known );

		self::assertSame( $known, $hooks );
		self::assertSame( [ 'Authorization/WordPress/AuthorizationLifecycle.php', 'Security/AuditLog.php' ], $scheduling, 'A file that schedules an event is new or gone: update Uninstaller::SCHEDULED_HOOKS and this list.' );
	}

	public function test_every_option_and_transient_name_the_plugin_writes_is_covered_or_known_to_be_foreign(): void {
		// Written by the plugin on purpose, owned by WordPress or by another plugin: never removed.
		$foreign = [ 'cptui_post_types', 'cptui_taxonomies', 'page_on_front', 'show_on_front', 'elementor_pro_theme_builder_conditions' ];
		$uncovered = [];
		foreach ( self::sources() as $file => $code ) {
			preg_match_all( '/\b(set_transient|update_option|add_option)\(\s*\'([^\']+)\'/', $code, $calls, PREG_SET_ORDER );
			foreach ( $calls as $call ) {
				if ( ! self::covered( $call[2], 'set_transient' === $call[1] ) ) {
					$uncovered[ $file . ' ' . $call[1] . '( ' . $call[2] ] = true;
				}
			}
			preg_match_all( '/\bconst\s+([A-Z_]*(?:OPTION|TRANSIENT|CACHE_KEY)[A-Z_]*)\s*=\s*\'([a-z][a-z0-9_\-]*)\'/', $code, $constants, PREG_SET_ORDER );
			foreach ( $constants as $constant ) {
				if ( ! self::covered( $constant[2], str_contains( $constant[1], 'TRANSIENT' ) || str_contains( $constant[1], 'CACHE_KEY' ) ) ) {
					$uncovered[ $file . ' const ' . $constant[1] . ' = ' . $constant[2] ] = true;
				}
			}
		}
		$uncovered = array_filter( array_keys( $uncovered ), static function ( string $entry ) use ( $foreign ): bool {
			foreach ( $foreign as $name ) {
				if ( str_ends_with( $entry, $name ) ) {
					return false;
				}
			}
			return true;
		} );

		self::assertSame( [], array_values( $uncovered ), 'These names start with no prefix of Uninstaller::OPTION_PREFIXES / TRANSIENT_PREFIXES. Add a prefix there, or list the name as foreign in this test.' );
	}

	/** Whether an option, or a transient name without WordPress's prefix, starts with a prefix the uninstall removes. */
	private static function covered( string $name, bool $transient ): bool {
		foreach ( $transient ? Uninstaller::TRANSIENT_PREFIXES : Uninstaller::OPTION_PREFIXES as $prefix ) {
			if ( str_starts_with( $name, $prefix ) ) {
				return true;
			}
		}
		return false;
	}

	/** @return array<string, string> Source of every plugin file, keyed by its path under includes/. */
	private static function sources(): array {
		$root = str_replace( '\\', '/', dirname( __DIR__, 3 ) . '/includes' );
		$sources = [];
		foreach ( new \RecursiveIteratorIterator( new \RecursiveDirectoryIterator( $root, \FilesystemIterator::SKIP_DOTS ) ) as $file ) {
			if ( $file instanceof \SplFileInfo && 'php' === $file->getExtension() ) {
				$sources[ substr( str_replace( '\\', '/', $file->getPathname() ), strlen( $root ) + 1 ) ] = (string) file_get_contents( $file->getPathname() );
			}
		}
		ksort( $sources );
		return $sources;
	}
}
