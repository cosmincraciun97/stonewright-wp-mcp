<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\Abilities\WooCommerce;

use Stonewright\WpMcp\Abilities\AbilityKernel;
use Stonewright\WpMcp\Security\Permissions;
use Stonewright\WpMcp\WooCommerce\WooRuntime;

/** @stonewright-status stable */
final class WcAttributeList extends AbilityKernel {

	public function name(): string {
		return 'stonewright/wc-attribute-list';
	}

	public function label(): string {
		return __( 'WooCommerce: Global attributes', 'stonewright' );
	}

	public function description(): string {
		return __( 'Lists WooCommerce global product attributes and optionally their terms.', 'stonewright' );
	}

	public function category(): string {
		return 'woocommerce';
	}

	public function input_schema(): array {
		return [
			'type'                 => 'object',
			'additionalProperties' => false,
			'properties'           => [
				'include_terms' => [ 'type' => 'boolean', 'default' => false ],
				'hide_empty'    => [ 'type' => 'boolean', 'default' => false ],
			],
		];
	}

	public function output_schema(): array {
		return [ 'type' => 'object', 'additionalProperties' => true ];
	}

	public function permission_callback( array $args ): bool|\WP_Error {
		return Permissions::manage_woocommerce();
	}

	public function execute( array $args ): array|\WP_Error {
		return $this->audit(
			$args,
			static function ( array $args ): array {
				if ( ! WooRuntime::available() || ! function_exists( 'wc_get_attribute_taxonomies' ) ) {
					return [ 'supported' => false, 'hint' => 'WooCommerce global attribute APIs are unavailable.' ];
				}
				$items = [];
				foreach ( (array) WooRuntime::get_attribute_taxonomies() as $attribute ) {
					if ( ! is_object( $attribute ) ) {
						continue;
					}
					$id       = (int) ( $attribute->attribute_id ?? 0 );
					$taxonomy = function_exists( 'wc_attribute_taxonomy_name' )
						? wc_attribute_taxonomy_name( (string) ( $attribute->attribute_name ?? '' ) )
						: 'pa_' . sanitize_key( (string) ( $attribute->attribute_name ?? '' ) );
					$item     = [
						'id'        => $id,
						'name'      => (string) ( $attribute->attribute_label ?? '' ),
						'slug'      => (string) ( $attribute->attribute_name ?? '' ),
						'taxonomy'  => $taxonomy,
						'type'      => (string) ( $attribute->attribute_type ?? 'select' ),
						'order_by'  => (string) ( $attribute->attribute_orderby ?? 'menu_order' ),
						'has_archives' => ! empty( $attribute->attribute_public ),
					];
					if ( ! empty( $args['include_terms'] ) ) {
						$terms = get_terms(
							[
								'taxonomy'   => $taxonomy,
								'hide_empty' => ! empty( $args['hide_empty'] ),
							]
						);
						$item['terms'] = $terms instanceof \WP_Error
							? []
							: array_map(
								static fn( \WP_Term $term ): array => [
									'id'    => (int) $term->term_id,
									'name'  => (string) $term->name,
									'slug'  => (string) $term->slug,
									'count' => (int) $term->count,
								],
								array_values(
									array_filter(
										is_array( $terms ) ? $terms : [],
										static fn( mixed $term ): bool => $term instanceof \WP_Term
									)
								)
							);
					}
					$items[] = $item;
				}
				return [ 'supported' => true, 'items' => $items, 'count' => count( $items ) ];
			}
		);
	}
}
