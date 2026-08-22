<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\Tests\Unit\Abilities\Themes;

use PHPUnit\Framework\TestCase;
use Stonewright\WpMcp\Abilities\Themes\ThemeChromeGet;
use Stonewright\WpMcp\Abilities\Themes\ThemeChromeUpdate;
use Stonewright\WpMcp\Core\AbilityRegistry;
use Stonewright\WpMcp\Security\Backup;
use Stonewright\WpMcp\Security\ConfirmationToken;

/**
 * @covers \Stonewright\WpMcp\Abilities\Themes\ThemeChromeGet
 * @covers \Stonewright\WpMcp\Abilities\Themes\ThemeChromeUpdate
 * @covers \Stonewright\WpMcp\Expertise\ThemeChrome
 */
final class ThemeChromeTest extends TestCase {

	protected function setUp(): void {
		$GLOBALS['stonewright_test_user_caps'] = [
			'edit_posts'         => true,
			'edit_theme_options' => true,
			'read'               => true,
		];
		$GLOBALS['stonewright_test_user_logged_in']  = true;
		$GLOBALS['stonewright_test_current_user_id'] = 1;
		$GLOBALS['stonewright_test_options']         = [ 'stonewright_mode' => 'development' ];
		$GLOBALS['stonewright_test_theme_mods']      = [];
		$GLOBALS['stonewright_test_wpdb_inserts']    = [];
		unset( $GLOBALS['stonewright_test_stylesheet'], $GLOBALS['stonewright_test_template'] );
	}

	protected function tearDown(): void {
		$GLOBALS['stonewright_test_user_caps']    = [];
		$GLOBALS['stonewright_test_options']      = [];
		$GLOBALS['stonewright_test_theme_mods']   = [];
		unset( $GLOBALS['stonewright_test_stylesheet'], $GLOBALS['stonewright_test_template'] );
	}

	public function test_registry_exposes_theme_chrome_pair(): void {
		$names = array_map(
			static fn( string $class ): string => ( new $class() )->name(),
			AbilityRegistry::list()
		);

		self::assertContains( 'stonewright/theme-chrome-get', $names );
		self::assertContains( 'stonewright/theme-chrome-update', $names );
	}

	public function test_get_reports_inactive_theme_without_throwing(): void {
		$result = ( new ThemeChromeGet() )->execute( [ 'theme' => 'generatepress' ] );

		self::assertIsArray( $result );
		self::assertSame( 'generatepress', $result['theme'] );
		self::assertFalse( $result['active'] );
		self::assertSame( [], $result['writable'] );
	}

	public function test_get_returns_live_generatepress_chrome_keys(): void {
		$GLOBALS['stonewright_test_stylesheet'] = 'generatepress';
		$GLOBALS['stonewright_test_template']   = 'generatepress';
		$GLOBALS['stonewright_test_options']['generate_settings'] = [
			'background_color'        => '#ffffff',
			'font_body'               => 'Inter',
			'header_layout_setting'   => 'fluid-header',
			'footer_widget_setting'   => '3',
			'blog_content'            => 'excerpt',
		];

		$result = ( new ThemeChromeGet() )->execute( [ 'theme' => 'generatepress' ] );

		self::assertIsArray( $result );
		self::assertTrue( $result['active'] );
		self::assertSame( '#ffffff', $result['colors']['background_color'] );
		self::assertSame( 'Inter', $result['typography']['font_body'] );
		self::assertSame( 'fluid-header', $result['header']['header_layout_setting'] );
		self::assertSame( '3', $result['footer']['footer_widget_setting'] );
		self::assertArrayNotHasKey( 'blog_content', $result['colors'] );
		$writable_keys = array_column( $result['writable'], 'key' );
		self::assertContains( 'background_color', $writable_keys );
		self::assertNotContains( 'blog_content', $writable_keys );
	}

	public function test_update_dry_run_does_not_write_or_snapshot(): void {
		$GLOBALS['stonewright_test_stylesheet'] = 'generatepress';
		$GLOBALS['stonewright_test_template']   = 'generatepress';
		$GLOBALS['stonewright_test_options']['generate_settings'] = [
			'background_color' => '#ffffff',
		];

		$result = ( new ThemeChromeUpdate() )->execute(
			[
				'theme'   => 'generatepress',
				'dry_run' => true,
				'colors'  => [ 'background_color' => '#111111' ],
			]
		);

		self::assertIsArray( $result );
		self::assertTrue( $result['dry_run'] );
		self::assertTrue( $result['changed'] );
		self::assertSame( '#ffffff', $GLOBALS['stonewright_test_options']['generate_settings']['background_color'] );
		self::assertSame( [], get_option( Backup::OPTION_SNAPSHOTS, [] ) );
	}

