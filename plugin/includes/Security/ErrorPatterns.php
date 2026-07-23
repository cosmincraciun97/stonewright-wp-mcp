<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\Security;

use Stonewright\WpMcp\Memory\Memory;
use Stonewright\WpMcp\Support\Logger;

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
	/**
	 * Expected safety blocks / hard stops that must not become active project/user learning.
	 *
	 * @var list<string>
	 */
	private const EXPECTED_SAFETY_CODES = [
		'stonewright_php_elementor_raw_write_blocked',
		'stonewright_php_code_file_write_blocked',
		'stonewright_php_read_only_violation',
		'stonewright_custom_code_grant_required',
		'stonewright_confirmation_required',
		'stonewright_confirmation_invalid',
		'stonewright_permission_denied',
	];

	public static function observe( string $ability, string $status, array $sanitized_args = [] ): void {
		$status = strtolower( $status );
		if ( ! in_array( $status, [ 'error', 'blocked' ], true ) ) {
			return;
		}

		$code = self::error_code( $sanitized_args );
		// Expected safety blocks: track count for hard-stop, never promote active learning.
		$expected_block = 'blocked' === $status || self::is_expected_safety_code( $code );

		$signature = self::signature( $ability, $sanitized_args );
		$store     = self::load();
		$now       = gmdate( 'c' );
		$cause_key = self::cause_key( $ability, $sanitized_args );

		if ( ! isset( $store[ $signature ] ) ) {
			$store[ $signature ] = [
				'signature'    => $signature,
				'cause_key'    => $cause_key,
				'ability'      => $ability,
				'error_code'   => $code,
				'message'      => self::message_excerpt( $sanitized_args ),
				'count'        => 0,
				'first_seen'   => $now,
				'last_seen'    => $now,
				'dismissed'    => false,
				'learning_key' => '',
				'state'        => $expected_block ? 'blocked_pending_repair' : 'observed',
				'expected'     => $expected_block,
			];
		}

		$store[ $signature ]['count']      = (int) $store[ $signature ]['count'] + 1;
		$store[ $signature ]['last_seen']  = $now;
		$store[ $signature ]['message']    = self::message_excerpt( $sanitized_args );
		$store[ $signature ]['error_code'] = $code;
		$store[ $signature ]['ability']    = $ability;
		$store[ $signature ]['cause_key']  = $cause_key;
		$store[ $signature ]['expected']   = $expected_block;
		if ( (int) $store[ $signature ]['count'] >= 2 ) {
			$store[ $signature ]['state'] = $expected_block ? 'blocked_pending_repair' : 'repeated';
		}

		// Only promote durable feedback learning for unexpected agent-caused errors.
		if (
			! $expected_block
			&& (int) $store[ $signature ]['count'] >= 2
			&& ! $store[ $signature ]['dismissed']
		) {
			// Store as unresolved incident-style feedback (not project/user rules).
			$learning_key = self::ensure_learning_record( $store[ $signature ] );
			$store[ $signature ]['learning_key'] = $learning_key;
			$store[ $signature ]['state']        = 'repair_attempted';
		}

		self::save( $store );
	}

	public static function is_expected_safety_code( string $code ): bool {
		$code = strtolower( sanitize_key( $code ) );
		foreach ( self::EXPECTED_SAFETY_CODES as $known ) {
			if ( $code === sanitize_key( $known ) || str_contains( $code, 'blocked' ) ) {
				return true;
			}
		}
		return false;
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
	 * How many times this exact error signature has been observed.
	 *
	 * Signature matches observe() storage: ability + error_code + message excerpt.
	 *
	 * @param array<string, mixed> $sanitized_args Keys such as error_code / message
	 *                                             or _meta.error_code / _meta.error_message.
	 */
	public static function occurrence_count( string $ability, array $sanitized_args ): int {
		$store = self::load();
		$sig   = self::signature( $ability, $sanitized_args );
		return (int) ( $store[ $sig ]['count'] ?? 0 );
	}

	/**
	 * On the 2nd+ identical failure, rewrite the WP_Error with hard-stop guidance.
	 *
	 * Call after AbilityKernel audit() has already run ErrorPatterns::observe()
	 * (via AuditLog::record). At that point the store count already includes the
	 * current failure, so count >= 2 means "this is the second or later occurrence".
	 *
	 * Lookup uses the original error code/message so the signature matches what
	 * observe() stored — never the post-escalation STOP message.
	 *
	 * @param array<string, mixed> $sanitized_args Optional audit args; merged under
	 *                                             error_code/message from $error.
	 */
	public static function escalate_error( string $ability, \WP_Error $error, array $sanitized_args = [] ): \WP_Error {
		// Build lookup from the error itself so signature matches observe() storage
		// which uses error_code + message from the ability result.
		$lookup = array_merge(
			$sanitized_args,
			[
				'error_code' => $error->get_error_code(),
				'message'    => $error->get_error_message(),
			]
		);
		$count = self::occurrence_count( $ability, $lookup );
		// If count is 0, try pure error-based args (observe may have used only those).
		if ( $count < 1 ) {
			$count = self::occurrence_count(
				$ability,
				[
					'error_code' => $error->get_error_code(),
					'message'    => $error->get_error_message(),
				]
			);
		}
		if ( $count < 2 ) {
			return $error;
		}

		$code    = $error->get_error_code();
		$repair  = RemediationHints::for_code( (string) $code, $ability );
		$message = sprintf(
			/* translators: 1: occurrence count, 2: original error message, 3: repair guidance */
			__( 'STOP: this exact error occurred %1$d times — do not retry the same call. %2$s Next step: %3$s', 'stonewright' ),
			$count,
			$error->get_error_message(),
			$repair
		);
		$data = array_merge(
			(array) $error->get_error_data(),
			[
				'occurrences' => $count,
				'repair'      => $repair,
			]
		);

		return new \WP_Error( $code, $message, $data );
	}

	/**
	 * @param array<string, mixed> $sanitized_args
	 */
	public static function signature( string $ability, array $sanitized_args ): string {
		// Prefer structured cause_key so equivalent failures do not fragment.
		$raw = self::cause_key( $ability, $sanitized_args );
		return hash( 'sha256', $raw );
	}

	/**
	 * Stable cause key: ability + error code + operation class (no volatile IDs).
	 *
	 * @param array<string, mixed> $sanitized_args
	 */
	public static function cause_key( string $ability, array $sanitized_args ): string {
		$code = self::error_code( $sanitized_args );
		$meta = is_array( $sanitized_args['_meta'] ?? null ) ? $sanitized_args['_meta'] : [];
		$op   = '';
		foreach ( [ 'operation_class', 'resource_type', 'cause_key' ] as $key ) {
			if ( ! empty( $meta[ $key ] ) && is_scalar( $meta[ $key ] ) ) {
				$op = (string) $meta[ $key ];
				break;
			}
			if ( ! empty( $sanitized_args[ $key ] ) && is_scalar( $sanitized_args[ $key ] ) ) {
				$op = (string) $sanitized_args[ $key ];
				break;
			}
		}
		return strtolower( $ability ) . '|' . strtolower( $code ) . '|' . strtolower( $op );
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
		$repair = RemediationHints::for_code( (string) ( $row['error_code'] ?? '' ), $ability );
		$correction = sprintf(
			'Unresolved incident for %s (cause %s): %s Exact remediation: %s Promote to project/user learning only after a verified repair or explicit user correction.',
			$ability,
			(string) ( $row['cause_key'] ?? $row['signature'] ?? '' ),
			'' !== $message ? $message : 'unknown error',
			$repair
		);

		// Audit feedback only — never project/user rules. Status pending until verified repair.
		$row_id = Memory::put_typed(
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
				'cause_key'   => (string) ( $row['cause_key'] ?? '' ),
				'state'       => 'unresolved_incident',
				'recorded_at' => current_time( 'mysql', true ),
			],
			1.0,
			[
				'topic'      => $topic,
				// Unresolved audit incidents are not active user/project rules.
				'status'     => 'stale',
				'precedence' => 400,
			]
		);

		if ( 0 === $row_id ) {
			Logger::error(
				'error_pattern_learning_write_failed',
				[
					'key'     => $key,
					'ability' => $ability,
				]
			);
		}

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
