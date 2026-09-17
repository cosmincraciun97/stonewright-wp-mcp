<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\Abilities\Acf;

/**
 * ACF availability, field-key resolution, raw readback, and typed comparators.
 */
final class AcfRuntime {

	private const COMPARABLE_TYPES = [
		'text'              => true,
		'textarea'          => true,
		'number'            => true,
		'range'             => true,
		'email'             => true,
		'url'               => true,
		'password'          => true,
		'wysiwyg'           => true,
		'oembed'            => true,
		'select'            => true,
		'checkbox'          => true,
		'radio'             => true,
		'button_group'      => true,
		'true_false'        => true,
		'image'             => true,
		'file'              => true,
		'gallery'           => true,
		'post_object'       => true,
		'page_link'         => true,
		'relationship'      => true,
		'user'              => true,
		'taxonomy'          => true,
		'date_picker'       => true,
		'date_time_picker'  => true,
		'time_picker'       => true,
		'color_picker'      => true,
		'link'              => true,
		'group'             => true,
		'repeater'          => true,
		'flexible_content'  => true,
		'google_map'        => true,
	];

	private const REFERENCE_TYPES = [
		'image'        => 'post',
		'file'         => 'post',
		'gallery'      => 'post',
		'post_object'  => 'post',
		'relationship' => 'post',
		'page_link'    => 'post',
		'user'         => 'user',
	];

	private const ID_MAP_KEYS = [ 'ID', 'id', 'attachment_id' ];

	private const DECIMAL_MAX_DIGITS = 80;

	public static function is_active(): bool {
		// Unit tests toggle this without unloading function stubs.
		if ( array_key_exists( 'stonewright_test_acf_active', $GLOBALS ) ) {
			return (bool) $GLOBALS['stonewright_test_acf_active'];
		}
		return function_exists( 'get_fields' )
			|| function_exists( 'acf_get_field_groups' )
			|| class_exists( 'ACF', false );
	}

	/**
	 * @return array<string, mixed>|\WP_Error
	 */
	public static function resolve_field( string $selector, int $post_id = 0 ) {
		$selector = trim( $selector );
		if ( '' === $selector ) {
			return self::unknown_selector_error( $selector );
		}

		$field = null;
		if ( function_exists( 'acf_get_field' ) ) {
			$found = acf_get_field( $selector );
			if ( is_array( $found ) && '' !== self::field_key( $found ) ) {
				$field = $found;
			}
		}
		if ( null === $field && function_exists( 'acf_maybe_get_field' ) ) {
			$found = acf_maybe_get_field( $selector, $post_id, false );
			if ( is_array( $found ) && '' !== self::field_key( $found ) ) {
				$field = $found;
			}
		}
		if ( ! is_array( $field ) ) {
			return self::unknown_selector_error( $selector );
		}

		return $field;
	}

	/**
	 * @param array<string, mixed> $field
	 */
	public static function field_key( array $field ): string {
		$key = (string) ( $field['key'] ?? '' );
		return str_starts_with( $key, 'field_' ) ? $key : '';
	}

	/**
	 * @param array<string, mixed> $field
	 */
	public static function field_type( array $field ): string {
		return sanitize_key( (string) ( $field['type'] ?? '' ) );
	}

	/**
	 * @param array<string, mixed> $field
	 */
	public static function is_comparable( array $field ): bool {
		$type = self::field_type( $field );
		return '' !== $type && isset( self::COMPARABLE_TYPES[ $type ] );
	}

	public static function read_raw( string $selector, int $post_id ): mixed {
		if ( ! function_exists( 'get_field' ) ) {
			return null;
		}
		return get_field( $selector, $post_id, false );
	}

	/**
	 * Stored ACF field key for this post (`acf_get_reference` or `_{$name}` meta).
	 *
	 * @param array<string, mixed> $field
	 */
	public static function read_reference( array $field, int $post_id ): string {
		$name = (string) ( $field['name'] ?? '' );
		$key  = self::field_key( $field );
		$selectors = array_values(
			array_filter(
				[ $name, $key ],
				static fn( string $selector ): bool => '' !== $selector
			)
		);
		if ( function_exists( 'acf_get_reference' ) ) {
			foreach ( $selectors as $selector ) {
				$ref = acf_get_reference( $selector, $post_id );
				if ( is_string( $ref ) && str_starts_with( $ref, 'field_' ) ) {
					return $ref;
				}
			}
		}
		foreach ( $selectors as $selector ) {
			$meta = get_post_meta( $post_id, '_' . $selector, true );
			if ( is_string( $meta ) && str_starts_with( $meta, 'field_' ) ) {
				return $meta;
			}
		}
		return '';
	}

