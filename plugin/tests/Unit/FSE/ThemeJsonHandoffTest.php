<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\Tests\Unit\FSE;

use PHPUnit\Framework\TestCase;
use Stonewright\WpMcp\Abilities\FSE\ThemeJsonHandoff;

/**
 * @covers \Stonewright\WpMcp\Abilities\FSE\ThemeJsonHandoff
 */
final class ThemeJsonHandoffTest extends TestCase {

	private string $theme_dir;

	protected function setUp(): void {
		$GLOBALS['stonewright_test_user_caps'] = [
			'manage_options'     => true,
			'edit_theme_options' => true,
			'edit_pages'         => true,
		];
		$GLOBALS['stonewright_test_user_logged_in']  = true;
		$GLOBALS['stonewright_test_current_user_id'] = 1;
		$GLOBALS['stonewright_test_options']         = [ 'stonewright_mode' => 'development' ];
		$GLOBALS['stonewright_test_direct_mode']     = false;
		$GLOBALS['stonewright_test_stylesheet']      = 'sw-child';
		$GLOBALS['stonewright_test_template']        = 'sw-parent';
		$GLOBALS['stonewright_test_audit_rows']      = [];

		$this->theme_dir = sys_get_temp_dir() . '/sw-theme-json-' . bin2hex( random_bytes( 4 ) );
		mkdir( $this->theme_dir );
		file_put_contents( $this->theme_dir . '/style.css', "/* child */\n" );
		file_put_contents(
			$this->theme_dir . '/theme.json',
			(string) wp_json_encode( [ 'version' => 3, 'settings' => [ 'color' => [ 'custom' => true ] ] ] )
		);
		$GLOBALS['stonewright_test_stylesheet_directory'] = $this->theme_dir;
	}

	protected function tearDown(): void {
		unset( $GLOBALS['stonewright_test_stylesheet_directory'], $GLOBALS['stonewright_test_stylesheet'], $GLOBALS['stonewright_test_template'], $GLOBALS['stonewright_test_direct_mode'] );
		$this->rmTree( $this->theme_dir );
	}

	public function test_ability_name_is_stable(): void {
		self::assertSame( 'stonewright/theme-json-handoff', ( new ThemeJsonHandoff() )->name() );
	}

	public function test_dry_run_stops_at_approval_without_writing(): void {
		$before = (string) file_get_contents( $this->theme_dir . '/theme.json' );
		$result = ( new ThemeJsonHandoff() )->execute(
			[
				'dry_run'    => true,
				'theme_json' => [
					'version'  => 3,
					'settings' => [
						'color' => [
							'palette' => [
								[ 'slug' => 'primary', 'color' => '#112233', 'name' => 'Primary' ],
							],
						],
					],
				],
				'native_gap' => [
					'reason'        => 'User global styles cannot own child-theme theme.json tokens.',
					'methods_tried' => [ 'typed_api', 'admin_form' ],
				],
			]
		);

		self::assertIsArray( $result );
		self::assertTrue( $result['dry_run'] );
		self::assertTrue( $result['agent_must_stop'] );
		self::assertTrue( $result['approval_required'] );
		self::assertNotSame( '', $result['approval_url'] );
		self::assertSame( 'theme.json', $result['path'] );
		self::assertArrayHasKey( 'before_bytes', $result );
		self::assertArrayHasKey( 'after_bytes', $result );
		self::assertArrayHasKey( 'change_summary', $result );
		self::assertSame( $before, (string) file_get_contents( $this->theme_dir . '/theme.json' ) );
	}

	public function test_apply_without_grant_does_not_write(): void {
		$before = (string) file_get_contents( $this->theme_dir . '/theme.json' );
		$result = ( new ThemeJsonHandoff() )->execute(
			[
				'dry_run'    => false,
				'theme_json' => [
					'version'  => 3,
					'settings' => [
						'color' => [
							'palette' => [
								[ 'slug' => 'primary', 'color' => '#112233', 'name' => 'Primary' ],
							],
						],
					],
				],
				'native_gap' => [
					'reason'        => 'User global styles cannot own child-theme theme.json tokens.',
					'methods_tried' => [ 'typed_api' ],
				],
			]
		);

		self::assertInstanceOf( \WP_Error::class, $result );
		self::assertSame( 'stonewright_custom_code_grant_required', $result->get_error_code() );
		self::assertSame( $before, (string) file_get_contents( $this->theme_dir . '/theme.json' ) );
	}

	public function test_pluginless_direct_must_not_write(): void {
		$GLOBALS['stonewright_test_direct_mode'] = true;
		$before = (string) file_get_contents( $this->theme_dir . '/theme.json' );
		$result = ( new ThemeJsonHandoff() )->execute(
			[
				'dry_run'    => false,
				'theme_json' => [ 'version' => 3 ],
			]
		);
		self::assertInstanceOf( \WP_Error::class, $result );
		self::assertSame( 'stonewright_direct_write_forbidden', $result->get_error_code() );
		self::assertSame( $before, (string) file_get_contents( $this->theme_dir . '/theme.json' ) );
	}

	public function test_rejects_invalid_theme_json_on_dry_run(): void {
		$result = ( new ThemeJsonHandoff() )->execute(
			[
				'dry_run'    => true,
				'theme_json' => [ 'version' => 'nope' ],
				'native_gap' => [
					'reason'        => 'Need child-theme tokens.',
					'methods_tried' => [ 'typed_api' ],
				],
			]
		);
		self::assertInstanceOf( \WP_Error::class, $result );
		self::assertSame( 'stonewright_theme_json_invalid', $result->get_error_code() );
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
