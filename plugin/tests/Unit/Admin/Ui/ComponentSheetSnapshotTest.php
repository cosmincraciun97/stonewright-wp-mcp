<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\Tests\Unit\Admin\Ui;

use DOMDocument;
use DOMElement;
use DOMXPath;
use PHPUnit\Framework\TestCase;
use Stonewright\WpMcp\Tests\Unit\Assets\CssSource;

/**
 * The component sheet is the page the browser specs measure. These checks keep it in step with the helpers and the
 * stylesheet, and prove its markup is wired correctly (unique ids, resolved references, named controls).
 *
 * Regenerate the checked-in page after changing a helper: STONEWRIGHT_UPDATE_FIXTURES=1 vendor/bin/phpunit --filter ComponentSheetSnapshotTest
 *
 * @coversNothing
 */
final class ComponentSheetSnapshotTest extends TestCase {

	private const FIXTURE = '/fixtures/admin-ui/component-sheet.html';

	/** Classes the sheet may carry that the stylesheet does not define (page chrome of the sheet itself). */
	private const SHEET_ONLY = [ 'sheet-label' ];

	private static function path(): string {
		return dirname( __DIR__, 3 ) . self::FIXTURE;
	}

	private static function dom( string $html ): DOMDocument {
		$dom = new DOMDocument();
		libxml_use_internal_errors( true );
		$dom->loadHTML( $html );
		libxml_clear_errors();

		return $dom;
	}

	public function test_the_checked_in_sheet_is_what_the_helpers_render(): void {
		$html = ComponentSheet::document();
		if ( '1' === getenv( 'STONEWRIGHT_UPDATE_FIXTURES' ) ) {
			$dir = dirname( self::path() );
			if ( ! is_dir( $dir ) ) {
				mkdir( $dir, 0777, true );
			}
			file_put_contents( self::path(), $html );
		}

		self::assertFileExists( self::path() );
		self::assertSame( file_get_contents( self::path() ), $html, 'Regenerate with STONEWRIGHT_UPDATE_FIXTURES=1 after changing a helper.' );
		self::assertStringNotContainsString( "\r", $html );
	}

	public function test_rendering_twice_gives_the_same_page(): void {
		self::assertSame( ComponentSheet::document(), ComponentSheet::document() );
	}

	public function test_every_class_in_the_sheet_is_defined_by_the_stylesheet_or_is_sheet_chrome(): void {
		$css     = CssSource::read( 'admin/sw-ui.css' );
		$defined = [];
		preg_match_all( '/\.([a-z][a-z0-9_-]*)/', (string) preg_replace( '~/\*.*?\*/~s', '', $css ), $found );
		foreach ( $found[1] as $name ) {
			$defined[ $name ] = true;
		}

		$dom = self::dom( ComponentSheet::document() );
		$missing = [];
		foreach ( ( new DOMXPath( $dom ) )->query( '//*[@class]' ) ?: [] as $node ) {
			foreach ( preg_split( '/\s+/', trim( (string) $node->getAttribute( 'class' ) ) ) ?: [] as $class ) {
				if ( '' === $class || in_array( $class, self::SHEET_ONLY, true ) || in_array( $class, [ 'wp-admin', 'wp-core-ui', 'sw-shell', 'sw-shell__content', 'wrap' ], true ) || str_starts_with( $class, 'admin-color-' ) ) {
					continue;
				}
				if ( ! isset( $defined[ $class ] ) ) {
					$missing[ $class ] = true;
				}
			}
		}

		self::assertSame( [], array_keys( $missing ), 'Markup the helpers print must have rules in sw-ui.css.' );
	}

	public function test_ids_are_unique_and_every_reference_resolves(): void {
		$dom   = self::dom( ComponentSheet::document() );
		$xpath = new DOMXPath( $dom );
		$ids   = [];
		foreach ( $xpath->query( '//*[@id]' ) ?: [] as $node ) {
			/** @var DOMElement $node */
			$id = $node->getAttribute( 'id' );
			self::assertArrayNotHasKey( $id, $ids, 'Duplicate id: ' . $id );
			$ids[ $id ] = true;
		}

		foreach ( [ 'aria-labelledby', 'aria-describedby', 'aria-controls', 'for' ] as $attribute ) {
			foreach ( $xpath->query( '//*[@' . $attribute . ']' ) ?: [] as $node ) {
				/** @var DOMElement $node */
				foreach ( preg_split( '/\s+/', trim( $node->getAttribute( $attribute ) ) ) ?: [] as $target ) {
					self::assertArrayHasKey( $target, $ids, $attribute . '="' . $target . '" points at nothing on <' . $node->nodeName . '>' );
				}
			}
		}
		foreach ( [ 'data-sw-ui-copy', 'data-sw-ui-reveal', 'data-sw-ui-dialog-open' ] as $attribute ) {
			foreach ( $xpath->query( '//*[@' . $attribute . ']' ) ?: [] as $node ) {
				/** @var DOMElement $node */
				$selector = $node->getAttribute( $attribute );
				self::assertStringStartsWith( '#', $selector, $attribute );
				self::assertArrayHasKey( substr( $selector, 1 ), $ids, $attribute . ' target ' . $selector );
			}
		}
		foreach ( $xpath->query( '//use' ) ?: [] as $node ) {
			/** @var DOMElement $node */
			self::assertArrayHasKey( ltrim( $node->getAttribute( 'href' ), '#' ), $ids, 'Icon reference ' . $node->getAttribute( 'href' ) );
		}
	}

