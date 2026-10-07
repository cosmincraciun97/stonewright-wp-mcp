<?php
/**
 * Compact notice channel to agents.
 * SPDX-License-Identifier: GPL-2.0-or-later
 *
 * @package Stonewright
 */

declare( strict_types=1 );

namespace Stonewright\WpMcp\Support;

use Stonewright\WpMcp\Security\SensitiveContent;

/**
 * Carries short notices on every ability response, so an agent that never receives a server
 * notification still learns what the site owner needs it to know on its next call.
 *
 * Two kinds of notice share the channel. A line is pushed with a lifetime and travels in the
 * top-level `notices` list until it expires. A field is computed for each response by a
 * registered provider and travels under its own top-level name; the pending rescue incident is
 * the first one.
 *
 * Attaching changes only array results, never replaces a key an ability already returns, and
 * adds nothing when there is nothing to say. An output schema that forbids extra properties
 * gets the two optional fields declared by declare_in_schema(), so a notice can never make a
 * response invalid against the schema the client holds.
 */
final class AgentNotices {

	public const OPTION      = 'stonewright_agent_notices';
	public const MAX_NOTICES = 5;
	public const MAX_LINE    = 160;
	public const MAX_TTL     = 86400;

	/** @var array<string, callable():mixed> */
	private static array $providers = [];

	/**
	 * Push a line that travels on responses for $ttl_seconds.
	 *
	 * @return bool Whether the line was stored.
	 */
	public static function push( string $key, string $line, int $ttl_seconds, ?int $now = null ): bool {
		$now  = $now ?? time();
		$line = trim( (string) preg_replace( '/\s+/', ' ', (string) preg_replace( '/[\x00-\x1F\x7F]+/', ' ', $line ) ) );
		if ( 1 !== preg_match( '/^[a-z0-9_]{1,40}$/D', $key ) || '' === $line || SensitiveContent::contains( $line ) ) {
			return false;
		}
		if ( strlen( $line ) > self::MAX_LINE ) {
			$line = substr( $line, 0, self::MAX_LINE - 3 ) . '...';
		}
		$stored = self::stored( $now );
		unset( $stored[ $key ] );
		$stored[ $key ] = [
			'line'    => $line,
			'at'      => $now,
			'expires' => $now + max( 1, min( self::MAX_TTL, $ttl_seconds ) ),
		];
		if ( count( $stored ) > self::MAX_NOTICES ) {
			uasort( $stored, static fn ( array $a, array $b ): int => $a['at'] <=> $b['at'] );
			$stored = array_slice( $stored, count( $stored ) - self::MAX_NOTICES, null, true );
		}
		update_option( self::OPTION, $stored, true );
		return true;
	}

	public static function dismiss( string $key ): void {
		$raw = get_option( self::OPTION, [] );
		if ( is_array( $raw ) && isset( $raw[ $key ] ) ) {
			unset( $raw[ $key ] );
			update_option( self::OPTION, $raw, true );
		}
	}

	/**
	 * Register a field computed for each response. It returns a small array, or null for nothing.
	 *
	 * @param callable():mixed $provider
	 */
	public static function register_field( string $field, callable $provider ): void {
		if ( 'notices' === $field || 1 !== preg_match( '/^[a-z][a-z0-9_]{0,39}$/D', $field ) ) {
			return;
		}
		self::$providers[ $field ] = $provider;
	}

	/**
	 * The top-level fields to add to a response.
	 *
	 * @return array<string, mixed>
	 */
	public static function fields( ?int $now = null ): array {
		$now = $now ?? time();
		$out = [];
		foreach ( self::$providers as $field => $provider ) {
			try {
				$value = $provider();
			} catch ( \Throwable $failure ) {
				unset( $failure );
				continue;
			}
			if ( ( is_array( $value ) && [] !== $value ) || ( is_string( $value ) && '' !== $value ) ) {
				$out[ $field ] = $value;
			}
		}
		$lines = array_column( self::stored( $now ), 'line' );
		if ( [] !== $lines ) {
			$out['notices'] = array_values( $lines );
		}
		return $out;
	}

	/**
	 * Add the notices to an ability result.
	 */
	public static function attach( mixed $result, ?int $now = null ): mixed {
		if ( ! is_array( $result ) || ( [] !== $result && array_is_list( $result ) ) ) {
			return $result;
		}
		foreach ( self::fields( $now ) as $field => $value ) {
			if ( ! array_key_exists( $field, $result ) ) {
				$result[ $field ] = $value;
			}
		}
		return $result;
	}

	/**
	 * Declare the optional notice fields in an output schema that would otherwise reject them.
	 * A schema that already allows extra properties is returned unchanged, so most tools
	 * advertise no extra tokens.
	 *
	 * @param array<string, mixed> $schema
	 * @return array<string, mixed>
	 */
	public static function declare_in_schema( array $schema ): array {
		if ( false !== ( $schema['additionalProperties'] ?? null ) ) {
			return $schema;
		}
		$properties = isset( $schema['properties'] ) && is_array( $schema['properties'] ) ? $schema['properties'] : [];
		if ( ! array_key_exists( 'pending_incident', $properties ) ) {
			$properties['pending_incident'] = [ 'type' => 'object' ];
		}
		if ( ! array_key_exists( 'notices', $properties ) ) {
			$properties['notices'] = [
				'type'  => 'array',
				'items' => [ 'type' => 'string' ],
			];
		}
		$schema['properties'] = $properties;
		return $schema;
	}

	public static function reset_for_tests(): void {
		self::$providers = [];
	}

	/**
	 * Lines that are still alive, oldest first.
	 *
	 * @return array<string, array{line:string,at:int,expires:int}>
	 */
	private static function stored( int $now ): array {
		$raw = get_option( self::OPTION, [] );
		$out = [];
		foreach ( is_array( $raw ) ? $raw : [] as $key => $item ) {
			if ( ! is_string( $key ) || ! is_array( $item ) || ! isset( $item['line'], $item['expires'] ) || (int) $item['expires'] <= $now ) {
				continue;
			}
			$out[ $key ] = [
				'line'    => (string) $item['line'],
				'at'      => (int) ( $item['at'] ?? 0 ),
				'expires' => (int) $item['expires'],
			];
		}
		return $out;
	}
}
