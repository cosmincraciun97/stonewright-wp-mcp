<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\Elementor\Provider;

/**
 * Loads the per-ability certification contracts shipped under data/elementor-native-contracts.
 *
 * One JSON file describes one Elementor ability: exact schema fingerprints per verified
 * Elementor version range, provider, runtime class, known side effects, and either the
 * certifiable surface or the machine-readable reason it stays unsupported. A file that does
 * not load cleanly is ignored, so its ability can never be certified.
 */
final class NativeContracts {

	public const CONTRACT_VERSION = 1;

	private const MAX_FILE_BYTES = 262144;
	private const MAX_FILES      = 64;
	private const NAME_PATTERN   = '/^elementor\/[a-z0-9][a-z0-9-]*$/';
	private const VERSION        = '/^\d+\.\d+\.\d+$/';
	private const VERSION_MAX    = '/^\d+\.\d+\.(?:\d+|\*)$/';
	private const FAMILIES       = [ 'kit_defaults', 'tree_composition', 'structure_read', 'global_kit' ];
	private const MAX_SCHEMA_BYTES = 32768;
	private const OPERATORS      = [ 'equals', 'same_set', 'keys_same_set', 'exact_strings', 'contains_all' ];

	/** @var array{contracts:array<string,array<string,mixed>>,errors:list<array{file:string,code:string}>}|null */
	private static ?array $loaded = null;

	public static function path(): string {
		$base = defined( 'STONEWRIGHT_DIR' ) ? rtrim( (string) constant( 'STONEWRIGHT_DIR' ), '/\\' ) : dirname( __DIR__, 3 );
		return $base . '/data/elementor-native-contracts';
	}

	/** @return list<string> */
	public static function names(): array {
		$names = array_keys( self::loaded()['contracts'] );
		sort( $names );
		return $names;
	}

	/** @return array<string,mixed>|null */
	public static function for_ability( string $name ): ?array {
		return self::loaded()['contracts'][ $name ] ?? null;
	}

	/** @return list<array{file:string,code:string}> */
	public static function errors(): array {
		return self::loaded()['errors'];
	}

	/** @return array{contracts:array<string,array<string,mixed>>,errors:list<array{file:string,code:string}>} */
	public static function load_from( string $directory ): array {
		$contracts = [];
		$errors    = [];
		$files     = is_dir( $directory ) && is_readable( $directory ) ? glob( rtrim( $directory, '/\\' ) . '/*.json' ) : false;
		if ( false === $files ) {
			return [ 'contracts' => [], 'errors' => [ [ 'file' => '', 'code' => 'contract_directory_unreadable' ] ] ];
		}
		sort( $files );
		foreach ( array_slice( $files, 0, self::MAX_FILES ) as $file ) {
			$basename = basename( $file );
			$size     = filesize( $file );
			$raw      = false !== $size && $size <= self::MAX_FILE_BYTES ? file_get_contents( $file ) : false;
			$decoded  = is_string( $raw ) ? json_decode( $raw, true, 32 ) : null;
			if ( ! is_array( $decoded ) ) {
				$errors[] = [ 'file' => $basename, 'code' => 'contract_file_unreadable' ];
				continue;
			}
			$name = (string) ( $decoded['ability'] ?? '' );
			if ( 1 !== preg_match( self::NAME_PATTERN, $name ) || substr( $name, strlen( 'elementor/' ) ) . '.json' !== $basename ) {
				$errors[] = [ 'file' => $basename, 'code' => 'contract_name_mismatch' ];
				continue;
			}
			if ( ! self::valid( $decoded ) ) {
				$errors[] = [ 'file' => $basename, 'code' => 'contract_invalid' ];
				continue;
			}
			$contracts[ $name ] = $decoded;
		}
		return [ 'contracts' => $contracts, 'errors' => $errors ];
	}

	/** @return array{contracts:array<string,array<string,mixed>>,errors:list<array{file:string,code:string}>} */
	private static function loaded(): array {
		if ( null === self::$loaded ) {
			self::$loaded = self::load_from( self::path() );
		}
		return self::$loaded;
	}

	/** @param array<string,mixed> $contract */
	private static function valid( array $contract ): bool {
		if ( self::CONTRACT_VERSION !== ( $contract['contract_version'] ?? null ) ) {
			return false;
		}
		foreach ( [ 'provider', 'source_plugin', 'runtime_class' ] as $key ) {
			if ( ! is_string( $contract[ $key ] ?? null ) || '' === $contract[ $key ] ) {
				return false;
			}
		}
		if ( ! self::valid_side_effects( $contract['side_effects'] ?? null ) || ! is_array( $contract['evidence'] ?? null ) ) {
			return false;
		}
		if ( 'unsupported' === ( $contract['status'] ?? null ) ) {
			return self::string_list( $contract['reasons'] ?? null, true )
				&& is_string( $contract['reason'] ?? null )
				&& in_array( $contract['reason'], (array) $contract['reasons'], true )
				&& self::string_list( $contract['unblock_requires'] ?? null, true );
		}
		if ( 'certifiable' !== ( $contract['status'] ?? null ) ) {
			return false;
		}
		// Every native contract pins the Elementor version; a loose policy is not accepted.
		if ( ! in_array( $contract['access'] ?? null, [ 'read', 'write' ], true ) || 'required' !== ( $contract['version_policy'] ?? null ) ) {
			return false;
		}
		if ( isset( $contract['description_policy'] ) && 'ignored' !== $contract['description_policy'] ) {
			return false;
		}
		if ( ! self::valid_routing( $contract ) ) {
			return false;
		}
		if ( ! is_array( $contract['annotations'] ?? null ) || ! is_array( $contract['runtime_contract'] ?? null ) || ! is_array( $contract['requires'] ?? null ) ) {
			return false;
		}
		if ( ! self::string_list( $contract['closure_requirements'] ?? null, false ) || ! self::valid_summary( $contract['summary'] ?? null ) || ! self::valid_probes( $contract['probes'] ?? null ) ) {
			return false;
		}
		return self::valid_schemas( $contract['schemas'] ?? null, 'ignored' === ( $contract['description_policy'] ?? '' ) );
	}

