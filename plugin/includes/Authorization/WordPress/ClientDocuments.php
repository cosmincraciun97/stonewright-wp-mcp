<?php
/**
 * Clients identified by a client ID metadata document.
 * SPDX-License-Identifier: AGPL-3.0-or-later
 *
 * @package Stonewright
 */

declare( strict_types=1 );

namespace Stonewright\WpMcp\Authorization\WordPress;

use Stonewright\WpMcp\Authorization\Model\OAuthFault;
use Stonewright\WpMcp\Authorization\Ports\Clock;
use Stonewright\WpMcp\Authorization\Protocol\ClientMetadataRules;
use Stonewright\WpMcp\Authorization\Protocol\RequestDecoder;
use Stonewright\WpMcp\Authorization\Protocol\TransportPolicy;
use Stonewright\WpMcp\Support\Logger;

/**
 * A client may use an HTTPS URL as its client_id; the URL serves a JSON document with
 * the client's metadata. Resolution:
 *
 * - The URL must be https on the default port, with a DNS host name (no IP literal), a
 *   path other than "/", no dot segments, no fragment and no user information.
 * - Every address the host resolves to must be public (no loopback, private, link-local,
 *   shared, documentation, multicast, reserved, mapped or translated private range); the
 *   request is pinned to a checked address, redirects are not followed, and the
 *   response is limited to MAXIMUM_BYTES within TIMEOUT seconds.
 * - The document's client_id must equal the URL, it must describe a public client
 *   (token_endpoint_auth_method "none" or omitted, no shared secret) and pass the same
 *   metadata rules as a registration, including the callback rules.
 * - The client is stored in the clients table under RowKeys::client_document() and
 *   reused until its cached copy ends: Cache-Control max-age clamped to 300 through
 *   86400 seconds, 300 for no-store or no-cache, 3600 without a directive. An expired
 *   copy that can no longer be fetched is refused.
 *
 * Every refusal is invalid_client.
 */
final class ClientDocuments {

	public const MAXIMUM_BYTES = 5120;
	public const TIMEOUT = 5;
	public const DEFAULT_LIFETIME = 3600;
	public const MINIMUM_LIFETIME = 300;
	public const MAXIMUM_LIFETIME = 86400;

	/** Ranges that are not public even when the address filters accept them. */
	private const BLOCKED_RANGES = [
		'0.0.0.0/8',
		'10.0.0.0/8',
		'100.64.0.0/10',
		'127.0.0.0/8',
		'169.254.0.0/16',
		'172.16.0.0/12',
		'192.0.0.0/24',
		'192.0.2.0/24',
		'192.88.99.0/24',
		'192.168.0.0/16',
		'198.18.0.0/15',
		'198.51.100.0/24',
		'203.0.113.0/24',
		'224.0.0.0/4',
		'240.0.0.0/4',
		'::/128',
		'::1/128',
		'::ffff:0:0/96',
		'64:ff9b::/96',
		'64:ff9b:1::/48',
		'100::/64',
		'2001::/23',
		'2001:db8::/32',
		'2002::/16',
		'fc00::/7',
		'fe80::/10',
		'fec0::/10',
		'ff00::/8',
	];

	/** @var \Closure(string): list<string> */
	private \Closure $resolve;

	/** @var \Closure(string, string): (array{status: int, body: string, cache_control: string}|null) */
	private \Closure $fetch;

	/**
	 * @param (\Closure(string): list<string>)|null                                                       $resolve Host => its addresses.
	 * @param (\Closure(string, string): (array{status: int, body: string, cache_control: string}|null))|null $fetch   URL and pinned address => response, or null on a transport failure.
	 */
	public function __construct( private ClientStore $clients, private Clock $clock, ?\Closure $resolve = null, ?\Closure $fetch = null ) {
		$this->resolve = $resolve ?? static fn ( string $host ): array => self::dns_addresses( $host );
		$this->fetch = $fetch ?? static fn ( string $url, string $address ): ?array => self::http_fetch( $url, $address );
	}

