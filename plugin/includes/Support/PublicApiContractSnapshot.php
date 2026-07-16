<?php
/**
 * Frozen public ability-contract snapshot helpers.
 *
 * Used by bin/generate-contracts.php and PublicApiContractTest to keep the
 * generator and compatibility gate on the same detection path.
 *
 * @package Stonewright\WpMcp
 */

declare( strict_types=1 );

namespace Stonewright\WpMcp\Support;

use ReflectionClass;
use Stonewright\WpMcp\Abilities\Ability;
use Stonewright\WpMcp\Core\AbilityRegistry;

/**
 * Collect and compare the public ability contract surface.
 */
final class PublicApiContractSnapshot {

	public const CONTRACT_VERSION = 1;

	/**
	 * Patterns that mark an ability as mutating state (Write).
	 *
	 * Kept in lockstep with plugin/bin/generate-ability-matrix.php.
	 *
	 * @var list<string>
	 */
	private const WRITE_PATTERNS = [
		'wp_update_post',
		'wp_insert_post',
		'update_post_meta',
		'add_post_meta',
		'update_option',
		'add_option',
		'delete_option',
		'update_metadata',
		'delete_metadata',
		'file_put_contents',
		'rename(',
		'unlink(',
		'copy(',
		'SandboxGuards',
		'snapshot_post',
		'wp_insert_attachment',
		'media_handle_sideload',
		'wpdb->insert(',
		'wpdb->update(',
		'wpdb->delete(',
		'$wpdb->insert',
		'$wpdb->update',
		'$wpdb->delete',
		'Memory::put(',
		'Memory::put_typed(',
		'Memory::delete(',
		'Memory::delete_by_id(',
		'Memory::update_by_id(',
		'Skills::save(',
		'Skills::delete(',
		'SpecToGutenberg()',
		'SpecToElementorV3()',
		'CandidateRepository::create(',
		'CandidateRepository::verify(',
		'CandidateRepository::promote(',
		'CandidateRepository::set_status(',
		'Skills::rollback(',
		'ExpertiseStore::record_scorecard(',
		'ExpertiseEvaluator::evaluate(',
		'ExpertisePromotion::promote(',
		'ExpertisePromotion::set_terminal_status(',
		'ElementorWriter::write',
		'new UploadMedia()',
		'new BuildPageFromSpec()',
		'ConfirmationGuard',
		'eval(',
	];

	/**
	 * Absolute path to the committed public-api contract snapshot.
	 */
	public static function contract_path(): string {
		return dirname( __DIR__, 3 ) . '/docs/contracts/public-api-v1.json';
	}

	/**
	 * Build a full contract document from the live ability registry.
	 *
	 * @return array{
	 *   version:int,
	 *   generated_at:string,
	 *   allowlist:array{removed:list<string>,renamed:array<string,string>,schema_changes:list<string>},
	 *   abilities:list<array<string,mixed>>
	 * }
	 */
	public static function collect( ?string $generated_at = null ): array {
		$abilities = [];

		foreach ( AbilityRegistry::list() as $class ) {
			$entry = self::collect_ability( $class );
			if ( null === $entry ) {
				continue;
			}
			$abilities[] = $entry;
		}

		usort(
			$abilities,
			static fn( array $a, array $b ): int => strcmp( (string) $a['ability_name'], (string) $b['ability_name'] )
		);

		return [
			'version'      => self::CONTRACT_VERSION,
			'generated_at' => $generated_at ?? gmdate( 'c' ),
			'allowlist'    => [
				'removed'        => [],
				'renamed'        => new \stdClass(),
				'schema_changes' => [],
			],
			'abilities'    => $abilities,
		];
	}

