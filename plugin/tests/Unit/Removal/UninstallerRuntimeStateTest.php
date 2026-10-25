<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\Tests\Unit\Removal;

use PHPUnit\Framework\TestCase;
use Stonewright\WpMcp\Authorization\WordPress\Housekeeping;
use Stonewright\WpMcp\Authorization\WordPress\StorageTables;
use Stonewright\WpMcp\Core\McpAbilitiesCompatibilityPreflight;
use Stonewright\WpMcp\Core\RescueInstaller;
use Stonewright\WpMcp\Core\RuntimeState;
use Stonewright\WpMcp\Core\Uninstaller;
use Stonewright\WpMcp\Elementor\EditorSaveGuard;
use Stonewright\WpMcp\Elementor\WidgetBuilder\ScanCache;
use Stonewright\WpMcp\Security\AuditLog;
use Stonewright\WpMcp\Security\ChangeJournal;
use Stonewright\WpMcp\Security\ChangeLedgerRetention;
use Stonewright\WpMcp\Security\HealthProbe;
use Stonewright\WpMcp\Support\OptionLock;
use Stonewright\WpMcp\Support\SchemaGate;
use Stonewright\WpMcp\Tests\Unit\Authorization\WordPress\Fixtures\StorageRig;
use Stonewright\WpMcp\Tests\Unit\Core\Fixtures\UninstallSite;

/**
 * What every uninstall and every deactivation removes whatever the data setting says: the runtime state and the
 * scheduled events. Settings, tables and data stay unless STONEWRIGHT_REMOVE_ALL_DATA asks for them.
 *
 * @covers \Stonewright\WpMcp\Core\RuntimeState
 * @covers \Stonewright\WpMcp\Core\Uninstaller
 */
final class UninstallerRuntimeStateTest extends TestCase {

	private mixed $previous_wpdb = null;

	protected function setUp(): void {
		$this->previous_wpdb = $GLOBALS['wpdb'] ?? null;
		StorageRig::reset_globals();
	}

	protected function tearDown(): void {
		$GLOBALS['wpdb'] = $this->previous_wpdb;
		StorageRig::reset_globals();
	}

	private function seed( int ...$sites ): \wpdb {
		$wpdb            = UninstallSite::reset( ...$sites );
		$GLOBALS['wpdb'] = $wpdb;
		return $wpdb;
	}

	/** @return list<string> Data, settings and other plugins' rows that carry names near the runtime ones. */
	private static function kept_rows(): array {
		return array_values( array_merge( array_diff( UninstallSite::PLUGIN_OPTIONS, UninstallSite::RUNTIME_OPTIONS ), UninstallSite::FOREIGN_OPTIONS ) );
	}

	public function test_default_uninstall_removes_runtime_state_and_events_but_keeps_data_tables_and_settings(): void {
		$wpdb = $this->seed();
		$tables = $wpdb->tables;
		$expected_options = array_diff_key( $GLOBALS['stonewright_test_options'], array_flip( UninstallSite::RUNTIME_OPTIONS ) );

		Uninstaller::unschedule_events();
		Uninstaller::remove_runtime_state();

		self::assertSame( $tables, $wpdb->tables, 'no table was dropped' );
		self::assertSame( $expected_options, $GLOBALS['stonewright_test_options'], 'only the runtime rows went; every setting and key stayed' );
		self::assertSame( UninstallSite::FOREIGN_HOOKS, array_keys( $GLOBALS['stonewright_test_scheduled_hooks'] ), 'every Stonewright event is unscheduled and the others stay' );
		foreach ( $wpdb->statements as $statement ) {
			self::assertStringStartsWith( 'SELECT option_name FROM', $statement, 'only reads: rows are deleted through the options API' );
		}
	}

	public function test_runtime_rows_are_searched_with_escaped_wildcards(): void {
		$wpdb = $this->seed();
		$GLOBALS['stonewright_test_options']['stonewrightXlock_x']       = 'kept';
		$GLOBALS['stonewright_test_options']['stonewright_lockXlookalike'] = 'kept';

		Uninstaller::remove_runtime_state();

		self::assertArrayHasKey( 'stonewrightXlock_x', $GLOBALS['stonewright_test_options'] );
		self::assertArrayHasKey( 'stonewright_lockXlookalike', $GLOBALS['stonewright_test_options'], 'the underscore in a prefix is not a wildcard' );
		self::assertNotSame( [], $wpdb->statements );
	}

	public function test_a_network_uninstall_clears_every_site_and_undoes_each_switch(): void {
		$this->seed( 1, 2, 3 );
		$GLOBALS['stonewright_test_is_multisite'] = true;

		try {
			Uninstaller::unschedule_events();
			Uninstaller::remove_runtime_state();
		} finally {
			unset( $GLOBALS['stonewright_test_is_multisite'] );
		}

		foreach ( [ 1, 2, 3 ] as $site ) {
			self::assertSame( [], array_intersect( UninstallSite::RUNTIME_OPTIONS, array_keys( UninstallSite::options( $site ) ) ), 'site ' . $site );
			self::assertSame( UninstallSite::FOREIGN_HOOKS, array_keys( UninstallSite::hooks( $site ) ), 'events of site ' . $site );
			self::assertSame( [], array_diff( self::kept_rows(), array_keys( UninstallSite::options( $site ) ) ), 'data of site ' . $site );
		}
		$switches = array_values( array_filter( UninstallSite::$log, static fn ( string $entry ): bool => str_starts_with( $entry, 'switch:' ) ) );
		$restores = array_values( array_filter( UninstallSite::$log, static fn ( string $entry ): bool => 'restore' === $entry ) );
		self::assertSame( count( $switches ), count( $restores ), 'every switch is undone' );
		self::assertSame( 1, UninstallSite::$current );
	}

