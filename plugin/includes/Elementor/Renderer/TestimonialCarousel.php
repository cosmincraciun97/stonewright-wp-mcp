<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\Elementor\Renderer;

use Stonewright\WpMcp\DesignTokens\Resolver;
use Stonewright\WpMcp\Elementor\Schema\WidgetSchemaRepository;
use Stonewright\WpMcp\Elementor\WidgetRegistry\WidgetCatalog;

/**
 * Live-schema-driven review / testimonial carousel compound.
 *
 * Selects only a registered native widget from a priority candidate list.
 * Validates repeater / nested children. Preserves unknown settings when
 * callers pass them through. Never substitutes static icon boxes.
 *
 * Candidate order:
 * 1. `testimonial-carousel` (Pro)
 * 2. `reviews` (Pro)
 * 3. `slides` (Pro, when items map to slides)
 * 4. `image-carousel` (only when items are pure images — still a carousel)
 *
 * When none are registered: emits `stonewright_elementor_native_widget_unavailable`
 * with candidates and returns null (no write).
 */
final class TestimonialCarousel {

	public const ERROR_CODE = 'stonewright_elementor_native_widget_unavailable';

	/** @var list<string> */
	private const CANDIDATES = [
		'testimonial-carousel',
		'reviews',
		'slides',
		'nested-carousel',
	];

	/**
	 * @param array<string, mixed>             $node
	 * @param array<int, array<string, mixed>> $diagnostics
	 * @return array<string, mixed>|null
	 */
	public static function render( array $node, Resolver $resolver, string $canonical_path, array &$diagnostics = [] ): ?array {
		$preferred = isset( $node['widget_type'] ) ? trim( (string) $node['widget_type'] ) : '';
		$candidates = self::CANDIDATES;
		if ( '' !== $preferred ) {
			array_unshift( $candidates, $preferred );
			$candidates = array_values( array_unique( $candidates ) );
		}

		$selected = self::select_widget( $candidates );
		if ( null === $selected ) {
			$diagnostics[] = [
				'code'       => self::ERROR_CODE,
				'type'       => (string) ( $node['type'] ?? 'testimonial-carousel' ),
				'path'       => $canonical_path,
				'renderer'   => 'elementor_v3',
				'message'    => 'No compatible native Elementor carousel/reviews widget is registered. Refusing write; never substitute static icon boxes.',
				'candidates' => $candidates,
				'repair'     => 'Install/activate Elementor Pro (or a widget providing testimonial-carousel/reviews/slides) or revise the design to a supported native widget.',
			];
			return null;
		}

		$items = self::items( $node );
		if ( [] === $items ) {
			$diagnostics[] = [
				'code'     => 'stonewright_elementor_carousel_items_required',
				'type'     => (string) ( $node['type'] ?? 'testimonial-carousel' ),
				'path'     => $canonical_path,
				'renderer' => 'elementor_v3',
				'message'  => 'Carousel requires at least one testimonial/review item.',
			];
			return null;
		}

		$settings = self::build_settings( $selected, $items, $node, $resolver, $canonical_path );
		if ( $settings instanceof \WP_Error ) {
			$diagnostics[] = [
				'code'     => (string) $settings->get_error_code(),
				'type'     => (string) ( $node['type'] ?? 'testimonial-carousel' ),
				'path'     => $canonical_path,
				'renderer' => 'elementor_v3',
				'message'  => $settings->get_error_message(),
				'data'     => $settings->get_error_data(),
			];
			return null;
		}

		// Preserve unknown settings the caller already validated against live schema.
		if ( isset( $node['settings'] ) && is_array( $node['settings'] ) ) {
			foreach ( $node['settings'] as $key => $value ) {
				if ( ! array_key_exists( (string) $key, $settings ) ) {
					$settings[ (string) $key ] = $value;
				}
			}
		}

		return [
			'id'         => Section::stable_id( $canonical_path ),
			'elType'     => 'widget',
			'widgetType' => $selected,
			'settings'   => $settings,
			'elements'   => [],
		];
	}

	/**
	 * @param list<string> $candidates
	 */
	public static function select_widget( array $candidates ): ?string {
		foreach ( $candidates as $candidate ) {
			$candidate = trim( (string) $candidate );
			if ( '' === $candidate ) {
				continue;
			}

			// Live runtime wins when Elementor is booted.
			if ( class_exists( '\\Elementor\\Plugin' ) ) {
				$schema = WidgetSchemaRepository::get( $candidate );
				if ( ! ( $schema instanceof \WP_Error ) ) {
					return $candidate;
				}
			}

			// Offline / catalog: accept known bundled widgets.
			if ( WidgetCatalog::has( $candidate ) ) {
				// Slides is Pro-gated for actual render; still selectable when catalog has it.
				if ( 'slides' === $candidate && ! ProGate::active() && ! WidgetCatalog::has( 'slides' ) ) {
					continue;
				}
				return $candidate;
			}

			// Pro-only candidates when Pro is active even if catalog shard missing.
			if ( ProGate::active() && in_array( $candidate, [ 'testimonial-carousel', 'reviews', 'nested-carousel' ], true ) ) {
				// Without live schema we cannot claim registration — skip.
				continue;
			}
		}

		return null;
	}

