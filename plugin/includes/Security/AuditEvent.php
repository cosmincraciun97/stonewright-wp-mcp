<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\Security;

/**
 * Normalizes one audit outcome into the permanent event contract.
 *
 * This is deliberately a pure value object factory. Persistence, incident
 * thresholds, and admin presentation belong to their own services so a bad
 * recorder can never invent a second taxonomy on the way to the database.
 */
final class AuditEvent {

	public const SCHEMA_VERSION = '2.0';

	public const CATEGORY_AUTH       = 'AUTH';
	public const CATEGORY_READ       = 'READ';
	public const CATEGORY_HEALTH     = 'HEALTH';
	public const CATEGORY_RUNTIME    = 'RUNTIME';
	public const CATEGORY_PERMISSION = 'PERMISSION';
	public const CATEGORY_SAFETY     = 'SAFETY';
	public const CATEGORY_VALIDATION = 'VALIDATION';
	public const CATEGORY_TRANSIENT  = 'TRANSIENT';
	public const CATEGORY_WRITE      = 'WRITE';
	public const CATEGORY_VERIFY     = 'VERIFY';
	public const CATEGORY_ROLLBACK   = 'ROLLBACK';
	public const CATEGORY_EXTERNAL   = 'EXTERNAL';
	public const CATEGORY_INCIDENT   = 'INCIDENT';

	public const OUTCOME_SUCCESS  = 'SUCCESS';
	public const OUTCOME_BLOCKED  = 'BLOCKED';
	public const OUTCOME_RETRYABLE = 'RETRYABLE';
	public const OUTCOME_FAILED   = 'FAILED';

	/** @var list<string> */
	public const CATEGORIES = [
		self::CATEGORY_AUTH,
		self::CATEGORY_READ,
		self::CATEGORY_HEALTH,
		self::CATEGORY_RUNTIME,
		self::CATEGORY_PERMISSION,
		self::CATEGORY_SAFETY,
		self::CATEGORY_VALIDATION,
		self::CATEGORY_TRANSIENT,
		self::CATEGORY_WRITE,
		self::CATEGORY_VERIFY,
		self::CATEGORY_ROLLBACK,
		self::CATEGORY_EXTERNAL,
		self::CATEGORY_INCIDENT,
	];

	/** @var list<string> */
	public const OUTCOMES = [
		self::OUTCOME_SUCCESS,
		self::OUTCOME_BLOCKED,
		self::OUTCOME_RETRYABLE,
		self::OUTCOME_FAILED,
	];

