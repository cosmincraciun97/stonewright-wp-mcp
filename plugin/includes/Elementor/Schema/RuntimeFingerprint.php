<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\Elementor\Schema;

/**
 * Builds a stable cache fingerprint for the live Elementor runtime.
 */
final class RuntimeFingerprint {

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
}