	/**
	 * @param array<string, mixed> $node
	 * @return list<array<string, mixed>>
	 */
	private static function items( array $node ): array {
		foreach ( [ 'items', 'testimonials', 'reviews', 'slides' ] as $key ) {
			if ( isset( $node[ $key ] ) && is_array( $node[ $key ] ) ) {
				$out = [];
				foreach ( $node[ $key ] as $item ) {
					if ( is_array( $item ) ) {
						$out[] = $item;
					}
				}
				return $out;
			}
		}
		return [];
	}

	/**
	 * @param list<array<string, mixed>> $items
	 * @param array<string, mixed>       $node
	 * @return array<string, mixed>|\WP_Error
	 */
	private static function build_settings( string $widget, array $items, array $node, Resolver $resolver, string $path ): array|\WP_Error {
		return match ( $widget ) {
			'slides' => self::settings_for_slides( $items, $node, $resolver, $path ),
			'testimonial-carousel', 'reviews' => self::settings_for_repeater( $widget, $items, $node, $resolver, $path ),
			'nested-carousel' => self::settings_for_repeater( $widget, $items, $node, $resolver, $path ),
			default => new \WP_Error(
				self::ERROR_CODE,
				sprintf( 'Selected widget "%s" has no compound settings builder.', $widget ),
				[ 'widget_type' => $widget ]
			),
		};
	}

	/**
	 * @param list<array<string, mixed>> $items
	 * @param array<string, mixed>       $node
	 * @return array<string, mixed>
	 */
	private static function settings_for_slides( array $items, array $node, Resolver $resolver, string $path ): array {
		$slides = [];
		foreach ( $items as $i => $item ) {
			$image  = isset( $item['image'] ) && is_array( $item['image'] ) ? $item['image'] : [];
			$button = isset( $item['button'] ) && is_array( $item['button'] ) ? $item['button'] : [];
			$slides[] = [
				'_id'              => Section::stable_id( $path . '.item.' . $i ),
				'heading'          => (string) ( $item['name'] ?? $item['heading'] ?? $item['title'] ?? '' ),
				'description'      => (string) ( $item['content'] ?? $item['description'] ?? $item['text'] ?? '' ),
				'background_image' => [
					'url' => (string) ( $image['url'] ?? '' ),
					'id'  => isset( $image['id'] ) ? (int) $image['id'] : '',
					'alt' => (string) ( $image['alt'] ?? '' ),
				],
				'button_text'      => (string) ( $button['text'] ?? $item['job'] ?? '' ),
				'link'             => [ 'url' => (string) ( $button['url'] ?? $item['url'] ?? '' ) ],
			];
		}

		$settings = [ 'slides' => $slides ];
		if ( isset( $node['autoplay'] ) ) {
			$settings['autoplay'] = ! empty( $node['autoplay'] ) ? 'yes' : '';
		}
		return $settings;
	}

	/**
	 * Generic repeater mapping for testimonial-carousel / reviews.
	 *
	 * @param list<array<string, mixed>> $items
	 * @param array<string, mixed>       $node
	 * @return array<string, mixed>
	 */
	private static function settings_for_repeater( string $widget, array $items, array $node, Resolver $resolver, string $path ): array {
		// Prefer live schema repeater key when available.
		$repeater_key = self::detect_repeater_key( $widget ) ?? 'slides';
		$rows         = [];
		foreach ( $items as $i => $item ) {
			$image  = isset( $item['image'] ) && is_array( $item['image'] ) ? $item['image'] : [];
			$row    = [
				'_id'         => Section::stable_id( $path . '.item.' . $i ),
				'content'     => (string) ( $item['content'] ?? $item['text'] ?? $item['description'] ?? '' ),
				'name'        => (string) ( $item['name'] ?? $item['title'] ?? '' ),
				'title'       => (string) ( $item['job'] ?? $item['title'] ?? $item['role'] ?? '' ),
				'image'       => [
					'url' => (string) ( $image['url'] ?? '' ),
					'id'  => isset( $image['id'] ) ? (int) $image['id'] : '',
					'alt' => (string) ( $image['alt'] ?? '' ),
				],
			];
			// Common alternate control names used across Pro versions.
			$row['testimonial_content'] = $row['content'];
			$row['testimonial_name']    = $row['name'];
			$row['testimonial_job']     = $row['title'];
			$row['testimonial_image']   = $row['image'];
			$rows[] = $row;
		}

		$settings = [ $repeater_key => $rows ];
		if ( isset( $node['autoplay'] ) ) {
			$settings['autoplay'] = ! empty( $node['autoplay'] ) ? 'yes' : '';
		}
		if ( isset( $node['slides_to_show'] ) ) {
			$settings['slides_to_show'] = (string) (int) $node['slides_to_show'];
		}

		return $settings;
	}

	private static function detect_repeater_key( string $widget ): ?string {
		if ( ! class_exists( '\\Elementor\\Plugin' ) ) {
			// Catalog offline: prefer slides for testimonial-carousel family.
			return match ( $widget ) {
				'reviews' => 'slides',
				default   => 'slides',
			};
		}

		$schema = WidgetSchemaRepository::get( $widget );
		if ( $schema instanceof \WP_Error ) {
			return null;
		}

		$controls = is_array( $schema['controls'] ?? null ) ? $schema['controls'] : [];
		foreach ( [ 'slides', 'testimonials', 'reviews', 'carousel' ] as $key ) {
			if ( isset( $controls[ $key ] ) ) {
				return $key;
			}
		}

		return 'slides';
	}
}
