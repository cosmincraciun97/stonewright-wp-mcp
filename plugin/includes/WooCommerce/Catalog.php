<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\WooCommerce;

/** Shared, allowlisted WooCommerce product mapping. */
final class Catalog {

	/** @return array<string, mixed> */
	public static function product_summary( object $product ): array {
		return [
			'id'                 => (int) self::read( $product, 'get_id', 0 ),
			'type'               => (string) self::read( $product, 'get_type', '' ),
			'name'               => (string) self::read( $product, 'get_name', '' ),
			'slug'               => (string) self::read( $product, 'get_slug', '' ),
			'status'             => (string) self::read( $product, 'get_status', '' ),
			'sku'                => (string) self::read( $product, 'get_sku', '' ),
			'price'              => (string) self::read( $product, 'get_price', '' ),
			'regular_price'      => (string) self::read( $product, 'get_regular_price', '' ),
			'sale_price'         => (string) self::read( $product, 'get_sale_price', '' ),
			'stock_status'       => (string) self::read( $product, 'get_stock_status', '' ),
			'manage_stock'       => (bool) self::read( $product, 'get_manage_stock', false ),
			'stock_quantity'     => self::nullable_int( self::read( $product, 'get_stock_quantity', null ) ),
			'catalog_visibility' => (string) self::read( $product, 'get_catalog_visibility', '' ),
			'permalink'          => (string) self::read( $product, 'get_permalink', '' ),
			'parent_id'          => (int) self::read( $product, 'get_parent_id', 0 ),
			'category_ids'       => self::int_list( self::read( $product, 'get_category_ids', [] ) ),
			'tag_ids'            => self::int_list( self::read( $product, 'get_tag_ids', [] ) ),
			'image_id'           => (int) self::read( $product, 'get_image_id', 0 ),
			'gallery_image_ids'  => self::int_list( self::read( $product, 'get_gallery_image_ids', [] ) ),
			'virtual'            => (bool) self::read( $product, 'get_virtual', false ),
			'downloadable'       => (bool) self::read( $product, 'get_downloadable', false ),
			'featured'           => (bool) self::read( $product, 'get_featured', false ),
			'date_modified'      => self::date_string( self::read( $product, 'get_date_modified', null ) ),
		];
	}

	/** @return array<string, mixed> */
	public static function product_payload( object $product ): array {
		$payload = self::product_summary( $product ) + [
			'description'        => (string) self::read( $product, 'get_description', '' ),
			'short_description'  => (string) self::read( $product, 'get_short_description', '' ),
			'sold_individually'  => (bool) self::read( $product, 'get_sold_individually', false ),
			'tax_status'         => (string) self::read( $product, 'get_tax_status', '' ),
			'tax_class'          => (string) self::read( $product, 'get_tax_class', '' ),
			'weight'             => (string) self::read( $product, 'get_weight', '' ),
			'length'             => (string) self::read( $product, 'get_length', '' ),
			'width'              => (string) self::read( $product, 'get_width', '' ),
			'height'             => (string) self::read( $product, 'get_height', '' ),
			'purchase_note'      => (string) self::read( $product, 'get_purchase_note', '' ),
			'menu_order'         => (int) self::read( $product, 'get_menu_order', 0 ),
			'attributes'         => self::attributes_payload( self::read( $product, 'get_attributes', [] ) ),
			'default_attributes' => self::string_map( self::read( $product, 'get_default_attributes', [] ) ),
			'children'           => self::int_list( self::read( $product, 'get_children', [] ) ),
			'external_url'       => (string) self::read( $product, 'get_product_url', '' ),
			'button_text'        => (string) self::read( $product, 'get_button_text', '' ),
		];
		return $payload;
	}

