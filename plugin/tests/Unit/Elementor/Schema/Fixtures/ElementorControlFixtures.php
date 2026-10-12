<?php
// SPDX-License-Identifier: GPL-2.0-or-later
declare( strict_types=1 );

namespace Stonewright\WpMcp\Tests\Unit\Elementor\Schema\Fixtures;

/**
 * Control definitions shaped like the ones Elementor 4.3 registers for the heading and button widgets, an icon-list
 * style repeater widget and the container, with the group controls and the responsive variants already expanded the
 * way `get_controls()` lists them. The keys, types, options, size units, defaults and conditions follow the Elementor
 * source; labels are trimmed. Only the breakpoints mobile and tablet are active.
 */
final class ElementorControlFixtures {

	private const UNITS_TYPE = [ 'px', 'em', 'rem', 'vw', 'custom' ];

	/** Points `\Elementor\Plugin::$instance` at a runtime that serves the fixtures below; returns the instance to restore. */
	public static function install(): object {
		$original = \Elementor\Plugin::$instance;
		\Elementor\Plugin::$instance = (object) [
			'widgets_manager'  => new class() {
				/** @return array<string, object>|object|null */
				public function get_widget_types( ?string $name = null ): array|object|null {
					$widgets = [
						'heading'   => new ControlFixtureElement( 'Heading', ElementorControlFixtures::heading() ),
						'button'    => new ControlFixtureElement( 'Button', ElementorControlFixtures::button() ),
						'icon-list' => new ControlFixtureElement( 'Icon List', ElementorControlFixtures::icon_list() ),
						'spacer'    => new ControlFixtureElement( 'Spacer', ElementorControlFixtures::spacer() ),
					];
					return null === $name ? $widgets : ( $widgets[ $name ] ?? null );
				}
			},
			'elements_manager' => new class() {
				/** @return array<string, object>|object */
				public function get_element_types( ?string $name = null ): array|object {
					$elements = [ 'container' => new ControlFixtureElement( 'Container', ElementorControlFixtures::container() ) ];
					return null === $name ? $elements : ( $elements[ $name ] ?? new ControlFixtureElement( $name, [] ) );
				}
			},
		];
		return $original;
	}

	/** @return array<string, array<string, mixed>> */
	private static function responsive( string $key, array $control ): array {
		$control['responsive'] = [ 'max' => 'desktop' ];
		$out                   = [ $key => $control ];
		foreach ( [ 'tablet', 'mobile' ] as $device ) {
			$variant                = $control;
			$variant['responsive']  = [ 'max' => $device ];
			$out[ $key . '_' . $device ] = $variant;
		}
		return $out;
	}

	/** @return array<string, array<string, mixed>> */
	public static function typography( string $name, array $condition = [] ): array {
		$gate = static fn( array $control ): array => $control + [ 'condition' => $condition + [ $name . '_typography!' => '' ] ];
		$out  = [ $name . '_typography' => [ 'type' => 'popover_toggle', 'return_value' => 'custom', 'label' => 'Typography' ] ];
		$out[ $name . '_font_family' ] = $gate( [ 'type' => 'font', 'default' => '', 'label' => 'Family' ] );
		$out += self::responsive( $name . '_font_size', $gate( [ 'type' => 'slider', 'size_units' => self::UNITS_TYPE, 'label' => 'Size' ] ) );
		$out[ $name . '_font_weight' ] = $gate(
			[
				'type'    => 'select',
				'default' => '',
				'label'   => 'Weight',
				'options' => [ '100' => '100', '200' => '200', '300' => '300', '400' => '400', '500' => '500', '600' => '600', '700' => '700', '800' => '800', '900' => '900', '' => 'Default', 'normal' => 'Normal', 'bold' => 'Bold' ],
			]
		);
		$out[ $name . '_text_transform' ] = $gate( [ 'type' => 'select', 'default' => '', 'options' => [ '' => 'Default', 'uppercase' => 'Uppercase', 'lowercase' => 'Lowercase', 'capitalize' => 'Capitalize', 'none' => 'Normal' ] ] );
		$out[ $name . '_font_style' ]     = $gate( [ 'type' => 'select', 'default' => '', 'options' => [ '' => 'Default', 'normal' => 'Normal', 'italic' => 'Italic', 'oblique' => 'Oblique' ] ] );
		$out[ $name . '_text_decoration' ] = $gate( [ 'type' => 'select', 'default' => '', 'options' => [ '' => 'Default', 'underline' => 'Underline', 'overline' => 'Overline', 'line-through' => 'Line Through', 'none' => 'None' ] ] );
		$out += self::responsive( $name . '_line_height', $gate( [ 'type' => 'slider', 'size_units' => [ 'px', 'em', 'rem', 'lh', 'rlh', 'custom' ] ] ) );
		$out += self::responsive( $name . '_letter_spacing', $gate( [ 'type' => 'slider', 'size_units' => self::UNITS_TYPE ] ) );
		$out += self::responsive( $name . '_word_spacing', $gate( [ 'type' => 'slider', 'size_units' => self::UNITS_TYPE ] ) );
		return $out;
	}