	/**
	 * @param array<string, mixed> $args Already redacted at the recorder boundary.
	 * @return array<string, mixed>
	 */
	public static function normalize( string $ability, array $args, string $status ): array {
		$meta       = is_array( $args['_meta'] ?? null ) ? $args['_meta'] : [];
		$code       = self::root_error_code( $ability, $status, $args, $meta );
		$path       = self::normalized_path( self::first_scalar( $meta, $args, [ 'normalized_path', 'path', 'resource_path' ] ) );
		$resource_type = self::safe_text( self::first_scalar( $meta, $args, [ 'resource_type' ] ), 96 );
		$resource_ref  = self::safe_resource_ref( self::first_scalar( $meta, $args, [ 'resource_ref', 'resource', 'post_id' ] ) );
		$schema_major  = self::schema_major( self::first_scalar( $meta, $args, [ 'schema_version', 'schema_major' ] ) );
		$ability_family = self::ability_family( $ability );
		$strategy       = self::fingerprint( self::first_scalar( $meta, $args, [ 'strategy_fingerprint', 'strategy_hash' ] ) );
		if ( '' === $strategy ) {
			$strategy = hash( 'sha256', implode( '|', [ $ability_family, $resource_type, $schema_major ] ) );
		}
		$resource_key = '' !== $resource_type || '' !== $resource_ref
			? hash( 'sha256', $resource_type . '|' . $resource_ref )
			: '';
		$cause = self::fingerprint( self::first_scalar( $meta, $args, [ 'cause_fingerprint', 'cause_hash' ] ) );
		if ( '' === $cause ) {
			$cause = hash( 'sha256', implode( '|', [ $ability_family, $code, $resource_key, $path, $schema_major, $strategy ] ) );
		}

		$category = self::category( $ability, $status, $code, $meta );
		if ( self::is_dry_run( $args, $meta ) && ! in_array( $category, [ self::CATEGORY_PERMISSION, self::CATEGORY_SAFETY, self::CATEGORY_AUTH, self::CATEGORY_ROLLBACK ], true ) ) {
			$category = self::CATEGORY_VALIDATION;
		}
		$outcome  = self::outcome( $status, $category, $meta, $code );
		if ( self::OUTCOME_SUCCESS !== $outcome && self::CATEGORY_WRITE === $category && '' !== (string) ( $meta['verification_status'] ?? '' ) ) {
			$category = self::CATEGORY_VERIFY;
		}
		if ( self::OUTCOME_FAILED === $outcome && 'failed' === strtolower( (string) ( $meta['rollback_status'] ?? '' ) ) ) {
			$category = self::CATEGORY_ROLLBACK;
		}

		// A successful row is not an error: it keeps no error code and no repair hint.
		$succeeded = self::OUTCOME_SUCCESS === $outcome;
		if ( $succeeded ) {
			$code = '';
		}
		// Every failed, blocked or retryable row says what happened, even when the caller gave no message.
		$message = self::public_message( $args, $meta );
		if ( ! $succeeded && '' === $message ) {
			$message = self::fallback_message( $code, $outcome );
		}

		$transaction_id = self::safe_text( self::first_scalar( $meta, $args, [ 'transaction_id', 'write_transaction_id' ] ), 96 );
		$change_set_id  = self::safe_text( self::first_scalar( $meta, $args, [ 'change_set_id' ] ), 96 );
		$repair_of      = self::safe_text( self::first_scalar( $meta, $args, [ 'repair_of' ] ), 96 );
		$supersedes     = self::safe_text( self::first_scalar( $meta, $args, [ 'supersedes' ] ), 96 );
		$verification_status = self::safe_text( self::first_scalar( $meta, $args, [ 'verification_status' ] ), 32 );
		$rollback_status     = self::safe_text( self::first_scalar( $meta, $args, [ 'rollback_status' ] ), 32 );
		$expected_verifier   = self::safe_text( self::first_scalar( $meta, $args, [ 'expected_verifier' ] ), 190 );
		$remediation_code    = $succeeded ? '' : self::safe_text( self::first_scalar( $meta, $args, [ 'remediation_code' ] ), 190 );
		$target_id           = self::target_id( $meta, $args );
		$before_sha256       = self::fingerprint( self::first_scalar( $meta, $args, [ 'before_sha256' ] ) );
		$after_sha256        = self::fingerprint( self::first_scalar( $meta, $args, [ 'after_sha256' ] ) );
		$context_hash    = self::context_hash( $meta );
		$event_id        = self::uuid( self::first_scalar( $meta, $args, [ 'event_id' ] ) );
		$correlation_id  = self::uuid( self::first_scalar( $meta, $args, [ 'correlation_id', 'request_id' ] ), false );
		$operation_id    = self::uuid( self::first_scalar( $meta, $args, [ 'operation_id' ] ), false );
		if ( '' === $operation_id ) {
			$operation_id = '' !== $correlation_id ? $correlation_id : $event_id;
		}
		$parent_event_id = self::uuid( self::first_scalar( $meta, $args, [ 'parent_event_id', 'parent_request_id' ] ), false );
		$attempt         = max( 1, min( 1000, (int) self::first_scalar( $meta, $args, [ 'attempt' ] ) ) );
		$lifecycle_phase = self::lifecycle_phase( self::first_scalar( $meta, $args, [ 'lifecycle_phase' ] ) );
		$terminal        = 'terminal' === $lifecycle_phase;
		$terminal_owner  = self::safe_text( self::first_scalar( $meta, $args, [ 'terminal_owner' ] ), 96 );
		if ( $terminal && '' === $terminal_owner ) {
			$terminal_owner = 'audit-log';
		}
		$idempotency_source = self::first_scalar( $meta, $args, [ 'idempotency_key' ] );
		if ( '' === $idempotency_source ) {
			$idempotency_source = $event_id;
		}
		$payload_hash = self::payload_hash( $args );
		$idempotency_key = hash(
			'sha256',
			implode( '|', [ $idempotency_source, $ability, $resource_type, $resource_ref, $payload_hash, $status, $operation_id ] )
		);
		// One cause is one incident: the same error from the same ability family on
		// the same kind of resource, whatever the record, path or category. A
		// successful row belongs to no incident.
		$incident_id    = $succeeded ? '' : hash( 'sha256', implode( '|', [ $ability_family, $code, $resource_type ] ) );
		$retry_after    = self::retry_after( $meta );
		$retry_limit    = 0;
		if ( self::is_write_busy( $code, $meta ) ) {
			if ( $retry_after <= 0 ) {
				$retry_after = 2;
			}
			$retry_limit = 3;
		}
		$execution_status = self::safe_text( self::first_scalar( $meta, $args, [ 'execution_status' ] ), 32 );
		if ( self::is_dry_run( $args, $meta ) && '' === $execution_status && 'ok' === strtolower( $status ) ) {
			$execution_status = 'planned';
		}
		$operation_class = self::safe_text( self::first_scalar( $meta, $args, [ 'operation_class' ] ), 96 );
		if ( '' === $operation_class ) {
			$operation_class = match ( $category ) {
				self::CATEGORY_HEALTH => 'HEALTH',
				self::CATEGORY_RUNTIME => 'EXECUTION',
				self::CATEGORY_READ => 'READ',
				self::CATEGORY_SAFETY, self::CATEGORY_PERMISSION => 'SAFETY',
				default => 'WRITE',
			};
		}

		return [
			'schema_version'          => self::SCHEMA_VERSION,
			'event_id'                => $event_id,
			'correlation_id'          => $correlation_id,
			'operation_id'            => $operation_id,
			'parent_event_id'         => $parent_event_id,
			'attempt'                 => $attempt,
			'idempotency_key'         => $idempotency_key,
			'lifecycle_phase'         => $lifecycle_phase,
			'terminal'                => $terminal,
			'terminal_owner'          => $terminal_owner,
			'backend'                 => 'plugin',
			'occurred_at'             => gmdate( 'c' ),
			'category'                => $category,
			'operation_class'         => $operation_class,
			'outcome'                 => $outcome,
			'severity_level'          => self::severity( $status, $outcome, $meta ),
			'ability'                 => self::safe_text( $ability, 190 ),
			'ability_family'          => $ability_family,
			'root_error_code'         => $code,
			'public_message'          => $message,
			'resource_type'           => $resource_type,
			'resource_key_hash'       => $resource_key,
			'normalized_path'         => $path,
			'cause_fingerprint'       => $cause,
			'strategy_fingerprint'    => $strategy,
			'change_set_id'           => $change_set_id,
			'repair_of'               => $repair_of,
			'transaction_id'          => $transaction_id,
			'context_token_id_hash'   => $context_hash,
			'verification_status'     => $verification_status,
			'execution_status'        => $execution_status,
			'rollback_status'         => $rollback_status,
			'expected_verifier'       => $expected_verifier,
			'remediation_code'        => $remediation_code,
			'before_sha256'           => $before_sha256,
			'after_sha256'            => $after_sha256,
			'retryable'               => self::OUTCOME_RETRYABLE === $outcome,
			'retry_after_seconds'     => $retry_after,
			'incident_id'             => $incident_id,
			'redacted_details'        => self::redacted_details(
				$meta,
				[
					'error_code'       => $succeeded ? '' : self::first_scalar( $meta, $args, [ 'error_code' ] ),
					'error_message'    => $succeeded ? '' : $message,
					'root_error_code'  => $code,
					'incident_id'      => $incident_id,
					'target_id'        => $target_id,
					'repair_of'        => $repair_of,
					'supersedes'       => $supersedes,
					'remediation_code' => $remediation_code,
					'retry_limit'      => $retry_limit,
					'execution_status' => $execution_status,
				],
				$succeeded ? [ 'error_code', 'error_message', 'root_error_code', 'remediation_code', 'incident_id' ] : []
			),
		];
	}

