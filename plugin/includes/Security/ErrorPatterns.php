<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\Security;

use Stonewright\WpMcp\Memory\Memory;

/**
 * Groups recurring audit ERROR signatures into incident-style patterns.
 *
 * Incidents and lessons are separate lifecycles:
 * - first/recurring failure → pattern counters + IncidentStore (via AuditLog)
 * - dry-run / repair hint → proposed remediation on the pattern only
 * - verified correlated repair or explicit user correction → optional learning
 *
 * Unresolved incidents must never populate both `correction` and `lesson` with
 * the same generic text, and must never auto-promote active project rules.
 */
final class ErrorPatterns {

	public const OPTION_KEY   = 'stonewright_error_patterns';
	public const MAX_PATTERNS = 200;
	public const LEGACY_LESSON_MIGRATION_OPTION = 'stonewright_legacy_audit_lessons_migrated_v1';

	/** @var callable|null */
	private static $test_before_observe = null;

	/**
	 * @param callable|null $callback Invoked at the start of observe(); unit tests only.
	 */
	public static function set_test_before_observe( ?callable $callback ): void {
		self::$test_before_observe = $callback;
	}

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
		'stonewright_rule_violation',
		'stonewright_feature_disabled',
		'rule_violation',
	];

	/**
	 * RFC 6749 / RFC 8628 protocol error codes.
	 *
	 * These belong to the OAuth wire format, not to Stonewright, so they keep
	 * their exact spelling when the failure originates in the auth surface. The
	 * same bare strings coming out of a Stonewright ability are Stonewright's own
	 * and do get namespaced.
	 *
	 * @var list<string>
	 */
	private const OAUTH_PROTOCOL_CODES = [
		'invalid_request',
		'invalid_client',
		'invalid_grant',
		'unauthorized_client',
		'unsupported_grant_type',
		'unsupported_response_type',
		'invalid_scope',
		'access_denied',
		'server_error',
		'temporarily_unavailable',
		'authorization_pending',
		'slow_down',
		'expired_token',
	];

	/**
	 * Prefixes owned by other code. Rewriting them would misattribute the failure.
	 *
	 * @var list<string>
	 */
	private const FOREIGN_PREFIXES = [ 'stonewright_', 'rest_', 'oauth_', 'http_' ];

	/**
	 * Placeholder used when the audit row carries no code at all. It names the
	 * absence of a code, so it must not be dressed up as a Stonewright one.
	 */
	private const UNKNOWN_CODE = 'error';

	/**
	 * Namespace an error code according to who actually emitted it.
	 *
	 * Ownership cannot be read off the code alone: `invalid_request` is both an
	 * OAuth protocol constant and a code Stonewright abilities emit. Namespacing
	 * it globally would rewrite a constant the client is entitled to read back;
	 * preserving it globally would leave Stonewright's own failures indistinguishable
	 * from every other plugin's.
	 *
	 * @param string $code    Raw code from the audit row.
	 * @param string $ability Ability name (slash form) that produced the failure.
	 * @param string $status  Audit status: error, blocked, or auth.
	 */
	public static function normalize_code( string $code, string $ability, string $status ): string {
		$code = sanitize_key( strtolower( trim( $code ) ) );
		if ( '' === $code || self::UNKNOWN_CODE === $code ) {
			return $code;
		}
		foreach ( self::FOREIGN_PREFIXES as $prefix ) {
			if ( str_starts_with( $code, $prefix ) ) {
				return $code;
			}
		}
		if ( self::is_auth_origin( $ability, $status ) && in_array( $code, self::OAUTH_PROTOCOL_CODES, true ) ) {
			return $code;
		}
		return 'stonewright_' . $code;
	}

	/**
	 * Whether the failure came from the auth surface rather than an ability.
	 *
	 * The status is authoritative when present; the ability name covers rows
	 * recorded before the auth status existed.
	 */
	private static function is_auth_origin( string $ability, string $status ): bool {
		if ( 'auth' === strtolower( trim( $status ) ) ) {
			return true;
		}
		$ability = strtolower( trim( $ability ) );
		return '' !== $ability && ( str_starts_with( $ability, 'oauth/' ) || str_contains( $ability, '/oauth-' ) );
	}

	public static function observe( string $ability, string $status, array $sanitized_args = [] ): void {
		if ( null !== self::$test_before_observe ) {
			( self::$test_before_observe )( $ability, $status, $sanitized_args );
		}
		$status = strtolower( $status );
		if ( ! in_array( $status, [ 'error', 'blocked' ], true ) ) {
			return;
		}
		$meta = is_array( $sanitized_args['_meta'] ?? null ) ? $sanitized_args['_meta'] : [];
		if ( ! empty( $meta['retryable'] ) || AuditEvent::OUTCOME_RETRYABLE === (string) ( $meta['outcome'] ?? '' ) ) {
			// Transient failures have their own incident threshold and must not
			// become durable repair instructions after a single burst.
			return;
		}

		$code = self::error_code( $sanitized_args, $ability, $status );
		// Expected safety blocks: track count for hard-stop, never promote active learning.
		$expected_block = 'blocked' === $status || self::is_expected_safety_code( $code );

		$signature = self::signature( $ability, $sanitized_args, $status );
		$store     = self::load();
		$now       = gmdate( 'c' );
		$cause_key = self::cause_key( $ability, $sanitized_args, $status );

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
				'outcome'      => (string) ( $meta['outcome'] ?? ( 'blocked' === $status ? AuditEvent::OUTCOME_BLOCKED : AuditEvent::OUTCOME_FAILED ) ),
				'resource_key_hash' => self::safe_hash( $meta['resource_key_hash'] ?? '' ),
				'normalized_path' => self::safe_text( $meta['normalized_path'] ?? '' ),
				'change_set_id' => self::safe_text( $meta['change_set_id'] ?? '' ),
				'strategy_fingerprint' => self::safe_hash( $meta['strategy_fingerprint'] ?? '' ),
			];
		}

		$delta = max( 1, min( 10000, (int) ( $meta['coalesced_count'] ?? 1 ) ) );
		$store[ $signature ]['count']      = (int) $store[ $signature ]['count'] + $delta;
		$store[ $signature ]['last_seen']  = $now;
		$store[ $signature ]['message']    = self::message_excerpt( $sanitized_args );
		$store[ $signature ]['error_code'] = $code;
		$store[ $signature ]['ability']    = $ability;
		$store[ $signature ]['cause_key']  = $cause_key;
		$store[ $signature ]['expected']   = $expected_block;
		$store[ $signature ]['outcome']   = (string) ( $meta['outcome'] ?? ( 'blocked' === $status ? AuditEvent::OUTCOME_BLOCKED : AuditEvent::OUTCOME_FAILED ) );
		$store[ $signature ]['resource_key_hash'] = self::safe_hash( $meta['resource_key_hash'] ?? ( $store[ $signature ]['resource_key_hash'] ?? '' ) );
		$store[ $signature ]['normalized_path'] = self::safe_text( $meta['normalized_path'] ?? ( $store[ $signature ]['normalized_path'] ?? '' ) );
		$store[ $signature ]['change_set_id'] = self::safe_text( $meta['change_set_id'] ?? ( $store[ $signature ]['change_set_id'] ?? '' ) );
		$store[ $signature ]['strategy_fingerprint'] = self::safe_hash( $meta['strategy_fingerprint'] ?? ( $store[ $signature ]['strategy_fingerprint'] ?? '' ) );
		if ( 'verified_resolved' === (string) ( $store[ $signature ]['state'] ?? '' ) || 'promoted_learning' === (string) ( $store[ $signature ]['state'] ?? '' ) ) {
			$store[ $signature ]['state'] = 'reopened';
			$store[ $signature ]['reopened_count'] = (int) ( $store[ $signature ]['reopened_count'] ?? 0 ) + 1;
		}
		if ( (int) $store[ $signature ]['count'] >= 2 ) {
			$store[ $signature ]['state'] = $expected_block ? 'blocked_pending_repair' : 'repeated';
		}

		// Incidents stay on the pattern/IncidentStore path. Propose remediation
		// text only — never mint a learning row with identical correction+lesson
		// for an unresolved generic failure.
		if (
			! $expected_block
			&& (int) $store[ $signature ]['count'] >= 2
			&& ! $store[ $signature ]['dismissed']
		) {
			$repair = RemediationHints::for_code( $code, $ability );
			$store[ $signature ]['proposed_remediation'] = $repair;
			$store[ $signature ]['state']                = 'repair_proposed';
			// learning_key stays empty until verified repair or explicit user correction.
			$store[ $signature ]['learning_key'] = (string) ( $store[ $signature ]['learning_key'] ?? '' );
		}

		self::save( $store );
	}

	/**
	 * Compatibility adapter for abilities that explicitly return a canonical
	 * verified-repair receipt. Generic success fields never change lifecycle.
	 *
	 * @param array<string, mixed> $result
	 */
	public static function observe_verified_repair( string $ability, array $result ): void {
		$receipt = is_array( $result['verified_repair_receipt'] ?? null ) ? $result['verified_repair_receipt'] : null;
		if ( null === $receipt ) {
			return;
		}

		IncidentStore::record_verified_repair( $receipt );
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
			if ( ! empty( $row['expected'] ) || in_array( (string) ( $row['outcome'] ?? '' ), [ AuditEvent::OUTCOME_BLOCKED, AuditEvent::OUTCOME_RETRYABLE ], true ) ) {
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
				'cause_fingerprint' => (string) ( $row['cause_fingerprint'] ?? '' ),
				'resource_key_hash' => (string) ( $row['resource_key_hash'] ?? '' ),
				'normalized_path' => (string) ( $row['normalized_path'] ?? '' ),
				'strategy_fingerprint' => (string) ( $row['strategy_fingerprint'] ?? '' ),
				'repair'     => (string) ( $row['proposed_remediation'] ?? RemediationHints::for_code( $code, $ability ) ),
				'state'      => (string) ( $row['state'] ?? '' ),
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
	public static function signature( string $ability, array $sanitized_args, string $status = '' ): string {
		// Prefer structured cause_key so equivalent failures do not fragment.
		$raw = self::cause_key( $ability, $sanitized_args, $status );
		return hash( 'sha256', $raw );
	}

	/**
	 * Stable cause key: ability + error code + operation class (no volatile IDs).
	 *
	 * @param array<string, mixed> $sanitized_args
	 */
	public static function cause_key( string $ability, array $sanitized_args, string $status = '' ): string {
		$code = self::error_code( $sanitized_args, $ability, $status );
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
		$structured = [];
		foreach ( [ 'resource_key_hash', 'normalized_path', 'strategy_fingerprint' ] as $key ) {
			if ( isset( $meta[ $key ] ) && is_scalar( $meta[ $key ] ) && '' !== (string) $meta[ $key ] ) {
				$structured[ $key ] = strtolower( (string) $meta[ $key ] );
			}
		}
		$base = strtolower( $ability ) . '|' . strtolower( $code ) . '|' . strtolower( $op );
		return [] === $structured ? $base : $base . '|' . http_build_query( $structured, '', '|' );
	}

	/**
	 * @param array<string, mixed> $args    Sanitized audit args.
	 * @param string               $ability Ability name, for ownership context.
	 * @param string               $status  Audit status, for ownership context.
	 */
	private static function error_code( array $args, string $ability = '', string $status = '' ): string {
		$meta = is_array( $args['_meta'] ?? null ) ? $args['_meta'] : [];
		foreach ( [ 'error_code', 'code', 'wp_error_code' ] as $key ) {
			if ( ! empty( $meta[ $key ] ) && is_scalar( $meta[ $key ] ) ) {
				return self::normalize_code( (string) $meta[ $key ], $ability, $status );
			}
			if ( ! empty( $args[ $key ] ) && is_scalar( $args[ $key ] ) ) {
				return self::normalize_code( (string) $args[ $key ], $ability, $status );
			}
		}
		return self::UNKNOWN_CODE;
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
	 * Reversible migration: identify legacy generic `learning-audit-error-*` /
	 * "unknown error" rows and mark them superseded (archived into incident
	 * history metadata). Never deletes rows. Leaves user-created and verified
	 * learning untouched.
	 *
	 * @return array{migrated:int,skipped:int,already_done:bool,write_failed?:int}
	 */
	public static function migrate_legacy_audit_lessons(): array {
		if ( '1' === (string) get_option( self::LEGACY_LESSON_MIGRATION_OPTION, '' ) ) {
			return [ 'migrated' => 0, 'skipped' => 0, 'already_done' => true ];
		}

		$migrated     = 0;
		$skipped      = 0;
		$write_failed = 0;
		$now          = current_time( 'mysql', true );
		$page_size    = 500;
		$offset       = 0;

		// Paginate every feedback row. Only mark the migration complete when every
		// eligible update succeeds — a put_typed failure must not set the done flag.
		while ( true ) {
			$rows = Memory::list_by_type( 'feedback', $page_size, $offset );
			if ( [] === $rows ) {
				break;
			}
			$offset += count( $rows );

			foreach ( $rows as $row ) {
				$key   = (string) ( $row['memory_key'] ?? '' );
				$value = is_array( $row['value'] ?? null ) ? $row['value'] : [];
				// list_by_type may expose value_json; decode when needed.
				if ( [] === $value && isset( $row['value_json'] ) && is_string( $row['value_json'] ) ) {
					$decoded = json_decode( $row['value_json'], true );
					$value   = is_array( $decoded ) ? $decoded : [];
				}

				if ( ! str_starts_with( $key, 'learning-audit-error-' ) ) {
					++$skipped;
					continue;
				}

				$source = (string) ( $value['source'] ?? '' );
				$state  = (string) ( $value['state'] ?? '' );
				// Leave verified / user-created / already-superseded alone.
				if ( in_array( $source, [ 'verified-repair', 'user', 'user-correction', 'learning-record' ], true ) ) {
					++$skipped;
					continue;
				}
				if ( in_array( $state, [ 'promoted_learning', 'verified_resolved', 'superseded', 'archived_incident' ], true ) ) {
					++$skipped;
					continue;
				}

				$correction = (string) ( $value['correction'] ?? '' );
				$lesson     = (string) ( $value['lesson'] ?? '' );
				$is_generic = (
					str_contains( strtolower( $correction ), 'unknown error' )
					|| str_contains( strtolower( $lesson ), 'unknown error' )
					|| ( '' !== $correction && $correction === $lesson && str_contains( $correction, 'Unresolved incident' ) )
					|| 'unresolved_incident' === $state
					|| 'audit-error' === $source
				);
				if ( ! $is_generic ) {
					++$skipped;
					continue;
				}

				$archived = array_merge(
					$value,
					[
						'state'             => 'superseded',
						'superseded_at'     => $now,
						'superseded_reason' => 'legacy_unresolved_audit_lesson',
						'archived_to'       => 'incident_history',
						// Clear dual generic lesson text so agents do not treat it as active teaching.
						'correction'        => '',
						'lesson'            => '',
						'legacy_correction' => mb_substr( $correction, 0, 500 ),
						'legacy_lesson'     => mb_substr( $lesson, 0, 500 ),
						'incident_history'  => [
							'cause_key'  => (string) ( $value['cause_key'] ?? '' ),
							'error_code' => (string) ( $value['error_code'] ?? '' ),
							'signature'  => (string) ( $value['signature'] ?? '' ),
							'trigger'    => (string) ( $value['trigger'] ?? '' ),
						],
					]
				);

				$id = Memory::put_typed(
					'feedback',
					(string) ( $row['scope'] ?? 'audit' ),
					$key,
					(string) ( $row['name'] ?? $row['topic'] ?? 'Superseded audit incident' ),
					$archived,
					(float) ( $row['confidence'] ?? 1.0 ),
					[
						'topic'      => (string) ( $row['topic'] ?? 'Superseded audit incident' ),
						'status'     => 'stale',
						'precedence' => min( 400, (int) ( $row['precedence'] ?? 400 ) ),
					]
				);
				if ( $id > 0 ) {
					++$migrated;
				} else {
					// Eligible row failed to update — leave migration unfinished.
					++$write_failed;
					++$skipped;
				}
			}

			if ( count( $rows ) < $page_size ) {
				break;
			}
		}

		if ( 0 === $write_failed ) {
			update_option( self::LEGACY_LESSON_MIGRATION_OPTION, '1', false );
		}

		return [
			'migrated'     => $migrated,
			'skipped'      => $skipped,
			'already_done' => false,
			'write_failed' => $write_failed,
		];
	}

	private static function safe_hash( mixed $value ): string {
		$value = is_scalar( $value ) ? strtolower( trim( (string) $value ) ) : '';
		return 1 === preg_match( '/^[a-f0-9]{64}$/', $value ) ? $value : '';
	}

	private static function safe_text( mixed $value ): string {
		return is_scalar( $value ) ? mb_substr( sanitize_text_field( (string) $value ), 0, 255 ) : '';
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
