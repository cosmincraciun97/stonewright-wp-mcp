<?php
/**
 * SPDX-FileCopyrightText: 2026 Ovation S.r.l. <dev@novamira.ai>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * Derived from includes/oauth/client-validation.php
 * Source SHA-256: aa9d865d5a8d7fa41f73f6740523c65a248294653ad8d99530f82759ae578523
 *
 * @package Stonewright\WpMcp
 */

declare( strict_types=1 );

namespace Stonewright\WpMcp\OAuth;

defined( 'ABSPATH' ) || exit;

/**
 * OAuth client registration and redirect safety controls.
 */
final class ClientValidation {

	/**
	 * @var list<string>
	 */
	public const ALLOWED_SCHEMES = [ 'https', 'claude', 'cursor', 'ms-onboarding-claude-code', 'chatgpt' ];

	public const DCR_RATE_LIMIT_PER_HOUR = 10;

	public const MAX_CLIENTS_PER_SITE = 50;

	public const MAX_CLIENTS_PER_IP = 10;

	public const STALE_UNUSED_CLIENT_TTL = 86400;

	public const ACTIVE_CLIENT_TTL = 14 * 86400;

	public const MAX_REDIRECT_URI_LENGTH = 2048;

	public const ENDPOINT_RATE_LIMIT_PER_MINUTE = 30;

	/**
	 * Return the filtered active-connection cap.
	 */
	public static function max_clients_per_site(): int {
		$cap = apply_filters( 'stonewright_oauth_max_clients', self::MAX_CLIENTS_PER_SITE );
		return is_int( $cap ) && $cap > 0 ? $cap : self::MAX_CLIENTS_PER_SITE;
	}

	/**
	 * Local/private redirect exceptions belong only to explicit local sites.
	 */
	public static function local_redirects_allowed(): bool {
		return 'local' === wp_get_environment_type();
	}

	/**
	 * Validate a Dynamic Client Registration redirect URI.
	 */
	public static function is_allowed_redirect_uri( string $uri, bool $dev_mode = false ): bool {
		if ( str_contains( $uri, '#' ) || strlen( $uri ) > self::MAX_REDIRECT_URI_LENGTH ) {
			return false;
		}

		$parsed = parse_url( $uri );
		if ( ! is_array( $parsed ) || ! isset( $parsed['scheme'] ) ) {
			return false;
		}

		$scheme = strtolower( (string) $parsed['scheme'] );
		if ( 'http' === $scheme ) {
			$host = self::normalize_uri_host( $parsed );
			return 'localhost' === $host || self::is_loopback_ip( $host );
		}

		if ( ! in_array( $scheme, self::ALLOWED_SCHEMES, true ) ) {
			return false;
		}

		if ( 'https' !== $scheme ) {
			return true;
		}

		$host = self::normalize_uri_host( $parsed );
		if ( '' === $host ) {
			return false;
		}

		$raw_ip = filter_var( $host, FILTER_VALIDATE_IP );
		if ( false !== $raw_ip ) {
			return ! self::is_blocked_ip( (string) $raw_ip, $dev_mode );
		}

		if ( 'localhost' === $host || str_ends_with( $host, '.localhost' ) || str_ends_with( $host, '.local' ) ) {
			return $dev_mode;
		}

		$resolved_ips = self::resolve_host_ips( $host );
		if ( null === $resolved_ips ) {
			return true;
		}

		foreach ( $resolved_ips as $ip ) {
			if ( self::is_blocked_ip( $ip, $dev_mode ) ) {
				return false;
			}
		}

		return true;
	}

	/**
	 * Normalize a parsed URI host, including bracketed IPv6 literals.
	 *
	 * @param array<array-key, mixed> $parsed Parsed URI.
	 */
	public static function normalize_uri_host( array $parsed ): string {
		$host = strtolower( (string) ( $parsed['host'] ?? '' ) );
		if ( str_starts_with( $host, '[' ) && str_ends_with( $host, ']' ) ) {
			$inner = substr( $host, 1, -1 );
			if ( false !== filter_var( $inner, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6 ) ) {
				return $inner;
			}
		}

		return $host;
	}

	/**
	 * Resolve valid A and AAAA records.
	 *
	 * @return list<string>|null
	 */
	public static function resolve_host_ips( string $host ): ?array {
		$ips  = [];
		$ipv4 = gethostbynamel( $host );
		if ( is_array( $ipv4 ) ) {
			foreach ( $ipv4 as $ip ) {
				if ( false !== filter_var( $ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4 ) ) {
					$ips[] = $ip;
				}
			}
		}

		if ( function_exists( 'dns_get_record' ) ) {
			$records = dns_get_record( $host, DNS_AAAA );
			if ( is_array( $records ) ) {
				foreach ( $records as $record ) {
					$ip = is_array( $record ) ? ( $record['ipv6'] ?? null ) : null;
					if ( is_string( $ip ) && false !== filter_var( $ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6 ) ) {
						$ips[] = $ip;
					}
				}
			}
		}

		$ips = array_values( array_unique( $ips ) );
		return [] === $ips ? null : $ips;
	}

