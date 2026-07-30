<?php
/**
 * OAuth consent contract tests.
 *
 * @package Stonewright\WpMcp
 */

declare( strict_types=1 );

namespace Stonewright\WpMcp\Tests\Unit\OAuth;

use PHPUnit\Framework\TestCase;
use Stonewright\WpMcp\OAuth\Consent;

final class ConsentTest extends TestCase {

	public function test_redirect_destination_hides_path_and_query(): void {
		self::assertSame(
			'https://client.example',
			Consent::redirect_destination_label( 'https://client.example/callback?secret=value' )
		);
		self::assertSame( 'cursor://callback', Consent::redirect_destination_label( 'cursor://callback' ) );
	}

	public function test_consent_requires_login_and_management_capability(): void {
		$GLOBALS['stonewright_test_user_logged_in'] = false;
		$GLOBALS['stonewright_test_user_caps']      = [ 'manage_options' => true ];
		self::assertFalse( Consent::can_authorize() );

		$GLOBALS['stonewright_test_user_logged_in'] = true;
		$GLOBALS['stonewright_test_user_caps']      = [];
		self::assertFalse( Consent::can_authorize() );

		$GLOBALS['stonewright_test_user_caps'] = [ 'manage_options' => true ];
		self::assertTrue( Consent::can_authorize() );
	}

	public function test_form_escapes_untrusted_client_name_and_destination(): void {
		ob_start();
		Consent::render_form( 'token', '<script>alert(1)</script>', 'https://client.example/callback' );
		$html = (string) ob_get_clean();

		self::assertStringNotContainsString( '<script>', $html );
		self::assertStringContainsString( '&lt;script&gt;alert(1)&lt;/script&gt;', $html );
		self::assertStringContainsString( 'https://client.example', $html );
		self::assertStringContainsString( 'test-nonce-stonewright_oauth_consent_token', $html );
	}
}
