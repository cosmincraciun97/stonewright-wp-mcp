<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\Elementor\Schema;

/**
 * Breakpoint isolation for design-derived Elementor mutations.
 */
final class ResponsiveScope {

	/** Default Elementor responsive suffixes (active set discovered at runtime may differ). */
	public const DEFAULT_SUFFIXES = [
		'',
		'_widescreen',
		'_laptop',
		'_tablet_extra',
		'_tablet',
		'_mobile_extra',
		'_mobile',
	];

	/**
	 * Map canonical breakpoint names to setting key suffixes.
	 *
	 * @return array<string, string>
	 */
	public static function breakpoint_suffixes(): array {
		return [
			'desktop'      => '',
			'widescreen'   => '_widescreen',
			'laptop'       => '_laptop',
			'tablet_extra' => '_tablet_extra',
			'tablet'       => '_tablet',
			'mobile_extra' => '_mobile_extra',
			'mobile'       => '_mobile',
			'base'         => '',
		];
	}

	/**
	 * Parse a batch or operation responsive_scope / allowed_breakpoints value.
	 *
	 * @param mixed $scope Array of names or a comma/pipe-separated string.
	 * @return list<string>
	 */
	public static function requested_names( mixed $scope ): array {
		$raw = [];
		if ( is_array( $scope ) ) {
			$raw = $scope;
		} elseif ( is_string( $scope ) && '' !== trim( $scope ) ) {
			$parts = preg_split( '/[\s,|]+/', $scope, -1, PREG_SPLIT_NO_EMPTY );
			$raw   = is_array( $parts ) ? $parts : [];
		}
		$out = [];
		foreach ( $raw as $item ) {
			if ( ! is_scalar( $item ) ) {
				continue;
			}
			$name = strtolower( trim( (string) $item ) );
			if ( '' === $name ) {
				continue;
			}
			$out[] = $name;
		}

		return array_values( array_unique( $out ) );
	}

	/**
	 * Suffix to breakpoint name, longest suffix first.
	 *
	 * Derived from breakpoint_suffixes() so the suffix vocabulary has exactly one
	 * definition: `_tablet_extra` must be tested before `_tablet`, or the extra
	 * breakpoint collapses into the base one.
	 *
	 * @return array<string, string>
	 */
	private static function suffix_breakpoints(): array {
		$map = [];
		foreach ( self::breakpoint_suffixes() as $name => $suffix ) {
			if ( '' === $suffix || isset( $map[ $suffix ] ) ) {
				continue;
			}
			$map[ $suffix ] = $name;
		}
		uksort( $map, static fn( string $a, string $b ): int => strlen( $b ) <=> strlen( $a ) );
		return $map;
	}

	/**
	 * Elementor's standalone visibility switchers.
	 *
	 * These are separate controls named after a breakpoint, not responsive
	 * variants of a `hide` control, so they carry no breakpoint scope of their
	 * own. Only the exact generated names count: `hide_title` is an ordinary
	 * control that happens to start with the same prefix.
	 *
	 * @return list<string>
	 */
	public static function visibility_controls(): array {
		$names = [];
		foreach ( array_keys( self::breakpoint_suffixes() ) as $breakpoint ) {
			if ( 'base' === $breakpoint ) {
				continue;
			}
			$names[] = 'hide_' . $breakpoint;
		}
		return $names;
	}

	public static function is_visibility_control( string $key ): bool {
		return in_array( $key, self::visibility_controls(), true );
	}

	/**
	 * Exact native switcher return values for Elementor's primary devices.
	 *
	 * @return array<string, string>
	 */
	public static function native_visibility_values(): array {
		return [
			'hide_desktop' => 'hidden-desktop',
			'hide_laptop'  => 'hidden-laptop',
			'hide_tablet'  => 'hidden-tablet',
			'hide_mobile'  => 'hidden-mobile',
		];
	}

	public static function expected_visibility_value( string $key ): ?string {
		return self::native_visibility_values()[ $key ] ?? null;
	}

	public static function visibility_value_is_valid( string $key, mixed $value ): bool {
		$expected = self::expected_visibility_value( $key );
		return null === $expected || '' === $value || $expected === $value;
	}

