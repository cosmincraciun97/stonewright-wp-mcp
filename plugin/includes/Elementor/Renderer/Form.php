<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\Elementor\Renderer;

use Stonewright\WpMcp\DesignTokens\Resolver;

/**
 * Renders a DesignSpec `form` node as an Elementor Pro form widget.
 *
 * **Elementor Pro is required.** If the `elementor-pro/elementor-pro.php` plugin
 * is not active this renderer returns a diagnostic-friendly fallback that surfaces
 * a clear unsupported message rather than silently breaking the page.
 *
 * Spec shape:
 *   {
 *     type: "form",
 *     form_name: "Contact Us",
 *     fields: [
 *       { type: "text",  label: "Name",  required: true },
 *       { type: "email", label: "Email", required: true }
 *     ],
 *     submit_actions: ["email"],
 *     button_text: "Send"
 *   }
 */
final class Form {

	/**
	 * @param array<string, mixed>         $node
	 * @param Resolver                     $resolver
	 * @param string                       $canonical_path
	 * @param array<int, array<string, mixed>> $diagnostics  Passed by reference; unsupported node info appended here.
	 * @return array<string, mixed>
	 */
	public static function render( array $node, Resolver $resolver, string $canonical_path, array &$diagnostics = [] ): array {
		if ( ! ProGate::active() ) {
			$diagnostics[] = [
				'code'      => ProGate::DIAGNOSTIC_REQUIRED,
				'type'      => 'form',
				'path'      => $canonical_path,
				'renderer'  => 'elementor_v3',
				'message'   => 'The Elementor Form widget requires Elementor Pro. Activate Elementor Pro to render this node.',
			];

			// Return a heading widget as a graceful fallback.
			return [
				'id'         => Section::stable_id( $canonical_path ),
				'elType'     => 'widget',
				'widgetType' => 'heading',
				'settings'   => [
					'title'       => '[Form placeholder — Elementor Pro required]',
					'header_size' => 'p',
				],
				'elements'   => [],
			];
		}

		$settings = self::settings_from_node( $node, $resolver, $canonical_path );

		return [
			'id'         => Section::stable_id( $canonical_path ),
			'elType'     => 'widget',
			'widgetType' => 'form',
			'settings'   => $settings,
			'elements'   => [],
		];
	}

	/**
	 * Build native Elementor form settings from a DesignSpec node.
	 *
	 * @param array<string, mixed> $node
	 * @return array<string, mixed>
	 */
	public static function settings_from_node( array $node, Resolver $resolver, string $canonical_path ): array {
		$fields     = [];
		$raw_fields = isset( $node['fields'] ) && is_array( $node['fields'] ) ? $node['fields'] : [];
		foreach ( $raw_fields as $i => $field ) {
			$field    = is_array( $field ) ? $field : [];
			$fields[] = [
				'_id'          => Section::stable_id( $canonical_path . '.field.' . $i ),
				'field_type'   => (string) ( $field['type'] ?? 'text' ),
				'field_label'  => (string) ( $field['label'] ?? '' ),
				'field_value'  => (string) ( $field['default'] ?? '' ),
				'required'     => ! empty( $field['required'] ) ? 'true' : '',
				'placeholder'  => (string) ( $field['placeholder'] ?? '' ),
			];
		}

		$settings = [
			'form_name'      => (string) ( $node['form_name'] ?? 'Contact Form' ),
			'form_fields'    => $fields,
			'button_text'    => (string) ( $node['button_text'] ?? 'Submit' ),
			'submit_actions' => isset( $node['submit_actions'] ) && is_array( $node['submit_actions'] )
				? $node['submit_actions']
				: [ 'email' ],
		];

		if ( isset( $node['field_style'] ) && is_array( $node['field_style'] ) ) {
			$settings = StyleMapper::apply(
				$settings,
				self::resolve_style( (array) $node['field_style'], $resolver ),
				[
					'background'    => [ 'key' => 'field_background_color', 'is_color' => true ],
					'text_color'    => [ 'key' => 'field_text_color', 'is_color' => true ],
					'border_color'  => [ 'key' => 'field_border_color', 'is_color' => true ],
					'border_width'  => [ 'key' => 'field_border_width', 'is_dimension' => true ],
					'border_radius' => [ 'key' => 'field_border_radius', 'is_dimension' => true ],
					'font_family'   => 'field_typography_font_family',
					'font_size'     => [ 'key' => 'field_typography_font_size', 'is_size' => true ],
					'font_weight'   => 'field_typography_font_weight',
				]
			);
		}

		if ( isset( $node['button_style'] ) && is_array( $node['button_style'] ) ) {
			$settings = StyleMapper::apply(
				$settings,
				self::resolve_style( (array) $node['button_style'], $resolver ),
				[
					'background'    => [ 'key' => 'button_background_color', 'is_color' => true ],
					'color'         => [ 'key' => 'button_text_color', 'is_color' => true ],
					'border_radius' => [ 'key' => 'button_border_radius', 'is_dimension' => true ],
					'font_family'   => 'button_typography_font_family',
					'font_size'     => [ 'key' => 'button_typography_font_size', 'is_size' => true ],
					'font_weight'   => 'button_typography_font_weight',
					'padding'       => [ 'key' => 'button_text_padding', 'is_dimension' => true ],
				]
			);
		}

		return $settings;
	}

	/**
	 * @param array<string, mixed> $style
	 * @return array<string, mixed>
	 */
	private static function resolve_style( array $style, Resolver $resolver ): array {
		foreach ( $style as $key => $value ) {
			if ( is_string( $value ) ) {
				$style[ $key ] = $resolver->resolve( $value );
			}
			if ( 'font_weight' === $key && is_numeric( $style[ $key ] ) ) {
				$style[ $key ] = (string) (int) $style[ $key ];
			}
		}
		return $style;
	}
}