	/** Whether a client_id names a metadata document (an https URL with a path). */
	public static function is_document_url( string $client_id ): bool {
		if ( '' === $client_id || strlen( $client_id ) > 2048 || ! str_starts_with( $client_id, 'https://' ) || preg_match( '/[\x00-\x20\x7f\\\\]/', $client_id ) || preg_match( '/%(?![0-9a-fA-F]{2})/', $client_id ) ) {
			return false;
		}
		$parts = parse_url( $client_id );
		if ( ! is_array( $parts ) || 'https' !== ( $parts['scheme'] ?? null ) || ! isset( $parts['host'] ) || isset( $parts['user'] ) || isset( $parts['pass'] ) || isset( $parts['fragment'] ) || ( isset( $parts['port'] ) && 443 !== $parts['port'] ) ) {
			return false;
		}
		if ( ! preg_match( '/^(?=.{1,253}$)(?:[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z](?:[a-z0-9-]{0,61}[a-z0-9])?$/iD', $parts['host'] ) ) {
			return false;
		}
		$path = $parts['path'] ?? '';
		if ( '' === $path || '/' === $path ) {
			return false;
		}
		foreach ( explode( '/', $path ) as $segment ) {
			if ( in_array( rawurldecode( $segment ), [ '.', '..' ], true ) ) {
				return false;
			}
		}
		return true;
	}

	/** The stored client key of a client_id: the hashed key for a document URL, else the id itself. */
	public static function client_key( string $client_id ): string {
		return self::is_document_url( $client_id ) ? RowKeys::client_document( $client_id ) : $client_id;
	}