	/** @return array<string, array<string, mixed>> */
	public static function box_shadow( string $name ): array {
		$gate = [ $name . '_type!' => '' ];
		return [
			$name . '_type'                => [ 'type' => 'popover_toggle', 'return_value' => 'yes' ],
			$name . '_box_shadow'          => [ 'type' => 'box_shadow', 'condition' => $gate ],
			$name . '_box_shadow_position' => [ 'type' => 'select', 'default' => ' ', 'options' => [ ' ' => 'Outline', 'inset' => 'Inset' ], 'condition' => $gate ],
		];
	}

	/** @return array<string, array<string, mixed>> */
	public static function common(): array {
		$out = [
			'_margin'               => [ 'type' => 'dimensions', 'size_units' => [ 'px', '%', 'em', 'rem', 'vw', 'custom' ] ],
			'_padding'              => [ 'type' => 'dimensions', 'size_units' => [ 'px', '%', 'em', 'rem', 'vw', 'custom' ] ],
			'_element_width'        => [ 'type' => 'select', 'default' => '', 'options' => [ '' => 'Default', 'inherit' => 'Full Width (100%)', 'auto' => 'Inline (auto)', 'initial' => 'Custom' ], 'selectors_dictionary' => [ 'inherit' => '100%' ] ],
			'_z_index'              => [ 'type' => 'number' ],
			'_element_id'           => [ 'type' => 'text', 'default' => '' ],
			'_css_classes'          => [ 'type' => 'text', 'default' => '' ],
			'hide_desktop'          => [ 'type' => 'switcher', 'return_value' => 'hidden-desktop' ],
			'hide_tablet'           => [ 'type' => 'switcher', 'return_value' => 'hidden-tablet' ],
			'hide_mobile'           => [ 'type' => 'switcher', 'return_value' => 'hidden-mobile' ],
		];
		$out += self::responsive( '_element_custom_width', [ 'type' => 'slider', 'size_units' => [ '%', 'px', 'vw', 'custom' ], 'condition' => [ '_element_width' => 'initial' ] ] );
		foreach ( [ '_margin', '_padding' ] as $key ) {
			$out += self::responsive( $key, $out[ $key ] );
		}
		return $out;
	}

	/** @return array<string, array<string, mixed>> */
	public static function heading(): array {
		$controls = [
			'title'       => [ 'type' => 'textarea', 'default' => 'Add Your Heading Text Here' ],
			'link'        => [ 'type' => 'url', 'default' => [ 'url' => '' ] ],
			'size'        => [ 'type' => 'select', 'default' => 'default', 'options' => [ 'default' => 'Default', 'small' => 'Small', 'medium' => 'Medium', 'large' => 'Large', 'xl' => 'XL', 'xxl' => 'XXL' ], 'condition' => [ 'size!' => 'default' ] ],
			'header_size' => [ 'type' => 'select', 'default' => 'h2', 'options' => [ 'h1' => 'H1', 'h2' => 'H2', 'h3' => 'H3', 'h4' => 'H4', 'h5' => 'H5', 'h6' => 'H6', 'div' => 'div', 'span' => 'span', 'p' => 'p' ] ],
			'blend_mode'  => [ 'type' => 'select', 'options' => [ '' => 'Normal', 'multiply' => 'Multiply', 'screen' => 'Screen' ] ],
			'title_color' => [ 'type' => 'color' ],
			'title_hover_color' => [ 'type' => 'color' ],
			'title_hover_color_transition_duration' => [ 'type' => 'slider', 'size_units' => [ 's', 'ms', 'custom' ], 'default' => [ 'unit' => 's' ] ],
		];
		$controls += self::responsive(
			'align',
			[
				'type'                 => 'choose',
				'default'              => '',
				'options'              => [ 'start' => [ 'title' => 'Left' ], 'center' => [ 'title' => 'Center' ], 'end' => [ 'title' => 'Right' ], 'justify' => [ 'title' => 'Justified' ] ],
				'selectors_dictionary' => [ 'left' => 'start', 'right' => 'end' ],
			]
		);
		$controls += self::typography( 'typography' );
		$controls += [
			'text_shadow_text_shadow_type' => [ 'type' => 'popover_toggle', 'return_value' => 'yes' ],
			'text_shadow_text_shadow'      => [ 'type' => 'text_shadow', 'condition' => [ 'text_shadow_text_shadow_type!' => '' ] ],
		];
		return $controls + self::common();
	}

