<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\Tests\Unit\Admin;

use PHPUnit\Framework\TestCase;
use Stonewright\WpMcp\Admin\SetupState;

/**
 * @covers \Stonewright\WpMcp\Admin\SetupState
 */
final class SetupStateTest extends TestCase {

	protected function setUp(): void {
		$GLOBALS['stonewright_test_options'] = [
			'stonewright_enabled'            => true,
			'stonewright_mode'               => 'staging',
			'stonewright_mcp_surface'        => 'essential',
			'stonewright_elementor_v4_atomic'=> false,
			'stonewright_install_mode'       => 'auto',
			'stonewright_site_alias'         => 'site-a',
			'stonewright_site_environment'   => 'staging',
		];
		$GLOBALS['stonewright_test_user_meta']       = [];
		$GLOBALS['stonewright_test_current_user_id'] = 3;
		$GLOBALS['stonewright_test_home_url']        = 'https://example.test/';
	}

	protected function tearDown(): void {
		$GLOBALS['stonewright_test_options']         = [];
		$GLOBALS['stonewright_test_user_meta']       = [];
		$GLOBALS['stonewright_test_current_user_id'] = 0;
		unset( $GLOBALS['stonewright_test_home_url'] );
	}

	public function test_export_includes_typed_setup_fields(): void {
		$state = SetupState::export( 3 );
		self::assertSame( 'staging', $state['wordpress_mode'] );
		self::assertSame( 'essential', $state['mcp_surface'] );
		self::assertTrue( $state['abilities_requested'] );
		self::assertArrayHasKey( 'abilities_effective', $state );
		self::assertFalse( $state['elementor_v4_atomic'] );
		self::assertSame( 'auto', $state['install_mode'] );
		self::assertSame( 'site-a', $state['site_alias'] );
		self::assertSame( 'staging', $state['site_environment'] );
		self::assertArrayHasKey( 'auth_method', $state );
		self::assertArrayHasKey( 'selected_client', $state );
		self::assertArrayHasKey( 'client_startup_profile', $state );
	}

	public function test_persist_partial_does_not_clobber_unrelated_fields(): void {
		$revision = \Stonewright\WpMcp\Core\AbilityRegistry::surface_revision();
		SetupState::persist_partial(
			[
				'mcp_surface' => 'bootstrap',
			],
			3
		);

		self::assertSame( 'bootstrap', get_option( 'stonewright_mcp_surface' ) );
		// Unrelated site alias survives the scoped write.
		self::assertSame( 'site-a', get_option( 'stonewright_site_alias' ) );
		self::assertSame( 'staging', get_option( 'stonewright_mode' ) );
		self::assertTrue( (bool) get_option( 'stonewright_enabled', false ) );
		self::assertSame( $revision + 1, \Stonewright\WpMcp\Core\AbilityRegistry::surface_revision() );
	}

	public function test_each_runtime_control_bumps_revision_without_clobbering_other_setup(): void {
		$revision = \Stonewright\WpMcp\Core\AbilityRegistry::surface_revision();
		$state = SetupState::persist_partial(
			[
				'wordpress_mode'      => 'development',
				'abilities_requested' => false,
				'elementor_v4_atomic' => true,
			],
			3
		);

		self::assertSame( $revision + 1, \Stonewright\WpMcp\Core\AbilityRegistry::surface_revision() );
		self::assertSame( 'development', $state['wordpress_mode'] );
		self::assertFalse( $state['abilities_requested'] );
		self::assertTrue( $state['elementor_v4_atomic'] );
		self::assertSame( 'site-a', $state['site_alias'] );
	}

	public function test_auth_method_and_client_survive_partial_mode_write(): void {
		SetupState::persist_partial(
			[
				'auth_method'     => 'application-password',
				'selected_client' => 'cursor',
				'transport_method'=> 'http',
			],
			3
		);

		SetupState::persist_partial( [ 'wordpress_mode' => 'development' ], 3 );

		$state = SetupState::export( 3 );
		self::assertSame( 'development', $state['wordpress_mode'] );
		self::assertSame( 'application-password', $state['auth_method'] );
		self::assertSame( 'cursor', $state['selected_client'] );
		self::assertSame( 'http', $state['transport_method'] );
	}

	public function test_client_startup_profile_follows_explicit_full_surface(): void {
		$GLOBALS['stonewright_test_options']['stonewright_mcp_surface'] = 'full';
		$profile = SetupState::client_startup_profile( 3, 'cursor' );
		self::assertSame( 'full', $profile );
	}
}
