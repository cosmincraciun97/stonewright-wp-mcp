<?php
/**
 * The WooCommerce family in the change ledger: products, variations, catalog terms and global attributes.
 * SPDX-License-Identifier: GPL-2.0-or-later
 *
 * @package Stonewright
 */

declare( strict_types=1 );

namespace Stonewright\WpMcp\Security\Adapters;

use Stonewright\WpMcp\Security\Permissions;
use Stonewright\WpMcp\WooCommerce\Catalog;
use Stonewright\WpMcp\WooCommerce\WooRuntime;

/**
 * Everything goes through the WooCommerce objects and functions the abilities use: the product and
 * variation objects through WooRuntime and Catalog (the same field mapping, setters and getters), terms
 * through the term functions, global attributes through wc_create_attribute(), wc_update_attribute() and
 * wc_delete_attribute().
 *
 * The image of a product or variation is the catalog payload of its fields (name, slug, status, sku,
 * prices, stock, tax, size, text, images, categories, tags, attributes and the like), without the values
 * WooCommerce computes (the price, the permalink, the modified date). The image of a term is its name,
 * slug, description and parent; of an attribute its label, slug, type, sort order and archives flag.
 *
 * A delete is a full image. A product moved to the trash is a status change that a restore reverses. A
 * product that was deleted for good is created again with a new id; its variations, its orders and its
 * relations are not restored, and the row says so. The same holds for a term (the products lose the term)
 * and for a global attribute (its terms are gone). The undo of a created product moves it to the trash; the
 * undo of a created term or attribute deletes it only while nothing uses it.
 */
final class WooCommerceAdapter extends FamilyAdapter {

	public const FAMILY = 'woocommerce';

	public const RECIPE = 'woocommerce';

	protected const ABILITIES = [
		'stonewright/wc-product-save',
		'stonewright/wc-product-delete',
		'stonewright/wc-variation-save',
		'stonewright/wc-variation-delete',
		'stonewright/wc-term-save',
		'stonewright/wc-term-delete',
		'stonewright/wc-attribute-save',
		'stonewright/wc-attribute-delete',
	];

	/** Values WooCommerce computes: not part of an image. */
	private const COMPUTED = [ 'id', 'price', 'permalink', 'date_modified' ];

	/** What a restore never writes back to a product. */
	private const NOT_RESTORED = [ 'id', 'type', 'price', 'permalink', 'date_modified', 'parent_id', 'children', 'external_url', 'button_text', 'attributes', 'default_attributes' ];

	/** What a variation has no setter for. */
	private const NOT_ON_VARIATIONS = [ 'description', 'short_description', 'sold_individually', 'default_attributes' ];

	private const ATTRIBUTE_FIELDS = [ 'name', 'slug', 'type', 'order_by', 'has_archives' ];

	public static function types(): array {
		return [ 'wc_product', 'wc_variation', 'wc_term', 'wc_attribute' ];
	}

	public static function ledger_family( string $type ): string {
		return self::FAMILY;
	}

	public static function image( string $type, string $id ): ?array {
		return match ( $type ) {
			'wc_product', 'wc_variation' => self::product_image( $id, 'wc_variation' === $type ),
			'wc_term'                    => self::term_image( $id ),
			'wc_attribute'               => self::attribute_image( $id ),
			default                      => null,
		};
	}

	public static function watch( string $ability, array $args ): array {
		$kind = self::kind_of( $ability );
		if ( null === $kind ) {
			return [];
		}
		$id = isset( $args['id'] ) && is_numeric( $args['id'] ) ? (int) $args['id'] : 0;
		if ( $id < 1 ) {
			return [];
		}
		$key = (string) $id;
		if ( 'wc_term' === $kind ) {
			$taxonomy = isset( $args['taxonomy'] ) && is_string( $args['taxonomy'] ) ? $args['taxonomy'] : '';
			if ( ! self::allowed_taxonomy( $taxonomy ) ) {
				return [];
			}
			$key = $taxonomy . ':' . $id;
		}
		return [ [ 'type' => $kind, 'id' => $key, 'before' => self::image( $kind, $key ) ] ];
	}

