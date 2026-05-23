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
		foreach ( [ 'nav', 'countdown', 'social-row', 'icon-bullet-list', 'logo+nav', 'header-template', 'footer-template' ] as $expected ) {
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

	public function test_figma_signature_detects_footer_by_name(): void {
		$node = [ 'name' => 'Footer Section', 'type' => 'FRAME', 'children' => [] ];
		$this->assertSame( 'footer-template', WidgetIntentResolver::detect_from_figma_signature( $node ) );
	}

	public function test_figma_signature_detects_header_by_name(): void {
		$node = [ 'name' => 'Site Header', 'type' => 'FRAME', 'children' => [] ];
		$this->assertSame( 'header-template', WidgetIntentResolver::detect_from_figma_signature( $node ) );
	}

	public function test_figma_signature_detects_countdown_from_digit_pairs(): void {
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
		$this->assertSame( 'countdown', WidgetIntentResolver::detect_from_figma_signature( $node ) );
	}

	public function test_figma_signature_detects_social_row_from_vector_only_frames(): void {
		$social = static fn(): array => [
			'type'     => 'FRAME',
			'children' => [ [ 'type' => 'VECTOR' ] ],
		];
		$node = [
			'type'     => 'FRAME',
			'name'     => 'Social row',
			'children' => [ $social(), $social(), $social(), $social() ],
		];
		$this->assertSame( 'social-row', WidgetIntentResolver::detect_from_figma_signature( $node ) );
	}

	public function test_figma_signature_detects_nav_from_text_siblings(): void {
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
		$this->assertSame( 'nav', WidgetIntentResolver::detect_from_figma_signature( $node ) );
	}

	public function test_figma_signature_detects_icon_bullet_list(): void {
		$bullet = static fn( string $text ): array => [
			'type'     => 'FRAME',
			'children' => [ [ 'type' => 'VECTOR' ], [ 'type' => 'TEXT', 'characters' => $text ] ],
		];
		$node = [
			'type'     => 'FRAME',
			'name'     => 'Feature list',
			'children' => [ $bullet( 'Fast' ), $bullet( 'Reliable' ), $bullet( 'Tested' ) ],
		];
		$this->assertSame( 'icon-bullet-list', WidgetIntentResolver::detect_from_figma_signature( $node ) );
	}

	public function test_figma_signature_returns_null_for_unrecognised_node(): void {
		$node = [ 'type' => 'TEXT', 'characters' => 'Just a heading' ];
		$this->assertNull( WidgetIntentResolver::detect_from_figma_signature( $node ) );
	}
}