	/**
	 * Return whether an IP is private, reserved, loopback, or otherwise blocked.
	 */
	public static function is_blocked_ip( string $ip, bool $dev_mode ): bool {
		$ip = self::normalize_ip_literal( $ip );
		if ( '' === $ip ) {
			return true;
		}

		if ( self::is_ipv4_mapped_ipv6( $ip ) ) {
			return true;
		}

		if ( $dev_mode && self::is_loopback_ip( $ip ) ) {
			return false;
		}

		return false === filter_var(
			$ip,
			FILTER_VALIDATE_IP,
			FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE
		);
	}

	/**
	 * Normalize an IPv4 or IPv6 literal.
	 */
	public static function normalize_ip_literal( string $ip ): string {
		$ip = strtolower( trim( $ip ) );
		if ( str_starts_with( $ip, '[' ) && str_ends_with( $ip, ']' ) ) {
			$ip = substr( $ip, 1, -1 );
		}

		return false !== filter_var( $ip, FILTER_VALIDATE_IP ) ? $ip : '';
	}

	/**
	 * Detect IPv4-mapped IPv6 literals consistently across supported PHP versions.
	 */
	public static function is_ipv4_mapped_ipv6( string $ip ): bool {
		$normalized = self::normalize_ip_literal( $ip );
		if ( '' === $normalized ) {
			return false;
		}

		$packed = inet_pton( $normalized );
		return is_string( $packed )
			&& 16 === strlen( $packed )
			&& str_repeat( "\0", 10 ) === substr( $packed, 0, 10 )
			&& "\xff\xff" === substr( $packed, 10, 2 );
	}

	/**
	 * Return whether an IP literal is loopback.
	 */
	public static function is_loopback_ip( string $ip ): bool {
		$ip = self::normalize_ip_literal( $ip );
		if ( false !== filter_var( $ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4 ) ) {
			return str_starts_with( $ip, '127.' );
		}

		if ( false !== filter_var( $ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6 ) ) {
			return '::1' === $ip;
		}

		return false;
	}

	/**
	 * Increment the per-IP Dynamic Client Registration counter.
	 */
	public static function check_and_increment_rate_limit( string $client_ip ): bool {
		$key     = 'stonewright_oauth_dcr_' . hash( 'sha256', $client_ip );
		$current = (int) get_transient( $key );
		if ( $current >= self::DCR_RATE_LIMIT_PER_HOUR ) {
			return false;
		}

		set_transient( $key, $current + 1, HOUR_IN_SECONDS );
		return true;
	}

	/**
	 * Apply a fixed-window endpoint rate limit.
	 */
	public static function within_endpoint_rate_limit( string $bucket, string $client_ip ): bool {
		if ( '' === $client_ip ) {
			return true;
		}

		$key     = 'stonewright_oauth_rl_' . $bucket . '_' . hash( 'sha256', $client_ip );
		$current = (int) get_transient( $key );
		if ( $current >= self::ENDPOINT_RATE_LIMIT_PER_MINUTE ) {
			return false;
		}

		set_transient( $key, $current + 1, MINUTE_IN_SECONDS );
		return true;
	}

	/**
	 * Count clients used within the refresh-token lifetime.
	 */
	public static function active_client_count(): int {
		global $wpdb;

		$cutoff = gmdate( 'Y-m-d H:i:s', time() - self::ACTIVE_CLIENT_TTL );
		$sql    = $wpdb->prepare(
			"SELECT COUNT(*) FROM {$wpdb->prefix}stonewright_oauth_clients
			WHERE last_used_at IS NOT NULL AND last_used_at > %s",
			$cutoff
		);

		return is_string( $sql ) ? (int) $wpdb->get_var( $sql ) : 0; // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
	}

	/**
	 * Count registered clients for a source IP hash.
	 */
	public static function client_count_for_ip( string $client_ip ): int {
		global $wpdb;

		$sql = $wpdb->prepare(
			"SELECT COUNT(*) FROM {$wpdb->prefix}stonewright_oauth_clients WHERE registered_by_ip_hash = %s",
			hash( 'sha256', $client_ip )
		);

		return is_string( $sql ) ? (int) $wpdb->get_var( $sql ) : 0; // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
	}

	/**
	 * Delete abandoned and expired client registrations.
	 */
	public static function prune_dead_clients(): void {
		global $wpdb;

		$pending_cutoff = gmdate( 'Y-m-d H:i:s', time() - self::STALE_UNUSED_CLIENT_TTL );
		$used_cutoff    = gmdate( 'Y-m-d H:i:s', time() - self::ACTIVE_CLIENT_TTL );
		$sql            = $wpdb->prepare(
			"DELETE FROM {$wpdb->prefix}stonewright_oauth_clients
			WHERE (last_used_at IS NULL AND created_at < %s AND admin_created = 0)
				OR (last_used_at IS NOT NULL AND last_used_at < %s)",
			$pending_cutoff,
			$used_cutoff
		);

		if ( is_string( $sql ) ) {
			$wpdb->query( $sql ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		}
	}
}
