<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\Elementor\WidgetRegistry;

/**
 * Turns widget recommendations into an implementation checklist that forces
 * the caller to configure Elementor controls, not merely place widgets.
 */
final class WidgetImplementationGuide {

	/**
	 * @param array<int, string> $candidate_widgets
	 * @return array<string, mixed>
	 */
	public static function build( string $task, array $candidate_widgets = [], string $design_context = '' ): array {
		$widgets = self::rank_widgets( $task, $candidate_widgets, $design_context );

		return [
			'ok'                    => true,
			'task'                  => $task,
			'design_context'        => $design_context,
			'recommendations'       => array_map(
				static fn( string $slug ): array => self::recommendation( $slug ),
				$widgets
			),
			'global_required_steps' => [
				'Call stonewright/elementor-describe-widget for every recommended widget before writing.',
				'Configure Content, Style, and Advanced controls; do not only insert the widget.',
				'Use responsive desktop, tablet, and mobile values for width, alignment, spacing, order, and visibility where the design implies them.',
				'When any recommendation has needs_online_research=true, research official Elementor documentation before writing.',
				'Do not use Elementor HTML widgets unless the user explicitly requested HTML and the write ability passes allow_html_widget=true.',
				'Before using background assets, write an asset selection plan: target section, source layer/node, crop bounds, WordPress media URL, and why it is not a full-page screenshot.',
				'Do not use a full-page screenshot as a section background; export the exact layer/section asset or recreate simple colors/gradients with Elementor controls.',
			],
		];
	}

	/**
	 * @param array<int, string> $candidate_widgets
	 * @return array<int, string>
	 */
	private static function rank_widgets( string $task, array $candidate_widgets, string $design_context ): array {
		$allow_html = preg_match( '/\ballow_html_widget\s*=\s*true\b/i', $design_context . ' ' . $task ) === 1;
		$clean      = [];
		foreach ( $candidate_widgets as $slug ) {
			$slug = self::clean_slug( $slug );
			if ( '' === $slug || ( 'html' === $slug && ! $allow_html ) ) {
				continue;
			}
			$clean[] = $slug;
		}

		$ranked = WidgetRecommender::recommend(
			$task,
			5,
			[
				'design_context'    => $design_context,
				'allow_html_widget' => $allow_html,
			]
		);

		$ordered = [];
		foreach ( $ranked as $row ) {
			$slug = self::clean_slug( (string) ( $row['slug'] ?? '' ) );
			if ( '' !== $slug && in_array( $slug, $clean, true ) ) {
				$ordered[] = $slug;
			}
		}

		foreach ( $clean as $slug ) {
			if ( ! in_array( $slug, $ordered, true ) ) {
				$ordered[] = $slug;
			}
		}

		return array_values( array_unique( $ordered ) );
	}

	/**
	 * @return array<string, mixed>
	 */
	private static function recommendation( string $slug ): array {
		$entry       = WidgetCatalog::entry( $slug );
		$known      = WidgetCatalog::has( $slug );
		$control_map = self::control_map( $slug );

		return [
			'widget'                => $slug,
			'title'                 => (string) ( $entry['title'] ?? $slug ),
			'ability'               => 'stonewright/elementor-add-' . $slug,
			'needs_online_research' => ! $known || count( $control_map['Content'] ) < 2 || count( $control_map['Style'] ) < 2,
			'required_controls'     => $control_map,
			'settings_highlights'   => array_slice( array_values( (array) ( $entry['settings_highlights'] ?? [] ) ), 0, 5 ),
			'required_for_render'   => WidgetCatalog::required_for_render( $slug ),
		];
	}

