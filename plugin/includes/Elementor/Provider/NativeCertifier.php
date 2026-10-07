<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\Elementor\Provider;

/**
 * Checks one registered upstream ability against its shipped contract.
 *
 * Certification fails closed: any identity, provider, ownership, annotation, runtime
 * constant, schema fingerprint, named probe or Elementor version mismatch rejects the
 * ability and reports each exact reason. Nothing here executes an ability.
 */
final class NativeCertifier {

	private const MAX_ENUM_ITEMS = 20;
	private const MAX_ENUM_BYTES = 100;
	private const VERSION        = '/^\d+\.\d+\.\d+(?:[-+][0-9A-Za-z.+-]*)?$/';

	/**
	 * @param array<string,mixed>      $ability  Upstream ability as discovered; schemas may be summary-truncated.
	 * @param array<string,mixed>|null $contract Contract for the ability name, if one ships.
	 * @return array{state:string,reason:string,reasons?:list<string>,issues:list<string>,contract:array<string,mixed>}
	 */
	public static function certify( array $ability, ?array $contract ): array {
		if ( null === $contract ) {
			return [ 'state' => 'unsupported', 'reason' => 'not_available_for_certification', 'issues' => [], 'contract' => [] ];
		}
		if ( 'unsupported' === ( $contract['status'] ?? '' ) ) {
			$reasons = array_values( array_map( 'strval', (array) ( $contract['reasons'] ?? [] ) ) );
			return [
				'state'    => 'unsupported',
				'reason'   => (string) $contract['reason'],
				'reasons'  => $reasons,
				'issues'   => [],
				'contract' => [
					'status'           => 'unsupported',
					'reasons'          => $reasons,
					'side_effects'     => (array) ( $contract['side_effects'] ?? [] ),
					'unblock_requires' => array_values( array_map( 'strval', (array) ( $contract['unblock_requires'] ?? [] ) ) ),
				],
			];
		}

		$meta      = (array) ( $ability['meta'] ?? [] );
		$runtime   = (array) ( $ability['runtime_contract'] ?? ( $meta['contract'] ?? [] ) );
		$issues    = [];
		$input     = (array) ( $ability['input_schema'] ?? [] );
		$output    = (array) ( $ability['output_schema'] ?? [] );
		$summary   = is_array( $ability['contract_summary'] ?? null ) ? $ability['contract_summary'] : self::summarize( $contract, $ability );

		if ( ( $ability['name'] ?? null ) !== $contract['ability'] || ( $ability['runtime_class'] ?? null ) !== $contract['runtime_class'] ) {
			$issues[] = 'runtime_identity_mismatch';
		}
		$source_plugin = (string) ( $ability['source_plugin'] ?? ( $meta['source_plugin'] ?? '' ) );
		if ( RuntimeOwnership::provider_id( $source_plugin ) !== $contract['provider'] ) {
			$issues[] = 'official_owner_mismatch';
		}
		if ( self::fingerprint( (array) ( $meta['annotations'] ?? [] ) ) !== self::fingerprint( (array) $contract['annotations'] ) ) {
			$issues[] = 'annotations_mismatch';
		}
		$issues = array_merge( $issues, self::schema_issues( $contract, $ability, $input, $output ) );
		foreach ( (array) $contract['probes'] as $probe ) {
			if ( ! self::probe_passes( (array) $probe, $input, $output ) ) {
				$issues[] = (string) $probe['id'];
			}
		}
		if ( self::fingerprint( $runtime ) !== self::fingerprint( (array) $contract['runtime_contract'] ) ) {
			$issues[] = 'runtime_constants_mismatch';
		}
		if ( ! self::ownership_verified( (string) ( $ability['provenance']['ownership'] ?? '' ) ) ) {
			$issues[] = 'ownership_unverified';
		}

		$issues    = array_values( array_unique( $issues ) );
		$certified = [] === $issues;
		return [
			'state'    => $certified ? 'certified' : 'rejected',
			'reason'   => $certified ? 'official_contract_certified' : 'upstream_contract_not_certified',
			'issues'   => $issues,
			'contract' => array_merge(
				$summary,
				[
					'access'                  => (string) $contract['access'],
					'runtime_operation_limit' => (int) ( $runtime['runtime_operation_limit'] ?? 0 ),
					'class_type'              => (string) ( $runtime['class_type'] ?? '' ),
					'side_effects'            => (array) $contract['side_effects'],
					'closure_requirements'    => array_values( array_map( 'strval', (array) $contract['closure_requirements'] ) ),
					'issues'                  => $issues,
				]
			),
		];
	}