	/** @param array<string,mixed> $contract */
	private static function valid_routing( array $contract ): bool {
		$routing = $contract['routing'] ?? null;
		if ( ! is_array( $routing ) || ! in_array( $routing['family'] ?? null, self::FAMILIES, true ) ) {
			return false;
		}
		$write = $routing['native_write'] ?? null;
		if ( 'read' === $contract['access'] ) {
			return 'read_only' === $write;
		}
		if ( 'refused' === $write ) {
			return is_string( $routing['refusal_reason'] ?? null ) && '' !== $routing['refusal_reason'];
		}
		// A native write that clears generated CSS for the whole site is never routable.
		return 'allowed' === $write && ! in_array( 'global_css_cache_clear', array_column( (array) $contract['side_effects'], 'id' ), true );
	}

	private static function valid_schemas( mixed $schemas, bool $description_ignored = false ): bool {
		if ( ! is_array( $schemas ) || ! array_is_list( $schemas ) || [] === $schemas ) {
			return false;
		}
		foreach ( $schemas as $schema ) {
			if ( ! is_array( $schema ) ) {
				return false;
			}
			$range = $schema['elementor_versions'] ?? null;
			if ( ! is_array( $range ) || 1 !== preg_match( self::VERSION, (string) ( $range['min'] ?? '' ) ) || 1 !== preg_match( self::VERSION_MAX, (string) ( $range['max'] ?? '' ) ) || ( ! str_ends_with( (string) $range['max'], '.*' ) && version_compare( (string) $range['min'], (string) $range['max'], '>' ) ) ) {
				return false;
			}
			foreach ( $description_ignored ? [ 'input_fingerprint', 'output_fingerprint' ] : [ 'input_fingerprint', 'output_fingerprint', 'description_fingerprint' ] as $key ) {
				if ( ! is_string( $schema[ $key ] ?? null ) || 1 !== preg_match( '/^[a-f0-9]{64}$/', $schema[ $key ] ) ) {
					return false;
				}
			}
			if ( array_key_exists( 'input_schema', $schema ) && ! self::embedded_schema_matches( $schema ) ) {
				return false;
			}
		}
		return true;
	}

	/** The certified input schema a contract embeds must be the one its fingerprint names, and stay within the schema size cap. */
	private static function embedded_schema_matches( array $schema ): bool {
		$embedded = $schema['input_schema'];
		if ( ! is_array( $embedded ) || strlen( (string) wp_json_encode( $embedded ) ) > self::MAX_SCHEMA_BYTES ) {
			return false;
		}
		return NativeCertifier::fingerprint( $embedded ) === $schema['input_fingerprint'];
	}

	private static function valid_side_effects( mixed $effects ): bool {
		if ( ! is_array( $effects ) || ! array_is_list( $effects ) || [] === $effects ) {
			return false;
		}
		foreach ( $effects as $effect ) {
			foreach ( [ 'id', 'scope', 'description' ] as $key ) {
				if ( ! is_array( $effect ) || ! is_string( $effect[ $key ] ?? null ) || '' === $effect[ $key ] ) {
					return false;
				}
			}
		}
		return true;
	}

	private static function valid_summary( mixed $summary ): bool {
		if ( ! is_array( $summary ) || ! is_array( $summary['enum_lists'] ?? null ) || ! is_array( $summary['flags'] ?? null ) ) {
			return false;
		}
		foreach ( (array) $summary['enum_lists'] as $name => $source ) {
			if ( ! is_string( $name ) || ! self::valid_source( $source ) ) {
				return false;
			}
		}
		foreach ( (array) $summary['flags'] as $name => $source ) {
			if ( ! is_string( $name ) || ! self::valid_source( $source ) || ! self::string_list( $source['contains_all'] ?? null, true ) ) {
				return false;
			}
		}
		return true;
	}

	private static function valid_probes( mixed $probes ): bool {
		if ( ! is_array( $probes ) || ! array_is_list( $probes ) ) {
			return false;
		}
		foreach ( $probes as $probe ) {
			if ( ! is_array( $probe ) || ! is_string( $probe['id'] ?? null ) || '' === $probe['id'] || ! self::valid_source( $probe ) ) {
				return false;
			}
			if ( 1 !== count( array_intersect( self::OPERATORS, array_keys( $probe ) ) ) ) {
				return false;
			}
		}
		return true;
	}

	private static function valid_source( mixed $source ): bool {
		return is_array( $source ) && in_array( $source['schema'] ?? null, [ 'input', 'output' ], true ) && self::string_list( $source['path'] ?? null, false );
	}

	private static function string_list( mixed $value, bool $required_non_empty ): bool {
		if ( ! is_array( $value ) || ! array_is_list( $value ) || ( $required_non_empty && [] === $value ) ) {
			return false;
		}
		foreach ( $value as $item ) {
			if ( ! is_string( $item ) || '' === $item ) {
				return false;
			}
		}
		return true;
	}
}