	private static function uuid( string $value = '', bool $generate = true ): string {
		$value = strtolower( trim( $value ) );
		if ( 1 === preg_match( '/^[a-f0-9]{8}-[a-f0-9]{4}-[1-5][a-f0-9]{3}-[89ab][a-f0-9]{3}-[a-f0-9]{12}$/', $value ) ) {
			return $value;
		}
		if ( ! $generate ) {
			return '';
		}
		if ( function_exists( 'wp_generate_uuid4' ) ) {
			return wp_generate_uuid4();
		}
		$hash = hash( 'sha256', uniqid( 'stonewright-', true ) );
		return substr( $hash, 0, 8 ) . '-' . substr( $hash, 8, 4 ) . '-4' . substr( $hash, 13, 3 ) . '-8' . substr( $hash, 17, 3 ) . '-' . substr( $hash, 20, 12 );
	}

	private static function lifecycle_phase( string $value ): string {
		$value = sanitize_key( strtolower( trim( $value ) ) );
		return in_array( $value, [ 'started', 'progress', 'retry', 'terminal' ], true ) ? $value : 'terminal';
	}

	/** @param array<string, mixed> $meta @param array<string, mixed> $args @param list<string> $keys */
	private static function first_scalar( array $meta, array $args, array $keys ): string {
		foreach ( $keys as $key ) {
			if ( isset( $meta[ $key ] ) && is_scalar( $meta[ $key ] ) ) {
				return (string) $meta[ $key ];
			}
			if ( isset( $args[ $key ] ) && is_scalar( $args[ $key ] ) ) {
				return (string) $args[ $key ];
			}
		}
		return '';
	}

