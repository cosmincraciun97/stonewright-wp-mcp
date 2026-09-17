<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\Elementor\Loop;

use Stonewright\WpMcp\Elementor\Schema\ResponsiveScope;
use Stonewright\WpMcp\Elementor\Schema\SettingsValidator;
use Stonewright\WpMcp\Elementor\Schema\WidgetSchemaRepository;

/**
 * Maps compact loop intent only to controls confirmed by the live widget schema.
 */
final class LoopIntentCompiler {
	private const CONTROL_ALIASES = [
		'template'             => [ 'template_id', 'loop_template_id', 'template' ],
		'post_type'            => [ 'query_post_type', 'post_type' ],
		'posts_per_page'       => [ 'posts_per_page', 'query_posts_per_page' ],
		'columns'              => [ 'columns' ],
		'slides_to_show'       => [ 'slides_to_show' ],
		'slides_to_scroll'     => [ 'slides_to_scroll' ],
		'arrows'               => [ 'arrows', 'navigation' ],
		'pagination'           => [ 'pagination', 'pagination_type' ],
		'pagination_load_type' => [ 'pagination_load_type' ],
		'order'                => [ 'order', 'query_order' ],
		'orderby'              => [ 'orderby', 'query_orderby' ],
		'offset'               => [ 'offset', 'query_offset' ],
		'post__in'             => [ 'post__in' ],
		'post__not_in'         => [ 'post__not_in' ],
		'tax_query'            => [ 'tax_query' ],
		'meta_query'           => [ 'meta_query' ],
	];

	private const QUERY_SOURCE_SENTINELS = [ 'by_id', 'current_query', 'related', 'recent' ];

	private const POST_TYPE_CONTROL_TYPES = [ 'select', 'select2', 'query' ];

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
		$post_type_control = self::resolve_post_type_control( $controls );
		if ( $post_type_control instanceof \WP_Error ) {
			return $post_type_control;
		}
		$post_type = sanitize_key( $post_type );
		$options_error = self::assert_post_type_option(
			$controls[ $post_type_control ],
			$post_type,
			$post_type_control,
			! empty( $intent['pending_post_type'] )
		);
		if ( $options_error instanceof \WP_Error ) {
			return $options_error;
		}
		$settings[ $post_type_control ] = $post_type;
		$resolved['post_type'] = $post_type_control;
		$pending_post_type = ! empty( $intent['pending_post_type'] );

