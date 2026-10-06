<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\Tests\Unit\Core;

use PHPUnit\Framework\TestCase;
use Stonewright\WpMcp\Tests\Unit\Core\Fixtures\UninstallSite;

/**
 * The real uninstall.php, included the way WordPress includes it when the plugin is
 * deleted. Every test runs in its own process, because WP_UNINSTALL_PLUGIN and
 * STONEWRIGHT_REMOVE_ALL_DATA are constants.
 */
final class UninstallFileTest extends TestCase {

	private static function file(): string {
		return dirname( __DIR__, 3 ) . '/uninstall.php';
	}

	/**
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_deleting_the_plugin_keeps_everything_by_default(): void {
		define( 'WP_UNINSTALL_PLUGIN', 'stonewright/stonewright.php' );
		$wpdb = UninstallSite::reset();
		$GLOBALS['wpdb'] = $wpdb;
		$tables = $wpdb->tables;
		$options = $GLOBALS['stonewright_test_options'];
		$hooks = $GLOBALS['stonewright_test_scheduled_hooks'];

		include self::file();

		self::assertSame( [], $wpdb->statements, 'no statement was run' );
		self::assertSame( $tables, $wpdb->tables );
		self::assertSame( $options, $GLOBALS['stonewright_test_options'] );
		self::assertSame( $hooks, $GLOBALS['stonewright_test_scheduled_hooks'] );
		self::assertSame( [], UninstallSite::$log, 'the caches were left alone as well' );
	}

	/** @return array<string, array{0: mixed}> */
	public static function values_that_are_not_true(): array {
		return [
			'false'             => [ false ],
			'the number 1'      => [ 1 ],
			'the string "true"' => [ 'true' ],
			'the string "yes"'  => [ 'yes' ],
		];
	}

	/**
	 * @dataProvider values_that_are_not_true
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_only_the_boolean_true_turns_removal_on( mixed $value ): void {
		define( 'WP_UNINSTALL_PLUGIN', 'stonewright/stonewright.php' );
		define( 'STONEWRIGHT_REMOVE_ALL_DATA', $value );
		$wpdb = UninstallSite::reset();
		$GLOBALS['wpdb'] = $wpdb;
		$tables = $wpdb->tables;
		$options = $GLOBALS['stonewright_test_options'];

		include self::file();

		self::assertSame( [], $wpdb->statements );
		self::assertSame( $tables, $wpdb->tables );
		self::assertSame( $options, $GLOBALS['stonewright_test_options'] );
	}

	/**
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_the_constant_removes_every_table_option_transient_and_event(): void {
		define( 'WP_UNINSTALL_PLUGIN', 'stonewright/stonewright.php' );
		define( 'STONEWRIGHT_REMOVE_ALL_DATA', true );
		$wpdb = UninstallSite::reset();
		$GLOBALS['wpdb'] = $wpdb;

		include self::file();

		$dropped = [];
		foreach ( $wpdb->statements as $statement ) {
			if ( preg_match( '/^DROP TABLE IF EXISTS `wptests_(.+)`$/', $statement, $match ) ) {
				$dropped[] = $match[1];
			}
		}
		$expected = UninstallSite::PLUGIN_TABLES;
		sort( $dropped );
		sort( $expected );
		self::assertSame( $expected, $dropped, 'exactly the plugin tables were dropped' );
		self::assertSame( [ 'options', 'postmeta', 'posts', 'stonewright_not_in_the_list', 'users' ], UninstallSite::remaining_tables( 1 ) );
		self::assertSame( UninstallSite::FOREIGN_OPTIONS, array_keys( $GLOBALS['stonewright_test_options'] ), 'every plugin option and transient is gone, nothing else' );
		self::assertFalse( get_option( 'stonewright_oauth_private_key' ) );
		self::assertFalse( get_option( 'stonewright_oauth_encryption_key' ) );
		self::assertSame( UninstallSite::FOREIGN_HOOKS, array_keys( $GLOBALS['stonewright_test_scheduled_hooks'] ), 'the plugin events are unscheduled, other events stay' );
		self::assertSame( [ 'flush' ], UninstallSite::$log );
	}
}
