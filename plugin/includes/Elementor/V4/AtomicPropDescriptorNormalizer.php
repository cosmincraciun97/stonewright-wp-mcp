<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\Elementor\V4;

/**
 * Converts a runtime Atomic prop object into a bounded descriptor.
 *
 * Current Elementor props implement JsonSerializable. Older runtimes exposed
 * to_json_schema(). Anything else is inventory noise, never a guessed write schema.
 */
final class AtomicPropDescriptorNormalizer {

	private const MAX_DEPTH         = 8;
	private const MAX_KEYS          = 256;
	private const MAX_ENCODED_BYTES = 32768;
	private const MAX_STRING_BYTES  = 1000;

	/**
	 * @return array{ok:bool,descriptor_format:?string,runtime_descriptor:?array<mixed>,issue:?array{code:string,error_class:string}}
	 */
	public static function normalize( object $prop ): array {
		if ( $prop instanceof \JsonSerializable ) {
			try {
				$raw = $prop->jsonSerialize();
			} catch ( \Throwable $error ) {
				return self::unavailable( get_class( $error ) );
			}
			return self::bound( $raw, 'elementor-json-serializable-v1' );
		}

		if ( is_callable( [ $prop, 'to_json_schema' ] ) ) {
			try {
				$raw = call_user_func( [ $prop, 'to_json_schema' ] );
			} catch ( \Throwable $error ) {
				return self::unavailable( get_class( $error ) );
			}
			return self::bound( $raw, 'legacy-json-schema-v1' );
		}

		return self::unavailable( '' );
	}

	/**
	 * @return array{ok:bool,descriptor_format:?string,runtime_descriptor:?array<mixed>,issue:?array{code:string,error_class:string}}
	 */
	private static function unavailable( string $error_class ): array {
		return [
			'ok'                  => false,
			'descriptor_format'   => null,
			'runtime_descriptor'  => null,
			'issue'               => [
				'code'        => 'descriptor_unavailable',
				'error_class' => $error_class,
			],
		];
	}

	/**
	 * @return array{ok:bool,descriptor_format:?string,runtime_descriptor:?array<mixed>,issue:?array{code:string,error_class:string}}
	 */
	private static function bound( mixed $raw, string $format ): array {
		if ( ! is_array( $raw ) ) {
			return self::unavailable( '' );
		}

		$keys   = 0;
		$walked = self::walk( $raw, 1, $keys );
		if ( ! $walked['ok'] || ! is_array( $walked['value'] ) ) {
			return self::unavailable( '' );
		}

		$encoded = wp_json_encode( $walked['value'], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
		if ( ! is_string( $encoded ) || strlen( $encoded ) > self::MAX_ENCODED_BYTES ) {
			return self::unavailable( '' );
		}

		return [
			'ok'                 => true,
			'descriptor_format'  => $format,
			'runtime_descriptor' => $walked['value'],
			'issue'              => null,
		];
	}

	/**
	 * @return array{ok:true,value:mixed}|array{ok:false,value:null}
	 */
	private static function walk( mixed $value, int $depth, int &$keys ): array {
		if ( is_resource( $value ) || $value instanceof \Closure ) {
			return [ 'ok' => false, 'value' => null ];
		}
		if ( is_float( $value ) && ! is_finite( $value ) ) {
			return [ 'ok' => false, 'value' => null ];
		}
		if ( is_string( $value ) ) {
			if ( strlen( $value ) > self::MAX_STRING_BYTES ) {
				$value = substr( $value, 0, self::MAX_STRING_BYTES );
			}
			return [ 'ok' => true, 'value' => $value ];
		}
		if ( is_int( $value ) || is_bool( $value ) || is_float( $value ) || null === $value ) {
			return [ 'ok' => true, 'value' => $value ];
		}
		if ( $value instanceof \stdClass ) {
			// Elementor 4.2+ casts empty prop metadata to (object) [] so JSON
			// keeps "{}". Plain data holders are safe to bound as arrays.
			$value = get_object_vars( $value );
		} elseif ( is_object( $value ) || ! is_array( $value ) ) {
			return [ 'ok' => false, 'value' => null ];
		}
		if ( $depth > self::MAX_DEPTH ) {
			return [ 'ok' => false, 'value' => null ];
		}

		$keys += count( $value );
		if ( $keys > self::MAX_KEYS ) {
			return [ 'ok' => false, 'value' => null ];
		}

		$out     = [];
		$is_list = array_is_list( $value );
		foreach ( $value as $key => $child ) {
			$walked = self::walk( $child, $depth + 1, $keys );
			if ( ! $walked['ok'] ) {
				return [ 'ok' => false, 'value' => null ];
			}
			$out[ $key ] = $walked['value'];
		}
		if ( ! $is_list ) {
			ksort( $out );
		}

		return [ 'ok' => true, 'value' => $out ];
	}
}