	private static function root_error_code( string $ability, string $status, array $args, array $meta ): string {
		$raw = self::recorded_error_code( $meta, $args );
		$raw = sanitize_key( strtolower( trim( $raw ) ) );
		if ( '' === $raw ) {
			return '';
		}
		if ( in_array( $raw, [ 'invalid_request', 'invalid_client', 'invalid_grant', 'unauthorized_client', 'unsupported_grant_type', 'server_error', 'temporarily_unavailable' ], true ) && ( 'auth' === strtolower( $status ) || str_starts_with( strtolower( $ability ), 'oauth/' ) ) ) {
			return $raw;
		}
		if ( str_starts_with( $raw, 'stonewright_' ) || str_starts_with( $raw, 'oauth_' ) || str_starts_with( $raw, 'rest_' ) || str_starts_with( $raw, 'http_' ) ) {
			return $raw;
		}
		return 'stonewright_' . $raw;
	}

	/**
	 * First non-empty error code a recorder attached to the row.
	 *
	 * A top-level `code` argument is an ability input (for example a PHP snippet
	 * digest), never an error code, so only `_meta.code` counts. Redaction markers
	 * are not codes either.
	 *
	 * @param array<string, mixed> $meta
	 * @param array<string, mixed> $args
	 */
	private static function recorded_error_code( array $meta, array $args ): string {
		foreach ( [ 'root_error_code', 'error_code', 'code', 'wp_error_code' ] as $key ) {
			$sources = 'code' === $key ? [ $meta ] : [ $meta, $args ];
			foreach ( $sources as $source ) {
				$value = isset( $source[ $key ] ) && is_scalar( $source[ $key ] ) ? trim( (string) $source[ $key ] ) : '';
				if ( '' !== $value && ! str_starts_with( $value, '[redacted' ) ) {
					return $value;
				}
			}
		}
		return '';
	}