	public function test_every_control_has_a_name_and_every_form_field_a_label(): void {
		$dom   = self::dom( ComponentSheet::document() );
		$xpath = new DOMXPath( $dom );
		$ids   = [];
		foreach ( $xpath->query( '//*[@id]' ) ?: [] as $node ) {
			/** @var DOMElement $node */
			$ids[ $node->getAttribute( 'id' ) ] = trim( preg_replace( '/\s+/', ' ', $node->textContent ) ?? '' );
		}

		$unnamed = [];
		foreach ( $xpath->query( '//button | //a[@href] | //summary' ) ?: [] as $node ) {
			/** @var DOMElement $node */
			$name = trim( preg_replace( '/\s+/', ' ', $node->textContent ) ?? '' );
			if ( '' === $name ) {
				$name = trim( $node->getAttribute( 'aria-label' ) );
			}
			if ( '' === $name && '' !== $node->getAttribute( 'aria-labelledby' ) ) {
				$name = (string) ( $ids[ $node->getAttribute( 'aria-labelledby' ) ] ?? '' );
			}
			if ( '' === $name ) {
				$unnamed[] = $dom->saveHTML( $node );
			}
		}
		self::assertSame( [], $unnamed, 'Every button, link and summary needs a name.' );

		$labelled_for = [];
		foreach ( $xpath->query( '//label[@for]' ) ?: [] as $label ) {
			/** @var DOMElement $label */
			$labelled_for[ $label->getAttribute( 'for' ) ] = true;
		}
		$unlabelled = [];
		foreach ( $xpath->query( '//input[not(@type="hidden")] | //select | //textarea' ) ?: [] as $field ) {
			/** @var DOMElement $field */
			$inside_label = $xpath->query( 'ancestor::label', $field )->length > 0;
			$named        = '' !== trim( $field->getAttribute( 'aria-label' ) ) || '' !== trim( $field->getAttribute( 'aria-labelledby' ) );
			if ( ! $inside_label && ! $named && ! isset( $labelled_for[ $field->getAttribute( 'id' ) ] ) ) {
				$unlabelled[] = $dom->saveHTML( $field );
			}
		}
		self::assertSame( [], $unlabelled, 'Every form field needs a label.' );
	}

	public function test_the_sheet_has_one_h1_and_headings_never_skip_a_level_downwards(): void {
		$dom   = self::dom( ComponentSheet::document() );
		$xpath = new DOMXPath( $dom );

		self::assertSame( 1, $xpath->query( '//h1' )->length, 'One page title.' );

		$previous = 0;
		foreach ( $xpath->query( '//h1 | //h2 | //h3 | //h4 | //h5 | //h6' ) ?: [] as $heading ) {
			$level = (int) substr( $heading->nodeName, 1 );
			self::assertLessThanOrEqual( $previous + 1, $level, 'Heading "' . trim( $heading->textContent ) . '" skips a level.' );
			$previous = $level;
		}
	}

	public function test_switches_tabs_and_dialogs_carry_the_roles_the_script_and_assistive_technology_need(): void {
		$dom   = self::dom( ComponentSheet::document() );
		$xpath = new DOMXPath( $dom );

		foreach ( $xpath->query( '//input[@role="switch"]' ) ?: [] as $switch ) {
			self::assertSame( 'checkbox', $switch->getAttribute( 'type' ), 'A switch is a native checkbox with a role.' );
		}
		self::assertGreaterThan( 0, $xpath->query( '//*[@role="tablist"]//*[@role="tab"][@aria-controls]' )->length );
		foreach ( $xpath->query( '//*[@role="tab"]' ) ?: [] as $tab ) {
			/** @var DOMElement $tab */
			$panel = $xpath->query( '//*[@id="' . $tab->getAttribute( 'aria-controls' ) . '"][@role="tabpanel"]' );
			self::assertSame( 1, $panel->length, 'Tab ' . $tab->getAttribute( 'id' ) . ' controls a tabpanel.' );
		}
		foreach ( $xpath->query( '//dialog' ) ?: [] as $dialog ) {
			/** @var DOMElement $dialog */
			self::assertNotSame( '', $dialog->getAttribute( 'aria-labelledby' ), 'A dialog is named by its title.' );
			self::assertSame( 1, $xpath->query( './/*[@autofocus]', $dialog )->length, 'A dialog opens focused on its safe action.' );
		}
	}

	public function test_the_sheet_loads_the_layer_before_the_legacy_stylesheets(): void {
		$html = ComponentSheet::document();

		self::assertLessThan( (int) strpos( $html, 'href="shell.css"' ), (int) strpos( $html, 'href="sw-ui.css"' ) );
		self::assertLessThan( (int) strpos( $html, 'href="admin.css"' ), (int) strpos( $html, 'href="shell.css"' ) );
		self::assertLessThan( (int) strpos( $html, 'src="sw-ui.js"' ), (int) strpos( $html, '<div class="sw-ui sw-ui-page">' ) );
	}
}
