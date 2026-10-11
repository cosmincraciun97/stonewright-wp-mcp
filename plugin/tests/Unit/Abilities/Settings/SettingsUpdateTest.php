<?php
/**
 * settings-update: every key is checked before anything is written, the default role cannot be an elevated one,
 * and the result says what was written, what was already the value and what was skipped.
 * SPDX-License-Identifier: GPL-2.0-or-later
 *
 * @package Stonewright
 */

declare( strict_types=1 );

namespace Stonewright\WpMcp\Tests\Unit\Abilities\Settings;

use PHPUnit\Framework\TestCase;
use Stonewright\WpMcp\Abilities\Settings\SettingsUpdate;

/**
 * @covers \Stonewright\WpMcp\Abilities\Settings\SettingsUpdate
 */
final class SettingsUpdateTest extends TestCase {

	protected function setUp(): void {
		$GLOBALS['stonewright_test_user_logged_in'] = true;
		$GLOBALS['stonewright_test_user_caps']      = [ 'manage_options' => true ];
		$GLOBALS['stonewright_test_options']        = [
			'blogname'     => 'Site A',
			'default_role' => 'subscriber',
			'stonewright_mode' => 'development',
		];
		unset( $GLOBALS['stonewright_test_roles'], $GLOBALS['stonewright_test_update_option_failures'] );
	}

	protected function tearDown(): void {
		$GLOBALS['stonewright_test_user_caps']  = [];
		$GLOBALS['stonewright_test_options']    = [];
		unset( $GLOBALS['stonewright_test_roles'], $GLOBALS['stonewright_test_update_option_failures'] );
	}

	/** @return array<string, array{0: string}> */
	public static function elevated_roles(): array {
		return [
			'administrator'     => [ 'administrator' ],
			'manage_options'    => [ 'shop_manager' ],
			'promote_users'     => [ 'people_lead' ],
			'edit_users'        => [ 'people_editor' ],
			'create_users'      => [ 'people_creator' ],
			'delete_users'      => [ 'people_remover' ],
			'edit_plugins'      => [ 'plugin_editor' ],
			'install_plugins'   => [ 'plugin_installer' ],
		];
	}

	/**
	 * @dataProvider elevated_roles
	 */
	public function test_default_role_refuses_a_role_with_elevated_capabilities( string $role ): void {
		$GLOBALS['stonewright_test_roles'] = array_merge(
			stonewright_test_default_roles(),
			[
				'shop_manager'     => [ 'name' => 'Shop manager', 'capabilities' => [ 'manage_options' => true, 'read' => true ] ],
				'people_lead'      => [ 'name' => 'People lead', 'capabilities' => [ 'promote_users' => true ] ],
				'people_editor'    => [ 'name' => 'People editor', 'capabilities' => [ 'edit_users' => true ] ],
				'people_creator'   => [ 'name' => 'People creator', 'capabilities' => [ 'create_users' => true ] ],
				'people_remover'   => [ 'name' => 'People remover', 'capabilities' => [ 'delete_users' => true ] ],
				'plugin_editor'    => [ 'name' => 'Plugin editor', 'capabilities' => [ 'edit_plugins' => true ] ],
				'plugin_installer' => [ 'name' => 'Plugin installer', 'capabilities' => [ 'install_plugins' => true ] ],
			]
		);

		$result = ( new SettingsUpdate() )->execute( [ 'settings' => [ 'blogname' => 'Renamed', 'default_role' => $role ] ] );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'stonewright_settings_invalid_value', $result->get_error_code() );
		$this->assertSame( 'subscriber', get_option( 'default_role' ), 'The default role is untouched.' );
		$this->assertSame( 'Site A', get_option( 'blogname' ), 'Nothing else was written either.' );
	}

	public function test_default_role_refuses_a_role_that_does_not_exist(): void {
		$result = ( new SettingsUpdate() )->execute( [ 'settings' => [ 'default_role' => 'no_such_role' ] ] );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'stonewright_settings_invalid_value', $result->get_error_code() );
		$this->assertSame( 'subscriber', get_option( 'default_role' ) );
	}

	public function test_default_role_accepts_an_ordinary_role(): void {
		$result = ( new SettingsUpdate() )->execute( [ 'settings' => [ 'default_role' => 'contributor' ] ] );

		$this->assertSame( [ 'default_role' ], $result['updated'] );
		$this->assertSame( 'contributor', get_option( 'default_role' ) );
	}

	public function test_a_blocked_key_stops_the_call_before_any_key_is_written(): void {
		$result = ( new SettingsUpdate() )->execute( [ 'settings' => [ 'blogname' => 'Renamed', 'siteurl' => 'https://example.test' ] ] );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'stonewright_settings_blocked_key', $result->get_error_code() );
		$this->assertSame( 'Site A', get_option( 'blogname' ), 'The key before the blocked one was not written.' );
	}

	public function test_a_value_that_is_not_a_scalar_stops_the_call_before_any_key_is_written(): void {
		$result = ( new SettingsUpdate() )->execute( [ 'settings' => [ 'blogname' => 'Renamed', 'blogdescription' => [ 'not', 'a', 'string' ] ] ] );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'stonewright_settings_invalid_value', $result->get_error_code() );
		$this->assertSame( 'Site A', get_option( 'blogname' ) );
	}

	public function test_the_result_names_the_updated_unchanged_and_skipped_keys(): void {
		$result = ( new SettingsUpdate() )->execute(
			[
				'settings' => [
					'blogname'        => 'Renamed',
					'default_role'    => 'subscriber',
					'not_allowlisted' => 'x',
				],
			]
		);

		$this->assertSame( [ 'blogname' ], $result['updated'] );
		$this->assertSame( [ 'default_role' ], $result['unchanged'] );
		$this->assertSame( [ [ 'key' => 'not_allowlisted', 'reason' => 'not_allowlisted' ] ], $result['skipped'] );
		$this->assertSame( 'Renamed', get_option( 'blogname' ) );
		$this->assertArrayNotHasKey( 'not_allowlisted', $GLOBALS['stonewright_test_options'] );
	}

	public function test_a_failed_write_restores_the_keys_written_before_it(): void {
		$GLOBALS['stonewright_test_update_option_failures'] = [ 'blogdescription' => true ];
		$GLOBALS['stonewright_test_options']['blogdescription'] = 'Old tagline';

		$result = ( new SettingsUpdate() )->execute( [ 'settings' => [ 'blogname' => 'Renamed', 'blogdescription' => 'New tagline' ] ] );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'stonewright_settings_write_failed', $result->get_error_code() );
		$this->assertSame( 'Site A', get_option( 'blogname' ), 'The earlier write was undone.' );
		$this->assertSame( 'Old tagline', get_option( 'blogdescription' ) );
	}
}