	public static function context( string $ability, array $args ): array {
		return [
			'taxonomy' => isset( $args['taxonomy'] ) && is_string( $args['taxonomy'] ) && self::allowed_taxonomy( $args['taxonomy'] ) ? $args['taxonomy'] : '',
			'existing' => isset( $args['id'] ) && is_numeric( $args['id'] ) && (int) $args['id'] > 0,
		];
	}

	public static function discover( string $ability, array $context, mixed $result ): array {
		$kind = self::kind_of( $ability );
		if ( null === $kind || ! str_ends_with( $ability, '-save' ) || true === ( $context['existing'] ?? false ) || ! is_array( $result ) || true === ( $result['dry_run'] ?? false ) ) {
			return [];
		}
		$row = $result[ [ 'wc_product' => 'product', 'wc_variation' => 'variation', 'wc_term' => 'term', 'wc_attribute' => 'attribute' ][ $kind ] ] ?? null;
		$id  = is_array( $row ) && isset( $row['id'] ) && is_numeric( $row['id'] ) ? (int) $row['id'] : 0;
		if ( $id < 1 ) {
			return [];
		}
		if ( 'wc_term' === $kind ) {
			$taxonomy = (string) ( $context['taxonomy'] ?? '' );
			return '' === $taxonomy ? [] : [ [ 'type' => $kind, 'id' => $taxonomy . ':' . $id ] ];
		}
		return [ [ 'type' => $kind, 'id' => (string) $id ] ];
	}

	public static function limits( string $ability, string $type, ?array $before, ?array $after ): array {
		if ( null === $before || null !== $after ) {
			return [];
		}
		return match ( $type ) {
			'wc_product'   => 'variable' === ( $before['type'] ?? '' ) ? [ 'new id', 'no variations' ] : [ 'new id' ],
			'wc_term'      => [ 'new id', 'products lose the term' ],
			'wc_attribute' => [ 'new id', 'no terms' ],
			default        => [ 'new id' ],
		};
	}

	protected static function subject( string $type, ?array $image, string $id ): string {
		$name = is_array( $image['fields'] ?? null ) ? (string) ( $image['fields']['name'] ?? '' ) : '';
		$noun = match ( $type ) {
			'wc_variation' => 'variation',
			'wc_term'      => 'catalog term',
			'wc_attribute' => 'attribute',
			default        => 'product',
		};
		return $noun . ' ' . ( '' === $name ? '#' . $id : FamilyLedger::clip( $name, 40 ) );
	}

	protected static function permitted( string $type, string $operation ): bool {
		return Permissions::manage_woocommerce();
	}

	protected static function removed( string $type, ?array $live ): bool {
		return null === $live || ( in_array( $type, [ 'wc_product', 'wc_variation' ], true ) && 'trash' === ( $live['fields']['status'] ?? '' ) );
	}

	protected static function write( string $type, string $id, ?array $target, array $row, array $options, ?array $live ): array {
		return match ( $type ) {
			'wc_product', 'wc_variation' => self::write_product( $id, 'wc_variation' === $type, $target, $live ),
			'wc_term'                    => self::write_term( $id, $target, $live ),
			'wc_attribute'               => self::write_attribute( $id, $target, $live ),
			default                      => self::refused( 'not_restorable' ),
		};
	}

	// -----------------------------------------------------------------------
	// Products and variations.
	// -----------------------------------------------------------------------

	private static function kind_of( string $ability ): ?string {
		return match ( true ) {
			str_starts_with( $ability, 'stonewright/wc-product-' )   => 'wc_product',
			str_starts_with( $ability, 'stonewright/wc-variation-' ) => 'wc_variation',
			str_starts_with( $ability, 'stonewright/wc-term-' )      => 'wc_term',
			str_starts_with( $ability, 'stonewright/wc-attribute-' ) => 'wc_attribute',
			default                                                  => null,
		};
	}

