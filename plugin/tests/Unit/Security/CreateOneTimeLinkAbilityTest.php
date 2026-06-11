<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\Tests\Unit\Security;

use PHPUnit\Framework\TestCase;
use Stonewright\WpMcp\Abilities\Security\CreateOneTimeLink;
use Stonewright\WpMcp\Security\OneTimeLink;

/**
 * @covers \Stonewright\WpMcp\Abilities\Security\CreateOneTimeLink
 */
final class CreateOneTimeLinkAbilityTest extends TestCase {

	protected function setUp(): void {
		$GLOBALS['stonewright_test_transients'] = [];
		$GLOBALS['stonewright_test_current_user_id'] = 77;
	}

	protected function tearDown(): void {
		$GLOBALS['stonewright_test_transients'] = [];
		$GLOBALS['stonewright_test_current_user_id'] = 0;
		$GLOBALS['stonewright_test_user_caps'] = [];
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
}
