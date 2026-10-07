<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\Tests\Unit\Admin\Ui;

use PHPUnit\Framework\TestCase;
use Stonewright\WpMcp\Admin\Ui\Button;
use Stonewright\WpMcp\Admin\Ui\Html;

/**
 * @covers \Stonewright\WpMcp\Admin\Ui\Button
 */
final class ButtonTest extends TestCase {

	protected function setUp(): void {
		Html::reset_ids();
	}

	public function test_a_button_is_a_native_button_with_a_type(): void {
		self::assertSame( '<button type="button" class="sw-ui-btn">Cancel</button>', Button::render( 'Cancel' ) );
		self::assertSame( '<button type="button" class="sw-ui-btn sw-ui-btn--primary">Save changes</button>', Button::render( 'Save changes', [ 'variant' => 'primary' ] ) );
		self::assertSame( '<button type="submit" class="sw-ui-btn sw-ui-btn--primary" name="approve" value="1">Approve</button>', Button::render( 'Approve', [ 'variant' => 'primary', 'type' => 'submit', 'name' => 'approve', 'value' => '1' ] ) );
	}

	/** @dataProvider variants */
	public function test_each_variant_has_its_class( string $variant, string $expected_class ): void {
		self::assertSame( '<button type="button" class="' . $expected_class . '">Go</button>', Button::render( 'Go', [ 'variant' => $variant ] ) );
	}

	/** @return array<string, array{0: string, 1: string}> */
	public static function variants(): array {
		return [
			'secondary is the plain button' => [ 'secondary', 'sw-ui-btn' ],
			'primary'                       => [ 'primary', 'sw-ui-btn sw-ui-btn--primary' ],
			'tertiary'                      => [ 'tertiary', 'sw-ui-btn sw-ui-btn--tertiary' ],
			'danger'                        => [ 'danger', 'sw-ui-btn sw-ui-btn--danger' ],
			'danger solid'                  => [ 'danger-solid', 'sw-ui-btn sw-ui-btn--danger-solid' ],
			'unknown falls back'            => [ 'rainbow', 'sw-ui-btn' ],
		];
	}

	public function test_sizes_and_unknown_sizes(): void {
		self::assertStringContainsString( 'sw-ui-btn--sm', Button::render( 'Go', [ 'size' => 'sm' ] ) );
		self::assertStringContainsString( 'sw-ui-btn--xs', Button::render( 'Go', [ 'size' => 'xs' ] ) );
		self::assertSame( '<button type="button" class="sw-ui-btn">Go</button>', Button::render( 'Go', [ 'size' => 'huge' ] ) );
	}

	public function test_an_unknown_type_is_a_plain_button_so_it_never_submits_by_accident(): void {
		self::assertStringStartsWith( '<button type="button"', Button::render( 'Go', [ 'type' => 'image' ] ) );
	}

	public function test_an_icon_only_button_is_named_by_its_label(): void {
		self::assertSame(
			'<button type="button" class="sw-ui-btn sw-ui-btn--icon" aria-label="Copy MCP server URL"><svg class="sw-ui-icon" aria-hidden="true"><use href="#sw-ui-icon-copy"></use></svg></button>',
			Button::render( 'Copy MCP server URL', [ 'icon' => 'copy', 'icon_only' => true ] )
		);
		// Without an icon there is nothing to show, so the label stays visible.
		self::assertSame( '<button type="button" class="sw-ui-btn">Copy</button>', Button::render( 'Copy', [ 'icon_only' => true ] ) );
	}

	public function test_a_context_tells_repeated_actions_apart_for_assistive_technology_only(): void {
		self::assertSame(
			'<button type="button" class="sw-ui-btn sw-ui-btn--danger sw-ui-btn--sm">Disconnect<span class="sw-ui-visually-hidden"> Example client</span></button>',
			Button::render( 'Disconnect', [ 'variant' => 'danger', 'size' => 'sm', 'context' => 'Example client' ] )
		);
		// The visible text stays the start of the accessible name.
		self::assertSame(
			'<a class="sw-ui-btn" href="https://example.test/e">Edit<span class="sw-ui-visually-hidden"> Example client</span></a>',
			Button::render( 'Edit', [ 'href' => 'https://example.test/e', 'context' => 'Example client' ] )
		);
	}

