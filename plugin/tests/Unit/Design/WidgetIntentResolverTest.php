<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\Tests\Unit\Design;

use PHPUnit\Framework\TestCase;
use Stonewright\WpMcp\Design\WidgetIntentResolver;

/**
 * @covers \Stonewright\WpMcp\Design\WidgetIntentResolver
 */
final class WidgetIntentResolverTest extends TestCase {

	public function test_intent_names_includes_all_canonical_intents(): void {
		$names = WidgetIntentResolver::intent_names();
		foreach ( [ 'nav', 'countdown', 'social-row', 'icon-bullet-list', 'image-gallery', 'newsletter-form', 'logo+nav', 'header-template', 'footer-template' ] as $expected ) {
			$this->assertContains( $expected, $names, "Missing canonical intent: {$expected}" );
		}
	}

	public function test_resolve_countdown_returns_widget_and_template(): void {
		$entry = WidgetIntentResolver::resolve( 'countdown' );
		$this->assertSame( 'countdown', $entry['widget'] );
		$this->assertSame( 'due_date', $entry['settings_template']['countdown_type'] );
		$this->assertSame( 'DAYS', $entry['settings_template']['label_days'] );
		$this->assertSame( 'yes', $entry['settings_template']['show_seconds'] );
	}

	public function test_resolve_nav_requires_menu_create_step(): void {
		$entry = WidgetIntentResolver::resolve( 'nav' );
		$this->assertSame( 'nav-menu', $entry['widget'] );
		$this->assertContains( 'stonewright/menu-create', $entry['required_steps'] );
		$this->assertContains( 'stonewright/menu-add-item', $entry['required_steps'] );
	}

	public function test_resolve_unknown_intent_returns_empty(): void {
		$this->assertSame( [], WidgetIntentResolver::resolve( 'no-such-intent-xyz' ) );
	}

	public function test_logo_nav_intent_is_multi_widget(): void {
		$entry = WidgetIntentResolver::resolve( 'logo+nav' );
		$this->assertNull( $entry['widget'] );
		$this->assertSame( [ 'image', 'nav-menu' ], $entry['widgets'] );
	}

	public function test_media_intents_require_search_then_batch_upload(): void {
		foreach ( WidgetIntentResolver::intents() as $intent => $entry ) {
			$steps = (array) ( $entry['required_steps'] ?? [] );
			if ( ! in_array( 'stonewright/media-upload', $steps, true ) && ! in_array( 'stonewright/media-list', $steps, true ) && ! in_array( 'stonewright/media-upload-batch', $steps, true ) ) {
				continue;
			}

			$this->assertContains( 'stonewright/media-list', $steps, "{$intent} should search existing media first." );
			$this->assertContains( 'stonewright/media-upload-batch', $steps, "{$intent} should batch upload only missing media." );
			$this->assertNotContains( 'stonewright/media-upload', $steps, "{$intent} should not recommend slower single media upload." );
		}
	}

	public function test_design_tree_detects_footer_by_name(): void {
		$node = [ 'name' => 'Footer Section', 'type' => 'FRAME', 'children' => [] ];
		$this->assertSame( 'footer-template', WidgetIntentResolver::detect_from_design_tree( $node ) );
	}

	public function test_design_tree_detects_header_by_name(): void {
		$node = [ 'name' => 'Site Header', 'type' => 'FRAME', 'children' => [] ];
		$this->assertSame( 'header-template', WidgetIntentResolver::detect_from_design_tree( $node ) );
	}

	public function test_design_tree_detects_countdown_from_digit_pairs(): void {
		$digit_group = static fn( string $digits ): array => [
			'type'     => 'FRAME',
			'children' => [
				[ 'type' => 'TEXT', 'characters' => $digits, 'style' => [ 'fontSize' => 72 ] ],
				[ 'type' => 'TEXT', 'characters' => 'DAYS', 'style' => [ 'fontSize' => 12 ] ],
			],
		];
		$node = [
			'type'     => 'FRAME',
			'name'     => 'Countdown row',
			'children' => [ $digit_group( '63' ), $digit_group( '11' ), $digit_group( '18' ) ],
		];
		$this->assertSame( 'countdown', WidgetIntentResolver::detect_from_design_tree( $node ) );
	}

	public function test_design_tree_detects_social_row_from_vector_only_frames(): void {
		$social = static fn(): array => [
			'type'     => 'FRAME',
			'children' => [ [ 'type' => 'VECTOR' ] ],
		];
		$node = [
			'type'     => 'FRAME',
			'name'     => 'Social row',
			'children' => [ $social(), $social(), $social(), $social() ],
		];
		$this->assertSame( 'social-row', WidgetIntentResolver::detect_from_design_tree( $node ) );
	}

	public function test_design_tree_detects_nav_from_text_siblings(): void {
		$text = static fn( string $t ): array => [
			'type'       => 'TEXT',
			'characters' => $t,
			'style'      => [ 'fontSize' => 16 ],
		];
		$node = [
			'type'     => 'FRAME',
			'name'     => 'Top row',
			'children' => [ $text( 'About' ), $text( 'Services' ), $text( 'Pricing' ), $text( 'Contact' ) ],
		];
		$this->assertSame( 'nav', WidgetIntentResolver::detect_from_design_tree( $node ) );
	}