	/** @return array<string, array<string, mixed>> */
	public static function button(): array {
		$controls = [
			'button_type'  => [ 'type' => 'select', 'default' => '', 'options' => [ '' => 'Default', 'info' => 'Info', 'success' => 'Success', 'warning' => 'Warning', 'danger' => 'Danger' ] ],
			'text'         => [ 'type' => 'text', 'default' => 'Click here' ],
			'link'         => [ 'type' => 'url', 'default' => [ 'url' => '' ] ],
			'size'         => [ 'type' => 'select', 'default' => 'sm', 'options' => [ 'xs' => 'Extra Small', 'sm' => 'Small', 'md' => 'Medium', 'lg' => 'Large', 'xl' => 'Extra Large' ], 'condition' => [ 'size[value]!' => 'sm' ] ],
			'selected_icon' => [ 'type' => 'icons', 'fa4compatibility' => 'icon', 'skin' => 'inline', 'label_block' => false ],
			'icon_align'   => [ 'type' => 'choose', 'default' => 'row', 'options' => [ 'row' => [ 'title' => 'Before' ], 'row-reverse' => [ 'title' => 'After' ] ], 'condition' => [ 'selected_icon[value]!' => '' ] ],
			'icon_indent'  => [ 'type' => 'slider', 'size_units' => [ 'px', 'em', 'rem', 'custom' ], 'condition' => [ 'text!' => '', 'selected_icon[value]!' => '' ] ],
			'button_css_id' => [ 'type' => 'text', 'default' => '' ],
			'button_text_color' => [ 'type' => 'color' ],
			'hover_color'  => [ 'type' => 'color' ],
			'button_background_hover_color' => [ 'type' => 'color' ],
			'button_hover_border_color'     => [ 'type' => 'color' ],
			'hover_animation' => [ 'type' => 'hover_animation' ],
			'background_background' => [ 'type' => 'choose', 'default' => 'classic', 'options' => [ 'classic' => [ 'title' => 'Classic' ], 'gradient' => [ 'title' => 'Gradient' ] ] ],
			'background_color' => [ 'type' => 'color', 'condition' => [ 'background_background' => [ 'classic', 'gradient' ] ] ],
			'border_border' => [ 'type' => 'select', 'options' => [ '' => 'Default', 'none' => 'None', 'solid' => 'Solid', 'double' => 'Double', 'dotted' => 'Dotted', 'dashed' => 'Dashed', 'groove' => 'Groove' ] ],
			'border_width'  => [ 'type' => 'dimensions', 'size_units' => [ 'px', 'em', 'rem', 'vw', 'custom' ], 'condition' => [ 'border_border!' => '' ] ],
			'border_color'  => [ 'type' => 'color', 'condition' => [ 'border_border!' => '' ] ],
			'border_radius' => [ 'type' => 'dimensions', 'size_units' => [ 'px', '%', 'em', 'rem', 'custom' ] ],
		];
		$controls += self::responsive( 'align', [ 'type' => 'choose', 'default' => '', 'options' => [ 'left' => [ 'title' => 'Left' ], 'center' => [ 'title' => 'Center' ], 'right' => [ 'title' => 'Right' ], 'justify' => [ 'title' => 'Justified' ] ] ] );
		$controls += self::responsive( 'text_padding', [ 'type' => 'dimensions', 'size_units' => [ 'px', 'em', '%', 'rem', 'vw', 'custom' ] ] );
		$controls += self::typography( 'typography' );
		$controls += self::box_shadow( 'button_box_shadow' );
		return $controls + self::common();
	}

	/** @return array<string, array<string, mixed>> */
	public static function icon_list(): array {
		$fields = [
			'text'          => [ 'type' => 'text', 'default' => '' ],
			'selected_icon' => [ 'type' => 'icons', 'default' => [ 'value' => 'fas fa-check', 'library' => 'fa-solid' ] ],
			'link'          => [ 'type' => 'url' ],
			'icon_color'    => [ 'type' => 'color' ],
			'tab_style'     => [ 'type' => 'select', 'default' => 'one', 'options' => [ 'one' => 'One', 'two' => 'Two' ] ],
		];
		return [
			'view'      => [ 'type' => 'choose', 'default' => 'traditional', 'options' => [ 'traditional' => [ 'title' => 'Default' ], 'inline' => [ 'title' => 'Inline' ] ] ],
			'icon_list' => [ 'type' => 'repeater', 'fields' => $fields, 'default' => [ [ 'text' => 'List Item #1' ] ] ],
		] + self::common();
	}