	/**
	 * @return array<string, mixed>|null
	 */
	private static function product_image( string $id, bool $variation ): ?array {
		if ( 1 !== preg_match( '/^[1-9][0-9]{0,18}$/D', $id ) || ! WooRuntime::available() ) {
			return null;
		}
		$product = WooRuntime::get_product( (int) $id );
		if ( ! is_object( $product ) ) {
			return null;
		}
		$payload = Catalog::product_payload( $product );
		$type    = (string) ( $payload['type'] ?? '' );
		foreach ( self::COMPUTED as $key ) {
			unset( $payload[ $key ] );
		}
		ksort( $payload, SORT_STRING );
		return [
			'fields' => $payload,
			'kind'   => $variation ? 'variation' : 'product',
			'type'   => $type,
			'v'      => self::IMAGE_VERSION,
		];
	}

	/**
	 * @param array<string, mixed>|null $target
	 * @param array<string, mixed>|null $live
	 * @return array{ok:bool,applied:bool,detail:string,id:string,limits:list<string>}
	 */
	private static function write_product( string $id, bool $variation, ?array $target, ?array $live ): array {
		if ( ! WooRuntime::available() ) {
			return self::refused( 'woocommerce_unavailable' );
		}
		if ( null === $target ) {
			// The undo of a creation: the product goes to the trash and is never deleted.
			$product = WooRuntime::get_product( (int) $id );
			if ( ! is_object( $product ) || ! method_exists( $product, 'delete' ) ) {
				return self::refused( 'product_missing' );
			}
			$product->delete( false );
			$after = self::product_image( $id, $variation );
			return self::applied( null !== $after && 'trash' === ( $after['fields']['status'] ?? '' ), 'trashed' );
		}

		$fields = is_array( $target['fields'] ?? null ) ? $target['fields'] : [];
		if ( null === $live ) {
			$product = $variation ? WooRuntime::new_variation() : WooRuntime::new_product( (string) ( $target['type'] ?? 'simple' ) );
			if ( $product instanceof \WP_Error ) {
				return self::refused( self::short_code( $product ) );
			}
		} else {
			$product = WooRuntime::get_product( (int) $id );
		}
		if ( ! is_object( $product ) ) {
			return self::refused( 'product_missing' );
		}
		if ( $variation && method_exists( $product, 'set_parent_id' ) ) {
			$product->set_parent_id( (int) ( $fields['parent_id'] ?? 0 ) );
		}

		$input   = self::restore_input( $fields, (string) ( $target['type'] ?? '' ), $variation, null === $live ? [] : (array) ( $live['fields'] ?? [] ) );
		$problem = Catalog::apply_product_input( $product, $input, $variation );
		if ( null !== $problem ) {
			return self::refused( self::short_code( $problem ) );
		}
		if ( ! method_exists( $product, 'save' ) ) {
			return self::refused( 'save_unavailable' );
		}
		try {
			$saved = (int) $product->save();
		} catch ( \Throwable ) {
			return self::refused( 'save_failed' );
		}
		if ( $saved < 1 ) {
			return self::refused( 'save_failed' );
		}
		$new   = (string) $saved;
		$after = self::product_image( $new, $variation );
		$wrong = null === $after ? [ 'resource' ] : array_keys( array_filter( $input, static fn ( mixed $value, string $key ): bool => self::canonical( $value ) !== self::canonical( $after['fields'][ $key ] ?? null ), ARRAY_FILTER_USE_BOTH ) );
		return self::applied( [] === $wrong, [] === $wrong ? ( null === $live ? 'recreated' : 'restored' ) : 'differences:' . implode( ',', array_slice( $wrong, 0, 5 ) ), $new );
	}