	/**
	 * Collect a single ability contract row.
	 *
	 * @param class-string $class
	 * @return array<string, mixed>|null
	 */
	public static function collect_ability( string $class ): ?array {
		$source = self::source_with_parents( $class );

		try {
			$ability = new $class();
		} catch ( \Throwable $e ) {
			fwrite( STDERR, sprintf( "WARNING: Could not instantiate %s: %s\n", $class, $e->getMessage() ) );
			return null;
		}

		if ( ! $ability instanceof Ability ) {
			return null;
		}

		$name = $ability->name();
		$input_schema  = self::safe_schema( $ability, 'input_schema' );
		$output_schema = self::safe_schema( $ability, 'output_schema' );

		return [
			'ability_name'       => $name,
			'mcp_name'           => str_replace( '/', '-', $name ),
			'kind'               => self::detect_kind( $source ),
			'input_schema_hash'  => self::schema_hash( $input_schema ),
			'output_schema_hash' => self::schema_hash( $output_schema ),
			'permission_class'   => self::detect_permission( $source ),
			'gates'              => [
				'backup'    => self::detect_backup( $source ),
				'token'     => self::detect_token( $source ),
				'validator' => self::detect_validator( $source ),
				'audit'     => self::detect_audit( $source ),
			],
		];
	}

	/**
	 * @return array{version:int,generated_at?:string,allowlist:array<string,mixed>,abilities:list<array<string,mixed>>}
	 * @throws \RuntimeException When the contract file is missing, empty, or invalid.
	 */
	public static function load( string $path ): array {
		if ( ! is_readable( $path ) ) {
			throw new \RuntimeException( 'Public API contract file is not readable: ' . $path );
		}

		$raw = file_get_contents( $path );
		if ( false === $raw || '' === $raw ) {
			throw new \RuntimeException( 'Public API contract file is empty: ' . $path );
		}

		try {
			$decoded = json_decode( $raw, true, 512, JSON_THROW_ON_ERROR );
		} catch ( \JsonException $e ) {
			throw new \RuntimeException( 'Public API contract JSON is invalid: ' . $e->getMessage(), 0, $e );
		}

		if ( ! is_array( $decoded ) || ! isset( $decoded['abilities'] ) || ! is_array( $decoded['abilities'] ) ) {
			throw new \RuntimeException( 'Public API contract is missing abilities[]' );
		}

		return $decoded;
	}