	public function test_event_names_are_the_ones_the_scheduling_classes_use(): void {
		self::assertEqualsCanonicalizing(
			[ AuditLog::RETENTION_HOOK, ChangeLedgerRetention::HOOK, Housekeeping::HOOK ],
			Uninstaller::SCHEDULED_HOOKS
		);
	}

	/**
	 * The prefixes are literals because the class that clears them loads without the plugin. Each one must equal the
	 * constant of the class that writes the rows, so a rename there cannot leave rows behind here.
	 */
	public function test_runtime_prefixes_match_the_classes_that_write_them(): void {
		$options = [
			'edit baseline'   => self::constant( EditorSaveGuard::class, 'OPTION_PREFIX' ),
			'journal seen'    => ChangeJournal::SEEN_OPTION,
			'helper state'    => RescueInstaller::OPTION_STATE,
			'widget scans'    => ScanCache::OPTION,
			'mcp preflight'   => McpAbilitiesCompatibilityPreflight::OPTION,
			'option locks'    => self::constant( OptionLock::class, 'PREFIX' ),
			'helper keys'     => self::helper_constant( 'KEY_PREFIX' ),
			'helper sessions' => self::helper_constant( 'SESSION_PREFIX' ),
		];
		self::assertEqualsCanonicalizing( array_values( $options ), RuntimeState::OPTION_PREFIXES, 'every option prefix has an owner here, and the other way round' );

		$transients = [
			'schema back-off' => self::constant( SchemaGate::class, 'BACKOFF_PREFIX' ),
			'oauth back-off'  => StorageTables::BACKOFF_TRANSIENT,
			'loopback probe'  => HealthProbe::COOLDOWN_KEY,
			'edit baseline'   => self::constant( EditorSaveGuard::class, 'OPTION_PREFIX' ),
		];
		self::assertEqualsCanonicalizing( array_values( $transients ), RuntimeState::TRANSIENT_PREFIXES );
	}

	public function test_no_runtime_prefix_swallows_data(): void {
		foreach ( array_merge( RuntimeState::OPTION_PREFIXES, RuntimeState::TRANSIENT_PREFIXES ) as $prefix ) {
			foreach ( self::kept_rows() as $name ) {
				self::assertFalse(
					str_starts_with( $name, $prefix ) || str_starts_with( preg_replace( '/^_(?:site_)?transient_(?:timeout_)?/', '', $name ) ?? $name, $prefix ),
					$name . ' is data or a setting but would be taken for runtime state by ' . $prefix
				);
			}
		}
	}

	/**
	 * A prefix matches every name that starts with it. A name written in the code that starts with a runtime prefix and
	 * is not the prefix itself would be deleted on deactivation: that is a decision to make here, not by accident.
	 */
	public function test_no_other_option_or_transient_name_in_the_code_starts_with_a_runtime_prefix(): void {
		$prefixes   = array_unique( array_merge( RuntimeState::OPTION_PREFIXES, RuntimeState::TRANSIENT_PREFIXES ) );
		$root       = dirname( __DIR__, 3 ) . '/includes';
		$collisions = [];
		foreach ( new \RecursiveIteratorIterator( new \RecursiveDirectoryIterator( $root, \FilesystemIterator::SKIP_DOTS ) ) as $file ) {
			if ( 'php' !== $file->getExtension() ) {
				continue;
			}
			preg_match_all( "/'(stonewright_[a-z0-9_]+)'/", (string) file_get_contents( $file->getPathname() ), $found );
			foreach ( $found[1] as $literal ) {
				foreach ( $prefixes as $prefix ) {
					if ( $literal !== $prefix && str_starts_with( $literal, $prefix ) ) {
						$collisions[ $literal ] = substr( str_replace( '\\', '/', $file->getPathname() ), strlen( str_replace( '\\', '/', $root ) ) + 1 );
					}
				}
			}
		}

		self::assertSame( [], $collisions, 'A name that a runtime prefix would delete. Rename it, or make it runtime state on purpose.' );
	}

	private static function constant( string $class, string $name ): string {
		return (string) ( new \ReflectionClassConstant( $class, $name ) )->getValue();
	}

	/** A constant of the rescue helper, read from its file: the helper loads without the plugin and has no namespace. */
	private static function helper_constant( string $name ): string {
		$source = (string) file_get_contents( dirname( __DIR__, 3 ) . '/mu/stonewright-rescue.php' );
		self::assertSame( 1, preg_match( '/const\s+' . $name . '\s*=\s*\'([^\']+)\'\s*;/', $source, $match ), $name . ' is declared in the helper' );
		return $match[1];
	}
}
