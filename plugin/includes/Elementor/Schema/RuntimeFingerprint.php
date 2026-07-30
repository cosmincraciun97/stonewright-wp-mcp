<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\Elementor\Schema;

/**
 * Builds a stable cache fingerprint for the live Elementor runtime.
 */
final class RuntimeFingerprint {

	/** @param array<string, mixed> $constraints */
	public static function matches_constraints( array $constraints ): bool {
		$components = (array) ( self::describe()['components'] ?? [] );
		foreach ( $constraints as $component => $expression ) {
			$expression = trim( (string) $expression );
			if ( '' === $expression || in_array( strtolower( $expression ), [ '*', 'optional' ], true ) ) {
				continue;
			}
			$version = trim( (string) ( $components[ (string) $component ] ?? '' ) );
			if ( '' === $version || ! self::matches_expression( $version, $expression ) ) {
				return false;
			}
		}
		return true;
	}

	/**
	 * @return array<string, mixed>
	 */
	public static function describe(): array {
		$plugins = [];
		$active  = array_values( array_filter( (array) get_option( 'active_plugins', [] ), 'is_string' ) );
		if ( function_exists( 'get_site_option' ) ) {
			$active = array_values( array_unique( array_merge( $active, array_keys( (array) get_site_option( 'active_sitewide_plugins', [] ) ) ) ) );
		}
		if ( function_exists( 'get_plugins' ) ) {
			foreach ( get_plugins() as $file => $metadata ) {
				if ( ! in_array( $file, $active, true ) ) {
					continue;
				}
				$plugins[ (string) $file ] = (string) ( $metadata['Version'] ?? '' );
			}
		}
		ksort( $plugins );

		$features = [];
		foreach ( [ 'elementor_experiment-e_atomic_elements', 'elementor_experiment-container', 'elementor_experiment-nested-elements' ] as $option ) {
			$features[ $option ] = get_option( $option, null );
		}

		$payload = [
			'wordpress'      => defined( 'WP_VERSION' ) ? (string) constant( 'WP_VERSION' ) : (string) get_bloginfo( 'version' ),
			'elementor_core' => defined( 'ELEMENTOR_VERSION' ) ? (string) constant( 'ELEMENTOR_VERSION' ) : '',
			'elementor_pro'  => defined( 'ELEMENTOR_PRO_VERSION' ) ? (string) constant( 'ELEMENTOR_PRO_VERSION' ) : '',
			'locale'         => function_exists( 'determine_locale' ) ? determine_locale() : get_locale(),
			'plugins'        => $plugins,
			'features'       => $features,
		];

		return [
			'hash'       => hash( 'sha256', (string) wp_json_encode( $payload ) ),
			'components' => $payload,
		];
	}

	private static function matches_expression( string $version, string $expression ): bool {
		foreach ( preg_split( '/\s+/', trim( $expression ) ) ?: [] as $clause ) {
			if ( '' === $clause ) {
				continue;
			}
			if ( str_ends_with( $clause, '.*' ) ) {
				if ( ! str_starts_with( $version . '.', substr( $clause, 0, -1 ) ) ) {
					return false;
				}
				continue;
			}
			if ( ! preg_match( '/^(>=|<=|>|<|=)?(.+)$/', $clause, $matches ) ) {
				return false;
			}
			$operator = '' === (string) $matches[1] ? '=' : (string) $matches[1];
			$required = trim( (string) $matches[2] );
			if ( '' === $required || ! version_compare( $version, $required, $operator ) ) {
				return false;
			}
		}
		return true;
	}
}
