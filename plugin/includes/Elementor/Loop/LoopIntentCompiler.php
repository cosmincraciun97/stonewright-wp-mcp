<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\Elementor\Loop;

use Stonewright\WpMcp\Elementor\Schema\SettingsValidator;
use Stonewright\WpMcp\Elementor\Schema\WidgetSchemaRepository;

/**
 * Maps compact loop intent only to controls exposed by the live widget.
 */
final class LoopIntentCompiler {
	private const CONTROL_ALIASES = [
		'template'        => [ 'template_id', 'loop_template_id', 'template' ],
		'post_type'       => [ 'post_type', 'query_post_type' ],
		'posts_per_page'  => [ 'posts_per_page', 'query_posts_per_page' ],
		'columns'         => [ 'columns' ],
		'slides_to_show'  => [ 'slides_to_show' ],
		'slides_to_scroll'=> [ 'slides_to_scroll' ],
		'arrows'          => [ 'arrows', 'navigation' ],
		'pagination'      => [ 'pagination', 'pagination_type' ],
		'order'           => [ 'order', 'query_order' ],
		'orderby'         => [ 'orderby', 'query_orderby' ],
		'offset'          => [ 'offset', 'query_offset' ],
		'post__in'        => [ 'post__in' ],
		'post__not_in'    => [ 'post__not_in' ],
		'tax_query'       => [ 'tax_query' ],
		'meta_query'      => [ 'meta_query' ],
	];

	/**
	 * @param array<string, mixed> $intent
	 * @return array<string, mixed>|\WP_Error
	 */
	public static function compile( string $display, int $template_id, string $post_type, array $intent ): array|\WP_Error {
		$widget_type = match ( $display ) {
			'carousel' => 'loop-carousel',
			'grid'     => 'loop-grid',
			default    => '',
		};
		if ( '' === $widget_type ) {
			return self::error(
				'display_invalid',
				__( 'Loop display must be carousel or grid.', 'stonewright' ),
				[ 'status' => 400, 'display' => sanitize_key( $display ) ]
			);
		}

		$schema = WidgetSchemaRepository::get( $widget_type );
		if ( $schema instanceof \WP_Error ) {
			return new \WP_Error(
				'stonewright_loop_widget_unavailable',
				$schema->get_error_message(),
				array_merge( (array) $schema->get_error_data(), [ 'widget_type' => $widget_type ] )
			);
		}
		$controls = is_array( $schema['controls'] ?? null ) ? $schema['controls'] : [];
		$template_control = self::resolve( $controls, 'template', true );
		if ( $template_control instanceof \WP_Error ) {
			return $template_control;
		}

		$settings = [ $template_control => $template_id ];
		$resolved = [ 'template' => $template_control ];
		$post_type_control = self::resolve( $controls, 'post_type', false );
		if ( is_string( $post_type_control ) ) {
			$settings[ $post_type_control ] = sanitize_key( $post_type );
			$resolved['post_type'] = $post_type_control;
		}

		$query = is_array( $intent['query'] ?? null ) ? $intent['query'] : [];
		foreach ( [ 'posts_per_page', 'post__in', 'post__not_in', 'tax_query', 'meta_query', 'order', 'orderby', 'offset' ] as $semantic ) {
			if ( ! array_key_exists( $semantic, $query ) ) {
				continue;
			}
			$control = self::resolve( $controls, $semantic, true );
			if ( $control instanceof \WP_Error ) {
				return $control;
			}
			$settings[ $control ] = self::query_value( $semantic, $query[ $semantic ] );
			$resolved[ $semantic ] = $control;
		}

		$responsive = is_array( $intent['responsive'] ?? null ) ? $intent['responsive'] : [];
		$responsive_semantic = 'carousel' === $display ? 'slides_to_show' : 'columns';
		if ( [] !== $responsive ) {
			$control = self::resolve( $controls, $responsive_semantic, true );
			if ( $control instanceof \WP_Error ) {
				return $control;
			}
			foreach ( [ 'desktop' => '', 'tablet' => '_tablet', 'mobile' => '_mobile' ] as $breakpoint => $suffix ) {
				if ( array_key_exists( $breakpoint, $responsive ) ) {
					$settings[ $control . $suffix ] = max( 1, (int) $responsive[ $breakpoint ] );
				}
			}
			$resolved[ $responsive_semantic ] = $control;
		}

		foreach ( [ 'slides_to_scroll', 'arrows', 'pagination' ] as $semantic ) {
			if ( ! array_key_exists( $semantic, $intent ) ) {
				continue;
			}
			$control = self::resolve( $controls, $semantic, true );
			if ( $control instanceof \WP_Error ) {
				return $control;
			}
			$value = $intent[ $semantic ];
			if ( in_array( $semantic, [ 'arrows', 'pagination' ], true ) ) {
				if ( 'pagination' === $semantic && ! self::is_switcher( $controls[ $control ] ) ) {
					$value = self::pagination_value( $controls[ $control ], (bool) $value );
					if ( $value instanceof \WP_Error ) {
						return $value;
					}
				} else {
					$value = self::switcher_value( $controls[ $control ], (bool) $value );
				}
			} elseif ( 'slides_to_scroll' === $semantic ) {
				$value = max( 1, (int) $value );
			}
			$settings[ $control ] = $value;
			$resolved[ $semantic ] = $control;
		}

		$validated = SettingsValidator::validate( $widget_type, $settings );
		if ( $validated instanceof \WP_Error ) {
			return $validated;
		}

		return [
			'widget_type'        => $widget_type,
			'settings'           => $validated['settings'],
			'schema_hash'        => (string) ( $schema['schema_hash'] ?? '' ),
			'runtime_fingerprint'=> (string) ( $schema['runtime_fingerprint'] ?? '' ),
			'resolved_controls'  => $resolved,
			'warnings'           => (array) ( $validated['warnings'] ?? [] ),
		];
	}

