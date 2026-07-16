<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\Tests\Unit\Admin;

use PHPUnit\Framework\TestCase;

/**
 * @coversNothing
 */
final class AdminJavascriptTest extends TestCase {

	public function test_copy_buttons_have_clipboard_rejection_fallback(): void {
		$script = (string) file_get_contents( dirname( __DIR__, 3 ) . '/assets/admin/admin.js' );

		self::assertStringContainsString( 'navigator.clipboard.writeText', $script );
		self::assertStringContainsString( '.catch( fallbackCopy )', $script );
		self::assertStringContainsString( "document.execCommand( 'copy' )", $script );
	}

	public function test_declarative_button_handlers_prevent_default_form_submission(): void {
		$script = (string) file_get_contents( dirname( __DIR__, 3 ) . '/assets/admin/admin.js' );

		foreach ( [
			'data-stonewright-copy',
			'data-stonewright-secret-toggle',
			'data-stonewright-generate-token',
			'data-stonewright-text-toggle',
			'data-stonewright-text-collapse',
			'data-stonewright-toggle-target',
			'data-stonewright-hide-target',
			'data-stonewright-row-toggle',
			'data-stonewright-skill-toggle',
		] as $attribute ) {
			self::assertMatchesRegularExpression(
				'/' . preg_quote( $attribute, '/' ) . '.*?addEventListener\( \'click\', function \( event \).*?event\.preventDefault\(\);/s',
				$script,
				$attribute . ' click handler should prevent accidental form submission.'
			);
		}
	}

	public function test_bridge_token_generator_uses_browser_crypto(): void {
		$script = (string) file_get_contents( dirname( __DIR__, 3 ) . '/assets/admin/admin.js' );

		self::assertStringContainsString( 'data-stonewright-generate-token', $script );
		self::assertStringContainsString( 'crypto.getRandomValues', $script );
		self::assertStringContainsString( 'data-stonewright-bridge-token-source', $script );
		self::assertStringContainsString( 'COMPANION_BEARER_TOKEN=', $script );
	}

	public function test_connection_verify_posts_to_loopback_endpoint(): void {
		$script = (string) file_get_contents( dirname( __DIR__, 3 ) . '/assets/admin/admin.js' );

		self::assertStringContainsString( 'data-stonewright-connection-verify', $script );
		self::assertStringContainsString( 'initConnectionVerify', $script );
		self::assertStringContainsString( "method: 'POST'", $script );
		self::assertStringContainsString( 'MCP loopback verified', $script );
		self::assertStringContainsString( 'normalizeChecklistStatus', $script );
	}
}
