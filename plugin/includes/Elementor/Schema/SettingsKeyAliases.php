<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\Elementor\Schema;

/**
 * Version-tolerant aliases for Elementor settings keys.
 *
 * Elementor renames control keys across versions/contexts (e.g. justify_content
 * vs flex_justify_content on containers). Aliases normalize inbound settings
 * to the canonical key before validation/write.
 */
final class SettingsKeyAliases {

	/**
	 * Map of alias keys to canonical Elementor settings keys.
	 *
	 * @var array<string, string>
	 */
	private const ALIASES = [
		// Flex / container short forms.
		'justify_content' => 'flex_justify_content',
		'align_items'     => 'flex_align_items',
		'align_content'   => 'flex_align_content',
		'flex_wrap'       => 'flex_wrap',
		// Common spacing renames.
		'gap'             => 'flex_gap',
		'row_gap'         => 'flex_row_gap',
		'column_gap'      => 'flex_column_gap',
		// Typography short forms.
		'font_family'     => 'typography_font_family',
		'font_size'       => 'typography_font_size',
		'font_weight'     => 'typography_font_weight',
		'line_height'     => 'typography_line_height',
		'letter_spacing'  => 'typography_letter_spacing',
		'text_transform'  => 'typography_text_transform',
		'text_decoration' => 'typography_text_decoration',
		// Background short forms.
		'bg_color'        => 'background_color',
		'background'      => 'background_color',
	];

	/**
	 * @return array<string, string>
	 */
	public static function all(): array {
		return self::ALIASES;
	}

	/**
	 * Normalize settings keys; prefers canonical when both present.
	 *
	 * When `$controls` (the live control list of the element) is given, a key
	 * that is itself a control of that element is never rewritten: an alias
	 * only stands in for a name the element does not define.
	 *
	 * @param array<string, mixed>                     $settings
	 * @param array<string, array<string, mixed>>|null $controls Live controls of the element, or null to alias every key.
	 * @return array{settings: array<string, mixed>, applied: list<array{alias:string,canonical:string}>}
	 */
	public static function normalize( array $settings, ?array $controls = null ): array {
		$applied = [];
		foreach ( self::ALIASES as $alias => $canonical ) {
			if ( ! array_key_exists( $alias, $settings ) ) {
				continue;
			}
			if ( $alias === $canonical ) {
				continue;
			}
			if ( null !== $controls && isset( $controls[ $alias ] ) ) {
				continue;
			}
			if ( ! array_key_exists( $canonical, $settings ) ) {
				$settings[ $canonical ] = $settings[ $alias ];
			}
			unset( $settings[ $alias ] );
			$applied[] = [
				'alias'     => $alias,
				'canonical' => $canonical,
			];
		}

		return [
			'settings' => $settings,
			'applied'  => $applied,
		];
	}

	/**
	 * Normalize the settings of one element: widgets keep every key their live
	 * schema defines, containers, sections and columns take the full alias map.
	 *
	 * @param array<string, mixed> $settings
	 * @return array{settings: array<string, mixed>, applied: list<array{alias:string,canonical:string}>}
	 */
	public static function normalize_for_element( array $settings, string $element_type, string $widget_type = '' ): array {
		if ( 'widget' !== $element_type || '' === $widget_type ) {
			return self::normalize( $settings );
		}
		$schema = WidgetSchemaRepository::get( $widget_type );
		return self::normalize( $settings, is_array( $schema ) ? (array) ( $schema['controls'] ?? [] ) : null );
	}

	public static function canonical( string $key ): string {
		return self::ALIASES[ $key ] ?? $key;
	}
}
