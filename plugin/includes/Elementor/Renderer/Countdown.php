<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\Elementor\Renderer;

use Stonewright\WpMcp\DesignTokens\Resolver;
use Stonewright\WpMcp\Elementor\Renderer\StyleMapper;

/**
 * Renders a DesignSpec `countdown` node as an Elementor Pro Countdown widget.
 *
 * Requires Elementor Pro. When Pro is not active the renderer emits a diagnostic
 * and returns a heading fallback.
 *
 * Spec shape (minimal):
 * {
 *   type: "countdown",
 *   due_date: "2026-06-11 09:00:00",
 *   show: { days: true, hours: true, minutes: true, seconds: false },
 *   labels: { days: "ZILE", hours: "ORE", minutes: "MIN", seconds: "SEC" }
 * }
 *
 * Full settings from Pro source (pro-elements/modules/countdown/widgets/countdown.php):
 *   countdown_type: 'due_date' | 'evergreen'
 *   due_date:       'YYYY-MM-DD HH:MM:SS'
 *   evergreen_counter_hours, evergreen_counter_minutes
 *   show_days, show_hours, show_minutes, show_seconds: 'yes'|'no'
 *   show_labels: 'yes'|'no'
 *   custom_labels: 'yes'|''
 *   label_days, label_hours, label_minutes, label_seconds: string
 *   expire_actions: ['message'|'redirect'|'hide'] (multi-select array)
 *   message_after_expire: string
 *   expire_redirect_url: { url: string }
 *
 * Style group prefixes (from Pro source):
 *   digits_typography  (Group_Control_Typography with name='digits_typography')
 *   label_typography   (Group_Control_Typography with name='label_typography')
 *   digits_color       (single COLOR control)
 *   label_color        (single COLOR control)
 */
final class Countdown {

	/**
	 * @return array<string, string|array<string, mixed>>
	 */
	private static function style_map(): array {
		return [
			'digits_color'              => [ 'key' => 'digits_color', 'is_color' => true ],
			'label_color'               => [ 'key' => 'label_color', 'is_color' => true ],
			'digits_font_size'          => [ 'key' => 'digits_typography_font_size', 'is_size' => true ],
			'digits_font_weight'        => 'digits_typography_font_weight',
			'digits_font_family'        => 'digits_typography_font_family',
			'digits_line_height'        => [ 'key' => 'digits_typography_line_height', 'is_size' => true ],
			'digits_letter_spacing'     => [ 'key' => 'digits_typography_letter_spacing', 'is_size' => true ],
			'digits_text_transform'     => 'digits_typography_text_transform',
			'label_font_size'           => [ 'key' => 'label_typography_font_size', 'is_size' => true ],
			'label_font_weight'         => 'label_typography_font_weight',
			'label_font_family'         => 'label_typography_font_family',
			'label_line_height'         => [ 'key' => 'label_typography_line_height', 'is_size' => true ],
			'label_text_transform'      => 'label_typography_text_transform',
		];
	}

