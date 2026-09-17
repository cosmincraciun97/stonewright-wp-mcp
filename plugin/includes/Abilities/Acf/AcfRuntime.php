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
	 * @param array<string, mixed> $field
	 */
	public static function values_equal( mixed $expected, mixed $actual, array $field ): bool {
		return self::canonicalize( $expected, $field ) === self::canonicalize( $actual, $field );
	}

	/**
	 * @param array<string, mixed> $field
	 */
	public static function references_valid( mixed $value, array $field ): bool {
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

	private static function canonicalize_true_false( mixed $value ): int {
		if ( true === $value || 1 === $value || '1' === $value ) {
			return 1;
		}
		return 0;
	}

	private static function canonicalize_number( mixed $value ): mixed {
		if ( is_int( $value ) || is_float( $value ) ) {
			return $value + 0;
		}
		if ( is_string( $value ) && is_numeric( $value ) ) {
			return str_contains( $value, '.' ) ? (float) $value : (int) $value;
		}
		return $value;
	}

	private static function canonicalize_id( mixed $value ): ?int {
		if ( false === $value || null === $value || '' === $value ) {
			return null;
		}
		if ( is_object( $value ) && isset( $value->ID ) ) {
			return (int) $value->ID;
		}
		if ( is_array( $value ) ) {
			foreach ( [ 'ID', 'id', 'attachment_id' ] as $key ) {
				if ( isset( $value[ $key ] ) && is_numeric( $value[ $key ] ) ) {
					return (int) $value[ $key ];
				}
			}
			return null;
		}
		if ( is_numeric( $value ) ) {
			return (int) $value;
		}
		return null;
	}

	/**
	 * @return list<int|null>
	 */
	private static function canonicalize_id_list( mixed $value ): array {
		if ( ! is_array( $value ) ) {
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
		$sub_fields = [];
		foreach ( (array) ( $field['sub_fields'] ?? [] ) as $sub ) {
			if ( ! is_array( $sub ) ) {
				continue;
			}
			$name = (string) ( $sub['name'] ?? '' );
			if ( '' !== $name ) {
				$sub_fields[ $name ] = $sub;
			}
		}
		$out = [];
		foreach ( $value as $key => $item ) {
			$sub       = $sub_fields[ (string) $key ] ?? [ 'type' => 'text', 'key' => 'field_nested_' . $key ];
			$out[ (string) $key ] = self::canonicalize( $item, $sub, $depth + 1 );
		}
		ksort( $out );
		return $out;
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
}