	/**
	 * @param array<string, mixed> $field
	 */
	public static function flush_value_cache( int $post_id, array $field ): void {
		if ( ! function_exists( 'acf_flush_value_cache' ) ) {
			return;
		}
		$name = (string) ( $field['name'] ?? '' );
		$key  = self::field_key( $field );
		if ( '' !== $name ) {
			acf_flush_value_cache( $post_id, $name );
		}
		if ( '' !== $key && $key !== $name ) {
			acf_flush_value_cache( $post_id, $key );
		}
	}

	/**
	 * Shape-check a proposed stored value before compare or write.
	 *
	 * @param array<string, mixed> $field
	 * @return true|\WP_Error
	 */
	public static function validate_value( mixed $value, array $field ): true|\WP_Error {
		return self::validate_value_at( $value, $field, 0 );
	}

	/**
	 * @param array<string, mixed> $field
	 */
	public static function values_equal( mixed $expected, mixed $actual, array $field ): bool {
		return self::canonicalize( $expected, $field ) === self::canonicalize( $actual, $field );
	}

	/**
	 * @param array<string, mixed> $field
	 */
	public static function references_valid( mixed $value, array $field ): bool {
		if ( self::validate_value( $value, $field ) instanceof \WP_Error ) {
			return false;
		}
		$type = self::field_type( $field );
		$kind = self::REFERENCE_TYPES[ $type ] ?? '';
		if ( '' === $kind ) {
			return true;
		}
		foreach ( self::extract_reference_ids( $value, $type ) as $id ) {
			if ( $id < 1 ) {
				return false;
			}
			if ( 'user' === $kind ) {
				$user = function_exists( 'get_user_by' ) ? get_user_by( 'id', $id ) : false;
				if ( ! $user ) {
					return false;
				}
				continue;
			}
			$post = get_post( $id );
			if ( ! $post ) {
				return false;
			}
		}
		return true;
	}

	/**
	 * @param array<string, mixed> $field
	 */
	public static function canonicalize( mixed $value, array $field, int $depth = 0 ): mixed {
		if ( $depth > 20 ) {
			return null;
		}
		$type = self::field_type( $field );
		return match ( $type ) {
			'true_false' => self::canonicalize_true_false( $value ),
			'number', 'range' => self::canonicalize_number( $value ),
			'image', 'file' => self::canonicalize_id( $value ),
			'gallery', 'relationship' => self::canonicalize_id_list( $value ),
			'post_object', 'user', 'taxonomy' => is_array( $value ) && ! self::is_id_map( $value )
				? self::canonicalize_id_list( $value )
				: self::canonicalize_id( $value ),
			'page_link' => is_numeric( $value ) || self::is_id_map( is_array( $value ) ? $value : null )
				? self::canonicalize_id( $value )
				: self::canonicalize_generic( $value ),
			'checkbox', 'select' => self::canonicalize_ordered_list( $value ),
			'repeater', 'flexible_content' => self::canonicalize_rows( $value, $field, $depth ),
			'group' => self::canonicalize_group( $value, $field, $depth ),
			default => self::canonicalize_generic( $value ),
		};
	}

	/**
	 * @param array<string, mixed> $extra
	 * @return array<string, mixed>
	 */
	public static function result( int $post_id, string $selector, mixed $value, array $extra ): array {
		return array_merge(
			[
				'post_id'             => $post_id,
				'selector'            => $selector,
				'ok'                  => true,
				'value'               => $value,
				'changed'             => false,
				'execution_status'    => 'unchanged',
				'verification_status' => 'verified',
				'effect_verified'     => true,
			],
			$extra
		);
	}

	public static function bound_type( array $field ): string {
		$type = self::field_type( $field );
		if ( '' === $type ) {
			return 'unknown';
		}
		return mb_substr( $type, 0, 40 );
	}

