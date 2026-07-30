<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\Abilities\WooCommerce;

use Stonewright\WpMcp\Abilities\AbilityKernel;
use Stonewright\WpMcp\Security\Permissions;
use Stonewright\WpMcp\WooCommerce\Taxonomies;
use Stonewright\WpMcp\WooCommerce\WooRuntime;

/** @stonewright-status stable */
final class WcTermList extends AbilityKernel {

	public function name(): string {
		return 'stonewright/wc-term-list';
	}

	public function label(): string {
		return __( 'WooCommerce: Catalog terms', 'stonewright' );
	}

	public function description(): string {
		return __( 'Lists product categories, tags, shipping classes, or global attribute terms through native taxonomy APIs.', 'stonewright' );
	}

	public function category(): string {
		return 'woocommerce';
	}

	public function input_schema(): array {
		return [
			'type'                 => 'object',
			'additionalProperties' => false,
			'properties'           => [
				'taxonomy'   => [ 'type' => 'string', 'pattern' => '^(product_cat|product_tag|product_shipping_class|pa_[a-z0-9_-]+)$' ],
				'search'     => [ 'type' => 'string' ],
				'hide_empty' => [ 'type' => 'boolean', 'default' => false ],
				'per_page'   => [ 'type' => 'integer', 'minimum' => 1, 'maximum' => 100, 'default' => 50 ],
				'page'       => [ 'type' => 'integer', 'minimum' => 1, 'default' => 1 ],
			],
			'required'             => [ 'taxonomy' ],
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
			static function ( array $args ): array|\WP_Error {
				if ( ! WooRuntime::available() ) {
					return [ 'supported' => false, 'hint' => 'WooCommerce is not active on this site.' ];
				}
				if ( ! Taxonomies::allowed( (string) $args['taxonomy'] ) ) {
					return Taxonomies::error();
				}
				$per_page = min( 100, max( 1, (int) ( $args['per_page'] ?? 50 ) ) );
				$query    = [
					'taxonomy'   => (string) $args['taxonomy'],
					'hide_empty' => ! empty( $args['hide_empty'] ),
					'number'     => $per_page,
					'offset'     => ( max( 1, (int) ( $args['page'] ?? 1 ) ) - 1 ) * $per_page,
				];
				if ( isset( $args['search'] ) && '' !== trim( (string) $args['search'] ) ) {
					$query['search'] = sanitize_text_field( (string) $args['search'] );
				}
				$terms = get_terms( $query );
				if ( $terms instanceof \WP_Error ) {
					return $terms;
				}
				$items = [];
				foreach ( is_array( $terms ) ? $terms : [] as $term ) {
					if ( $term instanceof \WP_Term ) {
						$items[] = self::payload( $term );
					}
				}
				return [ 'supported' => true, 'items' => $items, 'count' => count( $items ) ];
			}
		);
	}

	/** @return array<string, mixed> */
	public static function payload( \WP_Term $term ): array {
		return [
			'id'          => (int) $term->term_id,
			'taxonomy'    => (string) $term->taxonomy,
			'name'        => (string) $term->name,
			'slug'        => (string) $term->slug,
			'description' => (string) $term->description,
			'parent'      => (int) $term->parent,
			'count'       => (int) $term->count,
		];
	}
}