	/**
	 * The fields a restore hands to the catalog mapping. The attributes of a product are written only when
	 * there is something to write or something to clear, so that a site without attribute objects can still
	 * restore a plain product.
	 *
	 * @param array<string, mixed> $fields
	 * @param array<string, mixed> $live_fields
	 * @return array<string, mixed>
	 */
	private static function restore_input( array $fields, string $type, bool $variation, array $live_fields ): array {
		$input = [];
		foreach ( $fields as $key => $value ) {
			if ( in_array( $key, self::NOT_RESTORED, true ) || ( $variation && in_array( $key, self::NOT_ON_VARIATIONS, true ) ) ) {
				continue;
			}
			$input[ $key ] = $value;
		}
		if ( 'external' === $type ) {
			foreach ( [ 'external_url', 'button_text' ] as $key ) {
				if ( array_key_exists( $key, $fields ) ) {
					$input[ $key ] = $fields[ $key ];
				}
			}
		}
		if ( 'grouped' === $type && array_key_exists( 'children', $fields ) ) {
			$input['children'] = $fields['children'];
		}
		if ( $variation ) {
			$input['attributes'] = is_array( $fields['attributes'] ?? null ) ? $fields['attributes'] : [];
		} else {
			if ( [] !== (array) ( $fields['attributes'] ?? [] ) || [] !== (array) ( $live_fields['attributes'] ?? [] ) ) {
				$input['attributes'] = (array) ( $fields['attributes'] ?? [] );
			}
			if ( array_key_exists( 'default_attributes', $fields ) ) {
				$input['default_attributes'] = (array) $fields['default_attributes'];
			}
		}
		return $input;
	}

	// -----------------------------------------------------------------------
	// Catalog terms.
	// -----------------------------------------------------------------------

	private static function allowed_taxonomy( string $taxonomy ): bool {
		return 1 === preg_match( '/^(?:product_cat|product_tag|product_shipping_class|pa_[a-z0-9_-]+)$/D', $taxonomy );
	}

	/**
	 * @return array{0:string,1:int}|null The taxonomy and the id of a term resource id.
	 */
	private static function split_term( string $id ): ?array {
		if ( 1 !== preg_match( '/^([a-z0-9_-]+):([1-9][0-9]{0,18})$/D', $id, $match ) || ! self::allowed_taxonomy( $match[1] ) ) {
			return null;
		}
		return [ $match[1], (int) $match[2] ];
	}

	/**
	 * @return array<string, mixed>|null
	 */
	private static function term_image( string $id ): ?array {
		$parts = self::split_term( $id );
		if ( null === $parts || ! function_exists( 'get_term' ) ) {
			return null;
		}
		$term = get_term( $parts[1], $parts[0] );
		if ( ! is_object( $term ) || ! isset( $term->term_id ) ) {
			return null;
		}
		return [
			'fields'   => [
				'description' => (string) ( $term->description ?? '' ),
				'name'        => (string) ( $term->name ?? '' ),
				'parent'      => (int) ( $term->parent ?? 0 ),
				'slug'        => (string) ( $term->slug ?? '' ),
			],
			'taxonomy' => $parts[0],
			'v'        => self::IMAGE_VERSION,
		];
	}

	/**
	 * @param array<string, mixed>|null $target
	 * @param array<string, mixed>|null $live
	 * @return array{ok:bool,applied:bool,detail:string,id:string,limits:list<string>}
	 */
	private static function write_term( string $id, ?array $target, ?array $live ): array {
		$parts = self::split_term( $id );
		if ( null === $parts ) {
			return self::refused( 'taxonomy_not_allowed' );
		}
		[ $taxonomy, $term_id ] = $parts;
		if ( null === $target ) {
			$current = get_term( $term_id, $taxonomy );
			if ( is_object( $current ) && (int) ( $current->count ?? 0 ) > 0 ) {
				return self::refused( 'term_in_use' );
			}
			$deleted = wp_delete_term( $term_id, $taxonomy );
			return self::applied( ! $deleted instanceof \WP_Error && false !== $deleted && null === self::term_image( $id ), 'deleted' );
		}
		$fields = is_array( $target['fields'] ?? null ) ? $target['fields'] : [];
		$args   = [ 'slug' => (string) ( $fields['slug'] ?? '' ), 'description' => (string) ( $fields['description'] ?? '' ), 'parent' => (int) ( $fields['parent'] ?? 0 ) ];
		if ( 'product_cat' !== $taxonomy ) {
			unset( $args['parent'] );
		}
		if ( null === $live ) {
			$created = wp_insert_term( (string) ( $fields['name'] ?? '' ), $taxonomy, $args );
			if ( $created instanceof \WP_Error ) {
				return self::refused( self::short_code( $created ) );
			}
			$new = $taxonomy . ':' . (int) ( $created['term_id'] ?? 0 );
		} else {
			$updated = wp_update_term( $term_id, $taxonomy, [ 'name' => (string) ( $fields['name'] ?? '' ) ] + $args );
			if ( $updated instanceof \WP_Error ) {
				return self::refused( self::short_code( $updated ) );
			}
			$new = $id;
		}
		$differences = self::differences( $target, self::term_image( $new ) );
		return self::applied( [] === $differences, [] === $differences ? ( null === $live ? 'recreated' : 'restored' ) : 'differences:' . implode( ',', array_slice( $differences, 0, 5 ) ), $new );
	}