	/**
	 * Apply only documented setters. Unknown fields never become hidden meta.
	 *
	 * @param array<string, mixed> $input
	 */
	public static function apply_product_input( object $product, array $input, bool $variation = false ): ?\WP_Error {
		$string_fields = [
			'name'               => [ 'set_name', 'text' ],
			'slug'               => [ 'set_slug', 'slug' ],
			'status'             => [ 'set_status', 'key' ],
			'sku'                => [ 'set_sku', 'text' ],
			'regular_price'      => [ 'set_regular_price', 'decimal' ],
			'sale_price'         => [ 'set_sale_price', 'decimal' ],
			'stock_status'       => [ 'set_stock_status', 'key' ],
			'catalog_visibility' => [ 'set_catalog_visibility', 'key' ],
			'tax_status'         => [ 'set_tax_status', 'key' ],
			'tax_class'          => [ 'set_tax_class', 'key' ],
			'weight'             => [ 'set_weight', 'decimal' ],
			'length'             => [ 'set_length', 'decimal' ],
			'width'              => [ 'set_width', 'decimal' ],
			'height'             => [ 'set_height', 'decimal' ],
			'purchase_note'      => [ 'set_purchase_note', 'textarea' ],
		];
		if ( ! $variation ) {
			$string_fields += [
				'description'       => [ 'set_description', 'html' ],
				'short_description' => [ 'set_short_description', 'html' ],
				'external_url'      => [ 'set_product_url', 'url' ],
				'button_text'       => [ 'set_button_text', 'text' ],
			];
		}

		foreach ( $string_fields as $field => [ $setter, $format ] ) {
			if ( ! array_key_exists( $field, $input ) ) {
				continue;
			}
			$value = self::sanitize( (string) $input[ $field ], $format );
			$error = self::write( $product, $setter, $value );
			if ( null !== $error ) {
				return $error;
			}
		}

		foreach ( [ 'manage_stock', 'sold_individually', 'virtual', 'downloadable', 'featured' ] as $field ) {
			if ( ! array_key_exists( $field, $input ) ) {
				continue;
			}
			$error = self::write( $product, 'set_' . $field, (bool) $input[ $field ] );
			if ( null !== $error ) {
				return $error;
			}
		}

		if ( array_key_exists( 'stock_quantity', $input ) ) {
			$quantity = null === $input['stock_quantity'] ? null : (int) $input['stock_quantity'];
			$error    = self::write( $product, 'set_stock_quantity', $quantity );
			if ( null !== $error ) {
				return $error;
			}
		}

		foreach ( [ 'image_id', 'menu_order' ] as $field ) {
			if ( array_key_exists( $field, $input ) ) {
				$error = self::write( $product, 'set_' . $field, (int) $input[ $field ] );
				if ( null !== $error ) {
					return $error;
				}
			}
		}

		if ( $variation && isset( $input['attributes'] ) && is_array( $input['attributes'] ) ) {
			$attributes = [];
			foreach ( $input['attributes'] as $name => $value ) {
				$key = sanitize_key( (string) $name );
				if ( '' !== $key ) {
					$attributes[ $key ] = sanitize_text_field( (string) $value );
				}
			}
			return self::write( $product, 'set_attributes', $attributes );
		}

		if ( ! $variation ) {
			foreach ( [ 'category_ids', 'tag_ids', 'gallery_image_ids', 'children' ] as $field ) {
				if ( array_key_exists( $field, $input ) ) {
					$error = self::write( $product, 'set_' . $field, self::int_list( $input[ $field ] ) );
					if ( null !== $error ) {
						return $error;
					}
				}
			}
			if ( isset( $input['default_attributes'] ) && is_array( $input['default_attributes'] ) ) {
				$defaults = [];
				foreach ( $input['default_attributes'] as $name => $value ) {
					$key = sanitize_key( (string) $name );
					if ( '' !== $key ) {
						$defaults[ $key ] = sanitize_text_field( (string) $value );
					}
				}
				$error = self::write( $product, 'set_default_attributes', $defaults );
				if ( null !== $error ) {
					return $error;
				}
			}
			if ( isset( $input['attributes'] ) && is_array( $input['attributes'] ) ) {
				if ( ! class_exists( '\WC_Product_Attribute' ) ) {
					return new \WP_Error(
						'stonewright_wc_attributes_unavailable',
						'WooCommerce product attribute objects are unavailable.',
						[ 'status' => 400 ]
					);
				}
				$attributes = [];
				foreach ( $input['attributes'] as $index => $row ) {
					if ( ! is_array( $row ) ) {
						continue;
					}
					$attribute = new \WC_Product_Attribute();
					$attribute->set_id( max( 0, (int) ( $row['id'] ?? 0 ) ) );
					$attribute->set_name( sanitize_text_field( (string) ( $row['name'] ?? '' ) ) );
					$options = [];
					foreach ( is_array( $row['options'] ?? null ) ? $row['options'] : [] as $option ) {
						$options[] = is_int( $option ) ? max( 0, $option ) : sanitize_text_field( (string) $option );
					}
					$attribute->set_options( $options );
					$attribute->set_position( (int) ( $row['position'] ?? $index ) );
					$attribute->set_visible( ! isset( $row['visible'] ) || true === $row['visible'] );
					$attribute->set_variation( ! empty( $row['variation'] ) );
					$attributes[] = $attribute;
				}
				return self::write( $product, 'set_attributes', $attributes );
			}
		}

		return null;
	}

