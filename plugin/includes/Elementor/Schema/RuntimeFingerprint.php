<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\Elementor\Schema;

/**
 * Builds a stable cache fingerprint for the live Elementor runtime.
 */
final class RuntimeFingerprint {

	private const COMPONENT_ALIASES = [
		'elementor' => 'elementor_core',
	];

	/** @param array<string, mixed> $constraints */
	public static function matches_constraints( array $constraints ): bool {
		$components = (array) ( self::describe()['components'] ?? [] );
		foreach ( $constraints as $component => $expression ) {
			if ( 'any_of' === (string) $component ) {
				if ( ! self::matches_any_of( $expression ) ) {
					return false;
				}
				continue;
			}
			if ( ! is_string( $expression ) && ! is_numeric( $expression ) ) {
				return false;
			}
			$expression = trim( (string) $expression );
			$key        = self::COMPONENT_ALIASES[ (string) $component ] ?? (string) $component;
			$version    = trim( (string) ( $components[ $key ] ?? '' ) );
			$expr       = strtolower( $expression );
			if ( '' === $expression || in_array( $expr, [ '*', 'optional' ], true ) ) {
				continue;
			}
			if ( 'required' === $expr ) {
				if ( '' === $version ) {
					return false;
				}
				continue;
			}
			if ( '' === $version || ! self::matches_expression( $version, $expression ) ) {
				return false;
			}
		}
		return true;
	}

	private static function matches_any_of( mixed $any_of ): bool {
		$clauses = self::normalize_any_of( $any_of );
		if ( [] === $clauses ) {
			return false;
		}

		foreach ( $clauses as $component => $expression ) {
			if ( self::matches_constraints( [ (string) $component => $expression ] ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * @return array<string, string>
	 */
	private static function normalize_any_of( mixed $any_of ): array {
		if ( is_string( $any_of ) ) {
			$trimmed = trim( $any_of );
			if ( str_starts_with( $trimmed, '{' ) || str_starts_with( $trimmed, '[' ) ) {
				$decoded = json_decode( $trimmed, true );
				if ( is_array( $decoded ) ) {
					return self::normalize_any_of( $decoded );
				}
			}

			$clauses = [];
			foreach ( preg_split( '/[|,]/', $trimmed ) ?: [] as $part ) {
				$part = trim( $part );
				if ( '' !== $part ) {
					$clauses[ $part ] = 'required';
				}
			}

			return $clauses;
		}

		if ( ! is_array( $any_of ) ) {
			return [];
		}

		$clauses = [];
		if ( array_is_list( $any_of ) ) {
			foreach ( $any_of as $component ) {
				if ( is_string( $component ) && '' !== trim( $component ) ) {
					$clauses[ trim( $component ) ] = 'required';
				}
			}

			return $clauses;
		}

		foreach ( $any_of as $component => $expression ) {
			if ( ! is_string( $component ) || '' === $component ) {
				continue;
			}
			if ( is_string( $expression ) || is_numeric( $expression ) ) {
				$clauses[ $component ] = (string) $expression;
				continue;
			}
			$clauses[ $component ] = 'required';
		}

		return $clauses;
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
		if ( class_exists( \Stonewright\WpMcp\Expertise\IntegrationCatalog::class ) ) {
			foreach ( \Stonewright\WpMcp\Expertise\IntegrationCatalog::inspect() as $row ) {
				$id = (string) ( $row['id'] ?? '' );
				if ( '' === $id || isset( $payload[ $id ] ) ) {
					continue;
				}
				$payload[ $id ] = (string) ( $row['version'] ?? '' );
			}
		}

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