	/**
	 * @return array{Content:array<int,string>,Style:array<int,string>,Advanced:array<int,string>}
	 */
	private static function control_map( string $slug ): array {
		$content = [];
		$style   = [];

		foreach ( (array) ( WidgetCatalog::entry( $slug )['sections'] ?? [] ) as $section ) {
			if ( ! is_array( $section ) ) {
				continue;
			}

			$tab     = (string) ( $section['tab'] ?? '' );
			$target  = 'style' === $tab ? 'style' : 'content';
			$section_label = trim( (string) ( $section['label'] ?? '' ) );

			foreach ( (array) ( $section['controls'] ?? [] ) as $control ) {
				if ( ! is_array( $control ) ) {
					continue;
				}
				$label = self::control_label( $control, $section_label );
				if ( 'style' === $target ) {
					$style[] = $label;
				} else {
					$content[] = $label;
				}
			}

			foreach ( (array) ( $section['group_controls'] ?? [] ) as $group ) {
				if ( ! is_array( $group ) ) {
					continue;
				}
				$label = self::group_label( $group, $section_label );
				if ( 'style' === $target ) {
					$style[] = $label;
				} else {
					$content[] = $label;
				}
			}
		}

		return [
			'Content'  => self::limit_controls( $content, self::fallback_content_controls( $slug ) ),
			'Style'    => self::limit_controls( $style, self::fallback_style_controls( $slug ) ),
			'Advanced' => [
				'margin and padding',
				'width and custom width',
				'position absolute/fixed when design requires it',
				'z-index and order',
				'responsive visibility',
				'motion effects and transform',
				'background and background overlay',
				'border, radius, shadow, and CSS classes',
				'attributes, display conditions, cache settings where available',
			],
		];
	}

	/**
	 * @param array<int, string> $controls
	 * @param array<int, string> $fallback
	 * @return array<int, string>
	 */
	private static function limit_controls( array $controls, array $fallback ): array {
		$clean = array_values(
			array_unique(
				array_filter(
					array_map( 'trim', $controls ),
					static fn( string $item ): bool => '' !== $item
				)
			)
		);

		if ( [] === $clean ) {
			$clean = $fallback;
		}

		return array_slice( $clean, 0, 12 );
	}

	/**
	 * @param array<string, mixed> $control
	 */
	private static function control_label( array $control, string $section_label ): string {
		$key   = (string) ( $control['key'] ?? '' );
		$label = self::string_value( $control['label'] ?? $key, $key );
		$type  = (string) ( $control['type'] ?? '' );

		return trim( sprintf( '%s%s%s', '' !== $section_label ? $section_label . ': ' : '', $label, '' !== $type ? ' (' . $type . ')' : '' ) );
	}

	/**
	 * @param array<string, mixed> $group
	 */
	private static function group_label( array $group, string $section_label ): string {
		$name       = self::string_value( $group['name'] ?? $group['group'] ?? '', '' );
		$group_name = self::string_value( $group['group'] ?? '', '' );
		return trim( sprintf( '%s%s group%s', '' !== $section_label ? $section_label . ': ' : '', $name, '' !== $group_name ? ' (' . $group_name . ')' : '' ) );
	}

	private static function string_value( mixed $value, string $fallback ): string {
		if ( is_scalar( $value ) ) {
			return (string) $value;
		}
		if ( is_array( $value ) ) {
			foreach ( [ 'label', 'title', 'name', 'default' ] as $key ) {
				if ( isset( $value[ $key ] ) && is_scalar( $value[ $key ] ) ) {
					return (string) $value[ $key ];
				}
			}
		}
		return $fallback;
	}

	/**
	 * @return array<int, string>
	 */
	private static function fallback_content_controls( string $slug ): array {
		return match ( $slug ) {
			'nav-menu' => [ 'menu source', 'layout', 'dropdown breakpoint', 'submenu behavior' ],
			'form' => [ 'fields', 'labels', 'actions after submit', 'button text' ],
			'image-gallery', 'gallery' => [ 'image collection', 'columns', 'link behavior', 'caption' ],
			default => [ 'primary content', 'links/items', 'icons/media', 'semantic tags' ],
		};
	}

	/**
	 * @return array<int, string>
	 */
	private static function fallback_style_controls( string $slug ): array {
		return match ( $slug ) {
			'nav-menu' => [ 'typography', 'text color', 'hover/active state', 'dropdown colors', 'toggle button spacing' ],
			'form' => [ 'field typography', 'field background', 'button background', 'button hover', 'spacing' ],
			'image-gallery', 'gallery' => [ 'gap', 'border radius', 'overlay', 'caption typography' ],
			default => [ 'typography', 'colors', 'background', 'border', 'spacing', 'hover state' ],
		};
	}

	private static function clean_slug( string $slug ): string {
		return trim( strtolower( preg_replace( '/[^a-z0-9_-]+/', '-', $slug ) ?? '' ), '-' );
	}
}
