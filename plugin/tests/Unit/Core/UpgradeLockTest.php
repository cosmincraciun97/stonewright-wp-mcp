<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\Tests\Unit\Core;

use PHPUnit\Framework\TestCase;
use RuntimeException;
use Stonewright\WpMcp\Core\PluginRegistration;
use Stonewright\WpMcp\Support\OptionLock;
use Stonewright\WpMcp\Support\SchemaGate;

/**
 * The upgrade chain after a version change runs in one request at a time, and a store that fails to
 * repair its table cannot stop the version from being stored or the plugin from activating.
 *
 * @covers \Stonewright\WpMcp\Core\PluginRegistration::maybe_upgrade
 * @covers \Stonewright\WpMcp\Core\PluginRegistration::on_activate
 */
final class UpgradeLockTest extends TestCase {

	private const STORES = [ 'memory', 'audit', 'changes', 'incident' ];

	private mixed $original_wpdb;

	private string $log_file = '';

	private string|false $previous_log = false;

	protected function setUp(): void {
		$this->original_wpdb = $GLOBALS['wpdb'] ?? null;
		$this->reset();
		$this->log_file     = (string) tempnam( sys_get_temp_dir(), 'sw-upgrade-lock-' );
		$this->previous_log = ini_get( 'error_log' );
		ini_set( 'error_log', $this->log_file );
	}

	protected function tearDown(): void {
		ini_set( 'error_log', false === $this->previous_log ? '' : $this->previous_log );
		@unlink( $this->log_file );
		OptionLock::release( 'upgrade' );
		$this->reset();
		if ( null !== $this->original_wpdb ) {
			$GLOBALS['wpdb'] = $this->original_wpdb;
		}
	}

	private function reset(): void {
		$GLOBALS['stonewright_test_options']         = [ 'active_plugins' => [], 'stonewright_version' => '0.0.0-before-upgrade' ];
		$GLOBALS['stonewright_test_option_autoload'] = [];
		$GLOBALS['stonewright_test_transients']      = [];
		$GLOBALS['stonewright_test_actions']         = [];
		unset( $GLOBALS['stonewright_test_after_option_insert'] );
		foreach ( self::STORES as $key ) {
			SchemaGate::reset_for_tests( $key );
		}
	}

	private function log(): string {
		return is_file( $this->log_file ) ? (string) file_get_contents( $this->log_file ) : '';
	}

	/** A database handle whose first table install throws; everything else goes to the ordinary one. */
	private function throwing_wpdb(): object {
		return new class( $this->original_wpdb ) {
			public $prefix = 'wptests_';
			private int $installs = 0;

			public function __construct( private object $inner ) {}

			public function get_charset_collate(): string {
				if ( 0 === $this->installs++ ) {
					throw new RuntimeException( 'the table install failed' );
				}
				return '';
			}

			public function __call( string $name, array $arguments ): mixed {
				return $this->inner->$name( ...$arguments );
			}

			public function __get( string $name ): mixed {
				return $this->inner->$name;
			}
		};
	}

	public function test_a_request_that_does_not_get_the_lock_runs_nothing_and_stores_no_version(): void {
		self::assertTrue( OptionLock::acquire( 'upgrade', 300 ), 'Another request is running the upgrade.' );

		PluginRegistration::maybe_upgrade();

		self::assertSame( '0.0.0-before-upgrade', $GLOBALS['stonewright_test_options']['stonewright_version'], 'the next request tries again' );
		self::assertArrayNotHasKey( 'stonewright_mode', $GLOBALS['stonewright_test_options'], 'no step ran' );
		self::assertArrayNotHasKey( 'stonewright_mcp_surface', $GLOBALS['stonewright_test_options'] );

		OptionLock::release( 'upgrade' );
		PluginRegistration::maybe_upgrade();

		self::assertSame( STONEWRIGHT_VERSION, $GLOBALS['stonewright_test_options']['stonewright_version'] );
	}

	public function test_the_lock_is_released_when_the_upgrade_is_done(): void {
		PluginRegistration::maybe_upgrade();

		self::assertSame( STONEWRIGHT_VERSION, $GLOBALS['stonewright_test_options']['stonewright_version'] );
		self::assertArrayNotHasKey( 'stonewright_lock_upgrade', $GLOBALS['stonewright_test_options'] );
		self::assertTrue( OptionLock::acquire( 'upgrade', 300 ) );
	}

	public function test_a_version_stored_by_the_holder_before_us_is_not_upgraded_again(): void {
		// The previous holder finished between our version read and our lock.
		$GLOBALS['stonewright_test_after_option_insert'] = static function (): void {
			$GLOBALS['stonewright_test_options']['stonewright_version'] = STONEWRIGHT_VERSION;
		};

		PluginRegistration::maybe_upgrade();

		self::assertArrayNotHasKey( 'stonewright_mode', $GLOBALS['stonewright_test_options'], 'no step ran a second time' );
		self::assertArrayNotHasKey( 'stonewright_lock_upgrade', $GLOBALS['stonewright_test_options'], 'and the lock is released' );
	}

	public function test_a_current_version_takes_no_lock(): void {
		$GLOBALS['stonewright_test_options']['stonewright_version'] = STONEWRIGHT_VERSION;
		$GLOBALS['stonewright_test_after_option_insert']            = static function (): void {
			self::fail( 'A current site must not even try for the lock.' );
		};

		PluginRegistration::maybe_upgrade();

		self::assertArrayNotHasKey( 'stonewright_lock_upgrade', $GLOBALS['stonewright_test_options'] );
	}

	public function test_a_store_that_throws_cannot_keep_the_version_from_being_stored_on_upgrade(): void {
		$GLOBALS['wpdb'] = $this->throwing_wpdb();

		PluginRegistration::maybe_upgrade();

		self::assertSame( STONEWRIGHT_VERSION, $GLOBALS['stonewright_test_options']['stonewright_version'] );
		self::assertStringContainsString( 'upgrade_step_failed', $this->log() );
		self::assertStringContainsString( 'RuntimeException', $this->log() );
		self::assertArrayNotHasKey( 'stonewright_lock_upgrade', $GLOBALS['stonewright_test_options'] );
	}

	public function test_a_store_that_throws_cannot_keep_activation_from_storing_the_version(): void {
		$GLOBALS['stonewright_test_options'] = [ 'active_plugins' => [] ];
		$GLOBALS['wpdb']                     = $this->throwing_wpdb();
		$ref                                 = new \ReflectionClass( PluginRegistration::class );
		$ctor                                = $ref->getConstructor();
		self::assertNotNull( $ctor );
		$instance = $ref->newInstanceWithoutConstructor();
		$ctor->invoke( $instance, __FILE__ );

		$instance->on_activate();

		self::assertSame( STONEWRIGHT_VERSION, $GLOBALS['stonewright_test_options']['stonewright_version'] );
		self::assertStringContainsString( 'upgrade_step_failed', $this->log() );
	}
}