	/** Whether an address is a public unicast address. */
	public static function public_address( string $address ): bool {
		$packed = @inet_pton( $address ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- Invalid input is an expected refusal.
		if ( false === $packed || false === filter_var( $address, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE ) ) {
			return false;
		}
		if ( defined( 'FILTER_FLAG_GLOBAL_RANGE' ) && false === filter_var( $address, FILTER_VALIDATE_IP, (int) constant( 'FILTER_FLAG_GLOBAL_RANGE' ) ) ) {
			return false;
		}
		foreach ( self::BLOCKED_RANGES as $range ) {
			if ( self::in_range( $packed, $range ) ) {
				return false;
			}
		}
		return true;
	}

	/**
	 * The stored client for a document URL, fetching the document when no fresh copy exists.
	 *
	 * @return array<string, mixed> The client in ClientStore::find() form.
	 * @throws OAuthFault When the document cannot be used (invalid_client).
	 * @throws StorageFailure When the client cannot be stored.
	 */
	public function resolve( string $url ): array {
		if ( ! self::is_document_url( $url ) ) {
			throw new OAuthFault( 'invalid_client' );
		}
		$key = self::client_key( $url );
		$now = $this->clock->now();
		$stored = $this->clients->find( $key );
		if ( null !== $stored && ClientStore::DOCUMENT_PURPOSE === $stored['registration_purpose'] && $url === ( $stored['client_id_metadata_document'] ?? null ) && null !== $stored['registration_expires_at'] && $now < $stored['registration_expires_at'] ) {
			return $stored;
		}
		[ $profile, $lifetime ] = $this->fetch_document( $url );
		$this->clients->save_document( $key, $profile, $now + $lifetime );
		$client = $this->clients->find( $key );
		if ( null === $client ) {
			throw new StorageFailure( 'The client metadata document could not be read back.' );
		}
		return $client;
	}

	/**
	 * @return array{0: array<string, mixed>, 1: int} Accepted profile and cache lifetime.
	 * @throws OAuthFault When the document cannot be used (invalid_client).
	 */
	private function fetch_document( string $url ): array {
		$addresses = ( $this->resolve )( (string) parse_url( $url, PHP_URL_HOST ) );
		if ( [] === $addresses ) {
			throw new OAuthFault( 'invalid_client' );
		}
		foreach ( $addresses as $address ) {
			if ( ! is_string( $address ) || ! self::public_address( $address ) ) {
				throw new OAuthFault( 'invalid_client' );
			}
		}
		$ipv4 = array_values( array_filter( $addresses, static fn ( string $address ): bool => ! str_contains( $address, ':' ) ) );
		$response = ( $this->fetch )( $url, $ipv4[0] ?? $addresses[0] );
		if ( null === $response || 200 !== $response['status'] || strlen( $response['body'] ) > self::MAXIMUM_BYTES ) {
			throw new OAuthFault( 'invalid_client' );
		}
		try {
			$document = ( new RequestDecoder() )->registration( $response['body'], self::MAXIMUM_BYTES );
			if ( $url !== ( $document['client_id'] ?? null ) || array_key_exists( 'client_secret', $document ) || array_key_exists( 'client_secret_expires_at', $document ) ) {
				throw new OAuthFault( 'invalid_client' );
			}
			$accepted = ( new ClientMetadataRules( new TransportPolicy() ) )->accept(
				$document,
				[
					'authentication_methods'        => [ 'none' ],
					'omitted_authentication_method' => 'none',
					'grant_types'                   => [ 'authorization_code', 'refresh_token' ],
					'response_types'                => [ 'code' ],
					'native_clients'                => true,
					'maximum_redirects'             => 10,
					'maximum_text_bytes'            => 1024,
				]
			);
		} catch ( OAuthFault $refused ) {
			throw new OAuthFault( 'invalid_client' );
		}
		$accepted['client_id_metadata_document'] = $url;
		return [ $accepted, self::lifetime( $response['cache_control'] ) ];
	}

	private static function lifetime( string $cache_control ): int {
		$directives = strtolower( $cache_control );
		if ( preg_match( '/(?:^|[\s,])(?:no-store|no-cache)(?:$|[\s,=])/', $directives ) ) {
			return self::MINIMUM_LIFETIME;
		}
		if ( preg_match( '/(?:^|[\s,])max-age\s*=\s*"?(\d{1,10})/', $directives, $match ) ) {
			return max( self::MINIMUM_LIFETIME, min( self::MAXIMUM_LIFETIME, (int) $match[1] ) );
		}
		return self::DEFAULT_LIFETIME;
	}

	private static function in_range( string $packed, string $range ): bool {
		[ $network, $bits ] = explode( '/', $range );
		$base = inet_pton( $network );
		if ( false === $base || strlen( $base ) !== strlen( $packed ) ) {
			return false;
		}
		$bits = (int) $bits;
		$whole = intdiv( $bits, 8 );
		if ( substr( $packed, 0, $whole ) !== substr( $base, 0, $whole ) ) {
			return false;
		}
		$rest = $bits % 8;
		if ( 0 === $rest ) {
			return true;
		}
		$mask = ( 0xff << ( 8 - $rest ) ) & 0xff;
		return ( ord( $packed[ $whole ] ) & $mask ) === ( ord( $base[ $whole ] ) & $mask );
	}

	/** @return list<string> IPv4 and IPv6 addresses of a host name. */
	private static function dns_addresses( string $host ): array {
		$addresses = gethostbynamel( $host );
		$addresses = is_array( $addresses ) ? $addresses : [];
		if ( function_exists( 'dns_get_record' ) ) {
			$records = @dns_get_record( $host, DNS_AAAA ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- A failed lookup is an empty answer.
			foreach ( is_array( $records ) ? $records : [] as $record ) {
				if ( is_array( $record ) && isset( $record['ipv6'] ) && is_string( $record['ipv6'] ) ) {
					$addresses[] = $record['ipv6'];
				}
			}
		}
		return array_values( array_unique( array_map( 'strval', $addresses ) ) );
	}

	/**
	 * One GET pinned to a checked address, without redirects, bounded in size and time.
	 * Pinning needs the cURL transport; without it nothing is fetched.
	 *
	 * @return array{status: int, body: string, cache_control: string}|null
	 */
	private static function http_fetch( string $url, string $address ): ?array {
		if ( ! function_exists( 'curl_init' ) || ! defined( 'CURLOPT_RESOLVE' ) ) {
			Logger::warning( 'oauth_client_document_unavailable', [ 'reason' => 'curl_missing' ] );
			return null;
		}
		$host = (string) parse_url( $url, PHP_URL_HOST );
		$pin = static function ( $handle, $request, $requested ) use ( $url, $host, $address ): void {
			if ( $requested === $url ) {
				curl_setopt( $handle, (int) constant( 'CURLOPT_RESOLVE' ), [ $host . ':443:' . ( str_contains( $address, ':' ) ? '[' . $address . ']' : $address ) ] ); // phpcs:ignore WordPress.WP.AlternativeFunctions.curl_curl_setopt -- Pins the connection to the checked address.
			}
		};
		add_action( 'http_api_curl', $pin, 10, 3 );
		try {
			$response = wp_safe_remote_get(
				$url,
				[
					'timeout'             => self::TIMEOUT,
					'redirection'         => 0,
					'limit_response_size' => self::MAXIMUM_BYTES + 1,
					'headers'             => [ 'Accept' => 'application/json' ],
				]
			);
		} finally {
			remove_action( 'http_api_curl', $pin, 10 );
		}
		if ( is_wp_error( $response ) ) {
			return null;
		}
		$cache_control = wp_remote_retrieve_header( $response, 'cache-control' );
		return [
			'status'        => (int) wp_remote_retrieve_response_code( $response ),
			'body'          => (string) wp_remote_retrieve_body( $response ),
			'cache_control' => is_array( $cache_control ) ? implode( ', ', $cache_control ) : (string) $cache_control,
		];
	}
}