	/**
	 * Diff a frozen contract against a live snapshot.
	 *
	 * Removals, renames, schema-hash changes, and other field drift fail unless
	 * explicitly allowlisted. Live-only additions are permitted.
	 *
	 * @param array<string, mixed> $frozen
	 * @param array<string, mixed> $live
	 * @return list<string>
	 */
	public static function compatibility_violations( array $frozen, array $live ): array {
		$allowlist       = is_array( $frozen['allowlist'] ?? null ) ? $frozen['allowlist'] : [];
		$removed_allow   = array_fill_keys( array_map( 'strval', (array) ( $allowlist['removed'] ?? [] ) ), true );
		$schema_allow    = array_fill_keys( array_map( 'strval', (array) ( $allowlist['schema_changes'] ?? [] ) ), true );
		$renamed_allow   = [];
		foreach ( (array) ( $allowlist['renamed'] ?? [] ) as $from => $to ) {
			$renamed_allow[ (string) $from ] = (string) $to;
		}

		$live_by_name = [];
		foreach ( (array) ( $live['abilities'] ?? [] ) as $row ) {
			if ( ! is_array( $row ) || ! isset( $row['ability_name'] ) ) {
				continue;
			}
			$live_by_name[ (string) $row['ability_name'] ] = $row;
		}

		$violations = [];

		foreach ( (array) ( $frozen['abilities'] ?? [] ) as $contract ) {
			if ( ! is_array( $contract ) || ! isset( $contract['ability_name'] ) ) {
				$violations[] = 'frozen entry missing ability_name';
				continue;
			}

			$name = (string) $contract['ability_name'];

			if ( ! isset( $live_by_name[ $name ] ) ) {
				if ( isset( $renamed_allow[ $name ] ) ) {
					$new_name = $renamed_allow[ $name ];
					if ( ! isset( $live_by_name[ $new_name ] ) ) {
						$violations[] = "renamed ability missing target: {$name} -> {$new_name}";
					}
					continue;
				}
				if ( isset( $removed_allow[ $name ] ) ) {
					continue;
				}
				$violations[] = "removed ability without allowlist: {$name}";
				continue;
			}

			$current = $live_by_name[ $name ];

			foreach ( [ 'mcp_name', 'kind', 'permission_class' ] as $field ) {
				$expected = (string) ( $contract[ $field ] ?? '' );
				$actual   = (string) ( $current[ $field ] ?? '' );
				if ( $expected !== $actual ) {
					$violations[] = "{$name}: {$field} changed ({$expected} -> {$actual})";
				}
			}

			foreach ( [ 'input_schema_hash', 'output_schema_hash' ] as $field ) {
				$expected = (string) ( $contract[ $field ] ?? '' );
				$actual   = (string) ( $current[ $field ] ?? '' );
				if ( $expected !== $actual && ! isset( $schema_allow[ $name ] ) ) {
					$violations[] = "{$name}: {$field} changed without allowlist";
				}
			}

			$expected_gates = is_array( $contract['gates'] ?? null ) ? $contract['gates'] : [];
			$actual_gates   = is_array( $current['gates'] ?? null ) ? $current['gates'] : [];
			foreach ( [ 'backup', 'token', 'validator', 'audit' ] as $gate ) {
				$expected = (bool) ( $expected_gates[ $gate ] ?? false );
				$actual   = (bool) ( $actual_gates[ $gate ] ?? false );
				if ( $expected !== $actual ) {
					$violations[] = sprintf(
						'%s: gates.%s changed (%s -> %s)',
						$name,
						$gate,
						$expected ? 'true' : 'false',
						$actual ? 'true' : 'false'
					);
				}
			}
		}

		return $violations;
	}

	/**
	 * Stable pretty JSON for committed contract files.
	 *
	 * @param array<string, mixed> $document
	 * @throws \RuntimeException When JSON encoding fails.
	 */
	public static function encode_document( array $document ): string {
		// Normalize allowlist.renamed empty object vs empty array for stable output.
		if ( isset( $document['allowlist'] ) && is_array( $document['allowlist'] ) ) {
			$renamed = $document['allowlist']['renamed'] ?? [];
			if ( ( is_array( $renamed ) && [] === $renamed ) || $renamed instanceof \stdClass ) {
				$document['allowlist']['renamed'] = new \stdClass();
			}
		}

		$json = json_encode(
			$document,
			JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
		);
		if ( false === $json ) {
			throw new \RuntimeException( 'Failed to encode public API contract JSON' );
		}

		return $json . "\n";
	}

	/**
	 * SHA-256 of a stable (recursively key-sorted) JSON encoding.
	 *
	 * @param array<string, mixed> $schema
	 */
	public static function schema_hash( array $schema ): string {
		return hash( 'sha256', self::stable_json( $schema ) );
	}

	/**
	 * @param mixed $value
	 */
	public static function stable_json( mixed $value ): string {
		$encoded = json_encode(
			self::ksort_recursive( $value ),
			JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
		);
		return false === $encoded ? 'null' : $encoded;
	}

	/**
	 * @param mixed $value
	 * @return mixed
	 */
	private static function ksort_recursive( mixed $value ): mixed {
		if ( ! is_array( $value ) ) {
			return $value;
		}

		$is_list = array_is_list( $value );
		$out     = [];
		foreach ( $value as $key => $child ) {
			$out[ $key ] = self::ksort_recursive( $child );
		}
		if ( ! $is_list ) {
			ksort( $out );
		}
		return $out;
	}

