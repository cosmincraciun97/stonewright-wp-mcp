<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\Tests\Unit\Abilities\System;

use PHPUnit\Framework\TestCase;
use Stonewright\WpMcp\Abilities\System\ToolProfile;
use Stonewright\WpMcp\Core\AbilityRegistry;
use Stonewright\WpMcp\Security\ConfirmationToken;

/**
 * Switching to the full profile (the one that lists php-execute and the other code-running abilities)
 * is a choice an administrator makes in wp-admin. The tool-profile ability never makes it, in any mode.
 *
 * @covers \Stonewright\WpMcp\Abilities\System\ToolProfile
 */
final class ToolProfileFullRequiresAdminTest extends TestCase {

	protected function setUp(): void {
		$GLOBALS['stonewright_test_transients'] = [];
		$GLOBALS['stonewright_test_options']    = [
			'stonewright_enabled'                   => true,
			'stonewright_disabled_abilities'        => [],
			'stonewright_essential_tools_mode'      => true,
			'stonewright_essential_extra_abilities' => [],
			'stonewright_mcp_surface'               => 'essential',
			'stonewright_last_tool_profile'         => '',
			'stonewright_mode'                      => 'development',
		];
		$_SERVER['HTTP_MCP_SESSION_ID']         = 'full-requires-admin-test';
	}

	protected function tearDown(): void {
		unset( $_SERVER['HTTP_MCP_SESSION_ID'] );
		$GLOBALS['stonewright_test_transients'] = [];
		$GLOBALS['stonewright_test_options']    = [];
	}

	/**
	 * @return array<string, array{string}>
	 */
	public static function modes(): array {
		return [
			'development'     => [ 'development' ],
			'staging'         => [ 'staging' ],
			'production-safe' => [ 'production-safe' ],
		];
	}

	/**
	 * @dataProvider modes
	 */
	public function test_a_request_for_full_is_refused_in_every_mode_and_changes_nothing( string $mode ): void {
		$GLOBALS['stonewright_test_options']['stonewright_mode'] = $mode;

		$result = ( new ToolProfile() )->execute( [ 'action' => 'activate', 'profile' => 'full' ] );

		self::assertInstanceOf( \WP_Error::class, $result );
		self::assertSame( 'stonewright_tool_profile_full_requires_admin', $result->get_error_code() );
		$data = $result->get_error_data();
		self::assertSame( 403, $data['status'] );
		self::assertTrue( $data['approval_required'] );
		self::assertTrue( $data['agent_must_stop'] );
		self::assertFalse( $data['retryable'] );
		self::assertSame( 'blocked', $data['execution_status'] );
		self::assertStringContainsString( 'page=stonewright', $data['approval_url'] );
		self::assertStringContainsString( 'stonewright_mcp_surface', $data['approval_url'] );

		self::assertNull( AbilityRegistry::session_tool_profile(), 'No session profile was written.' );
		self::assertSame( 'essential', AbilityRegistry::mcp_surface() );
		self::assertSame( '', get_option( 'stonewright_last_tool_profile' ) );
		self::assertArrayNotHasKey( 'stonewright_tools_changed_at', $GLOBALS['stonewright_test_options'] );
	}

	public function test_the_default_action_is_activate_so_a_call_without_action_is_refused_too(): void {
		$result = ( new ToolProfile() )->execute( [ 'profile' => 'FULL ' ] );

		self::assertInstanceOf( \WP_Error::class, $result );
		self::assertSame( 'stonewright_tool_profile_full_requires_admin', $result->get_error_code() );
		self::assertNull( AbilityRegistry::session_tool_profile() );
	}

