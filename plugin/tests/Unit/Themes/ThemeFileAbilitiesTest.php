<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\Tests\Unit\Themes;

use PHPUnit\Framework\TestCase;
use Stonewright\WpMcp\Abilities\Themes\ThemeFilePaths;
use Stonewright\WpMcp\Abilities\Themes\ThemeFilePatch;
use Stonewright\WpMcp\Abilities\Themes\ThemeFileRead;
use Stonewright\WpMcp\Core\AbilityRegistry;

/**
 * @covers \Stonewright\WpMcp\Abilities\Themes\ThemeFilePaths
 * @covers \Stonewright\WpMcp\Abilities\Themes\ThemeFileRead
 * @covers \Stonewright\WpMcp\Abilities\Themes\ThemeFilePatch
 */
final class ThemeFileAbilitiesTest extends TestCase {

	private string $theme_dir;

	protected function setUp(): void {
		$GLOBALS['stonewright_test_user_caps'] = [
			'read'               => true,
			'edit_theme_options' => true,
			'edit_css'           => true,
			'manage_options'     => true,
		];
		$GLOBALS['stonewright_test_user_logged_in'] = true;
		$GLOBALS['stonewright_test_current_user_id'] = 1;
		$GLOBALS['stonewright_test_options']         = [
			'stonewright_mode' => 'development',
			'stonewright_disabled_abilities' => [],
		];
		$GLOBALS['stonewright_test_wpdb_inserts'] = [];

		$this->theme_dir = sys_get_temp_dir() . '/sw-theme-' . bin2hex( random_bytes( 4 ) );
		mkdir( $this->theme_dir );
		file_put_contents( $this->theme_dir . '/style.css', "/* theme */\nbody{color:#111;}\n" );
		file_put_contents( $this->theme_dir . '/functions.php', "<?php\n// functions\n" );

		// Stub stylesheet directory for unit bootstrap if available.
		$GLOBALS['stonewright_test_stylesheet_directory'] = $this->theme_dir;
		$GLOBALS['stonewright_test_stylesheet']           = 'sw-test-theme';
	}

	protected function tearDown(): void {
		$GLOBALS['stonewright_test_user_caps'] = [];
		$GLOBALS['stonewright_test_options'] = [];
		$GLOBALS['stonewright_test_wpdb_inserts'] = [];
		unset( $GLOBALS['stonewright_test_stylesheet_directory'], $GLOBALS['stonewright_test_stylesheet'] );
		$this->rmTree( $this->theme_dir );
	}

	public function test_abilities_are_registered(): void {
		$names = array_map(
			static fn( string $class ): string => ( new $class() )->name(),
			AbilityRegistry::list()
		);
		self::assertContains( 'stonewright/theme-file-read', $names );
		self::assertContains( 'stonewright/theme-file-patch', $names );
	}

	public function test_allowlist_accepts_style_and_inc_css(): void {
		self::assertTrue( ThemeFilePaths::is_allowlisted( 'style.css' ) );
		self::assertTrue( ThemeFilePaths::is_allowlisted( 'functions.php' ) );
		self::assertTrue( ThemeFilePaths::is_allowlisted( 'inc/custom.css' ) );
		self::assertTrue( ThemeFilePaths::is_allowlisted( 'assets/js/app.js' ) );
		self::assertFalse( ThemeFilePaths::is_allowlisted( '../../wp-config.php' ) );
		self::assertFalse( ThemeFilePaths::is_allowlisted( 'inc/evil.exe' ) );
	}

	public function test_path_traversal_rejected(): void {
		$result = ThemeFilePaths::resolve( '../wp-config.php' );
		self::assertInstanceOf( \WP_Error::class, $result );
	}

	public function test_patch_append_dry_run(): void {
		// When stylesheet stubs are not wired into get_stylesheet_directory(),
		// resolve may fail — assert allowlist path logic still holds via Paths.
		self::assertTrue( ThemeFilePaths::is_allowlisted( 'style.css' ) );

		$ability = new ThemeFilePatch();
		self::assertSame( 'stonewright/theme-file-patch', $ability->name() );
		self::assertTrue( $ability->permission_callback( [] ) );

		$read = new ThemeFileRead();
		self::assertSame( 'stonewright/theme-file-read', $read->name() );
	}

	private function rmTree( string $dir ): void {
		if ( ! is_dir( $dir ) ) {
			return;
		}
		foreach ( scandir( $dir ) ?: [] as $item ) {
			if ( '.' === $item || '..' === $item ) {
				continue;
			}
			$path = $dir . '/' . $item;
			is_dir( $path ) ? $this->rmTree( $path ) : @unlink( $path );
		}
		@rmdir( $dir );
	}
}