	/**
	 * Facts the contract asks to report, read from the raw schema before any size truncation.
	 *
	 * @param array<string,mixed> $contract
	 * @param array<string,mixed> $ability
	 * @return array<string,mixed>
	 */
	public static function summarize( array $contract, array $ability ): array {
		$summary = [];
		$spec    = (array) ( $contract['summary'] ?? [] );
		foreach ( (array) ( $spec['enum_lists'] ?? [] ) as $name => $source ) {
			$found = false;
			$raw   = self::path_value( self::schema_for( (array) $source, $ability ), (array) $source['path'], $found );
			$raw   = $found && is_array( $raw ) ? array_values( $raw ) : [];
			$items = [];
			foreach ( array_slice( $raw, 0, self::MAX_ENUM_ITEMS ) as $item ) {
				$normalized = is_scalar( $item ) || null === $item ? strtolower( trim( (string) $item ) ) : 'invalid_action_type';
				$items[]    = self::bounded( $normalized );
			}
			$name                           = (string) $name;
			$summary[ $name ]              = $items;
			$summary[ $name . '_count' ]     = count( $raw );
			$summary[ $name . '_truncated' ] = count( $raw ) > count( $items );
		}
		foreach ( (array) ( $spec['flags'] ?? [] ) as $name => $source ) {
			$found = false;
			$text  = self::path_value( self::schema_for( (array) $source, $ability ), (array) $source['path'], $found );
			$summary[ (string) $name ] = $found && is_string( $text ) && self::contains_all( $text, (array) $source['contains_all'] );
		}
		return $summary;
	}

	public static function ownership_verified( string $provenance ): bool {
		return in_array( $provenance, [ 'active_plugin_header', 'active_plugin_boundary' ], true );
	}

	public static function fingerprint( mixed $value ): string {
		// An object and an array of the same members hash alike, so a live schema that holds an empty
		// object (`default => (object) []`) matches the same schema recorded as decoded JSON.
		$canonicalize = static function ( mixed $item ) use ( &$canonicalize ): mixed {
			if ( is_object( $item ) ) {
				$item = get_object_vars( $item );
			}
			if ( ! is_array( $item ) ) {
				return $item;
			}
			if ( ! array_is_list( $item ) ) {
				ksort( $item );
			}
			foreach ( $item as $key => $child ) {
				$item[ $key ] = $canonicalize( $child );
			}
			return $item;
		};
		return hash( 'sha256', (string) wp_json_encode( $canonicalize( $value ) ) );
	}

	/**
	 * Version and fingerprint issues, comparing against the schema variant that covers the observed version.
	 *
	 * @param array<string,mixed> $contract
	 * @param array<string,mixed> $ability
	 * @param array<string,mixed> $input
	 * @param array<string,mixed> $output
	 * @return list<string>
	 */
	private static function schema_issues( array $contract, array $ability, array $input, array $output ): array {
		$found      = [];
		$version    = self::observed_version( $ability );
		$candidates = array_values( (array) $contract['schemas'] );
		if ( '' !== $version ) {
			$covering = array_values(
				array_filter(
					$candidates,
					static fn( array $schema ): bool => self::in_range( $version, $schema['elementor_versions'] )
				)
			);
			if ( [] === $covering ) {
				$found[] = 'elementor_version_out_of_range';
			} else {
				$candidates = $covering;
			}
		} elseif ( 'required' === ( $contract['version_policy'] ?? '' ) ) {
			$found[] = 'elementor_version_unverified';
		}

		$actual = [
			'input_schema_mismatch'     => self::fingerprint( $input ),
			'output_schema_mismatch'    => self::fingerprint( $output ),
			'ability_semantics_mismatch' => self::fingerprint( (string) ( $ability['description'] ?? '' ) ),
		];
		$keys     = [
			'input_schema_mismatch'      => 'input_fingerprint',
			'output_schema_mismatch'     => 'output_fingerprint',
			'ability_semantics_mismatch' => 'description_fingerprint',
		];
		if ( 'ignored' === ( $contract['description_policy'] ?? '' ) ) {
			unset( $actual['ability_semantics_mismatch'], $keys['ability_semantics_mismatch'] );
		}
		$best = null;
		foreach ( $candidates as $schema ) {
			$mismatches = [];
			foreach ( $keys as $issue => $key ) {
				if ( $actual[ $issue ] !== $schema[ $key ] ) {
					$mismatches[] = $issue;
				}
			}
			if ( null === $best || count( $mismatches ) < count( $best ) ) {
				$best = $mismatches;
			}
		}
		return array_merge( $found, $best ?? array_keys( $keys ) );
	}