	// -----------------------------------------------------------------------
	// Global attributes.
	// -----------------------------------------------------------------------

	/**
	 * @return array<string, mixed>|null
	 */
	private static function attribute_image( string $id ): ?array {
		if ( 1 !== preg_match( '/^[1-9][0-9]{0,18}$/D', $id ) || ! WooRuntime::available() ) {
			return null;
		}
		foreach ( (array) WooRuntime::get_attribute_taxonomies() as $attribute ) {
			if ( is_object( $attribute ) && (int) $id === (int) ( $attribute->attribute_id ?? 0 ) ) {
				return [
					'fields' => [
						'has_archives' => ! empty( $attribute->attribute_public ),
						'name'         => (string) ( $attribute->attribute_label ?? '' ),
						'order_by'     => (string) ( $attribute->attribute_orderby ?? '' ),
						'slug'         => (string) ( $attribute->attribute_name ?? '' ),
						'type'         => (string) ( $attribute->attribute_type ?? '' ),
					],
					'v'      => self::IMAGE_VERSION,
				];
			}
		}
		return null;
	}

	/**
	 * @param array<string, mixed>|null $target
	 * @param array<string, mixed>|null $live
	 * @return array{ok:bool,applied:bool,detail:string,id:string,limits:list<string>}
	 */
	private static function write_attribute( string $id, ?array $target, ?array $live ): array {
		if ( ! function_exists( 'wc_update_attribute' ) || ! function_exists( 'wc_create_attribute' ) || ! function_exists( 'wc_delete_attribute' ) ) {
			return self::refused( 'woocommerce_unavailable' );
		}
		if ( null === $target ) {
			$slug = (string) ( $live['fields']['slug'] ?? '' );
			if ( '' !== $slug && function_exists( 'taxonomy_exists' ) && taxonomy_exists( 'pa_' . $slug ) && function_exists( 'get_terms' ) && count( (array) get_terms( [ 'taxonomy' => 'pa_' . $slug, 'hide_empty' => false ] ) ) > 0 ) {
				return self::refused( 'attribute_in_use' );
			}
			$deleted = wc_delete_attribute( (int) $id );
			return self::applied( ! $deleted instanceof \WP_Error && false !== $deleted && null === self::attribute_image( $id ), 'deleted' );
		}
		$fields = is_array( $target['fields'] ?? null ) ? $target['fields'] : [];
		$args   = [];
		foreach ( self::ATTRIBUTE_FIELDS as $field ) {
			if ( array_key_exists( $field, $fields ) ) {
				$args[ $field ] = 'has_archives' === $field ? (bool) $fields[ $field ] : (string) $fields[ $field ];
			}
		}
		if ( null === $live ) {
			$created = wc_create_attribute( $args );
			if ( $created instanceof \WP_Error ) {
				return self::refused( self::short_code( $created ) );
			}
			$new = (string) (int) $created;
		} else {
			$updated = wc_update_attribute( (int) $id, $args );
			if ( $updated instanceof \WP_Error ) {
				return self::refused( self::short_code( $updated ) );
			}
			$new = $id;
		}
		$differences = self::differences( $target, self::attribute_image( $new ) );
		return self::applied( [] === $differences, [] === $differences ? ( null === $live ? 'recreated' : 'restored' ) : 'differences:' . implode( ',', array_slice( $differences, 0, 5 ) ), $new );
	}
}
