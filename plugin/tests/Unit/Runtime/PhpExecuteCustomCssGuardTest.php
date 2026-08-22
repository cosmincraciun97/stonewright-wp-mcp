<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\Tests\Unit\Runtime;

use PHPUnit\Framework\TestCase;
use Stonewright\WpMcp\Abilities\Runtime\PhpExecute;
use Stonewright\WpMcp\Security\ProtectedCustomCssWriteGuard;

/**
 * @covers \Stonewright\WpMcp\Security\ProtectedCustomCssWriteGuard
 * @covers \Stonewright\WpMcp\Abilities\Runtime\PhpExecute
 */
final class PhpExecuteCustomCssGuardTest extends TestCase {

	protected function setUp(): void {
		$GLOBALS['stonewright_test_user_caps']          = [
			'read'           => true,
			'manage_options' => true,
		];
		$GLOBALS['stonewright_test_user_logged_in']     = true;
		$GLOBALS['stonewright_test_current_user_id']    = 17;
		$GLOBALS['stonewright_test_wpdb_inserts']       = [];
		$GLOBALS['stonewright_test_custom_css']         = '';
		$GLOBALS['stonewright_test_options']            = [
			'stonewright_mode'                 => 'development',
			'stonewright_essential_tools_mode' => true,
			'stonewright_disabled_abilities'   => [],
		];
	}

	protected function tearDown(): void {
		$GLOBALS['stonewright_test_user_caps']       = [];
		$GLOBALS['stonewright_test_wpdb_inserts']    = [];
		$GLOBALS['stonewright_test_options']         = [];
		$GLOBALS['stonewright_test_transients']      = [];
		$GLOBALS['stonewright_test_custom_css']      = '';
	}

	public function test_wp_update_custom_css_post_is_blocked_and_points_at_theme_custom_css(): void {
		$result = ( new PhpExecute() )->execute(
			[
				'code' => 'wp_update_custom_css_post(".x{color:red}"); return true;',
			]
		);

		self::assertInstanceOf( \WP_Error::class, $result );
		self::assertSame( 'stonewright_php_custom_css_write_blocked', $result->get_error_code() );
		self::assertFalse( (bool) ( $result->get_error_data()['retryable'] ?? true ) );
		self::assertSame( 'stonewright/theme-custom-css', $result->get_error_data()['gated_tool'] );
		self::assertSame( 'stonewright-theme-custom-css', $result->get_error_data()['gated_mcp_tool'] );
		self::assertSame( '', $GLOBALS['stonewright_test_custom_css'] );
	}

	public function test_direct_option_custom_css_write_is_blocked(): void {
		$result = ( new PhpExecute() )->execute(
			[
				'code' => 'update_option("elementor_custom_css", ".kit{display:none}"); return true;',
			]
		);

		self::assertInstanceOf( \WP_Error::class, $result );
		self::assertSame( 'stonewright_php_custom_css_write_blocked', $result->get_error_code() );
		self::assertArrayNotHasKey( 'elementor_custom_css', $GLOBALS['stonewright_test_options'] );
	}

	public function test_page_settings_custom_css_meta_write_is_blocked(): void {
		$result = ( new PhpExecute() )->execute(
			[
				'code' => 'update_post_meta(10, "_elementor_page_settings", ["custom_css" => ".x{}"]); return true;',
			]
		);

		self::assertInstanceOf( \WP_Error::class, $result );
		self::assertTrue(
			in_array(
				$result->get_error_code(),
				[ 'stonewright_php_custom_css_write_blocked', 'stonewright_php_elementor_raw_write_blocked' ],
				true
			)
		);
	}

	public function test_inspect_allows_reading_custom_css(): void {
		$ok = ProtectedCustomCssWriteGuard::inspect( 'return wp_get_custom_css();' );
		self::assertTrue( $ok );
	}
}
