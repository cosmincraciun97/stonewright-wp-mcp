<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\Tests\Unit\Assets;

use PHPUnit\Framework\TestCase;

/**
 * The file field of the shared layer: the native input stays the control, its button is drawn like the layer's
 * secondary button, and the Skills import uses it inside the drop zone.
 *
 * @coversNothing
 */
final class SwUiFileControlTest extends TestCase {

	private static function css(): string {
		return CssSource::read( 'admin/sw-ui.css' );
	}

	private static function value( string $selector, string $property ): ?string {
		return CssSource::value_of( CssSource::rule_declarations( self::css(), $selector ), $property );
	}

	public function test_the_button_of_the_file_field_is_the_secondary_button_at_the_default_size(): void {
		$selector = ':where(.sw-ui) .sw-ui-file::file-selector-button';

		self::assertSame( 'var(--sw-control-h)', self::value( $selector, 'min-height' ) );
		self::assertSame( '0 var(--sw-space-4)', self::value( $selector, 'padding' ) );
		self::assertSame( '1px solid var(--sw-border-control)', self::value( $selector, 'border' ) );
		self::assertSame( 'var(--sw-radius-control)', self::value( $selector, 'border-radius' ) );
		self::assertSame( 'var(--sw-surface)', self::value( $selector, 'background' ) );
		self::assertSame( 'var(--sw-text)', self::value( $selector, 'color' ) );
		self::assertSame( 'inherit', self::value( $selector, 'font' ) );
	}

	public function test_the_button_of_the_file_field_is_a_44px_touch_target_at_phone_width(): void {
		$css = self::css();

		self::assertSame( '44px', CssSource::value_of( CssSource::rule_declarations( $css, '.sw-ui .sw-ui-file', '@media (max-width: 782px)' ), 'min-height' ) );
		self::assertSame( '44px', CssSource::value_of( CssSource::rule_declarations( $css, ':where(.sw-ui) .sw-ui-file::file-selector-button', '@media (max-width: 782px)' ), 'min-height' ) );
	}

	public function test_the_field_is_covered_by_the_pointer_focus_rules_so_only_the_keyboard_draws_a_ring(): void {
		$css = CssSource::strip_comments( self::css() );

		self::assertSame( 2, preg_match_all( '/\.sw-ui :is\(a, button, summary, input:is\([^)]*\[type="file"\]\)[^{]*:focus(?::not\(:focus-visible\)|-visible) \{/', $css ), 'Both the no-ring rule and the keyboard ring name the file field.' );
		self::assertSame( '2px', self::value( '.sw-ui .sw-ui-file', 'outline-offset' ) );
	}

	public function test_the_skills_import_uses_the_file_field_of_the_layer_inside_its_labelled_drop_zone(): void {
		$script = CssSource::read( 'admin/skills.js' );

		self::assertMatchesRegularExpression( "/className: 'sw-ui-file',\s*attrs: \{\s*type: 'file',\s*id: 'sw-skills-file'/", $script );
		self::assertMatchesRegularExpression( "/className: 'sw-ui-input',\s*attrs: \{\s*type: 'search',\s*id: 'sw-skills-search'/", $script, 'The search field stays a text field.' );
		self::assertStringContainsString( "attrs: { for: 'sw-skills-file' }", $script, 'The field keeps its label.' );
		self::assertStringContainsString( "'sw-ui-dropzone'", $script );
	}
}
