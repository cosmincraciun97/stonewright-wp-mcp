<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\WooCommerce;

/** Allowlist for WooCommerce-owned catalog taxonomies. */
final class Taxonomies {

	public static function allowed( string $taxonomy ): bool {
		if ( in_array( $taxonomy, [ 'product_cat', 'product_tag', 'product_shipping_class' ], true ) ) {
			return taxonomy_exists( $taxonomy );
		}
		if ( ! str_starts_with( $taxonomy, 'pa_' ) || ! taxonomy_exists( $taxonomy ) ) {
			return false;
		}
		if ( ! function_exists( 'wc_attribute_taxonomy_id_by_name' ) ) {
			return false;
		}
		return (int) wc_attribute_taxonomy_id_by_name( $taxonomy ) > 0;
	}

	public static function error(): \WP_Error {
		return new \WP_Error(
			'stonewright_wc_taxonomy_invalid',
			'Use a registered WooCommerce product category, tag, shipping class, or global attribute taxonomy.',
			[ 'status' => 400 ]
		);
	}
}