	/** @return array<string, array<string, mixed>> */
	public static function spacer(): array {
		return [ 'space' => [ 'type' => 'slider', 'size_units' => [ 'px', 'em', 'rem', 'vh', 'custom' ], 'default' => [ 'size' => 50 ] ] ] + self::common();
	}

	/** @return array<string, array<string, mixed>> */
	public static function container(): array {
		$controls = [
			'container_type' => [ 'type' => 'select', 'default' => 'flex', 'options' => [ 'flex' => 'Flexbox', 'grid' => 'Grid' ] ],
			'content_width'  => [ 'type' => 'select', 'default' => 'boxed', 'options' => [ 'boxed' => 'Boxed', 'full' => 'Full Width' ] ],
			'flex_direction' => [ 'type' => 'choose', 'options' => [ 'row' => [ 'title' => 'Row' ], 'column' => [ 'title' => 'Column' ], 'row-reverse' => [ 'title' => 'Row reversed' ], 'column-reverse' => [ 'title' => 'Column reversed' ] ] ],
			'flex_wrap'      => [ 'type' => 'choose', 'options' => [ 'nowrap' => [ 'title' => 'No wrap' ], 'wrap' => [ 'title' => 'Wrap' ] ] ],
			'flex_justify_content' => [ 'type' => 'select', 'default' => '', 'options' => [ '' => 'Default', 'flex-start' => 'Start', 'center' => 'Center', 'flex-end' => 'End', 'space-between' => 'Space Between', 'space-around' => 'Space Around', 'space-evenly' => 'Space Evenly' ] ],
			'flex_align_items'     => [ 'type' => 'select', 'default' => '', 'options' => [ '' => 'Default', 'flex-start' => 'Start', 'center' => 'Center', 'flex-end' => 'End', 'stretch' => 'Stretch' ] ],
			'background_background' => [ 'type' => 'choose', 'options' => [ 'classic' => [ 'title' => 'Classic' ], 'gradient' => [ 'title' => 'Gradient' ] ] ],
			'background_color'      => [ 'type' => 'color', 'condition' => [ 'background_background' => [ 'classic', 'gradient' ] ] ],
			'border_border'         => [ 'type' => 'select', 'options' => [ '' => 'Default', 'none' => 'None', 'solid' => 'Solid' ] ],
			'border_radius'         => [ 'type' => 'dimensions', 'size_units' => [ 'px', '%', 'em', 'rem', 'custom' ] ],
			'css_classes'           => [ 'type' => 'text', 'default' => '' ],
			'_element_id'           => [ 'type' => 'text', 'default' => '' ],
			'z_index'               => [ 'type' => 'number' ],
		];
		$controls += self::responsive( 'flex_gap', [ 'type' => 'gaps', 'size_units' => [ 'px', '%', 'em', 'rem', 'vw', 'custom' ] ] );
		$controls += self::responsive( 'boxed_width', [ 'type' => 'slider', 'size_units' => [ 'px', '%', 'em', 'rem', 'vw', 'custom' ], 'default' => [ 'unit' => 'px' ], 'condition' => [ 'content_width' => 'boxed' ] ] );
		$controls += self::responsive( 'width', [ 'type' => 'slider', 'size_units' => [ 'px', '%', 'em', 'rem', 'vw', 'custom' ], 'default' => [ 'unit' => '%' ], 'condition' => [ 'content_width' => 'full' ] ] );
		$controls += self::responsive( 'min_height', [ 'type' => 'slider', 'size_units' => [ 'px', 'em', 'rem', 'vh', 'custom' ] ] );
		$controls += self::responsive( 'padding', [ 'type' => 'dimensions', 'size_units' => [ 'px', '%', 'em', 'rem', 'vw', 'custom' ] ] );
		$controls += self::responsive( 'margin', [ 'type' => 'dimensions', 'size_units' => [ 'px', '%', 'em', 'rem', 'vw', 'custom' ] ] );
		$controls += self::box_shadow( 'box_shadow' );
		return $controls;
	}
}

/** A widget or element object as far as the schema repositories read it. */
final class ControlFixtureElement {

	/** @param array<string, array<string, mixed>> $controls */
	public function __construct( private string $title, private array $controls ) {}

	public function get_title(): string {
		return $this->title;
	}

	/** @return array<string, array<string, mixed>> */
	public function get_controls(): array {
		return $this->controls;
	}
}
