<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\Security;

/**
 * Domain-lock: records the WordPress home origin the first time AI abilities
 * are enabled. On each boot, verifies the current home origin still matches.
 *
 * A mismatch must NEVER overwrite operator enablement intent. It records a
 * redacted mismatch fingerprint, blocks effective enablement (via
 * PluginEffectiveState), and surfaces a persistent admin notice + rebind UI.
 *
 * Comparison uses the configured WordPress home URL (home_url), not untrusted
 * forwarded Host headers.
 *
 * @stonewright-status stable
 */
final class DomainLock {

	private const OPTION_LOCKED   = 'stonewright_locked_domain';
	private const OPTION_MISMATCH = 'stonewright_domain_mismatch';
	private const OPTION_PRIOR    = 'stonewright_domain_lock_prior';

	/** Retention window for reversible rebind rollback (seconds). */
	public const PRIOR_RETENTION = 7 * DAY_IN_SECONDS;

	/**
	 * Record the current home origin as the locked domain.
	 * No-op if a domain is already locked.
	 */
	public static function lock(): void {
		if ( '' !== self::locked_domain() ) {
			return;
		}
		update_option( self::OPTION_LOCKED, self::current_origin(), false );
	}

	/**
	 * Returns true when the current home origin matches the locked domain,
	 * or when no domain has been locked yet.
	 */
	public static function check(): bool {
		$stored = self::locked_domain();
		if ( '' === $stored ) {
			return true;
		}
		return self::normalize_origin( $stored ) === self::current_origin();
	}

	/**
	 * Whether destructive clear is allowed.
	 *
	 * Clear is refused while a live origin mismatch is active so the next boot
	 * cannot silently rebind the new origin without rebind/rollback confirmation.
	 */
	public static function can_clear(): bool {
		return self::check();
	}

	/**
	 * Clear the stored lock and any mismatch/prior records (destructive; prefer rebind).
	 *
	 * Refuses while origin mismatches — use rebind() or rollback() instead.
	 *
	 * @return true|\WP_Error True on success.
	 */
	public static function reset() {
		if ( ! self::can_clear() ) {
			return new \WP_Error(
				'stonewright_domain_reset_blocked',
				__(
					'Cannot clear domain lock while the site origin is mismatched. Use Review and rebind, or Restore prior domain binding.',
					'stonewright'
				)
			);
		}

		delete_option( self::OPTION_LOCKED );
		delete_option( self::OPTION_MISMATCH );
		delete_option( self::OPTION_PRIOR );

		return true;
	}

	/**
	 * Returns the locked domain option (raw stored string), or empty if unset.
	 */
	public static function locked_domain(): string {
		return (string) get_option( self::OPTION_LOCKED, '' );
	}

	/**
	 * Normalize a WordPress URL to a stable origin for comparison.
	 *
	 * Rules: scheme lowercased, host lowercased, IDN → punycode when available,
	 * default ports 80/443 stripped, path preserved with stable trailing slash
	 * (root becomes `/`; subdirectory paths keep a trailing slash).
	 */
	public static function normalize_origin( string $url ): string {
		$url = trim( $url );
		if ( '' === $url ) {
			return '';
		}

		$parts = wp_parse_url( $url );
		if ( ! is_array( $parts ) || empty( $parts['host'] ) ) {
			// Fallback: treat opaque strings as host-only after lowercasing.
			return strtolower( rtrim( $url, '/' ) ) . '/';
		}

		$scheme = isset( $parts['scheme'] ) ? strtolower( (string) $parts['scheme'] ) : 'https';
		$host   = strtolower( (string) $parts['host'] );

		if ( function_exists( 'idn_to_ascii' ) ) {
			$flags   = defined( 'IDNA_DEFAULT' ) ? (int) constant( 'IDNA_DEFAULT' ) : 0;
			$variant = defined( 'INTL_IDNA_VARIANT_UTS46' ) ? (int) constant( 'INTL_IDNA_VARIANT_UTS46' ) : 1;
			$ascii   = @idn_to_ascii( $host, $flags, $variant );
			if ( is_string( $ascii ) && '' !== $ascii ) {
				$host = strtolower( $ascii );
			}
		}

		$port = isset( $parts['port'] ) ? (int) $parts['port'] : 0;
		if (
			( 'http' === $scheme && 80 === $port )
			|| ( 'https' === $scheme && 443 === $port )
		) {
			$port = 0;
		}

		$path = isset( $parts['path'] ) ? (string) $parts['path'] : '/';
		$path = '/' . ltrim( $path, '/' );
		// Collapse empty path and ensure trailing slash for directory-style homes.
		if ( '/' === $path || '' === trim( $path, '/' ) ) {
			$path = '/';
		} else {
			$path = rtrim( $path, '/' ) . '/';
		}

		$authority = $host;
		if ( $port > 0 ) {
			$authority .= ':' . (string) $port;
		}

		return $scheme . '://' . $authority . $path;
	}

	/**
	 * Current configured WordPress home origin (not the request Host header).
	 */
	public static function current_origin(): string {
		return self::normalize_origin( home_url( '/' ) );
	}

	/**
	 * Redacted origin for admin UI (host kept, userinfo/query stripped).
	 */
	public static function redact_origin( string $url ): string {
		$normalized = self::normalize_origin( $url );
		if ( '' === $normalized ) {
			return '';
		}
		$parts = wp_parse_url( $normalized );
		if ( ! is_array( $parts ) || empty( $parts['host'] ) ) {
			return '[redacted]';
		}
		$scheme = isset( $parts['scheme'] ) ? (string) $parts['scheme'] : 'https';
		$host   = (string) $parts['host'];
		$port   = isset( $parts['port'] ) ? (int) $parts['port'] : 0;
		$path   = isset( $parts['path'] ) ? (string) $parts['path'] : '/';
		$out    = $scheme . '://' . $host;
		if ( $port > 0 ) {
			$out .= ':' . (string) $port;
		}
		return $out . $path;
	}