	/**
	 * @param array<string, array<string, mixed>> $controls
	 * @return string|\WP_Error|null
	 */
	private static function resolve( array $controls, string $semantic, bool $required ): string|\WP_Error|null {
		foreach ( self::CONTROL_ALIASES[ $semantic ] ?? [] as $candidate ) {
			if ( array_key_exists( $candidate, $controls ) ) {
				return $candidate;
			}
		}
		if ( ! $required ) {
			return null;
		}
		return self::error(
			'schema_incompatible',
			sprintf( __( 'Live loop widget schema has no compatible %s control.', 'stonewright' ), $semantic ),
			[
				'status'                   => 409,
				'missing_semantic_control' => $semantic,
				'accepted_aliases'         => self::CONTROL_ALIASES[ $semantic ] ?? [],
			]
		);
	}

	private static function query_value( string $semantic, mixed $value ): mixed {
		return match ( $semantic ) {
			'posts_per_page' => max( 1, min( 100, (int) $value ) ),
			'offset'         => max( 0, (int) $value ),
			'order'          => 'ASC' === strtoupper( (string) $value ) ? 'ASC' : 'DESC',
			'post__in', 'post__not_in' => array_values( array_unique( array_map( 'absint', (array) $value ) ) ),
			'tax_query', 'meta_query'  => (array) $value,
			default          => sanitize_key( (string) $value ),
		};
	}

	/** @param array<string, mixed> $control */
	private static function switcher_value( array $control, bool $enabled ): string {
		$on = is_scalar( $control['return_value'] ?? null ) ? (string) $control['return_value'] : 'yes';
		return $enabled ? $on : '';
	}

	/** @param array<string, mixed> $control */
	private static function is_switcher( array $control ): bool {
		return 'switcher' === strtolower( (string) ( $control['type'] ?? '' ) );
	}

	/** @param array<string, mixed> $control */
	private static function pagination_value( array $control, bool $enabled ): string|\WP_Error {
		$options = array_map( 'strval', array_keys( (array) ( $control['options'] ?? [] ) ) );
		$candidates = $enabled ? [ 'numbers', 'dots', 'bullets', 'yes' ] : [ '', 'none', 'no' ];
		foreach ( $candidates as $candidate ) {
			if ( in_array( $candidate, $options, true ) ) {
				return $candidate;
			}
		}
		return self::error(
			'schema_incompatible',
			__( 'Live pagination control has no safe boolean mapping.', 'stonewright' ),
			[
				'status'                   => 409,
				'missing_semantic_control' => 'pagination',
				'available_options'        => array_slice( $options, 0, 10 ),
			]
		);
	}

	/** @param array<string, mixed> $data */
	private static function error( string $code, string $message, array $data ): \WP_Error {
		return new \WP_Error( 'stonewright_loop_' . $code, $message, $data );
	}
}