	/**
	 * @param array<string, mixed> $field
	 * @return true|\WP_Error
	 */
	private static function validate_value_at( mixed $value, array $field, int $depth ): true|\WP_Error {
		if ( $depth > 20 ) {
			return self::invalid_value_error();
		}
		$type = self::field_type( $field );
		return match ( $type ) {
			'true_false' => self::validate_true_false( $value ),
			'number', 'range' => self::validate_number( $value ),
			'image', 'file' => self::validate_reference_value( $value, false ),
			'gallery', 'relationship' => self::validate_reference_list( $value ),
			'post_object', 'user', 'taxonomy' => is_array( $value ) && ! self::is_id_map( $value )
				? self::validate_reference_list( $value )
				: self::validate_reference_value( $value, false ),
			'page_link' => self::validate_page_link( $value ),
			'group' => self::validate_group( $value, $field, $depth ),
			'repeater', 'flexible_content' => self::validate_rows( $value, $field, $depth ),
			default => true,
		};
	}

	private static function unknown_selector_error( string $selector ): \WP_Error {
		return new \WP_Error(
			'stonewright_acf_unknown_selector',
			__( 'Unknown ACF selector. Resolve a real field name or field_* key before writing.', 'stonewright' ),
			[
				'status'   => 404,
				'selector' => mb_substr( $selector, 0, 80 ),
			]
		);
	}

	private static function invalid_value_error(): \WP_Error {
		return new \WP_Error(
			'stonewright_acf_invalid_value',
			__( 'ACF value does not match the field type.', 'stonewright' ),
			[ 'status' => 400 ]
		);
	}

	private static function validate_true_false( mixed $value ): true|\WP_Error {
		if ( true === $value || false === $value || 1 === $value || 0 === $value || '1' === $value || '0' === $value ) {
			return true;
		}
		return self::invalid_value_error();
	}

	private static function validate_number( mixed $value ): true|\WP_Error {
		if ( null === $value || '' === $value ) {
			return true;
		}
		if ( null === self::normalize_decimal( $value ) ) {
			return self::invalid_value_error();
		}
		return true;
	}

	private static function validate_page_link( mixed $value ): true|\WP_Error {
		if ( self::is_empty_reference( $value ) ) {
			return true;
		}
		if ( is_string( $value ) && ! is_numeric( $value ) ) {
			return true;
		}
		return self::validate_reference_value( $value, false );
	}

	private static function validate_reference_value( mixed $value, bool $required ): true|\WP_Error {
		if ( self::is_empty_reference( $value ) ) {
			return $required ? self::invalid_value_error() : true;
		}
		return self::parse_positive_id( $value ) instanceof \WP_Error
			? self::invalid_value_error()
			: true;
	}

	private static function validate_reference_list( mixed $value ): true|\WP_Error {
		if ( self::is_empty_reference( $value ) ) {
			return true;
		}
		if ( ! is_array( $value ) ) {
			return self::validate_reference_value( $value, true );
		}
		if ( self::is_id_map( $value ) ) {
			return self::validate_reference_value( $value, true );
		}
		foreach ( $value as $item ) {
			if ( self::validate_reference_value( $item, true ) instanceof \WP_Error ) {
				return self::invalid_value_error();
			}
		}
		return true;
	}

	/**
	 * @param array<string, mixed> $field
	 * @return true|\WP_Error
	 */
	private static function validate_group( mixed $value, array $field, int $depth ): true|\WP_Error {
		if ( ! is_array( $value ) ) {
			return self::invalid_value_error();
		}
		$subs = self::sub_field_index( $field );
		foreach ( $value as $key => $item ) {
			$sub = $subs[ (string) $key ] ?? null;
			if ( ! is_array( $sub ) ) {
				return self::invalid_value_error();
			}
			$inner = self::validate_value_at( $item, $sub, $depth + 1 );
			if ( $inner instanceof \WP_Error ) {
				return $inner;
			}
		}
		return true;
	}

	/**
	 * @param array<string, mixed> $field
	 * @return true|\WP_Error
	 */
	private static function validate_rows( mixed $value, array $field, int $depth ): true|\WP_Error {
		if ( ! is_array( $value ) ) {
			return self::invalid_value_error();
		}
		$rows = array_is_list( $value ) ? $value : array_values( $value );
		foreach ( $rows as $row ) {
			$inner = self::validate_group( $row, $field, $depth );
			if ( $inner instanceof \WP_Error ) {
				return $inner;
			}
		}
		return true;
	}

	private static function canonicalize_true_false( mixed $value ): int {
		if ( true === $value || 1 === $value || '1' === $value ) {
			return 1;
		}
		return 0;
	}

	private static function canonicalize_number( mixed $value ): mixed {
		if ( null === $value || '' === $value ) {
			return '';
		}
		$normalized = self::normalize_decimal( $value );
		return null === $normalized ? $value : $normalized;
	}

