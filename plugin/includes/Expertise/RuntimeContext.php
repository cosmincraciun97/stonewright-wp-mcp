<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\Expertise;

use Stonewright\WpMcp\Core\AbilityRegistry;

/** Captures only version and capability facts used for pack compatibility. */
final class RuntimeContext {

	/** @return array<string, mixed> */
	public static function capture(): array {
		$versions = [
			'wordpress'      => function_exists( 'get_bloginfo' ) ? (string) get_bloginfo( 'version' ) : (string) ( $GLOBALS['wp_version'] ?? '0.0.0' ),
			'php'            => PHP_VERSION,
			'elementor_core' => defined( 'ELEMENTOR_VERSION' ) ? (string) ELEMENTOR_VERSION : '',
			'elementor_pro'  => defined( 'ELEMENTOR_PRO_VERSION' ) ? (string) ELEMENTOR_PRO_VERSION : '',
			'woocommerce'    => defined( 'WC_VERSION' ) ? (string) WC_VERSION : '',
			'acf'            => defined( 'ACF_VERSION' ) ? (string) ACF_VERSION : '',
		];
		$abilities = [];
		$disabled  = array_fill_keys( array_map( 'strval', (array) get_option( 'stonewright_disabled_abilities', [] ) ), true );
		foreach ( AbilityRegistry::list() as $class ) {
			if ( class_exists( $class ) ) {
				$name = ( new $class() )->name();
				if ( ! isset( $disabled[ $name ] ) ) {
					$abilities[] = $name;
				}
			}
		}
		$facts = [ 'versions' => $versions, 'capabilities' => array_values( array_unique( $abilities ) ) ];
		$facts['integrations'] = IntegrationCatalog::inspect();
		$facts['fingerprint'] = hash( 'sha256', wp_json_encode( $facts, JSON_UNESCAPED_SLASHES ) ?: '' );
		return $facts;
	}
}
