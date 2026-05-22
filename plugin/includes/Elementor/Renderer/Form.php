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

		return [
			'id'         => Section::stable_id( $canonical_path ),
			'elType'     => 'widget',
			'widgetType' => 'form',
			'settings'   => $settings,
			'elements'   => [],
		];
	}
}