	/**
	 * Error classes come from what the recorder reported: the error code, an
	 * explicit category, and the operation class. The ability name only selects
	 * a surface (OAuth, site health, PHP runtime); a word inside it never
	 * becomes an error class, so `blocks-insert` is not a lock error and
	 * `post-revision-restore` is not a rollback. Without an error class, the
	 * ability's declared read/write nature decides between READ and WRITE.
	 *
	 * @param array<string, mixed> $meta
	 */
	private static function category( string $ability, string $status, string $code, array $meta ): string {
		$status  = strtolower( $status );
		$ability = strtolower( $ability );
		$signal  = strtolower( implode( '|', [ $code, (string) ( $meta['category'] ?? '' ), (string) ( $meta['operation_class'] ?? '' ) ] ) );
		if ( 'blocked' === $status ) {
			return self::contains_any( $signal, [ 'permission', 'forbidden', 'capability', 'unauthorized' ] )
				? self::CATEGORY_PERMISSION
				: self::CATEGORY_SAFETY;
		}
		if ( 'auth' === $status || str_starts_with( $ability, 'oauth/' ) || str_contains( $ability, 'oauth' ) || str_contains( $signal, 'oauth' ) ) {
			return self::CATEGORY_AUTH;
		}
		if ( self::contains_any( $signal, [ 'permission', 'forbidden', 'capability', 'unauthorized' ] ) ) {
			return self::CATEGORY_PERMISSION;
		}
		// A security check (a confirmation token being verified) is a safety row, not a write.
		if ( str_starts_with( $ability, 'security.' ) ) {
			return self::CATEGORY_SAFETY;
		}
		if ( self::contains_any( $signal, [ 'safety', 'blocked', 'confirmation', 'grant_required', 'read_only', 'rule_violation', 'css_classes_not_approved', 'not_approved' ] ) ) {
			return self::CATEGORY_SAFETY;
		}
		if ( 'failed' === strtolower( (string) ( $meta['rollback_status'] ?? '' ) ) || self::contains_any( $signal, [ 'rollback', 'restore' ] ) ) {
			return self::CATEGORY_ROLLBACK;
		}
		if ( self::is_write_busy( $code, $meta ) || ( self::has_transient_token( $signal ) && ! self::refuses_identical_retry( $code, $meta ) ) ) {
			return self::CATEGORY_TRANSIENT;
		}
		if ( self::contains_any( $signal, [ 'validation', 'schema', 'invalid', 'unsupported' ] ) ) {
			return self::CATEGORY_VALIDATION;
		}
		if ( self::contains_any( $signal, [ 'verify', 'readback', 'effect_verified' ] ) || 'failed' === strtolower( (string) ( $meta['verification_status'] ?? '' ) ) ) {
			return self::CATEGORY_VERIFY;
		}
		if ( self::contains_any( $signal, [ 'smtp', 'mail', 'external', 'newsman' ] ) ) {
			return self::CATEGORY_EXTERNAL;
		}
		if ( self::contains_any( $ability . '|' . $signal, [ 'site-health', 'health-check', 'health-test', '/health', ' health' ] ) ) {
			return self::CATEGORY_HEALTH;
		}
		if ( self::contains_any( $ability . '|' . $signal, [ 'php-execute', 'runtime-execute', 'runtime_execution' ] ) ) {
			return self::CATEGORY_RUNTIME;
		}
		$declared = strtolower( (string) ( $meta['operation_kind'] ?? '' ) );
		if ( 'read' === $declared ) {
			return self::CATEGORY_READ;
		}
		if ( 'write' === $declared ) {
			return self::CATEGORY_WRITE;
		}
		// Rows from recorders that declare nothing keep the read-name convention.
		if ( self::contains_any( $ability, [ '-get', '-list', '-status', '-search', '-inspect', '-describe', '-preview', '/read' ] ) ) {
			return self::CATEGORY_READ;
		}
		return self::CATEGORY_WRITE;
	}

	/**
	 * Lock, busy, and conflict errors are matched as whole words of the code,
	 * so a code that mentions "blocks" is not a lock error.
	 */
	private static function has_transient_token( string $signal ): bool {
		$tokens = preg_split( '/[^a-z0-9]+/', strtolower( $signal ) );
		$tokens = is_array( $tokens ) ? $tokens : [];
		return [] !== array_intersect( $tokens, [ 'busy', 'conflict', 'temporarily', 'lock', 'locked', 'deadlock' ] );
	}