	public function test_a_context_is_escaped_and_joins_the_name_of_an_icon_only_button(): void {
		self::assertSame(
			'<button type="button" class="sw-ui-btn sw-ui-btn--icon" aria-label="Copy MCP server URL"><svg class="sw-ui-icon" aria-hidden="true"><use href="#sw-ui-icon-copy"></use></svg></button>',
			Button::render( 'Copy', [ 'icon' => 'copy', 'icon_only' => true, 'context' => 'MCP server URL' ] )
		);
		self::assertStringContainsString( '&lt;b&gt;', Button::render( 'Remove', [ 'context' => '<b>x</b>' ] ) );
		self::assertStringNotContainsString( '<b>', Button::render( 'Remove', [ 'context' => '<b>x</b>' ] ) );
		self::assertSame( '<button type="button" class="sw-ui-btn">Go</button>', Button::render( 'Go', [ 'context' => '' ] ) );
	}

	public function test_an_icon_precedes_the_visible_label(): void {
		self::assertSame(
			'<button type="button" class="sw-ui-btn sw-ui-btn--sm"><svg class="sw-ui-icon" aria-hidden="true"><use href="#sw-ui-icon-plus"></use></svg>Add entry</button>',
			Button::render( 'Add entry', [ 'icon' => 'plus', 'size' => 'sm' ] )
		);
	}

	public function test_busy_and_disabled_states(): void {
		self::assertSame( '<button type="button" class="sw-ui-btn" aria-busy="true">Saving</button>', Button::render( 'Saving', [ 'busy' => true ] ) );
		self::assertSame( '<button type="button" class="sw-ui-btn" disabled>Save</button>', Button::render( 'Save', [ 'disabled' => true ] ) );
	}

	public function test_a_link_with_a_destination_is_an_anchor(): void {
		self::assertSame( '<a class="sw-ui-btn" href="https://example.test/docs">Docs</a>', Button::render( 'Docs', [ 'href' => 'https://example.test/docs' ] ) );
		self::assertSame(
			'<a class="sw-ui-btn" href="https://example.test/docs" target="_blank" rel="noopener noreferrer">Docs</a>',
			Button::render( 'Docs', [ 'href' => 'https://example.test/docs', 'new_tab' => true ] )
		);
	}

	public function test_a_disabled_link_loses_its_destination_and_its_place_in_the_tab_order(): void {
		self::assertSame( '<a class="sw-ui-btn" aria-disabled="true" tabindex="-1">Open</a>', Button::render( 'Open', [ 'href' => 'https://example.test/x', 'disabled' => true ] ) );
	}

	public function test_a_script_url_does_not_become_a_destination(): void {
		self::assertStringNotContainsString( 'javascript', Button::render( 'Go', [ 'href' => 'javascript:alert(1)' ] ) );
	}

	public function test_the_label_and_every_attribute_are_escaped(): void {
		$html = Button::render( '<img src=x onerror=alert(1)>', [ 'id' => 'a"b', 'attrs' => [ 'data-x' => '"><script>' ] ] );

		self::assertStringNotContainsString( '<img', $html );
		self::assertStringNotContainsString( '<script', $html );
		self::assertStringContainsString( '&lt;img src=x onerror=alert(1)&gt;', $html );
		self::assertStringContainsString( 'id="a&quot;b"', $html );
	}

	public function test_a_caller_cannot_unmake_what_the_helper_sets(): void {
		$html = Button::render( 'Go', [ 'attrs' => [ 'type' => 'submit', 'class' => 'x', 'onclick' => 'evil()', 'style' => 'x', 'disabled' => true, 'data-keep' => '1' ] ] );

		self::assertSame( '<button type="button" class="sw-ui-btn" data-keep="1">Go</button>', $html );
	}

	public function test_extra_classes_are_added_after_the_layers_own(): void {
		self::assertSame( '<button type="button" class="sw-ui-btn sw-ui-btn--primary my-extra">Go</button>', Button::render( 'Go', [ 'variant' => 'primary', 'class' => 'my-extra' ] ) );
	}

	public function test_a_group_lays_buttons_out_with_the_layers_gap(): void {
		self::assertSame(
			'<div class="sw-ui-actions sw-ui-actions--end"><button type="button" class="sw-ui-btn">A</button><button type="button" class="sw-ui-btn">B</button></div>',
			Button::group( [ Button::render( 'A' ), Button::render( 'B' ) ], true )
		);
		self::assertStringStartsWith( '<div class="sw-ui-actions">', Button::group( [] ) );
	}
}