	public function test_design_tree_detects_footer_link_column_before_nav(): void {
		$text = static fn( string $t ): array => [
			'type'       => 'TEXT',
			'characters' => $t,
			'style'      => [ 'fontSize' => 14 ],
		];
		$node = [
			'type'       => 'FRAME',
			'name'       => 'Footer useful links',
			'layoutMode' => 'VERTICAL',
			'children'   => [ $text( 'Despre nZEB Expo' ), $text( 'Misiune' ), $text( 'Media Kit & Presa' ), $text( 'Editii' ) ],
		];
		$this->assertSame( 'footer-link-column', WidgetIntentResolver::detect_from_design_tree( $node ) );
	}

	public function test_design_tree_detects_gallery_from_image_grid(): void {
		$image = static fn( string $id ): array => [
			'id'    => $id,
			'type'  => 'RECTANGLE',
			'name'  => 'Gallery image',
			'fills' => [ [ 'type' => 'IMAGE', 'imageRef' => $id ] ],
		];
		$node = [
			'type'     => 'FRAME',
			'name'     => 'Galerie foto grid',
			'children' => [ $image( 'a' ), $image( 'b' ), $image( 'c' ), $image( 'd' ) ],
		];
		$this->assertSame( 'image-gallery', WidgetIntentResolver::detect_from_design_tree( $node ) );
	}

	public function test_design_tree_detects_newsletter_form_from_labels(): void {
		$text = static fn( string $t ): array => [
			'type'       => 'TEXT',
			'characters' => $t,
			'style'      => [ 'fontSize' => 16 ],
		];
		$node = [
			'type'     => 'FRAME',
			'name'     => 'Newsletter form',
			'children' => [ $text( 'Nume *' ), $text( 'Prenume *' ), $text( 'Email *' ), $text( 'Interes *' ), $text( 'Aboneaza-te la newsletter' ) ],
		];
		$this->assertSame( 'newsletter-form', WidgetIntentResolver::detect_from_design_tree( $node ) );
	}

	public function test_design_tree_detects_icon_bullet_list(): void {
		$bullet = static fn( string $text ): array => [
			'type'     => 'FRAME',
			'children' => [ [ 'type' => 'VECTOR' ], [ 'type' => 'TEXT', 'characters' => $text ] ],
		];
		$node = [
			'type'     => 'FRAME',
			'name'     => 'Feature list',
			'children' => [ $bullet( 'Fast' ), $bullet( 'Reliable' ), $bullet( 'Tested' ) ],
		];
		$this->assertSame( 'icon-bullet-list', WidgetIntentResolver::detect_from_design_tree( $node ) );
	}

	public function test_design_tree_returns_null_for_unrecognised_node(): void {
		$node = [ 'type' => 'TEXT', 'characters' => 'Just a heading' ];
		$this->assertNull( WidgetIntentResolver::detect_from_design_tree( $node ) );
	}

	public function test_prompt_detection_prefers_native_widget_intents(): void {
		$this->assertSame( 'image-gallery', WidgetIntentResolver::detect_from_prompt( 'fa o galerie foto cu 8 imagini' ) );
		$this->assertSame( 'newsletter-form', WidgetIntentResolver::detect_from_prompt( 'formular newsletter cu nume prenume email si interes' ) );
		$this->assertSame( 'nav', WidgetIntentResolver::detect_from_prompt( 'meniu principal in header cu linkuri' ) );
		$this->assertSame( 'footer-template', WidgetIntentResolver::detect_from_prompt( 'footer cu trei coloane si social media' ) );
	}

	public function test_prompt_detection_covers_real_elementor_patterns(): void {
		$this->assertSame( 'video', WidgetIntentResolver::detect_from_prompt( 'sectiune aftermovie cu video poster si play button' ) );
		$this->assertSame( 'section-label', WidgetIntentResolver::detect_from_prompt( 'label 01 - Aftermovie cu border bottom sub text' ) );
		$this->assertSame( 'sticky-header', WidgetIntentResolver::detect_from_prompt( 'header sticky desktop si mobile cu doua meniuri' ) );
		$this->assertSame( 'mobile-nav', WidgetIntentResolver::detect_from_prompt( 'header mobile cu hamburger dropdown' ) );
		$this->assertSame( 'social-row', WidgetIntentResolver::detect_from_prompt( 'footer cu iconuri svg facebook instagram linkedin youtube tiktok' ) );
	}

	public function test_design_tree_detects_section_label_with_underline(): void {
		$node = [
			'type'     => 'FRAME',
			'name'     => 'Label',
			'children' => [
				[ 'type' => 'TEXT', 'characters' => '01 - AFTERMOVIE', 'style' => [ 'fontSize' => 14 ] ],
				[ 'type' => 'LINE', 'absoluteBoundingBox' => [ 'width' => 136, 'height' => 1, 'x' => 0, 'y' => 24 ] ],
			],
		];

		$this->assertSame( 'section-label', WidgetIntentResolver::detect_from_design_tree( $node ) );
	}

	public function test_design_tree_detects_video_poster(): void {
		$node = [
			'type'     => 'FRAME',
			'name'     => 'Aftermovie video',
			'children' => [
				[ 'type' => 'RECTANGLE', 'fills' => [ [ 'type' => 'IMAGE', 'imageRef' => 'poster' ] ] ],
				[ 'type' => 'VECTOR', 'name' => 'play' ],
			],
		];

		$this->assertSame( 'video', WidgetIntentResolver::detect_from_design_tree( $node ) );
	}
}
