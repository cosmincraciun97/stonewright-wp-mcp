<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\Tests\Unit\Core;

use PHPUnit\Framework\TestCase;
use Stonewright\WpMcp\Admin\MenuRegistry;
use Stonewright\WpMcp\Admin\RescuePage;
use Stonewright\WpMcp\Core\PluginRegistration;
use Stonewright\WpMcp\Gutenberg\BrowserQueue\QueueConsole;

/**
 * WordPress 6.7 and later report a text domain that is read before `init`. Stonewright boots on
 * `plugins_loaded`, so nothing it runs up to `init` may call a translation function: every label is
 * translated when it is first used, and every entry the admin shows is registered by `init`.
 *
 * @covers \Stonewright\WpMcp\Core\PluginRegistration
 * @covers \Stonewright\WpMcp\Gutenberg\BrowserQueue\QueueConsole
 * @covers \Stonewright\WpMcp\Admin\RescuePage
 * @covers \Stonewright\WpMcp\Admin\MenuRegistry
 */
final class BootTranslationTimingTest extends TestCase {

	private string|false $previous_error_log = false;

	protected function setUp(): void {
		// Booting writes structured log lines for the missing WordPress runtime; keep them out of the test output.
		$this->previous_error_log = ini_set( 'error_log', sys_get_temp_dir() . '/stonewright-boot-translation-timing.log' );
		$GLOBALS['stonewright_test_actions']             = [];
		$GLOBALS['stonewright_test_filters']             = [];
		$GLOBALS['stonewright_test_options']             = [];
		$GLOBALS['stonewright_test_did_actions']         = [];
		$GLOBALS['stonewright_test_translation_guard']   = false;
		$GLOBALS['stonewright_test_early_translations']  = [];
		$GLOBALS['stonewright_test_doing_it_wrong']      = [];
		MenuRegistry::reset_for_tests();
		self::forget_hook_guards();
	}

	protected function tearDown(): void {
		if ( false !== $this->previous_error_log ) {
			ini_set( 'error_log', $this->previous_error_log );
		}
		$GLOBALS['stonewright_test_actions']            = [];
		$GLOBALS['stonewright_test_filters']            = [];
		$GLOBALS['stonewright_test_options']            = [];
		$GLOBALS['stonewright_test_did_actions']        = [];
		$GLOBALS['stonewright_test_translation_guard']  = false;
		$GLOBALS['stonewright_test_early_translations'] = [];
		MenuRegistry::reset_for_tests();
		self::forget_hook_guards();
	}

	public function test_the_recorder_sees_every_translation_function_while_the_guard_is_on(): void {
		$GLOBALS['stonewright_test_translation_guard'] = true;

		__( 'a', 'stonewright' );
		_n( 'b', 'bs', 1, 'stonewright' );
		_x( 'c', 'context', 'stonewright' );
		_nx( 'd', 'ds', 1, 'context', 'stonewright' );
		esc_html__( 'e', 'stonewright' );
		esc_attr__( 'f', 'stonewright' );
		esc_html_x( 'g', 'context', 'stonewright' );
		esc_attr_x( 'h', 'context', 'stonewright' );
		ob_start();
		_e( 'i', 'stonewright' );
		esc_html_e( 'j', 'stonewright' );
		esc_attr_e( 'k', 'stonewright' );
		_ex( 'l', 'context', 'stonewright' );
		ob_end_clean();

		self::assertCount( 12, $GLOBALS['stonewright_test_early_translations'] );

		$GLOBALS['stonewright_test_translation_guard']  = false;
		$GLOBALS['stonewright_test_early_translations'] = [];
		__( 'not recorded', 'stonewright' );
		self::assertSame( [], $GLOBALS['stonewright_test_early_translations'] );
	}

	public function test_plugin_boot_and_the_hooks_before_init_translate_nothing(): void {
		$GLOBALS['stonewright_test_translation_guard'] = true;
		self::boot_plugin();
		foreach ( [ 'muplugins_loaded', 'plugins_loaded', 'setup_theme', 'after_setup_theme' ] as $hook ) {
			do_action( $hook );
		}
		$GLOBALS['stonewright_test_translation_guard'] = false;

		self::assertSame( [], $GLOBALS['stonewright_test_early_translations'], 'A translation function ran before init.' );
	}

	public function test_every_admin_entry_is_registered_and_labelled_once_init_has_run(): void {
		self::boot_plugin();
		foreach ( [ 'plugins_loaded', 'after_setup_theme' ] as $hook ) {
			do_action( $hook );
		}
		self::run_menu_registrations_of_init();

		$queue = MenuRegistry::entry( QueueConsole::PAGE );
		self::assertNotNull( $queue );
		self::assertSame( 'Block queue', $queue['label'] );
		self::assertSame( 'activity', $queue['hub'] );
		self::assertSame( 'queued or failed changes', $queue['count_label'] );
		self::assertTrue( $queue['beta'] );
		self::assertFalse( $queue['in_menu'] );
		self::assertSame( 'edit_posts', $queue['capability'] );
		self::assertNotSame( '', $queue['lede'] );
		self::assertIsCallable( $queue['count'] );

		$rescue = MenuRegistry::entry( 'stonewright-rescue' );
		self::assertNotNull( $rescue );
		self::assertSame( 'Rescue', $rescue['label'] );
		self::assertSame( 'activity', $rescue['hub'] );
		self::assertSame( 'needing attention', $rescue['count_label'] );
		self::assertIsCallable( $rescue['count'] );

		self::assertSame(
			[ 'stonewright-audit-log', 'stonewright-block-finalizer', 'stonewright-rescue' ],
			array_column( MenuRegistry::hub_entries( 'activity' ), 'slug' )
		);
	}

	private static function boot_plugin(): void {
		$ref = new \ReflectionClass( PluginRegistration::class );
		$ctor = $ref->getConstructor();
		self::assertNotNull( $ctor );
		$instance = $ref->newInstanceWithoutConstructor();
		$ctor->invoke( $instance, dirname( __DIR__, 3 ) . '/stonewright.php' );
		$register = $ref->getMethod( 'register_hooks' );
		$register->invoke( $instance );
	}

	/** Runs the `init` callbacks of the two pages that register themselves in the Activity hub. */
	private static function run_menu_registrations_of_init(): void {
		foreach ( $GLOBALS['stonewright_test_actions']['init'] ?? [] as $registered ) {
			$callback = $registered['callback'];
			if ( is_array( $callback ) && in_array( $callback[0], [ QueueConsole::class, RescuePage::class ], true ) ) {
				call_user_func( $callback );
			}
		}
	}

	/** The registrations that run once per process are armed again, so each test boots the plugin from scratch. */
	private static function forget_hook_guards(): void {
		$queue = new \ReflectionProperty( QueueConsole::class, 'attached' );
		$queue->setValue( null, false );
	}
}
