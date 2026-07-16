<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\Security;

use Stonewright\WpMcp\Memory\Memory;

/**
 * Groups recurring audit ERROR signatures and promotes them to learning records.
 */
final class ErrorPatterns {

	public const OPTION_KEY   = 'stonewright_error_patterns';
	public const MAX_PATTERNS = 200;

	/**
	 * Observe an audit row. When status is ERROR, bump the signature counter.
	 * At count >= 2, ensure a single learning record exists for the pattern.
	 *
	 * @param array<string, mixed> $sanitized_args
	 */
	public static function observe( string $ability, string $status, array $sanitized_args = [] ): void {
		if ( 'error' !== strtolower( $status ) ) {
			return;
		}

		$signature = self::signature( $ability, $sanitized_args );
		$store     = self::load();
		$now       = gmdate( 'c' );

		if ( ! isset( $store[ $signature ] ) ) {
			$store[ $signature ] = [
				'signature'    => $signature,
				'ability'      => $ability,
				'error_code'   => self::error_code( $sanitized_args ),
				'message'      => self::message_excerpt( $sanitized_args ),
				'count'        => 0,
				'first_seen'   => $now,
				'last_seen'    => $now,
				'dismissed'    => false,
				'learning_key' => '',
			];
		}

		$store[ $signature ]['count']      = (int) $store[ $signature ]['count'] + 1;
		$store[ $signature ]['last_seen']  = $now;
		$store[ $signature ]['message']    = self::message_excerpt( $sanitized_args );
		$store[ $signature ]['error_code'] = self::error_code( $sanitized_args );
		$store[ $signature ]['ability']    = $ability;

		if ( (int) $store[ $signature ]['count'] >= 2 && ! $store[ $signature ]['dismissed'] ) {
			$learning_key = self::ensure_learning_record( $store[ $signature ] );
			$store[ $signature ]['learning_key'] = $learning_key;
		}

		self::save( $store );
	}

	/**
	 * Active recurring patterns (count >= 2, not dismissed), newest first.
	 *
	 * @return list<array{signature:string,ability:string,error_code:string,message:string,count:int,last_seen:string,first_seen:string,repair:string}>
	 */
	public static function recurring( int $limit = 20 ): array {
		$store = self::load();
		$out   = [];
		foreach ( $store as $row ) {
			if ( (int) ( $row['count'] ?? 0 ) < 2 ) {
				continue;
			}
			if ( ! empty( $row['dismissed'] ) ) {
				continue;
			}
			$code  = (string) ( $row['error_code'] ?? 'error' );
			$ability = (string) ( $row['ability'] ?? '' );
			$out[] = [
				'signature'  => (string) ( $row['signature'] ?? '' ),
				'ability'    => $ability,
				'error_code' => $code,
				'message'    => (string) ( $row['message'] ?? '' ),
				'count'      => (int) ( $row['count'] ?? 0 ),
				'last_seen'  => (string) ( $row['last_seen'] ?? '' ),
				'first_seen' => (string) ( $row['first_seen'] ?? '' ),
				'repair'     => RemediationHints::for_code( $code, $ability ),
			];
		}
		usort(
			$out,
			static function ( array $a, array $b ): int {
				return $b['count'] <=> $a['count']
					?: strcmp( (string) $b['last_seen'], (string) $a['last_seen'] );
			}
		);
		return array_slice( $out, 0, max( 1, $limit ) );
	}

	public static function dismiss( string $signature ): bool {
		$store = self::load();
		if ( ! isset( $store[ $signature ] ) ) {
			return false;
		}
		$store[ $signature ]['dismissed'] = true;
		self::save( $store );
		return true;
	}

	/**
	 * @param array<string, mixed> $sanitized_args
	 */
	public static function signature( string $ability, array $sanitized_args ): string {
		$code    = self::error_code( $sanitized_args );
		$message = self::message_excerpt( $sanitized_args );
		$raw     = strtolower( $ability ) . '|' . strtolower( $code ) . '|' . strtolower( $message );
		return hash( 'sha256', $raw );
	}

	/**
	 * @param array<string, mixed> $args
	 */
	private static function error_code( array $args ): string {
		$meta = is_array( $args['_meta'] ?? null ) ? $args['_meta'] : [];
		foreach ( [ 'error_code', 'code', 'wp_error_code' ] as $key ) {
			if ( ! empty( $meta[ $key ] ) && is_scalar( $meta[ $key ] ) ) {
				return sanitize_key( (string) $meta[ $key ] );
			}
			if ( ! empty( $args[ $key ] ) && is_scalar( $args[ $key ] ) ) {
				return sanitize_key( (string) $args[ $key ] );
			}
		}
		return 'error';
	}

	/**
	 * @param array<string, mixed> $args
	 */
	private static function message_excerpt( array $args ): string {
		$meta = is_array( $args['_meta'] ?? null ) ? $args['_meta'] : [];
		$msg  = '';
		foreach ( [ 'error_message', 'message', 'detail' ] as $key ) {
			if ( ! empty( $meta[ $key ] ) && is_scalar( $meta[ $key ] ) ) {
				$msg = (string) $meta[ $key ];
				break;
			}
			if ( ! empty( $args[ $key ] ) && is_scalar( $args[ $key ] ) ) {
				$msg = (string) $args[ $key ];
				break;
			}
		}
		$msg = preg_replace( '/\s+/', ' ', trim( $msg ) ) ?? '';
		return mb_substr( $msg, 0, 120 );
	}

	/**
	 * @param array<string, mixed> $row
	 */
	private static function ensure_learning_record( array $row ): string {
		$ability  = (string) ( $row['ability'] ?? 'unknown' );
		$message  = (string) ( $row['message'] ?? '' );
		$sig8     = substr( (string) ( $row['signature'] ?? '' ), 0, 8 );
		$key      = 'learning-audit-error-' . $sig8;
		$topic    = 'Recurring error: ' . $ability;
		$correction = sprintf(
			'Ability %s failed repeatedly with: %s. Check inputs, permissions, and prior successful args before retrying.',
			$ability,
			'' !== $message ? $message : 'unknown error'
		);

		// Dedupe: put_typed upserts on scope+key.
		Memory::put_typed(
			'feedback',
			'audit',
			$key,
			$topic,
			[
				'correction'  => $correction,
				'lesson'      => $correction,
				'trigger'     => $ability,
				'severity'   => 'high',
				'source'      => 'audit-error',
				'signature'   => (string) ( $row['signature'] ?? '' ),
				'recorded_at' => current_time( 'mysql', true ),
			],
			1.0,
			[
				'topic'      => $topic,
				'status'     => 'active',
				'precedence' => 700,
			]
		);

		return $key;
	}

	/**
	 * @return array<string, array<string, mixed>>
	 */
	private static function load(): array {
		$raw = get_option( self::OPTION_KEY, [] );
		return is_array( $raw ) ? $raw : [];
	}

	/**
	 * @param array<string, array<string, mixed>> $store
	 */
	private static function save( array $store ): void {
		// LRU: if over cap, drop lowest-count oldest first.
		if ( count( $store ) > self::MAX_PATTERNS ) {
			uasort(
				$store,
				static function ( array $a, array $b ): int {
					return ( (int) ( $a['count'] ?? 0 ) ) <=> ( (int) ( $b['count'] ?? 0 ) )
						?: strcmp( (string) ( $a['last_seen'] ?? '' ), (string) ( $b['last_seen'] ?? '' ) );
				}
			);
			$store = array_slice( $store, -self::MAX_PATTERNS, null, true );
		}
		update_option( self::OPTION_KEY, $store, false );
	}
}
