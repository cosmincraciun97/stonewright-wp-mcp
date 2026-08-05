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
		$outcome  = self::outcome( $status, $category, $meta );
		if ( self::OUTCOME_SUCCESS !== $outcome && self::CATEGORY_WRITE === $category && '' !== (string) ( $meta['verification_status'] ?? '' ) ) {
			$category = self::CATEGORY_VERIFY;
		}
		if ( self::OUTCOME_FAILED === $outcome && 'failed' === strtolower( (string) ( $meta['rollback_status'] ?? '' ) ) ) {
			$category = self::CATEGORY_ROLLBACK;
		}

		$transaction_id = self::safe_text( self::first_scalar( $meta, $args, [ 'transaction_id', 'write_transaction_id' ] ), 96 );
		$change_set_id  = self::safe_text( self::first_scalar( $meta, $args, [ 'change_set_id' ] ), 96 );
		$verification_status = self::safe_text( self::first_scalar( $meta, $args, [ 'verification_status' ] ), 32 );
		$rollback_status     = self::safe_text( self::first_scalar( $meta, $args, [ 'rollback_status' ] ), 32 );
		$expected_verifier   = self::safe_text( self::first_scalar( $meta, $args, [ 'expected_verifier' ] ), 190 );
		$remediation_code    = self::safe_text( self::first_scalar( $meta, $args, [ 'remediation_code' ] ), 190 );
		$before_sha256       = self::fingerprint( self::first_scalar( $meta, $args, [ 'before_sha256' ] ) );
		$after_sha256        = self::fingerprint( self::first_scalar( $meta, $args, [ 'after_sha256' ] ) );
		$context_hash    = self::context_hash( $meta );
		$event_id       = self::event_id();
		$incident_id    = hash( 'sha256', implode( '|', [ $category, $ability_family, $code, $resource_key, $path, $cause, $strategy ] ) );
		$retry_after    = self::retry_after( $meta );

		return [
			'schema_version'          => self::SCHEMA_VERSION,
			'event_id'                => $event_id,
			'occurred_at'             => gmdate( 'c' ),
			'category'                => $category,
			'outcome'                 => $outcome,
			'severity_level'          => self::severity( $status, $outcome, $meta ),
			'ability'                 => self::safe_text( $ability, 190 ),
			'ability_family'          => $ability_family,
			'root_error_code'         => $code,
			'public_message'          => self::public_message( $args, $meta ),
			'resource_type'           => $resource_type,
			'resource_key_hash'       => $resource_key,
			'normalized_path'         => $path,
			'cause_fingerprint'       => $cause,
			'strategy_fingerprint'    => $strategy,
			'change_set_id'           => $change_set_id,
			'transaction_id'          => $transaction_id,
			'context_token_id_hash'   => $context_hash,
			'verification_status'     => $verification_status,
			'rollback_status'         => $rollback_status,
			'expected_verifier'       => $expected_verifier,
			'remediation_code'        => $remediation_code,
			'before_sha256'           => $before_sha256,
			'after_sha256'            => $after_sha256,
			'retryable'               => self::OUTCOME_RETRYABLE === $outcome,
			'retry_after_seconds'     => $retry_after,
			'incident_id'             => $incident_id,
			'redacted_details'        => self::redacted_details( $meta ),
		];
	}

	private static function event_id(): string {
		return function_exists( 'wp_generate_uuid4' )
			? wp_generate_uuid4()
			: substr( hash( 'sha256', uniqid( 'stonewright-', true ) ), 0, 36 );
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
		$raw = self::first_scalar( $meta, $args, [ 'root_error_code', 'error_code', 'code', 'wp_error_code' ] );
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

	private static function category( string $ability, string $status, string $code, array $meta ): string {
		$hint = strtolower( implode( '|', [ $ability, $code, (string) ( $meta['category'] ?? '' ), (string) ( $meta['operation_class'] ?? '' ) ] ) );
		if ( 'blocked' === strtolower( $status ) ) {
			return self::contains_any( $hint, [ 'permission', 'forbidden', 'capability', 'unauthorized' ] )
				? self::CATEGORY_PERMISSION
				: self::CATEGORY_SAFETY;
		}
		if ( 'auth' === strtolower( $status ) || str_starts_with( strtolower( $ability ), 'oauth/' ) || str_contains( $hint, 'oauth' ) ) {
			return self::CATEGORY_AUTH;
		}
		if ( self::contains_any( $hint, [ 'permission', 'forbidden', 'capability', 'unauthorized' ] ) ) {
			return self::CATEGORY_PERMISSION;
		}
		if ( self::contains_any( $hint, [ 'safety', 'blocked', 'confirmation', 'grant_required', 'read_only', 'rule_violation' ] ) ) {
			return self::CATEGORY_SAFETY;
		}
		if ( self::contains_any( $hint, [ 'busy', 'conflict', 'rate', 'retry', 'temporarily', 'lock' ] ) ) {
			return self::CATEGORY_TRANSIENT;
		}
		if ( self::contains_any( $hint, [ 'validation', 'schema', 'invalid', 'unsupported' ] ) ) {
			return self::CATEGORY_VALIDATION;
		}
		if ( self::contains_any( $hint, [ 'rollback', 'restore' ] ) || 'failed' === strtolower( (string) ( $meta['rollback_status'] ?? '' ) ) ) {
			return self::CATEGORY_ROLLBACK;
		}
		if ( self::contains_any( $hint, [ 'verify', 'readback', 'effect_verified' ] ) || 'failed' === strtolower( (string) ( $meta['verification_status'] ?? '' ) ) ) {
			return self::CATEGORY_VERIFY;
		}
		if ( self::contains_any( $hint, [ 'smtp', 'mail', 'external', 'newsman' ] ) ) {
			return self::CATEGORY_EXTERNAL;
		}
		return self::CATEGORY_WRITE;
	}

	private static function outcome( string $status, string $category, array $meta ): string {
		if ( 'ok' === strtolower( $status ) && ! in_array( strtolower( (string) ( $meta['verification_status'] ?? '' ) ), [ 'failed', 'missing' ], true ) && 'failed' !== strtolower( (string) ( $meta['rollback_status'] ?? '' ) ) ) {
			return self::OUTCOME_SUCCESS;
		}
		if ( self::CATEGORY_AUTH === $category && (int) ( $meta['http_status'] ?? 0 ) >= 500 ) {
			return self::OUTCOME_RETRYABLE;
		}
		if ( ! empty( $meta['retryable'] ) || self::CATEGORY_TRANSIENT === $category || 429 === (int) ( $meta['http_status'] ?? 0 ) ) {
			return self::OUTCOME_RETRYABLE;
		}
		if ( 'blocked' === strtolower( $status ) || self::CATEGORY_PERMISSION === $category || self::CATEGORY_SAFETY === $category || self::CATEGORY_AUTH === $category ) {
			return self::OUTCOME_BLOCKED;
		}
		return self::OUTCOME_FAILED;
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
	private static function public_message( array $args, array $meta ): string {
		foreach ( [ $meta['public_message'] ?? null, $meta['error_message'] ?? null, $args['message'] ?? null ] as $value ) {
			if ( is_scalar( $value ) && '' !== trim( (string) $value ) ) {
				$message = mb_substr( preg_replace( '/\s+/', ' ', sanitize_text_field( (string) $value ) ) ?? '', 0, 500 );
				$message = (string) preg_replace( '#\b(Bearer|Basic)\s+[A-Za-z0-9._~+/-=]+#i', '$1 [redacted]', $message );
				$message = (string) preg_replace( '~\b(client_secret|access_token|refresh_token|id_token|assertion|authorization|token|password|code)\b(\s*[:=]\s*)(?:"[^"]*"|\'[^\']*\'|[^\s&,;]+)~i', '$1$2[redacted]', $message );
				return $message;
			}
		}
		return '';
	}

	/** @return array<string, scalar> */
	private static function redacted_details( array $meta ): array {
		$allowed = [ 'rule_id', 'failed_action_index', 'element_id', 'setting_path', 'expected_type', 'actual_type', 'schema_version', 'remediation_code', 'expected_verifier', 'verification_status', 'rollback_status', 'coalesced_count', 'http_status' ];
		$out = [];
		foreach ( $allowed as $key ) {
			if ( isset( $meta[ $key ] ) && is_scalar( $meta[ $key ] ) ) {
				$out[ $key ] = is_string( $meta[ $key ] ) ? mb_substr( sanitize_text_field( (string) $meta[ $key ] ), 0, 255 ) : $meta[ $key ];
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
