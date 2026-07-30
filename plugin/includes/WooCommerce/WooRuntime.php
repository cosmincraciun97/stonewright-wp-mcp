<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\WooCommerce;

/**
 * Narrow seam around WooCommerce globals and product constructors.
 *
 * WooCommerce remains optional, so no Woo class may be referenced before the
 * plugin is active. Test overrides keep catalog behavior unit-testable without
 * shipping a second copy of WooCommerce.
 */
final class WooRuntime {

	/** @var array<string, callable> */
	private static array $test_overrides = [];

	public static function available(): bool {
		if ( isset( self::$test_overrides['available'] ) ) {
			return (bool) ( self::$test_overrides['available'] )();
		}
		return function_exists( 'wc_get_product' ) && function_exists( 'wc_get_products' );
	}

	public static function version(): string {
		return defined( 'WC_VERSION' ) ? sanitize_text_field( (string) constant( 'WC_VERSION' ) ) : '';
	}

	public static function get_product( int $product_id ): mixed {
		return self::invoke( 'get_product', 'wc_get_product', [ $product_id ] );
	}

	/** @param array<string, mixed> $query */
	public static function get_products( array $query ): mixed {
		return self::invoke( 'get_products', 'wc_get_products', [ $query ] );
	}

	/** @param array<string, mixed> $query */
	public static function get_orders( array $query ): mixed {
		return self::invoke( 'get_orders', 'wc_get_orders', [ $query ] );
	}

	public static function get_attribute_taxonomies(): mixed {
		return self::invoke( 'get_attribute_taxonomies', 'wc_get_attribute_taxonomies', [] );
	}

	public static function new_product( string $type ): object {
		if ( isset( self::$test_overrides['new_product'] ) ) {
			$product = ( self::$test_overrides['new_product'] )( $type );
			return is_object( $product )
				? $product
				: new \WP_Error( 'stonewright_wc_product_create_failed', 'WooCommerce did not create a product object.' );
		}

		$classes = [
			'simple'   => '\WC_Product_Simple',
			'variable' => '\WC_Product_Variable',
			'grouped'  => '\WC_Product_Grouped',
			'external' => '\WC_Product_External',
		];
		$class   = $classes[ $type ] ?? '';
		if ( '' === $class || ! class_exists( $class ) ) {
			return new \WP_Error(
				'stonewright_wc_product_type_unsupported',
				'WooCommerce does not expose the requested product type.',
				[ 'status' => 400 ]
			);
		}
		return new $class();
	}

	public static function new_variation(): object {
		if ( isset( self::$test_overrides['new_variation'] ) ) {
			$variation = ( self::$test_overrides['new_variation'] )();
			return is_object( $variation )
				? $variation
				: new \WP_Error( 'stonewright_wc_variation_create_failed', 'WooCommerce did not create a variation object.' );
		}
		if ( ! class_exists( '\WC_Product_Variation' ) ) {
			return new \WP_Error(
				'stonewright_wc_variation_unsupported',
				'WooCommerce variation APIs are unavailable.',
				[ 'status' => 400 ]
			);
		}
		return new \WC_Product_Variation();
	}

	/** @param array<string, callable> $overrides */
	public static function set_test_overrides( array $overrides ): void {
		self::$test_overrides = $overrides;
	}

	public static function reset_test_overrides(): void {
		self::$test_overrides = [];
	}

	/** @param list<mixed> $arguments */
	private static function invoke( string $override, string $function, array $arguments ): mixed {
		if ( isset( self::$test_overrides[ $override ] ) ) {
			return ( self::$test_overrides[ $override ] )( ...$arguments );
		}
		if ( ! function_exists( $function ) ) {
			return null;
		}
		return $function( ...$arguments );
	}
}
