<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\Tests\Unit\Themes;

use PHPUnit\Framework\TestCase;
use Stonewright\WpMcp\Abilities\FSE\ThemeJsonHandoff;
use Stonewright\WpMcp\Abilities\Themes\ThemeFilePatch;
use Stonewright\WpMcp\Abilities\Themes\ThemeFileRead;
use Stonewright\WpMcp\Security\Permissions;

/**
 * Who may read and write theme PHP, and the wp-config.php switches that withdraw file editing.
 *
 * @covers \Stonewright\WpMcp\Abilities\Themes\ThemeFileRead
 * @covers \Stonewright\WpMcp\Abilities\Themes\ThemeFilePatch
 * @covers \Stonewright\WpMcp\Abilities\FSE\ThemeJsonHandoff
 * @covers \Stonewright\WpMcp\Security\Permissions
 */
final class ThemeFileEditGateTest extends TestCase {

	private string $theme_dir;

	protected function setUp(): void {
		$GLOBALS['stonewright_test_user_caps']       = [ 'read' => true, 'edit_theme_options' => true, 'edit_css' => true, 'manage_options' => true ];
		$GLOBALS['stonewright_test_user_logged_in']  = true;
		$GLOBALS['stonewright_test_current_user_id'] = 1;
		$GLOBALS['stonewright_test_options']         = [ 'stonewright_mode' => 'development', 'stonewright_disabled_abilities' => [] ];
		$GLOBALS['stonewright_test_wpdb_inserts']    = [];
		$GLOBALS['stonewright_test_direct_mode']     = false;

		$this->theme_dir = sys_get_temp_dir() . '/sw-theme-gate-' . bin2hex( random_bytes( 4 ) );
		mkdir( $this->theme_dir );
		file_put_contents( $this->theme_dir . '/style.css', "/* theme */\n" );
		file_put_contents( $this->theme_dir . '/functions.php', "<?php\n// functions\n" );
		$GLOBALS['stonewright_test_stylesheet_directory'] = $this->theme_dir;
		$GLOBALS['stonewright_test_stylesheet']           = 'sw-test-theme';
	}

	protected function tearDown(): void {
		$GLOBALS['stonewright_test_user_caps'] = [];
		$GLOBALS['stonewright_test_options']   = [];
		unset( $GLOBALS['stonewright_test_stylesheet_directory'], $GLOBALS['stonewright_test_stylesheet'], $GLOBALS['stonewright_test_direct_mode'] );
		foreach ( glob( $this->theme_dir . '/*' ) ?: [] as $file ) {
			@unlink( $file );
		}
		@rmdir( $this->theme_dir );
	}

	/** @return iterable<string, array{object}> */
	public static function abilities(): iterable {
		yield 'theme-file-read' => [ new ThemeFileRead() ];
		yield 'theme-file-patch' => [ new ThemeFilePatch() ];
	}

	/** @dataProvider abilities */
	public function test_php_files_need_both_manage_options_and_edit_theme_options( object $ability ): void {
		$php = [ 'path' => 'functions.php' ];

		$GLOBALS['stonewright_test_user_caps'] = [ 'manage_options' => true ];
		self::assertFalse( $ability->permission_callback( $php ), 'manage_options alone is not enough.' );

		$GLOBALS['stonewright_test_user_caps'] = [ 'edit_theme_options' => true ];
		self::assertFalse( $ability->permission_callback( $php ), 'edit_theme_options alone is not enough.' );

		$GLOBALS['stonewright_test_user_caps'] = [ 'manage_options' => true, 'edit_theme_options' => true ];
		self::assertTrue( $ability->permission_callback( $php ) );
	}

	/** @dataProvider abilities */
	public function test_css_files_keep_their_lighter_gate( object $ability ): void {
		$GLOBALS['stonewright_test_user_caps'] = [ 'edit_css' => true ];

		self::assertTrue( $ability->permission_callback( [ 'path' => 'style.css' ] ) );
		self::assertFalse( $ability->permission_callback( [ 'path' => 'functions.php' ] ) );
	}

	public function test_theme_file_writes_are_allowed_when_wp_config_sets_neither_switch(): void {
		self::assertTrue( Permissions::theme_file_writes_allowed() );
	}

	/**
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_disallow_file_edit_stops_theme_file_and_theme_json_writes_but_not_reads(): void {
		define( 'DISALLOW_FILE_EDIT', true );
		$this->setUp();

		self::assertFalse( Permissions::theme_file_writes_allowed() );

		$patch = ( new ThemeFilePatch() )->execute( [ 'path' => 'style.css', 'mode' => 'append', 'content' => 'a{}', 'dry_run' => true ] );
		self::assertInstanceOf( \WP_Error::class, $patch );
		self::assertSame( 'stonewright_file_edit_disabled', $patch->get_error_code() );

		$handoff = ( new ThemeJsonHandoff() )->execute( [ 'theme_json' => [ 'version' => 3 ], 'dry_run' => true ] );
		self::assertInstanceOf( \WP_Error::class, $handoff );
		self::assertSame( 'stonewright_file_edit_disabled', $handoff->get_error_code() );

		$read = ( new ThemeFileRead() )->execute( [ 'path' => 'style.css' ] );
		self::assertIsArray( $read );
		self::assertSame( "/* theme */\n", $read['content'] );
		$this->tearDown();
	}

	/**
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_disallow_file_mods_stops_theme_file_writes_too(): void {
		define( 'DISALLOW_FILE_MODS', true );
		$this->setUp();

		self::assertFalse( Permissions::theme_file_writes_allowed() );

		$patch = ( new ThemeFilePatch() )->execute( [ 'path' => 'style.css', 'mode' => 'append', 'content' => 'a{}' ] );
		self::assertInstanceOf( \WP_Error::class, $patch );
		self::assertSame( 'stonewright_file_edit_disabled', $patch->get_error_code() );
		self::assertSame( "/* theme */\n", (string) file_get_contents( $this->theme_dir . '/style.css' ) );
		$this->tearDown();
	}
}