	/**
	 * Return only requested fields that did not survive the native save/readback.
	 *
	 * @param array<string, mixed> $input
	 * @return list<string>
	 */
	public static function mismatch_fields( object $product, array $input, bool $variation = false ): array {
		$actual     = self::product_payload( $product );
		$mismatches = [];
		$ignored    = [
			'id',
			'dry_run',
			'confirmation_token',
			'stonewright_context_token',
			'stonewright_fields',
		];
		foreach ( $input as $field => $value ) {
			if ( in_array( $field, $ignored, true ) ) {
				continue;
			}
			if ( ! array_key_exists( $field, $actual ) ) {
				$mismatches[] = $field;
				continue;
			}
			$expected = self::normalized_input_value( $field, $value, $variation );
			$received = self::normalized_readback_value( $field, $actual[ $field ], $variation );
			if ( $expected !== $received ) {
				$mismatches[] = $field;
			}
		}
		return $mismatches;
	}

	/** @param mixed $value @return list<int> */
	public static function int_list( mixed $value ): array {
		if ( ! is_array( $value ) ) {
			return [];
		}
		return array_values(
			array_unique(
				array_filter(
					array_map( static fn( mixed $id ): int => max( 0, (int) $id ), $value ),
					static fn( int $id ): bool => $id > 0
				)
			)
		);
	}

	private static function read( object $object, string $method, mixed $default ): mixed {
		return method_exists( $object, $method ) ? $object->{$method}() : $default;
	}

	private static function write( object $object, string $method, mixed $value ): ?\WP_Error {
		if ( ! method_exists( $object, $method ) ) {
			return new \WP_Error(
				'stonewright_wc_setter_unavailable',
				'The installed WooCommerce product type does not support a requested field.',
				[ 'status' => 400, 'field' => str_replace( 'set_', '', $method ) ]
			);
		}
		try {
			$object->{$method}( $value );
		} catch ( \Throwable ) {
			return new \WP_Error(
				'stonewright_wc_value_invalid',
				'WooCommerce rejected a requested product field.',
				[ 'status' => 400, 'field' => str_replace( 'set_', '', $method ) ]
			);
		}
		return null;
	}

	private static function sanitize( string $value, string $format ): string {
		return match ( $format ) {
			'decimal'  => function_exists( 'wc_format_decimal' ) ? wc_format_decimal( $value ) : (string) preg_replace( '/[^0-9.\-]/', '', $value ),
			'html'     => wp_kses_post( $value ),
			'key'      => sanitize_key( $value ),
			'slug'     => sanitize_title( $value ),
			'textarea' => sanitize_textarea_field( $value ),
			'url'      => esc_url_raw( $value ),
			default    => sanitize_text_field( $value ),
		};
	}

	/** @return array<string, mixed>|array<int, array<string, mixed>> */
	private static function attributes_payload( mixed $attributes ): array {
		if ( ! is_array( $attributes ) ) {
			return [];
		}
		$rows = [];
		foreach ( $attributes as $key => $attribute ) {
			if ( is_object( $attribute ) ) {
				$rows[] = [
					'id'        => (int) self::read( $attribute, 'get_id', 0 ),
					'name'      => (string) self::read( $attribute, 'get_name', '' ),
					'options'   => self::attribute_options( self::read( $attribute, 'get_options', [] ) ),
					'position'  => (int) self::read( $attribute, 'get_position', 0 ),
					'visible'   => (bool) self::read( $attribute, 'get_visible', false ),
					'variation' => (bool) self::read( $attribute, 'get_variation', false ),
				];
				continue;
			}
			if ( is_scalar( $attribute ) ) {
				$attribute_key = sanitize_key( (string) $key );
				if ( '' !== $attribute_key ) {
					$rows[ $attribute_key ] = sanitize_text_field( (string) $attribute );
				}
			}
		}
		return $rows;
	}