	/** @param array<string, mixed> $args */
	private static function payload_hash( array $args ): string {
		$copy = $args;
		$identity_keys = [
			'event_id',
			'correlation_id',
			'request_id',
			'operation_id',
			'parent_event_id',
			'parent_request_id',
			'attempt',
			'idempotency_key',
			'lifecycle_phase',
			'terminal_owner',
		];
		foreach ( $identity_keys as $key ) {
			unset( $copy[ $key ] );
		}
		if ( isset( $copy['_meta'] ) && is_array( $copy['_meta'] ) ) {
			foreach ( $identity_keys as $key ) {
				unset( $copy['_meta'][ $key ] );
			}
		}
		$encoded = wp_json_encode( self::canonicalize( $copy ), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
		return hash( 'sha256', is_string( $encoded ) ? $encoded : '' );
	}

	private static function canonicalize( mixed $value ): mixed {
		if ( ! is_array( $value ) ) {
			return $value;
		}
		if ( array_is_list( $value ) ) {
			return array_map( [ self::class, 'canonicalize' ], $value );
		}
		ksort( $value );
		foreach ( $value as $key => $item ) {
			$value[ $key ] = self::canonicalize( $item );
		}
		return $value;
	}

	private static function outcome( string $status, string $category, array $meta, string $code = '' ): string {
		if ( 'ok' === strtolower( $status ) && ! in_array( strtolower( (string) ( $meta['verification_status'] ?? '' ) ), [ 'failed', 'missing' ], true ) && 'failed' !== strtolower( (string) ( $meta['rollback_status'] ?? '' ) ) ) {
			return self::OUTCOME_SUCCESS;
		}
		if ( self::refuses_identical_retry( $code, $meta ) ) {
			if ( 'blocked' === strtolower( $status ) || self::CATEGORY_PERMISSION === $category || self::CATEGORY_SAFETY === $category || self::CATEGORY_AUTH === $category ) {
				return self::OUTCOME_BLOCKED;
			}
			return self::OUTCOME_FAILED;
		}
		if ( self::CATEGORY_AUTH === $category && (int) ( $meta['http_status'] ?? 0 ) >= 500 ) {
			return self::OUTCOME_RETRYABLE;
		}
		if ( ! empty( $meta['retryable'] ) || self::CATEGORY_TRANSIENT === $category || 429 === (int) ( $meta['http_status'] ?? 0 ) || self::is_write_busy( $code, $meta ) ) {
			return self::OUTCOME_RETRYABLE;
		}
		if ( 'blocked' === strtolower( $status ) || self::CATEGORY_PERMISSION === $category || self::CATEGORY_SAFETY === $category || self::CATEGORY_AUTH === $category ) {
			return self::OUTCOME_BLOCKED;
		}
		return self::OUTCOME_FAILED;
	}

	/** @param array<string, mixed> $args @param array<string, mixed> $meta */
	private static function is_dry_run( array $args, array $meta ): bool {
		if ( ! empty( $args['dry_run'] ) ) {
			return true;
		}
		return 'planned' === strtolower( (string) ( $meta['execution_status'] ?? '' ) )
			|| 'planned' === strtolower( (string) ( $meta['verification_status'] ?? '' ) );
	}

	/** @param array<string, mixed> $meta */
	private static function is_write_busy( string $code, array $meta ): bool {
		$haystack = strtolower( $code . '|' . (string) ( $meta['error_code'] ?? '' ) . '|' . (string) ( $meta['root_error_code'] ?? '' ) );
		return str_contains( $haystack, 'write_busy' );
	}

	/** @param array<string, mixed> $meta */
	private static function refuses_identical_retry( string $code, array $meta ): bool {
		$haystack = strtolower( $code . '|' . (string) ( $meta['error_code'] ?? '' ) . '|' . (string) ( $meta['root_error_code'] ?? '' ) );
		foreach ( [ 'settings_invalid', 'invalid_schema', 'schema_missing', 'css_classes_not_approved', 'read_only_violation', 'php_parse_error', 'parse_error' ] as $marker ) {
			if ( str_contains( $haystack, $marker ) ) {
				return true;
			}
		}
		return false;
	}

	private static function severity( string $status, string $outcome, array $meta ): string {
		if ( self::OUTCOME_FAILED === $outcome && 'failed' === strtolower( (string) ( $meta['rollback_status'] ?? '' ) ) ) {
			return 'critical';
		}
		if ( self::OUTCOME_FAILED === $outcome ) {
			return 'error';
		}
		if ( self::OUTCOME_RETRYABLE === $outcome ) {
			return (int) ( $meta['http_status'] ?? 0 ) >= 500 ? 'error' : 'warning';
		}
		if ( self::OUTCOME_BLOCKED === $outcome || 'auth' === strtolower( $status ) ) {
			return 'warning';
		}
		return 'info';
	}

	private static function ability_family( string $ability ): string {
		$parts = explode( '/', strtolower( sanitize_text_field( $ability ) ), 2 );
		$slug  = $parts[1] ?? $parts[0];
		$bits  = array_values( array_filter( explode( '-', $slug ), static fn ( string $bit ): bool => '' !== $bit ) );
		return implode( '-', array_slice( $bits, 0, min( 2, count( $bits ) ) ) );
	}

	private static function schema_major( string $value ): string {
		if ( 1 === preg_match( '/^(\d+)/', trim( $value ), $matches ) ) {
			return (string) $matches[1];
		}
		return '';
	}

	private static function fingerprint( string $value ): string {
		$value = strtolower( trim( $value ) );
		return 1 === preg_match( '/^[a-f0-9]{64}$/', $value ) ? $value : '';
	}

	private static function normalized_path( string $value ): string {
		$value = str_replace( '\\', '/', sanitize_text_field( $value ) );
		$value = preg_replace( '#/{2,}#', '/', $value ) ?? $value;
		$value = ltrim( $value, '/' );
		$parts = [];
		foreach ( explode( '/', $value ) as $part ) {
			if ( '' === $part || '.' === $part || '..' === $part ) {
				continue;
			}
			$parts[] = sanitize_file_name( $part );
		}
		if ( count( $parts ) > 6 ) {
			$parts = array_slice( $parts, -6 );
		}
		return mb_substr( implode( '/', $parts ), 0, 255 );
	}

	private static function safe_resource_ref( string $value ): string {
		$value = str_replace( '\\', '/', sanitize_text_field( $value ) );
		if ( str_starts_with( $value, '/' ) || preg_match( '/^[A-Za-z]:\//', $value ) ) {
			$value = basename( $value );
		}
		return mb_substr( $value, 0, 255 );
	}

	private static function safe_text( string $value, int $length ): string {
		return mb_substr( sanitize_text_field( $value ), 0, $length );
	}

	private static function context_hash( array $meta ): string {
		$value = self::first_scalar( $meta, [], [ 'context_token_id', 'context_id', 'context_token_hash' ] );
		if ( '' === $value ) {
			return '';
		}
		return hash( 'sha256', $value );
	}

	private static function retry_after( array $meta ): int {
		foreach ( [ 'retry_after_seconds', 'retry_after' ] as $key ) {
			if ( isset( $meta[ $key ] ) && is_scalar( $meta[ $key ] ) ) {
				return max( 0, min( 86400, (int) $meta[ $key ] ) );
			}
		}
		return 0;
	}

	/** @param array<string, mixed> $args @param array<string, mixed> $meta */
	/** Readable message for a row whose caller supplied none. */
	private static function fallback_message( string $code, string $outcome ): string {
		$what = match ( $outcome ) {
			self::OUTCOME_BLOCKED   => 'The call was blocked',
			self::OUTCOME_RETRYABLE => 'The call failed temporarily',
			default                 => 'The call failed',
		};
		return '' !== $code ? sprintf( '%s (%s).', $what, $code ) : $what . ' without an error code.';
	}

	private static function public_message( array $args, array $meta ): string {
		foreach ( [ $meta['public_message'] ?? null, $meta['error_message'] ?? null, $args['message'] ?? null ] as $value ) {
			if ( is_scalar( $value ) && '' !== trim( (string) $value ) ) {
				return self::redact_public_text( (string) $value, 500 );
			}
		}
		return '';
	}

	/**
	 * Bounded post/resource id from meta or args, including nested args.post_id / args.id.
	 *
	 * @param array<string, mixed> $meta
	 * @param array<string, mixed> $args
	 */
	private static function target_id( array $meta, array $args ): string {
		$direct = self::first_scalar( $meta, $args, [ 'target_id', 'post_id', 'id' ] );
		if ( '' !== $direct ) {
			return mb_substr( sanitize_text_field( $direct ), 0, 64 );
		}
		$nested = is_array( $args['args'] ?? null ) ? $args['args'] : [];
		foreach ( [ 'post_id', 'id' ] as $key ) {
			if ( isset( $nested[ $key ] ) && is_scalar( $nested[ $key ] ) && '' !== trim( (string) $nested[ $key ] ) ) {
				return mb_substr( sanitize_text_field( (string) $nested[ $key ] ), 0, 64 );
			}
		}
		return '';
	}

	private static function redact_public_text( string $value, int $length ): string {
		$message = mb_substr( preg_replace( '/\s+/', ' ', sanitize_text_field( $value ) ) ?? '', 0, $length );
		$message = (string) preg_replace( '#\b(Bearer|Basic)\s+[A-Za-z0-9._~+/-=]+#i', '$1 [redacted]', $message );
		return (string) preg_replace( '~\b(client_secret|access_token|refresh_token|id_token|assertion|authorization|token|password|code)\b(\s*[:=]\s*)(?:"[^"]*"|\'[^\']*\'|[^\s&,;]+)~i', '$1$2[redacted]', $message );
	}

	/**
	 * @param array<string, mixed>  $meta
	 * @param array<string, scalar> $computed
	 * @param list<string>          $omit Keys this row must not carry (error fields on a success).
	 * @return array<string, scalar>
	 */
	private static function redacted_details( array $meta, array $computed = [], array $omit = [] ): array {
		$allowed = [
			'rule_id',
			'failed_action_index',
			'element_id',
			'setting_path',
			'rejected_settings',
			'removed_settings',
			'expected_type',
			'actual_type',
			'schema_version',
			'remediation_code',
			'expected_verifier',
			'verification_status',
			'rollback_status',
			'coalesced_count',
			'http_status',
			'error_code',
			'error_message',
			'root_error_code',
			'incident_id',
			'target_id',
			'repair_of',
			'supersedes',
			'retry_limit',
			'execution_status',
		];
		$source = $meta;
		foreach ( $computed as $key => $value ) {
			if ( is_scalar( $value ) && '' !== trim( (string) $value ) ) {
				$source[ $key ] = $value;
			}
		}
		$out     = [];
		foreach ( $allowed as $key ) {
			if ( in_array( $key, $omit, true ) || ! isset( $source[ $key ] ) || ! is_scalar( $source[ $key ] ) ) {
				continue;
			}
			if ( ! is_string( $source[ $key ] ) ) {
				$out[ $key ] = $source[ $key ];
				continue;
			}
			$max = 'error_message' === $key ? 500 : 255;
			$value = 'error_message' === $key
				? self::redact_public_text( (string) $source[ $key ], $max )
				: mb_substr( sanitize_text_field( (string) $source[ $key ] ), 0, $max );
			if ( '' !== $value ) {
				$out[ $key ] = $value;
			}
		}
		return $out;
	}

	/** @param list<string> $needles */
	private static function contains_any( string $haystack, array $needles ): bool {
		foreach ( $needles as $needle ) {
			if ( str_contains( $haystack, $needle ) ) {
				return true;
			}
		}
		return false;
	}
}
