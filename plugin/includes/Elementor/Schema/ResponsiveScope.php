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

	public static function base_key( string $key ): string {
		return (string) preg_replace(
			'/_(widescreen|laptop|tablet_extra|tablet|mobile_extra|mobile)$/',
			'',
			$key
		);
	}

	public static function key_breakpoint( string $key ): string {
		foreach ( [
			'_widescreen'   => 'widescreen',
			'_laptop'       => 'laptop',
			'_tablet_extra' => 'tablet_extra',
			'_tablet'       => 'tablet',
			'_mobile_extra' => 'mobile_extra',
			'_mobile'       => 'mobile',
		] as $suffix => $name ) {
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

		$suffix_map = self::breakpoint_suffixes();
		foreach ( $settings as $key => $value ) {
			$key = (string) $key;
			if ( in_array( $key, [ '__dynamic__', '__globals__' ], true ) ) {
				continue;
			}
			$bp = self::key_breakpoint( $key );
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
					]
				);
			}

			// Non-responsive control written with a breakpoint suffix or as base when only mobile allowed.
			$base = self::base_key( $key );
			if ( [] !== $controls && isset( $controls[ $base ] ) ) {
				$control = (array) $controls[ $base ];
				$is_resp = ! empty( $control['responsive'] );
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
			if ( ! in_array( $bp, $allowed, true ) ) {
				$kept[ $key ] = $value;
			}
		}
		ksort( $kept );
		return hash( 'sha256', (string) wp_json_encode( $kept ) );
	}
}