	private static function canonicalize_id( mixed $value ): ?int {
		if ( self::is_empty_reference( $value ) ) {
			return null;
		}
		$parsed = self::parse_positive_id( $value );
		return $parsed instanceof \WP_Error ? null : $parsed;
	}

	/**
	 * @return list<int|null>
	 */
	private static function canonicalize_id_list( mixed $value ): array {
		if ( ! is_array( $value ) ) {
			$id = self::canonicalize_id( $value );
			return null === $id ? [] : [ $id ];
		}
		if ( self::is_id_map( $value ) ) {
			$id = self::canonicalize_id( $value );
			return null === $id ? [] : [ $id ];
		}
		$out = [];
		foreach ( $value as $item ) {
			$out[] = self::canonicalize_id( $item );
		}
		return $out;
	}

	private static function canonicalize_ordered_list( mixed $value ): array {
		if ( ! is_array( $value ) ) {
			return [ self::canonicalize_generic( $value ) ];
		}
		$items = array_is_list( $value ) ? $value : array_values( $value );
		$out   = [];
		foreach ( $items as $item ) {
			$out[] = self::canonicalize_generic( $item );
		}
		return $out;
	}

	private static function canonicalize_generic( mixed $value ): mixed {
		if ( is_object( $value ) && isset( $value->ID ) ) {
			return (int) $value->ID;
		}
		if ( ! is_array( $value ) ) {
			return $value;
		}
		if ( array_is_list( $value ) ) {
			$out = [];
			foreach ( $value as $item ) {
				$out[] = self::canonicalize_generic( $item );
			}
			return $out;
		}
		$out = [];
		foreach ( $value as $key => $item ) {
			$out[ (string) $key ] = self::canonicalize_generic( $item );
		}
		ksort( $out );
		return $out;
	}

	/**
	 * @param array<string, mixed> $field
	 */
	private static function canonicalize_rows( mixed $value, array $field, int $depth ): array {
		if ( ! is_array( $value ) ) {
			return [];
		}
		$rows = array_is_list( $value ) ? $value : array_values( $value );
		$out  = [];
		foreach ( $rows as $row ) {
			if ( ! is_array( $row ) ) {
				$out[] = self::canonicalize_generic( $row );
				continue;
			}
			$out[] = self::canonicalize_group( $row, $field, $depth );
		}
		return $out;
	}

	/**
	 * @param array<string, mixed> $field
	 */
	private static function canonicalize_group( mixed $value, array $field, int $depth ): mixed {
		if ( ! is_array( $value ) ) {
			return self::canonicalize_generic( $value );
		}
		$subs = self::sub_field_index( $field );
		$out  = [];
		foreach ( $value as $key => $item ) {
			$sub = $subs[ (string) $key ] ?? [ 'type' => 'text', 'key' => 'field_nested_' . $key ];
			$out[ (string) $key ] = self::canonicalize( $item, $sub, $depth + 1 );
		}
		ksort( $out );
		return $out;
	}

	/**
	 * @param array<string, mixed> $field
	 * @return array<string, array<string, mixed>>
	 */
	private static function sub_field_index( array $field ): array {
		$subs = [];
		foreach ( (array) ( $field['sub_fields'] ?? [] ) as $sub ) {
			if ( ! is_array( $sub ) ) {
				continue;
			}
			$name = (string) ( $sub['name'] ?? '' );
			$key  = (string) ( $sub['key'] ?? '' );
			if ( '' !== $name ) {
				$subs[ $name ] = $sub;
			}
			if ( '' !== $key ) {
				$subs[ $key ] = $sub;
			}
		}
		return $subs;
	}

	/**
	 * @return list<int>
	 */
	private static function extract_reference_ids( mixed $value, string $type ): array {
		if ( in_array( $type, [ 'gallery', 'relationship' ], true )
			|| ( is_array( $value ) && ! self::is_id_map( $value ) && in_array( $type, [ 'post_object', 'user', 'taxonomy' ], true ) ) ) {
			$ids = [];
			foreach ( self::canonicalize_id_list( $value ) as $id ) {
				if ( is_int( $id ) ) {
					$ids[] = $id;
				}
			}
			return $ids;
		}
		$id = self::canonicalize_id( $value );
		return is_int( $id ) ? [ $id ] : [];
	}

	private static function is_id_map( mixed $value ): bool {
		return is_array( $value ) && ( isset( $value['ID'] ) || isset( $value['id'] ) || isset( $value['attachment_id'] ) );
	}