		$scope = ResponsiveScope::requested_names( $intent['responsive_scope'] ?? [] );
		$query = is_array( $intent['query'] ?? null ) ? $intent['query'] : [];
		foreach ( [ 'posts_per_page', 'post__in', 'post__not_in', 'tax_query', 'meta_query', 'order', 'orderby', 'offset' ] as $semantic ) {
			if ( ! array_key_exists( $semantic, $query ) ) {
				continue;
			}
			$control = self::resolve( $controls, $semantic, true );
			if ( $control instanceof \WP_Error ) {
				return $control;
			}
			$narrow = self::non_responsive_in_narrow_scope( $controls[ $control ], $control, $scope );
			if ( $narrow instanceof \WP_Error ) {
				return $narrow;
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

		if ( array_key_exists( 'pagination_load_type', $intent ) ) {
			$load_type = self::apply_conditional_control(
				$controls,
				'pagination_load_type',
				$intent['pagination_load_type'],
				$settings
			);
			if ( $load_type instanceof \WP_Error ) {
				return $load_type;
			}
			$settings[ $load_type['control'] ] = $load_type['value'];
			$resolved['pagination_load_type'] = $load_type['control'];
		}

		$visibility = is_array( $intent['visibility'] ?? null ) ? $intent['visibility'] : [];
		foreach ( $visibility as $key => $value ) {
			$key = sanitize_key( (string) $key );
			if ( '' === $key || ! array_key_exists( $key, $controls ) ) {
				return self::error(
					'schema_incompatible',
					sprintf( __( 'Live loop widget schema has no compatible %s control.', 'stonewright' ), $key ),
					[
						'status'                   => 409,
						'missing_semantic_control' => $key,
						'path'                     => 'settings.' . $key,
						'expected'                 => 'a native visibility control from the live schema',
					]
				);
			}
			$settings[ $key ] = is_scalar( $value ) ? (string) $value : '';
		}

		$to_validate = $settings;
		if ( $pending_post_type ) {
			unset( $to_validate[ $post_type_control ] );
		}
		$validated = SettingsValidator::validate( $widget_type, $to_validate );
		if ( $validated instanceof \WP_Error ) {
			return $validated;
		}
		if ( $pending_post_type ) {
			$validated['settings'][ $post_type_control ] = $post_type;
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
	 * @return string|\WP_Error
	 */
	private static function resolve_post_type_control( array $controls ): string|\WP_Error {
		$ordered = [];
		foreach ( self::CONTROL_ALIASES['post_type'] as $candidate ) {
			if ( array_key_exists( $candidate, $controls ) ) {
				$ordered[] = $candidate;
			}
		}
		foreach ( array_keys( $controls ) as $key ) {
			$key = (string) $key;
			if ( ( 'post_type' === $key || str_ends_with( $key, '_post_type' ) ) && ! in_array( $key, $ordered, true ) ) {
				$ordered[] = $key;
			}
		}

		foreach ( $ordered as $candidate ) {
			if ( self::is_confirmed_post_type_control( (array) $controls[ $candidate ] ) ) {
				return $candidate;
			}
		}

		return self::error(
			'schema_incompatible',
			__( 'Live loop widget schema has no compatible post_type control.', 'stonewright' ),
			[
				'status'                   => 409,
				'missing_semantic_control' => 'post_type',
				'path'                     => 'settings.post_type',
				'expected'                 => 'a select or query control with post-type options',
				'accepted_aliases'         => self::CONTROL_ALIASES['post_type'],
			]
		);
	}

	/** @param array<string, mixed> $control */
	private static function is_confirmed_post_type_control( array $control ): bool {
		$type = strtolower( (string) ( $control['type'] ?? '' ) );
		if ( ! in_array( $type, self::POST_TYPE_CONTROL_TYPES, true ) ) {
			return false;
		}
		$options = $control['options'] ?? null;
		if ( ! is_array( $options ) || [] === $options ) {
			return false;
		}

		return true;
	}

	/**
	 * @param array<string, mixed> $control
	 */
	private static function assert_post_type_option( array $control, string $post_type, string $control_key, bool $pending_post_type = false ): ?\WP_Error {
		$options = array_map( 'strval', array_keys( (array) ( $control['options'] ?? [] ) ) );
		if ( in_array( $post_type, $options, true ) ) {
			return null;
		}
		if ( $pending_post_type && '' !== $post_type && ! in_array( $post_type, self::QUERY_SOURCE_SENTINELS, true ) ) {
			return null;
		}

		return self::error(
			'post_type_unsupported',
			sprintf( __( 'Post type %s is not an option on the live loop query control.', 'stonewright' ), $post_type ),
			[
				'status'            => 409,
				'post_type'         => $post_type,
				'control'           => $control_key,
				'path'              => 'settings.' . $control_key,
				'available_options' => array_values(
					array_filter(
						$options,
						static fn( string $option ): bool => ! in_array( $option, self::QUERY_SOURCE_SENTINELS, true )
					)
				),
			]
		);
	}

	/**
	 * @param array<string, mixed> $control
	 * @param list<string>         $scope
	 */
	private static function non_responsive_in_narrow_scope( array $control, string $control_key, array $scope ): ?\WP_Error {
		if ( [] === $scope ) {
			return null;
		}
		$global = array_intersect( $scope, [ 'desktop', 'base', 'widescreen' ] );
		if ( [] !== $global ) {
			return null;
		}
		if ( ResponsiveScope::control_is_responsive( $control, $control_key ) ) {
			return null;
		}

		return self::error(
			'non_responsive_control',
			sprintf(
				__( 'Control %s is not responsive; a mobile-only change is not representable on the live schema.', 'stonewright' ),
				$control_key
			),
			[
				'status'            => 409,
				'control'           => $control_key,
				'path'              => 'settings.' . $control_key,
				'responsive_scope'  => $scope,
				'plan_alternatives' => [
					[
						'id'      => 'apply_query_globally',
						'summary' => 'Apply the query value on the single loop widget for every breakpoint.',
					],
					[
						'id'      => 'explicit_two_loops',
						'summary' => 'If the user explicitly chooses two native loop widgets, keep the same query and template, unique IDs, and native visibility. This is not automatic.',
					],
				],
			]
		);
	}

	/**
	 * @param array<string, array<string, mixed>> $controls
	 * @return array{control:string,value:mixed}|\WP_Error
	 */
	private static function apply_conditional_control( array $controls, string $semantic, mixed $value, array $settings ): array|\WP_Error {
		$control_key = self::resolve( $controls, $semantic, true );
		if ( $control_key instanceof \WP_Error ) {
			return $control_key;
		}
		$control   = (array) $controls[ $control_key ];
		$condition = (array) ( $control['condition'] ?? [] );
		if ( [] !== $condition && ! self::condition_is_active( $condition, $settings, $controls ) ) {
			$named = implode( ', ', array_keys( $condition ) );
			return self::error(
				'inactive_condition',
				sprintf(
					__( 'Cannot write %1$s because its condition is inactive (%2$s).', 'stonewright' ),
					$control_key,
					$named
				),
				[
					'status'    => 409,
					'control'   => $control_key,
					'path'      => 'settings.' . $control_key,
					'condition' => $condition,
					'expected'  => 'the control condition/activator to be satisfied',
				]
			);
		}

		return [
			'control' => $control_key,
			'value'   => is_scalar( $value ) ? (string) $value : $value,
		];
	}

	/**
	 * @param array<string, mixed>                $condition
	 * @param array<string, mixed>                $settings
	 * @param array<string, array<string, mixed>> $controls
	 */
	private static function condition_is_active( array $condition, array $settings, array $controls ): bool {
		foreach ( $condition as $raw_key => $expected ) {
			if ( ! is_string( $raw_key ) ) {
				continue;
			}
			$negated = str_ends_with( $raw_key, '!' );
			$key     = $negated ? substr( $raw_key, 0, -1 ) : $raw_key;
			$actual  = $settings[ $key ] ?? ( $controls[ $key ]['default'] ?? null );
			$matches = is_array( $expected )
				? in_array( $actual, $expected, true )
				: $actual === $expected || ( is_scalar( $actual ) && is_scalar( $expected ) && (string) $actual === (string) $expected );
			if ( $negated ? $matches : ! $matches ) {
				return false;
			}
		}

		return true;
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
				'path'                     => 'settings.' . $semantic,
				'expected'                 => 'a live control compatible with ' . $semantic,
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
				'path'                     => 'settings.pagination',
				'expected'                 => 'a pagination option that maps from a boolean',
				'available_options'        => array_slice( $options, 0, 10 ),
			]
		);
	}

	/** @param array<string, mixed> $data */
	private static function error( string $code, string $message, array $data ): \WP_Error {
		return new \WP_Error( 'stonewright_loop_' . $code, $message, $data );
	}
}