	public function test_a_valid_production_safe_confirmation_token_does_not_unlock_full(): void {
		$GLOBALS['stonewright_test_options']['stonewright_mode'] = 'production-safe';
		$args  = [ 'action' => 'activate', 'profile' => 'full' ];
		$token = ConfirmationToken::issue( 'stonewright/tool-profile', $args );

		$result = ( new ToolProfile() )->execute( $args + [ 'confirmation_token' => $token ] );

		self::assertInstanceOf( \WP_Error::class, $result );
		self::assertSame( 'stonewright_tool_profile_full_requires_admin', $result->get_error_code() );
		self::assertNull( AbilityRegistry::session_tool_profile() );
	}

	public function test_a_bootstrap_surface_is_not_widened_to_full(): void {
		update_option( 'stonewright_mcp_surface', 'bootstrap', false );

		$result = ( new ToolProfile() )->execute( [ 'action' => 'activate', 'profile' => 'full' ] );

		self::assertInstanceOf( \WP_Error::class, $result );
		self::assertSame( 'bootstrap', AbilityRegistry::mcp_surface() );
	}

	public function test_the_refusal_reaches_an_mcp_client_in_the_message_with_the_settings_link(): void {
		$ability = new ToolProfile();
		$call    = AbilityRegistry::registration_args( $ability )['execute_callback'];

		$result = $call( [ 'action' => 'activate', 'profile' => 'full' ] );

		self::assertInstanceOf( \WP_Error::class, $result );
		self::assertSame( 1, preg_match( '/(\{.*\})\s*$/', $result->get_error_message(), $match ), $result->get_error_message() );
		$payload = json_decode( $match[1], true );
		self::assertSame( 'stonewright_tool_profile_full_requires_admin', $payload['code'] );
		self::assertTrue( $payload['approval_required'] );
		self::assertTrue( $payload['agent_must_stop'] );
		self::assertStringContainsString( 'stonewright_mcp_surface', $payload['approval_url'] );
	}

	public function test_the_other_profiles_still_switch(): void {
		$result = ( new ToolProfile() )->execute( [ 'action' => 'activate', 'profile' => 'elementor-design' ] );

		self::assertIsArray( $result );
		self::assertSame( 'elementor-design', $result['profile'] );
		self::assertSame( 'elementor-design', AbilityRegistry::session_tool_profile()['profile'] );
	}

	public function test_resolve_still_describes_full_without_changing_anything(): void {
		$result = ( new ToolProfile() )->execute( [ 'action' => 'resolve', 'profile' => 'full' ] );

		self::assertIsArray( $result );
		self::assertSame( 'full', $result['profile'] );
		self::assertFalse( $result['tools_changed'] );
		self::assertNull( AbilityRegistry::session_tool_profile() );
		self::assertSame( 'essential', AbilityRegistry::mcp_surface() );
	}

	public function test_extras_still_cannot_add_php_execute(): void {
		$result = ( new ToolProfile() )->execute(
			[
				'action'  => 'activate',
				'profile' => 'essential',
				'extras'  => [ 'stonewright/php-execute' ],
			]
		);

		self::assertInstanceOf( \WP_Error::class, $result );
		self::assertSame( [], (array) get_option( 'stonewright_essential_extra_abilities', [] ) );
	}

	public function test_a_site_whose_administrator_already_chose_full_is_not_refused(): void {
		update_option( 'stonewright_mcp_surface', 'full', false );
		update_option( 'stonewright_essential_tools_mode', false, false );

		$result = ( new ToolProfile() )->execute( [ 'action' => 'activate', 'profile' => 'full' ] );

		self::assertIsArray( $result );
		self::assertSame( 'full', $result['profile'] );
		self::assertSame( 'full', AbilityRegistry::mcp_surface() );
	}

	public function test_the_description_and_schema_say_an_administrator_chooses_full(): void {
		$ability = new ToolProfile();

		self::assertStringContainsString( 'wp-admin', $ability->description() );
		self::assertStringContainsString( 'full', $ability->description() );
		$profile = $ability->input_schema()['properties']['profile']['description'];
		self::assertStringContainsString( 'wp-admin', $profile );
	}
}
