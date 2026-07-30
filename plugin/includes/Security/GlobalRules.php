<?php
/**
 * Site-agnostic operating rules, shipped as data instead of stored per site.
 *
 * These rules started life as per-site memory entries, which meant they applied
 * to exactly one project and vanished on a memory reset. Encoding them in a
 * packaged JSON registry makes them apply to every project, survive a reset,
 * stay testable, and stay readable by both the plugin and the companion.
 *
 * Rule text must never name a host, a URL, or a site-local record id;
 * GlobalRulesTest enforces that.
 *
 * severity:
 *   hard     — a runtime guard makes the violation fail. `enforcement.guard`
 *              names that guard, and Task 7 wires it.
 *   strong   — surfaced in every task payload. Deviation must be justified.
 *              PHP cannot mechanically enforce these, so they never claim a
 *              runtime guard: advertising fake enforcement is worse than none.
 *   advisory — surfaced on matching tasks only.
 *
 * @package Stonewright\WpMcp
 */

declare( strict_types=1 );

namespace Stonewright\WpMcp\Security;

use RuntimeException;

/**
 * Loads, validates, and caches the shared global rule registry.
 */
final class GlobalRules {

	public const SEVERITIES = [ 'hard', 'strong', 'advisory' ];

	public const SCOPES = [ 'all', 'elementor', 'design', 'code' ];

	public const ENFORCEMENT_KINDS = [ 'runtime', 'instruction' ];

	/**
	 * Canonical record key order. Also the required key set: a record with a
	 * missing or extra key is a registry defect, not something to tolerate.
	 *
	 * @var list<string>
	 */
	private const RECORD_KEYS = [ 'id', 'severity', 'scope', 'rule', 'why', 'enforcement' ];

	/**
	 * @var list<array{id: string, severity: string, scope: string, rule: string, why: string, enforcement: array{kind: string, guard: string}}>|null
	 */
	private static ?array $cache = null;

	/**
	 * Absolute path to the packaged registry.
	 *
	 * Lives under `plugin/data/` so the release ZIP — which is built by copying
	 * the plugin directory — always contains it. A repository-root location
	 * would be missing from every installed copy.
	 */
	public static function path(): string {
		$dir = defined( 'STONEWRIGHT_DIR' ) ? (string) constant( 'STONEWRIGHT_DIR' ) : dirname( __DIR__, 2 ) . '/';

		return $dir . 'data/global-rules.json';
	}

	/**
	 * @return list<array{id: string, severity: string, scope: string, rule: string, why: string, enforcement: array{kind: string, guard: string}}>
	 * @throws RuntimeException When the packaged registry is missing or invalid.
	 */
	public static function all(): array {
		if ( null === self::$cache ) {
			self::$cache = self::load_from( self::path() );
		}

		return self::$cache;
	}

	/**
	 * Drops the per-request cache. Test seam.
	 */
	public static function reset_cache(): void {
		self::$cache = null;
	}

	/**
	 * @return list<string>
	 */
	public static function ids(): array {
		return array_map( static fn( array $rule ): string => $rule['id'], self::all() );
	}

	/**
	 * @return list<string>
	 */
	public static function ids_for_severity( string $severity ): array {
		$matched = array_filter( self::all(), static fn( array $rule ): bool => $severity === $rule['severity'] );

		return array_values( array_map( static fn( array $rule ): string => $rule['id'], $matched ) );
	}

	/**
	 * @return array{id: string, severity: string, scope: string, rule: string, why: string, enforcement: array{kind: string, guard: string}}|null
	 */
	public static function get( string $id ): ?array {
		foreach ( self::all() as $rule ) {
			if ( $id === $rule['id'] ) {
				return $rule;
			}
		}

		return null;
	}

	/**
	 * Content hash of the shipped registry, so clients can cache rule bodies
	 * across calls and only refetch when the digest changes.
	 */
	public static function digest(): string {
		return self::digest_of( self::all() );
	}

	/**
	 * Digest of an arbitrary record list. Encoding is canonical, so formatting
	 * of the source file never affects the hash.
	 *
	 * @param list<array<string, mixed>> $rules Rule records.
	 */
	public static function digest_of( array $rules ): string {
		return sha1( (string) json_encode( $rules, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ) );
	}

	/**
	 * Reads and validates a registry file. Never cached, so tests and the
	 * companion sync check can point at fixtures.
	 *
	 * @return list<array{id: string, severity: string, scope: string, rule: string, why: string, enforcement: array{kind: string, guard: string}}>
	 * @throws RuntimeException When the file is missing, unreadable, or invalid.
	 */
	public static function load_from( string $path ): array {
		if ( ! is_file( $path ) || ! is_readable( $path ) ) {
			throw new RuntimeException(
				sprintf( 'The global rule registry is missing or unreadable at %s.', $path )
			);
		}

		$raw = file_get_contents( $path );
		if ( false === $raw ) {
			throw new RuntimeException(
				sprintf( 'The global rule registry is missing or unreadable at %s.', $path )
			);
		}

		$decoded = json_decode( $raw, true );
		if ( ! is_array( $decoded ) ) {
			throw new RuntimeException(
				sprintf( 'The global rule registry at %s is not valid JSON.', $path )
			);
		}

		if ( ! array_is_list( $decoded ) ) {
			throw new RuntimeException(
				sprintf( 'The global rule registry at %s must be a list of rule records.', $path )
			);
		}

		$rules = [];
		$seen  = [];
		foreach ( $decoded as $index => $record ) {
			$rule = self::validate_record( $record, (int) $index, $path );

			if ( isset( $seen[ $rule['id'] ] ) ) {
				throw new RuntimeException(
					sprintf( 'The global rule registry at %s declares duplicate rule id "%s".', $path, $rule['id'] )
				);
			}

			$seen[ $rule['id'] ] = true;
			$rules[]             = $rule;
		}

		return $rules;
	}