	/** @return list<int|string> */
	private static function attribute_options( mixed $options ): array {
		if ( ! is_array( $options ) ) {
			return [];
		}
		return array_values(
			array_map(
				static fn( mixed $option ): int|string => is_int( $option )
					? max( 0, $option )
					: sanitize_text_field( (string) $option ),
				$options
			)
		);
	}

	/** @return array<string, string> */
	private static function string_map( mixed $value ): array {
		if ( ! is_array( $value ) ) {
			return [];
		}
		$out = [];
		foreach ( $value as $key => $item ) {
			$clean_key = sanitize_key( (string) $key );
			if ( '' !== $clean_key ) {
				$out[ $clean_key ] = sanitize_text_field( (string) $item );
			}
		}
		ksort( $out );
		return $out;
	}

	private static function normalized_input_value( string $field, mixed $value, bool $variation ): mixed {
		if ( in_array( $field, [ 'manage_stock', 'sold_individually', 'virtual', 'downloadable', 'featured' ], true ) ) {
			return (bool) $value;
		}
		if ( 'stock_quantity' === $field ) {
			return null === $value ? null : (int) $value;
		}
		if ( in_array( $field, [ 'image_id', 'menu_order' ], true ) ) {
			return (int) $value;
		}
		if ( in_array( $field, [ 'category_ids', 'tag_ids', 'gallery_image_ids', 'children' ], true ) ) {
			return self::int_list( $value );
		}
		if ( in_array( $field, [ 'regular_price', 'sale_price', 'weight', 'length', 'width', 'height' ], true ) ) {
			return self::decimal_for_compare( self::sanitize( (string) $value, 'decimal' ) );
		}
		if ( 'attributes' === $field ) {
			return $variation
				? self::string_map( $value )
				: self::normalized_input_attributes( $value );
		}
		if ( 'default_attributes' === $field ) {
			return self::string_map( $value );
		}
		$formats = [
			'slug'              => 'slug',
			'status'            => 'key',
			'stock_status'      => 'key',
			'catalog_visibility'=> 'key',
			'tax_status'        => 'key',
			'tax_class'         => 'key',
			'description'       => 'html',
			'short_description' => 'html',
			'purchase_note'     => 'textarea',
			'external_url'      => 'url',
		];
		return self::sanitize( (string) $value, $formats[ $field ] ?? 'text' );
	}

	private static function normalized_readback_value( string $field, mixed $value, bool $variation ): mixed {
		if ( in_array( $field, [ 'regular_price', 'sale_price', 'weight', 'length', 'width', 'height' ], true ) ) {
			return self::decimal_for_compare( (string) $value );
		}
		if ( 'attributes' === $field ) {
			return $variation ? self::string_map( $value ) : self::normalized_input_attributes( $value );
		}
		if ( 'default_attributes' === $field ) {
			return self::string_map( $value );
		}
		return $value;
	}

	/** @return list<array<string, mixed>> */
	private static function normalized_input_attributes( mixed $value ): array {
		if ( ! is_array( $value ) ) {
			return [];
		}
		$rows = [];
		foreach ( $value as $index => $row ) {
			if ( ! is_array( $row ) ) {
				continue;
			}
			$rows[] = [
				'id'        => max( 0, (int) ( $row['id'] ?? 0 ) ),
				'name'      => sanitize_text_field( (string) ( $row['name'] ?? '' ) ),
				'options'   => self::attribute_options( $row['options'] ?? [] ),
				'position'  => (int) ( $row['position'] ?? $index ),
				'visible'   => ! isset( $row['visible'] ) || true === $row['visible'],
				'variation' => ! empty( $row['variation'] ),
			];
		}
		return $rows;
	}

	private static function decimal_for_compare( string $value ): string {
		if ( '' === $value ) {
			return '';
		}
		$normalized = rtrim( rtrim( $value, '0' ), '.' );
		return '-0' === $normalized || '' === $normalized ? '0' : $normalized;
	}

	private static function nullable_int( mixed $value ): ?int {
		return null === $value || '' === $value ? null : (int) $value;
	}

	private static function date_string( mixed $value ): string {
		return is_object( $value ) && method_exists( $value, 'date' )
			? (string) $value->date( DATE_ATOM )
			: '';
	}
}