	/**
	 * Decide whether a control accepts breakpoint-suffixed keys.
	 *
	 * Elementor's add_responsive_control() records the breakpoint bounds as an
	 * array, and an unbounded control gets an empty array. A boolean cast of that
	 * empty array reports the most common responsive control as fixed, which is
	 * why this reads presence and shape instead of truthiness.
	 *
	 * @param array<string, mixed> $control Raw or normalized control array.
	 * @param string               $name    Control name, when not carried in the array.
	 */
	public static function control_is_responsive( array $control, string $name = '' ): bool {
		foreach ( [ 'responsive', 'is_responsive' ] as $flag ) {
			if ( ! array_key_exists( $flag, $control ) ) {
				continue;
			}
			$value = $control[ $flag ];
			if ( is_array( $value ) ) {
				return true;
			}
			if ( ! is_bool( $value ) && ! is_scalar( $value ) ) {
				continue;
			}
			if ( $value ) {
				return true;
			}
			// An explicit falsy flag is the runtime's own answer; the convention
			// allowlist must not override it.
			return false;
		}

		$name = '' !== $name ? $name : (string) ( $control['key'] ?? '' );
		return self::responsive_by_convention( $name );
	}

	/**
	 * Controls Elementor makes responsive without recording the metadata.
	 *
	 * Shared by the widget and container schema repositories so a control cannot
	 * be responsive in one schema and fixed in the other.
	 *
	 * @return list<string>
	 */
	public static function convention_responsive_controls(): array {
		return [
			'width',
			'boxed_width',
			'height',
			'min_height',
			'flex_direction',
			'flex_justify_content',
			'flex_align_items',
			'flex_align_content',
			'flex_gap',
			'flex_wrap',
			'grid_columns_grid',
			'padding',
			'margin',
			'_margin',
			'border_width',
			'border_radius',
			'z_index',
		];
	}

	public static function responsive_by_convention( string $name ): bool {
		return '' !== $name && in_array( $name, self::convention_responsive_controls(), true );
	}

	public static function base_key( string $key ): string {
		if ( self::is_visibility_control( $key ) ) {
			return $key;
		}
		foreach ( array_keys( self::suffix_breakpoints() ) as $suffix ) {
			if ( str_ends_with( $key, $suffix ) ) {
				return substr( $key, 0, -strlen( $suffix ) );
			}
		}
		return $key;
	}

	/**
	 * Breakpoint a setting key targets, or null when it targets none.
	 *
	 * Null means the key is a standalone control whose name only looks like a
	 * breakpoint variant, so callers must skip it instead of inventing a scope.
	 */
	public static function key_breakpoint( string $key ): ?string {
		if ( self::is_visibility_control( $key ) ) {
			return null;
		}
		foreach ( self::suffix_breakpoints() as $suffix => $name ) {
			if ( str_ends_with( $key, $suffix ) ) {
				return $name;
			}
		}
		return 'desktop';
	}