	/**
	 * @param array<string, mixed>             $node
	 * @param Resolver                         $resolver
	 * @param string                           $canonical_path
	 * @param array<int, array<string, mixed>> $diagnostics
	 * @return array<string, mixed>
	 */
	public static function render( array $node, Resolver $resolver, string $canonical_path, array &$diagnostics = [] ): array {
		if ( ! ProGate::active() ) {
			$diagnostics[] = [
				'code'     => ProGate::DIAGNOSTIC_REQUIRED,
				'type'     => 'countdown',
				'path'     => $canonical_path,
				'renderer' => 'elementor_v3',
				'message'  => 'The Elementor Countdown widget requires Elementor Pro. Activate Elementor Pro to render this node.',
			];

			return [
				'id'         => Section::stable_id( $canonical_path ),
				'elType'     => 'widget',
				'widgetType' => 'heading',
				'settings'   => [
					'title'       => '[Countdown placeholder — Elementor Pro required]',
					'header_size' => 'p',
				],
				'elements'   => [],
			];
		}

		$countdown_type = (string) ( $node['countdown_type'] ?? 'due_date' );
		$settings       = [
			'countdown_type' => $countdown_type,
		];

		// Due date.
		if ( 'due_date' === $countdown_type && isset( $node['due_date'] ) ) {
			$settings['due_date'] = (string) $node['due_date'];
		}

		// Evergreen hours/minutes.
		if ( 'evergreen' === $countdown_type ) {
			if ( isset( $node['evergreen_hours'] ) ) {
				$settings['evergreen_counter_hours'] = (int) $node['evergreen_hours'];
			}
			if ( isset( $node['evergreen_minutes'] ) ) {
				$settings['evergreen_counter_minutes'] = (int) $node['evergreen_minutes'];
			}
		}

		// Show/hide individual units.
		// The spec accepts both `show: { days: true }` and flat `show_days: true`.
		$show = is_array( $node['show'] ?? null ) ? (array) $node['show'] : [];
		$settings['show_days']    = self::yes_no( $node['show_days']    ?? ( $show['days']    ?? true ) );
		$settings['show_hours']   = self::yes_no( $node['show_hours']   ?? ( $show['hours']   ?? true ) );
		$settings['show_minutes'] = self::yes_no( $node['show_minutes'] ?? ( $show['minutes'] ?? true ) );
		$settings['show_seconds'] = self::yes_no( $node['show_seconds'] ?? ( $show['seconds'] ?? true ) );

		// Labels.
		$labels = is_array( $node['labels'] ?? null ) ? (array) $node['labels'] : [];
		$settings['show_labels'] = self::yes_no( $node['show_labels'] ?? true );

		// Only emit custom_labels + label values if any label customisation is
		// present in the spec.
		$custom_label_keys = [ 'label_days', 'label_hours', 'label_minutes', 'label_seconds' ];
		$has_custom        = false;
		foreach ( $custom_label_keys as $k ) {
			if ( isset( $node[ $k ] ) ) {
				$has_custom = true;
			}
		}
		// Also check nested `labels` dict.
		if ( ! empty( $labels ) ) {
			$has_custom = true;
		}

		if ( $has_custom ) {
			$settings['custom_labels'] = 'yes';
			if ( isset( $node['label_days'] ) || isset( $labels['days'] ) ) {
				$settings['label_days'] = (string) ( $node['label_days'] ?? $labels['days'] ?? 'Days' );
			}
			if ( isset( $node['label_hours'] ) || isset( $labels['hours'] ) ) {
				$settings['label_hours'] = (string) ( $node['label_hours'] ?? $labels['hours'] ?? 'Hours' );
			}
			if ( isset( $node['label_minutes'] ) || isset( $labels['minutes'] ) ) {
				$settings['label_minutes'] = (string) ( $node['label_minutes'] ?? $labels['minutes'] ?? 'Minutes' );
			}
			if ( isset( $node['label_seconds'] ) || isset( $labels['seconds'] ) ) {
				$settings['label_seconds'] = (string) ( $node['label_seconds'] ?? $labels['seconds'] ?? 'Seconds' );
			}
		}

		// Expire actions (stored as array in Elementor's SELECT2 multi).
		if ( isset( $node['expire_actions'] ) ) {
			$actions = is_array( $node['expire_actions'] ) ? $node['expire_actions'] : [ $node['expire_actions'] ];
			$settings['expire_actions'] = array_values( array_map( 'strval', $actions ) );
		}

		if ( isset( $node['expire_message'] ) ) {
			$settings['message_after_expire'] = (string) $node['expire_message'];
		}

		if ( isset( $node['expire_redirect_url'] ) ) {
			$settings['expire_redirect_url'] = [ 'url' => (string) $node['expire_redirect_url'] ];
		}

		// Style block.
		if ( isset( $node['style'] ) && is_array( $node['style'] ) ) {
			$style    = self::resolve_style( (array) $node['style'], $resolver );
			$settings = StyleMapper::apply( $settings, $style, self::style_map() );
		}

		return [
			'id'         => Section::stable_id( $canonical_path ),
			'elType'     => 'widget',
			'widgetType' => 'countdown',
			'settings'   => $settings,
			'elements'   => [],
		];
	}

	/**
	 * Coerce a truthy/falsy value into Elementor's 'yes'|'no' switcher format.
	 *
	 * @param mixed $value
	 */
	private static function yes_no( $value ): string {
		if ( is_string( $value ) ) {
			return in_array( strtolower( $value ), [ 'yes', '1', 'true' ], true ) ? 'yes' : 'no';
		}
		return $value ? 'yes' : 'no';
	}

	/**
	 * @param array<string, mixed> $style
	 * @return array<string, mixed>
	 */
	private static function resolve_style( array $style, Resolver $resolver ): array {
		foreach ( $style as $k => $v ) {
			if ( is_string( $v ) ) {
				$style[ $k ] = $resolver->resolve( $v );
			}
		}
		return $style;
	}
}