	/**
	 * Record a domain mismatch without touching operator enablement intent.
	 *
	 * Only writes when no mismatch record exists or the fingerprint changes,
	 * so boot-time checks do not rewrite options on every request.
	 *
	 * @return array<string, mixed> Stored mismatch payload.
	 */
	public static function record_mismatch(): array {
		$locked      = self::locked_domain();
		$current     = self::current_origin();
		$fingerprint = hash(
			'sha256',
			self::normalize_origin( $locked ) . '|' . $current
		);

		$existing = self::mismatch();
		if (
			is_array( $existing )
			&& isset( $existing['fingerprint'] )
			&& (string) $existing['fingerprint'] === $fingerprint
		) {
			return $existing;
		}

		$payload = [
			'locked_redacted'  => self::redact_origin( $locked ),
			'current_redacted' => self::redact_origin( $current ),
			'fingerprint'      => $fingerprint,
			'recorded_at'      => time(),
			'reason'           => 'domain_mismatch',
		];
		update_option( self::OPTION_MISMATCH, $payload, false );
		return $payload;
	}

	/**
	 * @return array<string, mixed>|null
	 */
	public static function mismatch(): ?array {
		$raw = get_option( self::OPTION_MISMATCH, null );
		return is_array( $raw ) ? $raw : null;
	}

	public static function clear_mismatch(): void {
		delete_option( self::OPTION_MISMATCH );
	}

	/**
	 * Snapshot current lock, bind to the live origin, audit the rebind.
	 *
	 * Requires manage_options (caller must gate).
	 *
	 * @return bool|\WP_Error True on success.
	 */
	public static function rebind() {
		$previous = self::locked_domain();
		$current  = self::current_origin();
		if ( '' === $current ) {
			return new \WP_Error(
				'stonewright_domain_rebind_empty',
				__( 'Cannot rebind: current site origin is empty.', 'stonewright' )
			);
		}

		if ( '' !== $previous ) {
			update_option(
				self::OPTION_PRIOR,
				[
					'origin'     => $previous,
					'snapshotted_at' => time(),
					'expires_at' => time() + self::PRIOR_RETENTION,
				],
				false
			);
		}

		update_option( self::OPTION_LOCKED, $current, false );
		self::clear_mismatch();

		AuditLog::record(
			'admin:domain-lock-rebind',
			[
				'previous_redacted' => self::redact_origin( $previous ),
				'new_redacted'      => self::redact_origin( $current ),
				'_meta'             => [
					'operation_class' => 'security',
					'resource_type'   => 'domain_lock',
					'resource_ref'    => 'rebind',
				],
			],
			'ok'
		);

		return true;
	}

	/**
	 * Restore the prior binding when still within the retention window.
	 *
	 * @return bool|\WP_Error True on success.
	 */
	public static function rollback() {
		$prior = get_option( self::OPTION_PRIOR, null );
		if ( ! is_array( $prior ) ) {
			return new \WP_Error(
				'stonewright_domain_rollback_empty',
				__( 'No prior domain binding is available to restore.', 'stonewright' )
			);
		}

		$expires = (int) ( $prior['expires_at'] ?? 0 );
		if ( $expires > 0 && time() > $expires ) {
			delete_option( self::OPTION_PRIOR );
			return new \WP_Error(
				'stonewright_domain_rollback_expired',
				__( 'The prior domain binding retention window has expired.', 'stonewright' )
			);
		}

		$origin = (string) ( $prior['origin'] ?? '' );
		if ( '' === $origin ) {
			delete_option( self::OPTION_PRIOR );
			return new \WP_Error(
				'stonewright_domain_rollback_invalid',
				__( 'The prior domain binding is invalid.', 'stonewright' )
			);
		}

		$current = self::locked_domain();
		update_option( self::OPTION_LOCKED, self::normalize_origin( $origin ), false );
		delete_option( self::OPTION_PRIOR );

		// If live origin no longer matches restored lock, record mismatch again.
		if ( ! self::check() ) {
			self::record_mismatch();
		} else {
			self::clear_mismatch();
		}

		AuditLog::record(
			'admin:domain-lock-rollback',
			[
				'restored_redacted' => self::redact_origin( $origin ),
				'from_redacted'     => self::redact_origin( $current ),
				'_meta'             => [
					'operation_class' => 'security',
					'resource_type'   => 'domain_lock',
					'resource_ref'    => 'rollback',
				],
			],
			'ok'
		);

		return true;
	}

	/**
	 * Whether a rollback action is currently available.
	 */
	public static function can_rollback(): bool {
		$prior = get_option( self::OPTION_PRIOR, null );
		if ( ! is_array( $prior ) ) {
			return false;
		}
		$expires = (int) ( $prior['expires_at'] ?? 0 );
		if ( $expires > 0 && time() > $expires ) {
			return false;
		}
		return '' !== (string) ( $prior['origin'] ?? '' );
	}

	/**
	 * Admin/status export (no secrets).
	 *
	 * @return array{
	 *   locked: string,
	 *   current: string,
	 *   matches: bool,
	 *   mismatch: array<string, mixed>|null,
	 *   can_rollback: bool
	 * }
	 */
	public static function status(): array {
		$locked = self::locked_domain();
		return [
			'locked'       => '' !== $locked ? self::redact_origin( $locked ) : '',
			'current'      => self::redact_origin( self::current_origin() ),
			'matches'      => self::check(),
			'mismatch'     => self::mismatch(),
			'can_rollback' => self::can_rollback(),
		];
	}
}