	/**
	 * @return array<string, mixed>
	 */
	private static function safe_schema( Ability $ability, string $method ): array {
		try {
			$schema = $ability->{$method}();
			return is_array( $schema ) ? $schema : [];
		} catch ( \Throwable $e ) {
			// Fall back to hashing a stable method-source marker so the field
			// still exists when schemas cannot be evaluated without full WP.
			$class = get_class( $ability );
			$file  = self::class_to_file( $class );
			$src   = self::read_file( $file );
			$body  = self::extract_method_body( $src, $method );
			return [
				'_schema_unavailable' => true,
				'class'               => $class,
				'method'              => $method,
				'source_hash'         => hash( 'sha256', $body ),
			];
		}
	}

	private static function detect_kind( string $source ): string {
		$clean = self::source_without_strings_and_comments( $source );
		foreach ( self::WRITE_PATTERNS as $pattern ) {
			if ( str_contains( $clean, $pattern ) ) {
				return 'Write';
			}
		}
		return 'Read';
	}

	private static function detect_permission( string $source ): string {
		$body = self::extract_method_body( $source, 'permission_callback' );
		if ( '' === $body ) {
			$body = $source;
		}

		if ( preg_match( '/return\s+Permissions::([a-zA-Z_]+\([^)]*\))/s', $body, $p ) ) {
			return 'Permissions::' . $p[1];
		}
		if ( preg_match( '/Permissions::([a-zA-Z_]+\([^)]*\))/s', $body, $p ) ) {
			return 'Permissions::' . $p[1] . ' (compound)';
		}

		return 'Permissions::read()';
	}

	private static function detect_token( string $source ): bool {
		return str_contains( $source, 'use ConfirmationGuard' )
			|| str_contains( $source, 'ConfirmationToken::verify_or_error' )
			|| str_contains( $source, 'require_confirmation' )
			|| str_contains( $source, 'require_sandbox_confirmation' )
			|| str_contains( $source, 'confirmation_token_error(' )
			|| str_contains( $source, 'production_safe_token_error(' )
			|| str_contains( $source, 'new BuildPageFromSpec()' );
	}

	private static function detect_backup( string $source ): bool {
		return str_contains( $source, 'Backup::snapshot_post' )
			|| str_contains( $source, 'SpecToGutenberg()' )
			|| str_contains( $source, 'SpecToElementorV3()' )
			|| str_contains( $source, 'ApplyToPost()' )
			|| str_contains( $source, 'new BuildPageFromSpec()' );
	}

	private static function detect_validator( string $source ): bool {
		if ( str_contains( $source, 'ThemeJson\\Validator' ) || str_contains( $source, 'ThemeJson\Validator' ) ) {
			return true;
		}
		if ( str_contains( $source, 'DesignSpec\\Validator' ) && str_contains( $source, 'Validator::validate' ) ) {
			return true;
		}
		return str_contains( $source, 'new ValidateSpec()' )
			|| str_contains( $source, 'SpecToGutenberg()' )
			|| str_contains( $source, 'SpecToElementorV3()' )
			|| str_contains( $source, 'ApplyToPost()' )
			|| str_contains( $source, 'new BuildPageFromSpec()' );
	}

	private static function detect_audit( string $source ): bool {
		$clean = self::source_without_strings_and_comments( $source );
		return str_contains( $clean, 'AuditLog::record' )
			|| str_contains( $clean, '$this->audit(' )
			|| str_contains( $clean, '->audit(' );
	}

	/**
	 * Walk parent classes and union source (excluding AbilityKernel/Ability bases).
	 */
	private static function source_with_parents( string $class ): string {
		$stop_at = [
			'Stonewright\\WpMcp\\Abilities\\AbilityKernel',
			'Stonewright\\WpMcp\\Abilities\\Ability',
		];

		$sources = [];
		try {
			$ref = new ReflectionClass( $class );
			while ( $ref !== false ) {
				$name = $ref->getName();
				if ( in_array( $name, $stop_at, true ) ) {
					break;
				}
				$src = self::read_file( self::class_to_file( $name ) );
				if ( '' !== $src ) {
					$sources[] = $src;
				}
				$ref = $ref->getParentClass();
			}
		} catch ( \ReflectionException $e ) {
			$sources[] = self::read_file( self::class_to_file( $class ) );
		}

		return implode( "\n", $sources );
	}

