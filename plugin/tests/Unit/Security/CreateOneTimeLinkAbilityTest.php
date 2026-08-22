<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\Tests\Unit\Security;

use PHPUnit\Framework\TestCase;
use Stonewright\WpMcp\Abilities\Security\CreateOneTimeLink;
use Stonewright\WpMcp\Security\ConfirmationToken;
use Stonewright\WpMcp\Security\OneTimeLink;

/**
 * @covers \Stonewright\WpMcp\Abilities\Security\CreateOneTimeLink
 */
final class CreateOneTimeLinkAbilityTest extends TestCase {

	protected function setUp(): void {
		$GLOBALS['stonewright_test_transients'] = [];
		$GLOBALS['stonewright_test_current_user_id'] = 77;
		$GLOBALS['stonewright_test_wpdb_inserts'] = [];
		$GLOBALS['stonewright_test_options'] = [
			'stonewright_mode' => 'development',
		];
	}

	protected function tearDown(): void {
		$GLOBALS['stonewright_test_transients'] = [];
		$GLOBALS['stonewright_test_current_user_id'] = 0;
		$GLOBALS['stonewright_test_user_caps'] = [];
		$GLOBALS['stonewright_test_wpdb_inserts'] = [];
		$GLOBALS['stonewright_test_options'] = [];
	}

	public function test_permission_requires_manage_options(): void {
		$ability = new CreateOneTimeLink();

		$GLOBALS['stonewright_test_user_caps']['manage_options'] = false;
		self::assertFalse( $ability->permission_callback( [] ) );

		$GLOBALS['stonewright_test_user_caps']['manage_options'] = true;
		self::assertTrue( $ability->permission_callback( [] ) );
	}

	public function test_execute_returns_short_lived_link_without_password_material(): void {
		$GLOBALS['stonewright_test_user_caps']['manage_options'] = true;

		$result = ( new CreateOneTimeLink() )->execute( [ 'ttl_seconds' => 60 ] );

		self::assertIsArray( $result );
		self::assertSame( 60, $result['expires_in'] );
		self::assertStringContainsString( 'stonewright_otl=', $result['url'] );
		self::assertStringNotContainsString( 'password', $result['url'] );
		self::assertStringNotContainsString( 'application', $result['url'] );

		parse_str( (string) parse_url( $result['url'], PHP_URL_QUERY ), $params );
		$token = (string) ( $params['stonewright_otl'] ?? '' );
		self::assertSame( 77, OneTimeLink::consume( $token ) );
		self::assertFalse( OneTimeLink::consume( $token ) );
	}

	public function test_input_schema_includes_confirmation_token(): void {
		$schema = ( new CreateOneTimeLink() )->input_schema();
		self::assertArrayHasKey( 'confirmation_token', $schema['properties'] );
		self::assertArrayHasKey( 'user_agent', $schema['properties'] );
	}

	public function test_user_agent_override_binds_redemption_to_the_browser(): void {
		$GLOBALS['stonewright_test_user_caps']['manage_options'] = true;
		$_SERVER['REMOTE_ADDR']     = '192.0.2.10';
		$_SERVER['HTTP_USER_AGENT'] = 'StonewrightMcp/1.0';

		$result = ( new CreateOneTimeLink() )->execute(
			[
				'ttl_seconds' => 60,
				'user_agent'  => 'Mozilla/5.0 PlaywrightVerify/1.0',
			]
		);

		self::assertIsArray( $result );
		parse_str( (string) parse_url( $result['url'], PHP_URL_QUERY ), $params );
		$token = (string) ( $params['stonewright_otl'] ?? '' );

		self::assertFalse( OneTimeLink::consume( $token ) );

		$_SERVER['HTTP_USER_AGENT'] = 'Mozilla/5.0 PlaywrightVerify/1.0';
		self::assertSame( 77, OneTimeLink::consume( $token ) );

		unset( $_SERVER['REMOTE_ADDR'], $_SERVER['HTTP_USER_AGENT'] );
	}

	public function test_production_safe_mode_requires_confirmation_token(): void {
		$GLOBALS['stonewright_test_user_caps']['manage_options'] = true;
		$GLOBALS['stonewright_test_options']['stonewright_mode'] = 'production-safe';

		$blocked = ( new CreateOneTimeLink() )->execute( [ 'ttl_seconds' => 60 ] );
		self::assertInstanceOf( \WP_Error::class, $blocked );
		self::assertSame( 'stonewright_confirmation_required', $blocked->get_error_code() );

		$args = [ 'ttl_seconds' => 60 ];
		$args['confirmation_token'] = ConfirmationToken::issue( 'stonewright/security-create-one-time-link', $args );
		$result = ( new CreateOneTimeLink() )->execute( $args );
		self::assertIsArray( $result );
		self::assertStringContainsString( 'stonewright_otl=', $result['url'] );
	}

	public function test_execute_is_audited(): void {
		$GLOBALS['stonewright_test_user_caps']['manage_options'] = true;

		$result = ( new CreateOneTimeLink() )->execute( [ 'ttl_seconds' => 90 ] );
		self::assertIsArray( $result );

		$inserts = array_values(
			array_filter(
				$GLOBALS['stonewright_test_wpdb_inserts'],
				static fn( array $insert ): bool => 'stonewright/security-create-one-time-link' === ( $insert['data']['ability_name'] ?? null )
			)
		);
		self::assertNotEmpty( $inserts );
		self::assertSame( 'ok', $inserts[0]['data']['result_status'] ?? null );
	}
}
