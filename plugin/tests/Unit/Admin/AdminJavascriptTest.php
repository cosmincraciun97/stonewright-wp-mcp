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
}