	/**
	 * A maximum of `X.Y.*` covers every release of that minor line, so a patch release inside
	 * the verified line certifies only when its fingerprints match exactly; a new minor is outside it.
	 *
	 * @param array<string,mixed> $range
	 */
	private static function in_range( string $version, array $range ): bool {
		if ( version_compare( $version, (string) $range['min'], '<' ) ) {
			return false;
		}
		$max = (string) $range['max'];
		if ( str_ends_with( $max, '.*' ) ) {
			return str_starts_with( $version . '.', substr( $max, 0, -1 ) );
		}
		return version_compare( $version, $max, '<=' );
	}

	/** @param array<string,mixed> $ability */
	private static function observed_version( array $ability ): string {
		$raw = trim( (string) ( $ability['source_version'] ?? ( $ability['meta']['source_version'] ?? '' ) ) );
		return 1 === preg_match( self::VERSION, $raw ) ? $raw : '';
	}

	/** @param array<string,mixed> $probe @param array<string,mixed> $input @param array<string,mixed> $output */
	private static function probe_passes( array $probe, array $input, array $output ): bool {
		$found = false;
		$value = self::path_value( 'output' === $probe['schema'] ? $output : $input, (array) $probe['path'], $found );
		if ( ! $found ) {
			return false;
		}
		if ( array_key_exists( 'equals', $probe ) ) {
			return $value === $probe['equals'];
		}
		if ( array_key_exists( 'contains_all', $probe ) ) {
			return is_string( $value ) && self::contains_all( $value, (array) $probe['contains_all'] );
		}
		if ( ! is_array( $value ) ) {
			return false;
		}
		if ( array_key_exists( 'keys_same_set', $probe ) ) {
			return self::same_set( array_keys( $value ), (array) $probe['keys_same_set'] );
		}
		if ( array_key_exists( 'exact_strings', $probe ) ) {
			$expected = (array) $probe['exact_strings'];
			foreach ( $value as $item ) {
				if ( ! is_string( $item ) ) {
					return false;
				}
			}
			return count( $value ) === count( $expected ) && self::same_set( $value, $expected );
		}
		return self::same_set( $value, (array) ( $probe['same_set'] ?? [] ) );
	}

	/** @param array<string,mixed> $source @param array<string,mixed> $ability @return array<string,mixed> */
	private static function schema_for( array $source, array $ability ): array {
		return (array) ( 'output' === ( $source['schema'] ?? '' ) ? ( $ability['output_schema'] ?? [] ) : ( $ability['input_schema'] ?? [] ) );
	}

	/** @param array<mixed> $schema @param list<mixed> $path */
	private static function path_value( array $schema, array $path, bool &$found ): mixed {
		$value = $schema;
		foreach ( $path as $key ) {
			if ( ! is_array( $value ) || ! array_key_exists( $key, $value ) ) {
				$found = false;
				return null;
			}
			$value = $value[ $key ];
		}
		$found = true;
		return $value;
	}

	/** @param list<mixed> $actual @param list<mixed> $expected */
	private static function same_set( array $actual, array $expected ): bool {
		$actual   = array_values( array_map( 'strval', $actual ) );
		$expected = array_values( array_map( 'strval', $expected ) );
		sort( $actual );
		sort( $expected );
		return $actual === $expected;
	}

	/** @param list<mixed> $needles */
	private static function contains_all( string $text, array $needles ): bool {
		$text = strtolower( $text );
		foreach ( $needles as $needle ) {
			if ( ! str_contains( $text, strtolower( (string) $needle ) ) ) {
				return false;
			}
		}
		return true;
	}

	private static function bounded( string $value ): string {
		if ( strlen( $value ) <= self::MAX_ENUM_BYTES ) {
			return $value;
		}
		return substr( $value, 0, max( 0, self::MAX_ENUM_BYTES - 3 ) ) . '...';
	}
}
