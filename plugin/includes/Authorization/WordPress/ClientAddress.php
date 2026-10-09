<?php
/**
 * The address a request counts as for OAuth rate limits and abuse budgets.
 * SPDX-License-Identifier: GPL-2.0-or-later
 *
 * @package Stonewright
 */

declare( strict_types=1 );

namespace Stonewright\WpMcp\Authorization\WordPress;

/**
 * The connection address (REMOTE_ADDR), unless that address is a configured trusted
 * proxy. Behind a trusted proxy the client is the right-most X-Forwarded-For address
 * that is not itself a trusted proxy, so clients behind one reverse proxy do not share
 * a single bucket. Nothing is trusted by default: without configuration a forwarding
 * header is never read, so a client cannot choose its bucket.
 *
 * Trusted proxies are IP addresses and CIDR ranges (IPv4 and IPv6), set with the
 * STONEWRIGHT_TRUSTED_PROXIES constant (a list or a comma-separated string) and
 * extended or replaced through the stonewright_trusted_proxies filter. Unreadable
 * entries are skipped; a /0 range is never accepted. A forwarding header that holds
 * anything other than a comma-separated list of plain IP addresses is ignored as a
 * whole, and the connection address is used.
 */
final class ClientAddress {

	/** Filter for the list of trusted proxy addresses and CIDR ranges. */
	public const FILTER = 'stonewright_trusted_proxies';

	/** Constant for the same list, for wp-config.php. */
	public const CONSTANT = 'STONEWRIGHT_TRUSTED_PROXIES';

	/** The address the current request counts as, or an empty string when there is none. */
	public static function resolve(): string {
		$remote = isset( $_SERVER['REMOTE_ADDR'] ) && is_string( $_SERVER['REMOTE_ADDR'] ) ? trim( $_SERVER['REMOTE_ADDR'] ) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Validated as an IP address in resolve_from().
		$forwarded = isset( $_SERVER['HTTP_X_FORWARDED_FOR'] ) && is_string( $_SERVER['HTTP_X_FORWARDED_FOR'] ) ? $_SERVER['HTTP_X_FORWARDED_FOR'] : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Every entry is validated as an IP address in resolve_from().
		if ( '' === trim( $forwarded ) ) {
			return self::valid( $remote ) ? $remote : '';
		}
		return self::resolve_from( $remote, $forwarded, self::trusted_proxies() );
	}

	/**
	 * @param string       $remote    The connection address.
	 * @param string       $forwarded The X-Forwarded-For header value.
	 * @param list<mixed>  $trusted   Trusted proxy addresses and CIDR ranges.
	 */
	public static function resolve_from( string $remote, string $forwarded, array $trusted ): string {
		$remote = trim( $remote );
		if ( ! self::valid( $remote ) ) {
			return '';
		}
		$ranges = self::parse( $trusted );
		if ( '' === trim( $forwarded ) || ! self::contains( $ranges, $remote ) ) {
			return $remote;
		}
		$entries = explode( ',', $forwarded );
		for ( $index = count( $entries ) - 1; $index >= 0; --$index ) {
			$entry = trim( $entries[ $index ] );
			if ( ! self::valid( $entry ) ) {
				return $remote;
			}
			if ( ! self::contains( $ranges, $entry ) ) {
				return $entry;
			}
		}
		return $remote;
	}

	/**
	 * The readable entries of the configured list, as written.
	 *
	 * @return list<string>
	 */
	public static function trusted_proxies(): array {
		$configured = defined( self::CONSTANT ) ? constant( self::CONSTANT ) : [];
		$list = array_column( self::parse( self::entries( $configured ) ), 'entry' );
		return array_column( self::parse( self::entries( apply_filters( self::FILTER, $list ) ) ), 'entry' );
	}

	/** @return list<mixed> */
	private static function entries( mixed $value ): array {
		if ( is_string( $value ) ) {
			return preg_split( '/[\s,]+/', $value, -1, PREG_SPLIT_NO_EMPTY ) ?: [];
		}
		return is_array( $value ) ? array_values( $value ) : [];
	}

	/**
	 * @param list<mixed> $entries
	 * @return list<array{entry: string, packed: string, bits: int}>
	 */
	private static function parse( array $entries ): array {
		$ranges = [];
		foreach ( $entries as $entry ) {
			if ( ! is_string( $entry ) ) {
				continue;
			}
			$entry = trim( $entry );
			$parts = explode( '/', $entry, 2 );
			if ( ! self::valid( $parts[0] ) ) {
				continue;
			}
			$packed = (string) inet_pton( $parts[0] );
			$maximum = strlen( $packed ) * 8;
			if ( isset( $parts[1] ) ) {
				if ( 1 !== preg_match( '/^[0-9]{1,3}$/D', $parts[1] ) ) {
					continue;
				}
				$bits = (int) $parts[1];
			} else {
				$bits = $maximum;
			}
			if ( $bits < 1 || $bits > $maximum ) {
				continue;
			}
			if ( 16 === strlen( $packed ) && $bits >= 96 && self::mapped( $packed ) ) {
				$packed = substr( $packed, 12 );
				$bits -= 96;
				if ( $bits < 1 ) {
					continue;
				}
			}
			$ranges[] = [ 'entry' => $entry, 'packed' => $packed, 'bits' => $bits ];
		}
		return $ranges;
	}

	/** @param list<array{entry: string, packed: string, bits: int}> $ranges */
	private static function contains( array $ranges, string $address ): bool {
		$packed = (string) inet_pton( $address );
		if ( 16 === strlen( $packed ) && self::mapped( $packed ) ) {
			$packed = substr( $packed, 12 );
		}
		foreach ( $ranges as $range ) {
			if ( strlen( $range['packed'] ) !== strlen( $packed ) ) {
				continue;
			}
			$whole = intdiv( $range['bits'], 8 );
			if ( substr( $packed, 0, $whole ) !== substr( $range['packed'], 0, $whole ) ) {
				continue;
			}
			$rest = $range['bits'] % 8;
			if ( 0 === $rest ) {
				return true;
			}
			$mask = ( 0xFF << ( 8 - $rest ) ) & 0xFF;
			if ( ( ord( $packed[ $whole ] ) & $mask ) === ( ord( $range['packed'][ $whole ] ) & $mask ) ) {
				return true;
			}
		}
		return false;
	}

	private static function mapped( string $packed ): bool {
		return str_starts_with( $packed, str_repeat( "\0", 10 ) . "\xff\xff" );
	}

	private static function valid( string $address ): bool {
		return '' !== $address && false !== filter_var( $address, FILTER_VALIDATE_IP ) && false !== inet_pton( $address );
	}
}