	/**
	 * @param array<string, mixed> $settings
	 * @param list<string>         $allowed_breakpoints e.g. ['mobile']
	 * @param array<string, mixed> $controls Live widget controls keyed by name.
	 * @return bool|\WP_Error True when valid.
	 */
	public static function assert_settings_in_scope( array $settings, array $allowed_breakpoints, array $controls = [], string $widget_type = '' ): bool|\WP_Error {
		foreach ( $settings as $key => $value ) {
			$key      = (string) $key;
			$expected = self::expected_visibility_value( $key );
			if ( null === $expected || self::visibility_value_is_valid( $key, $value ) ) {
				continue;
			}
			return new \WP_Error(
				'stonewright_elementor_settings_invalid',
				sprintf(
					/* translators: 1: setting key, 2: exact native value */
					__( 'Elementor visibility setting %1$s must be empty or use its native value %2$s.', 'stonewright' ),
					$key,
					$expected
				),
				[
					'status'      => 400,
					'widget_type' => $widget_type,
					'violations'  => [
						[
							'path'        => 'settings.' . $key,
							'code'        => 'invalid_responsive_visibility_value',
							'expected'    => 'an empty value or ' . $expected,
							'got_type'    => get_debug_type( $value ),
							'suggestions' => [ '', $expected ],
						],
					],
					'retryable'   => true,
					'repair'      => 'Use the native Elementor Responsive switcher value for this device, then read back settings and verify frontend classes.',
				]
			);
		}

		$allowed = array_values(
			array_unique(
				array_map(
					static fn( string $b ): string => strtolower( trim( $b ) ),
					$allowed_breakpoints
				)
			)
		);
		if ( [] === $allowed ) {
			return true;
		}

		foreach ( $settings as $key => $value ) {
			$key = (string) $key;
			if ( in_array( $key, [ '__dynamic__', '__globals__' ], true ) ) {
				continue;
			}
			$bp = self::key_breakpoint( $key );
			if ( null === $bp ) {
				// Standalone visibility switcher: it has no breakpoint variants, so
				// no breakpoint scope can contain or exclude it.
				continue;
			}
			if ( ! in_array( $bp, $allowed, true ) ) {
				return new \WP_Error(
					'stonewright_responsive_scope_violation',
					sprintf(
						/* translators: 1: setting key, 2: breakpoint, 3: allowed list */
						__( 'Setting %1$s targets breakpoint %2$s which is outside allowed scope (%3$s).', 'stonewright' ),
						$key,
						$bp,
						implode( ', ', $allowed )
					),
					[
						'status'               => 400,
						'setting'              => $key,
						'breakpoint'           => $bp,
						'allowed_breakpoints'  => $allowed,
						'widget_type'          => $widget_type,
						'execution_status'     => 'blocked',
					]
				);
			}

			// Non-responsive control written with a breakpoint suffix or as base when only mobile allowed.
			$base = self::base_key( $key );
			if ( [] !== $controls && isset( $controls[ $base ] ) ) {
				$control = (array) $controls[ $base ];
				$is_resp = self::control_is_responsive( $control, $base );
				if ( ! $is_resp && $key !== $base ) {
					return new \WP_Error(
						'unsupported_responsive_control',
						sprintf(
							/* translators: 1: widget, 2: control, 3: breakpoint */
							__( 'Control %2$s on widget %1$s is not responsive; cannot isolate breakpoint %3$s. No write performed.', 'stonewright' ),
							$widget_type,
							$base,
							$bp
						),
						[
							'status'      => 400,
							'widget_type' => $widget_type,
							'control'     => $base,
							'breakpoint'  => $bp,
							'code'        => 'unsupported_responsive_control',
						]
					);
				}
				// Mobile-only task must not write bare base keys unless desktop is allowed.
				if ( $key === $base && ! in_array( 'desktop', $allowed, true ) && ! in_array( 'base', $allowed, true ) && $is_resp ) {
					return new \WP_Error(
						'stonewright_responsive_scope_violation',
						sprintf(
							/* translators: 1: setting key, 2: allowed list */
							__( 'Base setting %1$s is outside allowed responsive scope (%2$s). Use the breakpoint-suffixed key.', 'stonewright' ),
							$key,
							implode( ', ', $allowed )
						),
						[
							'status'              => 400,
							'setting'             => $key,
							'allowed_breakpoints' => $allowed,
							'widget_type'         => $widget_type,
						]
					);
				}
			}
		}

		return true;
	}

	/**
	 * Hash all setting keys that are outside the allowed breakpoint scope.
	 *
	 * @param array<string, mixed> $settings Full element settings.
	 * @param list<string>         $allowed_breakpoints
	 */
	public static function hash_non_target_breakpoints( array $settings, array $allowed_breakpoints ): string {
		$allowed = array_map( 'strtolower', $allowed_breakpoints );
		$kept    = [];
		foreach ( $settings as $key => $value ) {
			$key = (string) $key;
			if ( in_array( $key, [ '__dynamic__', '__globals__' ], true ) ) {
				continue;
			}
			$bp = self::key_breakpoint( $key );
			if ( null === $bp ) {
				// Visibility switchers belong to no breakpoint, so hashing them would
				// report an out-of-scope change for an in-scope write.
				continue;
			}
			if ( ! in_array( $bp, $allowed, true ) ) {
				$kept[ $key ] = $value;
			}
		}
		ksort( $kept );
		return hash( 'sha256', (string) wp_json_encode( $kept ) );
	}
}