	private static function class_to_file( string $class ): string {
		$plugin_dir = dirname( __DIR__, 2 );
		$relative   = str_replace( 'Stonewright\\WpMcp\\', '', $class );
		$relative   = str_replace( '\\', '/', $relative );
		return $plugin_dir . '/includes/' . $relative . '.php';
	}

	private static function read_file( string $path ): string {
		if ( ! is_readable( $path ) ) {
			return '';
		}
		$content = file_get_contents( $path );
		return false === $content ? '' : $content;
	}

	private static function source_without_strings_and_comments( string $source ): string {
		$tokens = @token_get_all( $source );
		if ( ! is_array( $tokens ) ) {
			return $source;
		}

		$skip_types = [
			T_CONSTANT_ENCAPSED_STRING,
			T_ENCAPSED_AND_WHITESPACE,
			T_COMMENT,
			T_DOC_COMMENT,
		];
		foreach ( [ 'T_START_HEREDOC', 'T_END_HEREDOC', 'T_NOWDOC' ] as $const ) {
			if ( defined( $const ) ) {
				$skip_types[] = constant( $const );
			}
		}

		$clean = '';
		foreach ( $tokens as $token ) {
			if ( is_array( $token ) ) {
				if ( in_array( $token[0], $skip_types, true ) ) {
					continue;
				}
				$clean .= $token[1];
				continue;
			}
			$clean .= $token;
		}

		return $clean;
	}

	/**
	 * Extract a method body via token_get_all brace balancing.
	 */
	private static function extract_method_body( string $source, string $method ): string {
		$tokens = @token_get_all( $source );
		if ( ! is_array( $tokens ) ) {
			return '';
		}

		$count      = count( $tokens );
		$skip_types = [
			T_CONSTANT_ENCAPSED_STRING,
			T_ENCAPSED_AND_WHITESPACE,
			T_COMMENT,
			T_DOC_COMMENT,
		];
		foreach ( [ 'T_START_HEREDOC', 'T_END_HEREDOC', 'T_NOWDOC' ] as $const ) {
			if ( defined( $const ) ) {
				$skip_types[] = constant( $const );
			}
		}

		$found_function = false;
		$i              = 0;
		while ( $i < $count ) {
			$tok = $tokens[ $i ];
			if ( is_array( $tok ) && T_FUNCTION === $tok[0] ) {
				$j = $i + 1;
				while ( $j < $count && is_array( $tokens[ $j ] ) && T_WHITESPACE === $tokens[ $j ][0] ) {
					++$j;
				}
				if ( $j < $count && is_array( $tokens[ $j ] ) && $tokens[ $j ][1] === $method ) {
					$found_function = true;
					$i              = $j + 1;
					break;
				}
			}
			++$i;
		}

		if ( ! $found_function ) {
			return '';
		}

		while ( $i < $count ) {
			$tok = $tokens[ $i ];
			if ( '{' === $tok || ( is_array( $tok ) && '{' === $tok[1] ) ) {
				++$i;
				break;
			}
			++$i;
		}

		$depth = 1;
		$body  = '';
		while ( $i < $count && $depth > 0 ) {
			$tok = $tokens[ $i ];
			if ( is_string( $tok ) ) {
				if ( '{' === $tok ) {
					++$depth;
					$body .= $tok;
				} elseif ( '}' === $tok ) {
					--$depth;
					if ( $depth > 0 ) {
						$body .= $tok;
					}
				} else {
					$body .= $tok;
				}
			} else {
				$body .= $tok[1];
			}
			++$i;
		}

		return $body;
	}
}
