<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\DesignSpec;

use Stonewright\WpMcp\Security\AuditLog;

/**
 * Security-hardened asset sideloader for DesignSpec image URLs.
 *
 * SSRF mitigations:
 *   - Scheme must be http or https. file://, gopher://, ftp://, data:, etc. are rejected.
 *   - Host must NOT resolve to a private/loopback IP range:
 *       127.0.0.0/8, 169.254.0.0/16, 10.0.0.0/8, 172.16.0.0/12,
 *       192.168.0.0/16, ::1, fc00::/7, fe80::/10,
 *       ::ffff:0:0/96 (IPv4-mapped), ::/96 (IPv4-compatible, RFC 4291 §2.5.5.1),
 *       2002::/16 (6to4, RFC 3056).
 *   - Uses wp_safe_remote_get (NOT wp_remote_get) which applies WP HTTP safety net.
 *   - Follows at most 2 redirects. sslverify: true.
 *   - Response must be HTTP 200.
 *   - Content-Type must begin with 'image/'.
 *   - Body size ≤ apply_filters('stonewright_asset_max_bytes', 10 MiB).
 *
 * NEVER use download_url() here — it follows unlimited redirects and does not
 * enforce Content-Type or size before writing to disk.
 *
 * DNS REBINDING LIMITATION (TOCTOU):
 *   check_host_is_public() resolves the hostname via gethostbyname() and validates
 *   the returned IP against private ranges. wp_safe_remote_get() then performs a
 *   second DNS resolution at fetch time. An attacker controlling the DNS record
 *   can return a public IP for the first lookup and a private IP for the second
 *   (classic DNS rebinding / TOCTOU race).
 *
 *   In production-safe mode this is mitigated by the confirmation-token-signed spec
 *   content: the spec — including all asset URLs — is cryptographically bound to
 *   the token before any sideload is attempted. An attacker would need to obtain a
 *   valid token for the tampered URL, which requires compromising the signing secret.
 *
 *   For untrusted-source ingestion paths processed without a subsequent
 *   confirmation token, DNS rebinding is a
 *   known limitation of the current implementation. The window is narrow (two DNS
 *   lookups within one HTTP request cycle) but non-zero in adversarial environments.
 *
 *   Possible future hardening: use cURL CURLOPT_RESOLVE to pin the hostname to the
 *   IP validated at check_host_is_public() time, eliminating the second lookup
 *   entirely. This would require bypassing wp_safe_remote_get() with a direct cURL
 *   handle, which introduces its own maintenance cost.
 */
final class AssetSideloader {

	/**
	 * Default maximum sideload body size (10 MiB).
	 */
	private const DEFAULT_MAX_BYTES = 10 * 1024 * 1024;

	/**
	 * Ability name used in audit log entries.
	 */
	private const AUDIT_ABILITY = 'stonewright/design.asset_sideload';

