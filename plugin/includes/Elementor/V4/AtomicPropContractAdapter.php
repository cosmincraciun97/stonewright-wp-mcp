<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\Elementor\V4;

/**
 * Versioned certification boundary for Atomic runtime descriptors.
 *
 * Adapters match exact input fingerprints, a descriptor format, and an Elementor
 * version range. They never infer write types from names, class names, defaults,
 * or scalar JSON-schema values.
 */
final class AtomicPropContractAdapter {

	/** @var list<string> */
	private const JSON_SCHEMA_TYPES = [ 'string', 'number', 'integer', 'boolean', 'object', 'array', 'null', 'raw-json' ];

	/** @var list<array{id:string,descriptor_format:string,elementor_version_min:string,elementor_version_max:string,input_fingerprints:list<string>,compact_contract:array{key:string,type:string}}> */
	private static array $adapters = [];

	public static function reset(): void {
		self::$adapters = [];
	}

	/**
	 * @param array<string,mixed> $adapter
	 */
	public static function register( array $adapter ): void {
		$id     = is_string( $adapter['id'] ?? null ) ? $adapter['id'] : '';
		$format = is_string( $adapter['descriptor_format'] ?? null ) ? $adapter['descriptor_format'] : '';
		$min    = is_string( $adapter['elementor_version_min'] ?? null ) ? $adapter['elementor_version_min'] : '';
		$max    = is_string( $adapter['elementor_version_max'] ?? null ) ? $adapter['elementor_version_max'] : '';
		$hashes = self::fingerprint_list( $adapter['input_fingerprints'] ?? null );
		$contract = $adapter['compact_contract'] ?? null;
		if ( '' === $id || '' === $format || '' === $min || '' === $max || [] === $hashes || ! self::valid_contract( $contract ) || ! is_array( $contract ) ) {
			return;
		}

		self::$adapters[] = [
			'id'                    => $id,
			'descriptor_format'     => $format,
			'elementor_version_min' => $min,
			'elementor_version_max' => $max,
			'input_fingerprints'    => $hashes,
			'compact_contract'      => [
				'key'  => (string) $contract['key'],
				'type' => (string) $contract['type'],
			],
		];
	}

	public static function fingerprint( mixed $descriptor ): string {
		if ( ! is_array( $descriptor ) ) {
			return hash( 'sha256', '' );
		}
		$encoded = wp_json_encode( self::canonicalize( $descriptor ), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
		return hash( 'sha256', is_string( $encoded ) ? $encoded : '' );
	}

	/**
	 * @param array{ok?:bool,descriptor_format?:string|null,runtime_descriptor?:array<mixed>|null} $normalized
	 * @param array<string,mixed>                                                                   $context
	 * @return array{matched:bool,compact_contract:?array{key:string,type:string},adapter_id:?string}
	 */
	public static function certify( array $normalized, array $context = [] ): array {
		$miss = [
			'matched'          => false,
			'compact_contract' => null,
			'adapter_id'       => null,
		];
		$format     = is_string( $normalized['descriptor_format'] ?? null ) ? $normalized['descriptor_format'] : '';
		$descriptor = $normalized['runtime_descriptor'] ?? null;
		$version    = is_string( $context['elementor_version'] ?? null ) ? $context['elementor_version'] : '';
		if ( '' === $format || ! is_array( $descriptor ) || '' === $version ) {
			return $miss;
		}

		$fingerprint = self::fingerprint( $descriptor );
		foreach ( self::$adapters as $adapter ) {
			if ( $adapter['descriptor_format'] !== $format ) {
				continue;
			}
			if ( ! in_array( $fingerprint, $adapter['input_fingerprints'], true ) ) {
				continue;
			}
			if ( version_compare( $version, $adapter['elementor_version_min'], '<' ) ) {
				continue;
			}
			if ( version_compare( $version, $adapter['elementor_version_max'], '>' ) ) {
				continue;
			}
			return [
				'matched'          => true,
				'compact_contract' => $adapter['compact_contract'],
				'adapter_id'       => $adapter['id'],
			];
		}

		return $miss;
	}

	/**
	 * @return list<string>
	 */
	private static function fingerprint_list( mixed $fingerprints ): array {
		if ( ! is_array( $fingerprints ) ) {
			return [];
		}
		$hashes = [];
		foreach ( $fingerprints as $fingerprint ) {
			if ( is_string( $fingerprint ) && 1 === preg_match( '/^[a-f0-9]{64}$/', $fingerprint ) ) {
				$hashes[] = $fingerprint;
			}
		}
		return $hashes;
	}

	/** @param mixed $contract */
	private static function valid_contract( mixed $contract ): bool {
		if ( ! is_array( $contract ) || 2 !== count( $contract ) ) {
			return false;
		}
		$key  = $contract['key'] ?? null;
		$type = $contract['type'] ?? null;
		if ( ! is_string( $key ) || ! is_string( $type ) || '' === $key || '' === $type ) {
			return false;
		}
		if ( in_array( strtolower( $type ), self::JSON_SCHEMA_TYPES, true ) ) {
			return false;
		}
		return true;
	}

	private static function canonicalize( mixed $value ): mixed {
		if ( ! is_array( $value ) ) {
			return $value;
		}
		if ( ! array_is_list( $value ) ) {
			ksort( $value );
		}
		foreach ( $value as $key => $item ) {
			$value[ $key ] = self::canonicalize( $item );
		}
		return $value;
	}
}