	/**
	 * @param mixed $record Raw decoded record.
	 * @return array{id: string, severity: string, scope: string, rule: string, why: string, enforcement: array{kind: string, guard: string}}
	 * @throws RuntimeException When the record is malformed.
	 */
	private static function validate_record( mixed $record, int $index, string $path ): array {
		if ( ! is_array( $record ) ) {
			throw new RuntimeException(
				sprintf( 'Rule record %d in %s must be an object.', $index, $path )
			);
		}

		foreach ( self::RECORD_KEYS as $key ) {
			if ( ! array_key_exists( $key, $record ) ) {
				throw new RuntimeException(
					sprintf( 'Rule record %d in %s is missing the required "%s" field.', $index, $path, $key )
				);
			}
		}

		$extra = array_diff( array_keys( $record ), self::RECORD_KEYS );
		if ( [] !== $extra ) {
			throw new RuntimeException(
				sprintf( 'Rule record %d in %s declares unknown field "%s".', $index, $path, (string) reset( $extra ) )
			);
		}

		$id = is_string( $record['id'] ) ? $record['id'] : '';
		if ( 1 !== preg_match( '/^[a-z][a-z0-9]*(?:-[a-z0-9]+)*$/', $id ) ) {
			throw new RuntimeException(
				sprintf( 'Rule record %d in %s has an id that is not a lowercase slug.', $index, $path )
			);
		}

		$severity = is_string( $record['severity'] ) ? $record['severity'] : '';
		if ( ! in_array( $severity, self::SEVERITIES, true ) ) {
			throw new RuntimeException(
				sprintf( 'Rule "%s" in %s declares an unknown severity "%s".', $id, $path, $severity )
			);
		}

		$scope = is_string( $record['scope'] ) ? $record['scope'] : '';
		if ( ! in_array( $scope, self::SCOPES, true ) ) {
			throw new RuntimeException(
				sprintf( 'Rule "%s" in %s declares an unknown scope "%s".', $id, $path, $scope )
			);
		}

		$text = is_string( $record['rule'] ) ? trim( $record['rule'] ) : '';
		if ( '' === $text ) {
			throw new RuntimeException(
				sprintf( 'Rule "%s" in %s has empty rule text.', $id, $path )
			);
		}

		$why = is_string( $record['why'] ) ? trim( $record['why'] ) : '';
		if ( '' === $why ) {
			throw new RuntimeException(
				sprintf( 'Rule "%s" in %s has an empty "why" field.', $id, $path )
			);
		}

		$enforcement = self::validate_enforcement( $record['enforcement'], $id, $severity, $path );

		return [
			'id'          => $id,
			'severity'    => $severity,
			'scope'       => $scope,
			'rule'        => $text,
			'why'         => $why,
			'enforcement' => $enforcement,
		];
	}

	/**
	 * @param mixed $enforcement Raw decoded enforcement block.
	 * @return array{kind: string, guard: string}
	 * @throws RuntimeException When enforcement claims do not match severity.
	 */
	private static function validate_enforcement( mixed $enforcement, string $id, string $severity, string $path ): array {
		if ( ! is_array( $enforcement ) ) {
			throw new RuntimeException(
				sprintf( 'Rule "%s" in %s must declare an enforcement object.', $id, $path )
			);
		}

		$kind = isset( $enforcement['kind'] ) && is_string( $enforcement['kind'] ) ? $enforcement['kind'] : '';
		if ( ! in_array( $kind, self::ENFORCEMENT_KINDS, true ) ) {
			throw new RuntimeException(
				sprintf( 'Rule "%s" in %s declares an unknown enforcement kind "%s".', $id, $path, $kind )
			);
		}

		$guard = isset( $enforcement['guard'] ) && is_string( $enforcement['guard'] ) ? trim( $enforcement['guard'] ) : '';

		if ( 'runtime' === $kind && 1 !== preg_match( '/^[a-z][a-z0-9_]*$/', $guard ) ) {
			throw new RuntimeException(
				sprintf( 'Rule "%s" in %s is runtime-enforced but names no concrete runtime guard.', $id, $path )
			);
		}

		if ( 'runtime' !== $kind && '' !== $guard ) {
			throw new RuntimeException(
				sprintf( 'Rule "%s" in %s claims a runtime guard while not being runtime-enforced.', $id, $path )
			);
		}

		if ( 'hard' === $severity && 'runtime' !== $kind ) {
			throw new RuntimeException(
				sprintf( 'Rule "%s" in %s is hard but declares no runtime guard.', $id, $path )
			);
		}

		return [
			'kind'  => $kind,
			'guard' => $guard,
		];
	}
}