	/**
	 * Sideload a remote image URL into the WordPress media library.
	 *
	 * @param string $url Source URL (must be http/https, public host).
	 * @return int|\WP_Error Attachment ID on success; WP_Error on any failure.
	 */
	public static function sideload( string $url ): int|\WP_Error {
		// ── 1. Scheme check ──────────────────────────────────────────────────────
		$parsed = wp_parse_url( $url );
		if ( ! is_array( $parsed ) ) {
			return new \WP_Error(
				'stonewright_asset_invalid_url',
				__( 'Asset URL could not be parsed.', 'stonewright' ),
				[ 'url' => $url ]
			);
		}

		$scheme = strtolower( (string) ( $parsed['scheme'] ?? '' ) );
		if ( ! in_array( $scheme, [ 'http', 'https' ], true ) ) {
			return new \WP_Error(
				'stonewright_asset_blocked_scheme',
				sprintf( __( 'Asset URL scheme "%s" is not allowed. Only http and https are accepted.', 'stonewright' ), $scheme ),
				[ 'url' => $url, 'scheme' => $scheme ]
			);
		}

		// ── 2. Host SSRF check ───────────────────────────────────────────────────
		$host = (string) ( $parsed['host'] ?? '' );
		if ( '' === $host ) {
			return new \WP_Error(
				'stonewright_asset_invalid_url',
				__( 'Asset URL has no host component.', 'stonewright' ),
				[ 'url' => $url ]
			);
		}

		$host_check = self::check_host_is_public( $host );
		if ( is_wp_error( $host_check ) ) {
			return $host_check;
		}

		// ── 3. Fetch ─────────────────────────────────────────────────────────────
		$max_bytes = (int) apply_filters( 'stonewright_asset_max_bytes', self::DEFAULT_MAX_BYTES );

		$response = wp_safe_remote_get(
			$url,
			[
				'timeout'     => 10,
				'redirection' => 2,
				'sslverify'   => true,
			]
		);

		if ( is_wp_error( $response ) ) {
			return new \WP_Error(
				'stonewright_asset_fetch_failed',
				sprintf( __( 'Asset fetch failed: %s', 'stonewright' ), $response->get_error_message() ),
				[ 'url' => $url ]
			);
		}

		// ── 4. Response validation ────────────────────────────────────────────────
		$code = wp_remote_retrieve_response_code( $response );
		if ( 200 !== (int) $code ) {
			return new \WP_Error(
				'stonewright_asset_http_error',
				sprintf( __( 'Asset URL returned HTTP %d.', 'stonewright' ), $code ),
				[ 'url' => $url, 'code' => $code ]
			);
		}

		$content_type = wp_remote_retrieve_header( $response, 'content-type' );
		$content_type = is_array( $content_type ) ? (string) ( $content_type[0] ?? '' ) : (string) $content_type;
		// Accept "image/png", "image/jpeg; charset=binary", etc.
		if ( ! str_starts_with( $content_type, 'image/' ) ) {
			return new \WP_Error(
				'stonewright_asset_not_image',
				sprintf( __( 'Asset Content-Type "%s" is not an image.', 'stonewright' ), $content_type ),
				[ 'url' => $url, 'content_type' => $content_type ]
			);
		}

		$body = wp_remote_retrieve_body( $response );
		$size = strlen( $body );
		if ( $size > $max_bytes ) {
			return new \WP_Error(
				'stonewright_asset_too_large',
				sprintf( __( 'Asset body (%1$d bytes) exceeds the maximum allowed size (%2$d bytes).', 'stonewright' ), $size, $max_bytes ),
				[ 'url' => $url, 'size' => $size, 'max' => $max_bytes ]
			);
		}

		if ( 0 === $size ) {
			return new \WP_Error(
				'stonewright_asset_empty_body',
				__( 'Asset response body is empty.', 'stonewright' ),
				[ 'url' => $url ]
			);
		}

		// ── 5. Write to temp + sideload ──────────────────────────────────────────
		$tmp = wp_tempnam( basename( (string) ( $parsed['path'] ?? 'asset' ) ) );
		if ( false === $tmp || '' === $tmp ) {
			return new \WP_Error(
				'stonewright_asset_tempnam_failed',
				__( 'Could not create a temporary file for asset sideloading.', 'stonewright' )
			);
		}

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
		$written = file_put_contents( $tmp, $body );
		if ( false === $written || $written !== $size ) {
			@unlink( $tmp ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
			return new \WP_Error(
				'stonewright_asset_write_failed',
				__( 'Could not write asset to temporary file.', 'stonewright' ),
				[ 'url' => $url ]
			);
		}

		// Derive filename from URL path; sanitize it.
		$basename   = sanitize_file_name( basename( (string) ( $parsed['path'] ?? 'asset.png' ) ) );
		if ( '' === $basename ) {
			$basename = 'asset.png';
		}

		$file_array = [
			'name'     => $basename,
			'tmp_name' => $tmp,
		];

		// wp_handle_sideload() moves the temp file into the uploads dir.
		require_once ABSPATH . 'wp-admin/includes/file.php';
		require_once ABSPATH . 'wp-admin/includes/media.php';
		require_once ABSPATH . 'wp-admin/includes/image.php';

		$attachment_id = media_handle_sideload( $file_array, 0, basename( $basename, '.' . pathinfo( $basename, PATHINFO_EXTENSION ) ) );

		if ( is_wp_error( $attachment_id ) ) {
			return $attachment_id;
		}

		// ── 6. Mark orphan-discoverable ───────────────────────────────────────────
		// Operators can identify sideloaded attachments that became orphans (e.g. if the
		// ElementorWriter::write() call later fails) via a meta_query on this key.
		// There is no automatic cleanup — media_handle_sideload has no atomic undo.
		update_post_meta( $attachment_id, '_stonewright_sideloaded_at', time() );

		// ── 7. Audit log ─────────────────────────────────────────────────────────
		AuditLog::record(
			self::AUDIT_ABILITY,
			[
				'source_url'    => $url,
				'attachment_id' => $attachment_id,
				'size'          => $size,
				'content_type'  => $content_type,
			]
		);

		return $attachment_id;
	}

	/**
	 * Verify that $host is not a private/loopback address.
	 *
	 * Resolves the hostname to its first IP (gethostbyname) and checks it
	 * against known private ranges. IPv6 literals are also checked.
	 *
	 * TOCTOU / DNS REBINDING NOTE:
	 *   This method performs a DNS lookup and validates the resolved IP, but the
	 *   subsequent wp_safe_remote_get() call in sideload() performs its own
	 *   independent DNS resolution. Between these two lookups, an attacker
	 *   controlling the DNS TTL can swap the record to point at an internal address
	 *   (DNS rebinding). Defense relies on:
	 *   - confirmation-token-signed spec content in production-safe mode, which
	 *     binds asset URLs to a valid token before any sideload executes.
	 *   - For untrusted-source ingestion paths,
	 *     DNS rebinding is a known limitation of this implementation.
	 *   - Possible future hardening: use cURL CURLOPT_RESOLVE to force the fetch
	 *     to use the same IP that was validated here, eliminating the second lookup.
	 *
	 * @param string $host Hostname or IP from the URL.
	 * @return bool|\WP_Error
	 */
	private static function check_host_is_public( string $host ): bool|\WP_Error {
		// Strip IPv6 brackets: [::1] → ::1
		$bare_host = trim( $host, '[]' );

		// Fast path: reject known loopback names.
		$lower = strtolower( $bare_host );
		if ( in_array( $lower, [ 'localhost', 'ip6-localhost', 'ip6-loopback' ], true ) ) {
			return new \WP_Error(
				'stonewright_asset_blocked_host',
				sprintf( __( 'Asset host "%s" is not allowed (loopback).', 'stonewright' ), $host ),
				[ 'host' => $host ]
			);
		}

		// Resolve to IPv4 string if not already numeric.
		// gethostbyname() returns the original string if resolution fails — treat as public.
		$ip = filter_var( $bare_host, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4 | FILTER_FLAG_IPV6 )
			? $bare_host
			: gethostbyname( $bare_host );

		// IPv4 private range check.
		if ( filter_var( $ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4 ) ) {
			$blocked_ranges = [
				[ '127.0.0.0', 8 ],  // Loopback
				[ '169.254.0.0', 16 ],  // Link-local
				[ '10.0.0.0', 8 ],  // RFC 1918
				[ '172.16.0.0', 12 ],  // RFC 1918
				[ '192.168.0.0', 16 ],  // RFC 1918
			];
			foreach ( $blocked_ranges as [ $network, $prefix ] ) {
				if ( self::ipv4_in_cidr( $ip, $network, $prefix ) ) {
					return new \WP_Error(
						'stonewright_asset_blocked_host',
						sprintf( __( 'Asset host resolves to a private IP address "%s" which is not allowed.', 'stonewright' ), $ip ),
						[ 'host' => $host, 'resolved_ip' => $ip, 'blocked_range' => "{$network}/{$prefix}" ]
					);
				}
			}
		}

		// IPv6 private range check.
		if ( filter_var( $ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6 ) ) {
			$expanded = self::expand_ipv6( $ip );
			if ( null !== $expanded ) {
				if ( self::ipv6_is_private( $expanded ) ) {
					return new \WP_Error(
						'stonewright_asset_blocked_host',
						sprintf( __( 'Asset host resolves to a private IPv6 address "%s" which is not allowed.', 'stonewright' ), $ip ),
						[ 'host' => $host, 'resolved_ip' => $ip ]
					);
				}
			}
		}

		return true;
	}

	/**
	 * Returns true if $ip (dotted-quad) is inside CIDR $network/$prefix_len.
	 */
	private static function ipv4_in_cidr( string $ip, string $network, int $prefix_len ): bool {
		$ip_long  = ip2long( $ip );
		$net_long = ip2long( $network );
		if ( false === $ip_long || false === $net_long ) {
			return false;
		}
		$mask = ~( ( 1 << ( 32 - $prefix_len ) ) - 1 );
		return ( $ip_long & $mask ) === ( $net_long & $mask );
	}

	/**
	 * Expand a compressed IPv6 address to full 32-hex-char representation.
	 * Returns null if the address is not valid IPv6.
	 */
	private static function expand_ipv6( string $ip ): ?string {
		$packed = inet_pton( $ip );
		if ( false === $packed || strlen( $packed ) !== 16 ) {
			return null;
		}
		return bin2hex( $packed );
	}

	/**
	 * Returns true if the expanded (32-char hex) IPv6 address is private or loopback.
	 *
	 * Checked ranges:
	 *   ::1              (loopback)               → 0000...0001
	 *   fc00::/7         (Unique Local)           → first byte fc or fd
	 *   fe80::/10        (Link-Local)             → fe8x, fe9x, feax, febx
	 *   ::ffff:0:0/96    (IPv4-mapped)            → embedded IPv4 re-checked against private ranges
	 *   ::/96            (IPv4-compatible, dep.)  → high 96 bits zero, embedded IPv4 re-checked
	 *   2002::/16        (6to4, RFC 3056)         → embedded IPv4 in bits 16-47 re-checked
	 */
	private static function ipv6_is_private( string $hex32 ): bool {
		// ::1 loopback — all zeros except last nibble = 1.
		if ( $hex32 === '00000000000000000000000000000001' ) {
			return true;
		}

		// fc00::/7 — first byte is 0xfc or 0xfd (bit pattern 1111110x).
		$first_byte = hexdec( substr( $hex32, 0, 2 ) );
		if ( ( $first_byte & 0xfe ) === 0xfc ) {
			return true;
		}

		// fe80::/10 — first 10 bits are 1111111010 → bytes fe8x–febx.
		// First two bytes in hex: fe80–febf
		$first_two = hexdec( substr( $hex32, 0, 4 ) );
		if ( $first_two >= 0xfe80 && $first_two <= 0xfebf ) {
			return true;
		}

		// ::ffff:0:0/96 — IPv4-mapped IPv6. Decode embedded IPv4 and re-check private ranges.
		// These addresses bypass the IPv4 check above because filter_var recognises them as IPv6.
		// Examples: ::ffff:127.0.0.1, ::ffff:10.0.0.1, ::ffff:192.168.1.1.
		if ( substr( $hex32, 0, 24 ) === '00000000000000000000ffff' ) {
			$embedded_ipv4 = long2ip( hexdec( substr( $hex32, 24, 8 ) ) );
			if ( false !== $embedded_ipv4 && self::ipv4_is_private_range( $embedded_ipv4 ) ) {
				return true;
			}
		}

		// ::/96 — IPv4-compatible IPv6 (RFC 4291 §2.5.5.1, deprecated).
		// High 96 bits are all zero; the embedded IPv4 occupies the low 32 bits.
		// Exclude ::0 (unspecified) and ::1 (already handled as loopback above).
		if (
			substr( $hex32, 0, 24 ) === '000000000000000000000000' &&
			substr( $hex32, 24, 8 ) !== '00000000' &&
			substr( $hex32, 24, 8 ) !== '00000001'
		) {
			$embedded_ipv4 = long2ip( hexdec( substr( $hex32, 24, 8 ) ) );
			if ( false !== $embedded_ipv4 && self::ipv4_is_private_range( $embedded_ipv4 ) ) {
				return true;
			}
		}

		// 2002::/16 — 6to4 (RFC 3056). Embedded IPv4 occupies bits 16–47 (hex chars 4–11).
		// Example: 2002:7f00:0001:: encodes 127.0.0.1.
		if ( substr( $hex32, 0, 4 ) === '2002' ) {
			$embedded_ipv4 = long2ip( hexdec( substr( $hex32, 4, 8 ) ) );
			if ( false !== $embedded_ipv4 && self::ipv4_is_private_range( $embedded_ipv4 ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Returns true if $ipv4 (dotted-quad) falls within any private, loopback,
	 * or link-local IPv4 range.
	 *
	 * @param string $ipv4 Dotted-quad IPv4 address.
	 * @return bool True if the address is in a private/loopback/link-local range.
	 */
	private static function ipv4_is_private_range( string $ipv4 ): bool {
		foreach ( [
			[ '127.0.0.0', 8 ],    // Loopback
			[ '169.254.0.0', 16 ], // Link-local
			[ '10.0.0.0', 8 ],     // RFC 1918
			[ '172.16.0.0', 12 ],  // RFC 1918
			[ '192.168.0.0', 16 ], // RFC 1918
		] as $range ) {
			if ( self::ipv4_in_cidr( $ipv4, $range[0], $range[1] ) ) {
				return true;
			}
		}
		return false;
	}
}