	public function test_update_rejects_unknown_keys(): void {
		$GLOBALS['stonewright_test_stylesheet'] = 'generatepress';
		$GLOBALS['stonewright_test_template']   = 'generatepress';
		$GLOBALS['stonewright_test_options']['generate_settings'] = [
			'background_color' => '#ffffff',
		];

		$result = ( new ThemeChromeUpdate() )->execute(
			[
				'theme'   => 'generatepress',
				'dry_run' => true,
				'colors'  => [ 'invented_accent' => '#ff00aa' ],
			]
		);

		self::assertInstanceOf( \WP_Error::class, $result );
		self::assertSame( 'stonewright_unknown_chrome_key', $result->get_error_code() );
	}

	public function test_update_apply_snapshots_and_writes_live_keys(): void {
		$GLOBALS['stonewright_test_stylesheet'] = 'generatepress';
		$GLOBALS['stonewright_test_template']   = 'generatepress';
		$GLOBALS['stonewright_test_options']['generate_settings'] = [
			'background_color' => '#ffffff',
		];

		$result = ( new ThemeChromeUpdate() )->execute(
			[
				'theme'   => 'generatepress',
				'dry_run' => false,
				'colors'  => [ 'background_color' => '#111111' ],
			]
		);

		self::assertIsArray( $result );
		self::assertFalse( $result['dry_run'] );
		self::assertTrue( $result['changed'] );
		self::assertTrue( $result['effect_verified'] );
		self::assertSame( '#111111', $GLOBALS['stonewright_test_options']['generate_settings']['background_color'] );
		$snapshots = get_option( Backup::OPTION_SNAPSHOTS, [] );
		self::assertNotEmpty( $snapshots );
		$audit = $GLOBALS['stonewright_test_wpdb_inserts'] ?? [];
		$abilities = array_column( array_column( $audit, 'data' ), 'ability_name' );
		self::assertContains( 'stonewright/theme-chrome-update', $abilities );
	}

	public function test_update_requires_confirmation_in_production_safe(): void {
		$GLOBALS['stonewright_test_stylesheet'] = 'generatepress';
		$GLOBALS['stonewright_test_template']   = 'generatepress';
		$GLOBALS['stonewright_test_options']    = [
			'stonewright_mode'    => 'production-safe',
			'generate_settings'   => [ 'background_color' => '#ffffff' ],
		];

		$blocked = ( new ThemeChromeUpdate() )->execute(
			[
				'theme'   => 'generatepress',
				'dry_run' => false,
				'colors'  => [ 'background_color' => '#111111' ],
			]
		);

		self::assertInstanceOf( \WP_Error::class, $blocked );
		self::assertSame( 'stonewright_confirmation_required', $blocked->get_error_code() );

		$args  = [
			'theme'   => 'generatepress',
			'dry_run' => false,
			'colors'  => [ 'background_color' => '#111111' ],
		];
		$token = ConfirmationToken::issue( 'stonewright/theme-chrome-update', $args );
		$ok    = ( new ThemeChromeUpdate() )->execute( $args + [ 'confirmation_token' => $token ] );

		self::assertIsArray( $ok );
		self::assertSame( '#111111', $GLOBALS['stonewright_test_options']['generate_settings']['background_color'] );
	}

	public function test_update_writes_kadence_theme_mods(): void {
		$GLOBALS['stonewright_test_stylesheet'] = 'kadence';
		$GLOBALS['stonewright_test_template']   = 'kadence';
		$GLOBALS['stonewright_test_theme_mods'] = [
			'kadence_global_palette' => [ '#111111' ],
			'header_desktop_items'   => [ 'logo' ],
		];

		$dry = ( new ThemeChromeUpdate() )->execute(
			[
				'theme'   => 'kadence',
				'dry_run' => true,
				'colors'  => [ 'kadence_global_palette' => [ '#abcdef' ] ],
			]
		);
		self::assertIsArray( $dry );
		self::assertSame( [ '#111111' ], $GLOBALS['stonewright_test_theme_mods']['kadence_global_palette'] );

		$apply = ( new ThemeChromeUpdate() )->execute(
			[
				'theme'   => 'kadence',
				'dry_run' => false,
				'colors'  => [ 'kadence_global_palette' => [ '#abcdef' ] ],
			]
		);
		self::assertIsArray( $apply );
		self::assertSame( [ '#abcdef' ], $GLOBALS['stonewright_test_theme_mods']['kadence_global_palette'] );
		self::assertSame( [ 'logo' ], $GLOBALS['stonewright_test_theme_mods']['header_desktop_items'] );
	}

	public function test_write_permission_uses_theme_options_not_open_gate(): void {
		$GLOBALS['stonewright_test_user_caps'] = [ 'edit_posts' => true, 'read' => true ];

		$allowed = ( new ThemeChromeUpdate() )->permission_callback(
			[
				'theme'   => 'blocksy',
				'dry_run' => false,
			]
		);

		self::assertFalse( $allowed );
	}
}
