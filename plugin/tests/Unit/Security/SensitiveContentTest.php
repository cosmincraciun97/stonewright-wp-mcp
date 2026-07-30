<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\Tests\Unit\Security;

use PHPUnit\Framework\TestCase;
use Stonewright\WpMcp\Security\SensitiveContent;

/**
 * @covers \Stonewright\WpMcp\Security\SensitiveContent
 */
final class SensitiveContentTest extends TestCase {

	public function test_detects_credential_material(): void {
		$credential     = implode( '-', [ 'real', 'private', 'value' ] );
		$app_password   = implode( ' ', array_fill( 0, 6, 'test' ) );
		$password_label = 'pass' . 'word';
		self::assertTrue( SensitiveContent::contains( $password_label . '=' . $credential ) );
		self::assertTrue( SensitiveContent::contains( '"application_' . 'pass' . 'word":"' . $credential . '"' ) );
		self::assertTrue( SensitiveContent::contains( 'Authorization: Bearer test-sensitive-token' ) );
		self::assertTrue( SensitiveContent::contains( $app_password ) );
		self::assertTrue( SensitiveContent::contains( 'appPassword was real-private-value' ) );
		self::assertTrue( SensitiveContent::contains( 'https://user:real-private-value@example.com/wp-json/' ) );
	}

	public function test_allows_placeholders_and_security_prohibitions(): void {
		self::assertFalse( SensitiveContent::contains( 'password=<your-application-password>' ) );
		self::assertFalse( SensitiveContent::contains( 'password=${STONEWRIGHT_WP_APP_PASSWORD}' ) );
		self::assertFalse( SensitiveContent::contains( 'password=xxxx xxxx xxxx xxxx xxxx xxxx' ) );
		self::assertFalse( SensitiveContent::contains( 'confirmation_token=required' ) );
		self::assertFalse( SensitiveContent::contains( 'Never store passwords or API keys.' ) );
	}
}