	private static function is_empty_reference( mixed $value ): bool {
		return null === $value || false === $value || '' === $value;
	}

	private static function parse_positive_id( mixed $value ): int|\WP_Error {
		if ( is_int( $value ) ) {
			return $value >= 1 ? $value : self::invalid_value_error();
		}
		if ( is_float( $value ) ) {
			if ( is_finite( $value ) && $value >= 1 && $value === floor( $value ) && $value <= PHP_INT_MAX ) {
				return (int) $value;
			}
			return self::invalid_value_error();
		}
		if ( is_string( $value ) ) {
			if ( 1 !== preg_match( '/^\+?[1-9]\d*$/', $value ) ) {
				return self::invalid_value_error();
			}
			$digits = ltrim( $value, '+' );
			$as_int = (int) $digits;
			if ( (string) $as_int !== $digits ) {
				return self::invalid_value_error();
			}
			return $as_int;
		}
		if ( is_object( $value ) ) {
			$vars = get_object_vars( $value );
			if ( isset( $value->ID ) && 1 === count( $vars ) ) {
				return self::parse_positive_id( $value->ID );
			}
			return self::invalid_value_error();
		}
		if ( is_array( $value ) ) {
			foreach ( array_keys( $value ) as $key ) {
				if ( ! in_array( (string) $key, self::ID_MAP_KEYS, true ) ) {
					return self::invalid_value_error();
				}
			}
			$raw = $value['ID'] ?? $value['id'] ?? $value['attachment_id'] ?? null;
			if ( null === $raw ) {
				return self::invalid_value_error();
			}
			return self::parse_positive_id( $raw );
		}
		return self::invalid_value_error();
	}

	private static function normalize_decimal( mixed $value ): ?string {
		if ( is_bool( $value ) || is_array( $value ) || is_object( $value ) || null === $value ) {
			return null;
		}
		if ( is_int( $value ) ) {
			return (string) $value;
		}
		if ( is_float( $value ) ) {
			if ( ! is_finite( $value ) ) {
				return null;
			}
			$encoded = json_encode( $value );
			return is_string( $encoded ) ? self::canonical_decimal_string( $encoded ) : null;
		}
		if ( ! is_string( $value ) ) {
			return null;
		}
		return self::canonical_decimal_string( trim( $value ) );
	}

	private static function canonical_decimal_string( string $raw ): ?string {
		if ( '' === $raw ) {
			return '';
		}
		if ( 1 !== preg_match( '/^([+-])?(?:(\d+)(?:\.(\d*))?|\.(\d+))(?:[eE]([+-]?\d+))?$/', $raw, $match ) ) {
			return null;
		}
		$sign = '-' === ( $match[1] ?? '' ) ? '-' : '';
		if ( '' !== ( $match[4] ?? '' ) ) {
			$int  = '';
			$frac = $match[4];
		} else {
			$int  = $match[2] ?? '';
			$frac = $match[3] ?? '';
		}
		if ( '' === $int && '' === $frac ) {
			return null;
		}
		$digits = $int . $frac;
		$exp    = isset( $match[5] ) && '' !== $match[5] ? (int) $match[5] : 0;
		if ( abs( $exp ) > self::DECIMAL_MAX_DIGITS ) {
			return null;
		}
		$point = strlen( $int ) + $exp;
		if ( $point <= 0 ) {
			$normalized = '0.' . str_repeat( '0', -$point ) . $digits;
		} elseif ( $point >= strlen( $digits ) ) {
			$normalized = $digits . str_repeat( '0', $point - strlen( $digits ) );
		} else {
			$normalized = substr( $digits, 0, $point ) . '.' . substr( $digits, $point );
		}
		if ( str_contains( $normalized, '.' ) ) {
			[ $whole, $fraction ] = explode( '.', $normalized, 2 );
			$whole    = ltrim( $whole, '0' );
			$whole    = '' === $whole ? '0' : $whole;
			$fraction = rtrim( $fraction, '0' );
			$normalized = '' === $fraction ? $whole : $whole . '.' . $fraction;
		} else {
			$normalized = ltrim( $normalized, '0' );
			$normalized = '' === $normalized ? '0' : $normalized;
		}
		$digit_count = strlen( str_replace( '.', '', $normalized ) );
		if ( $digit_count > self::DECIMAL_MAX_DIGITS ) {
			return null;
		}
		if ( '0' === $normalized ) {
			return '0';
		}
		return $sign . $normalized;
	}
}
