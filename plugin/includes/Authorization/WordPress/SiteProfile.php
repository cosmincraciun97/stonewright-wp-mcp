<?php
/**
 * What the running site exposes as an OAuth authorization server.
 * SPDX-License-Identifier: AGPL-3.0-or-later
 *
 * @package Stonewright
 */

declare( strict_types=1 );

namespace Stonewright\WpMcp\Authorization\WordPress;

use Stonewright\WpMcp\Authorization\Model\OAuthFault;
use Stonewright\WpMcp\Authorization\Protocol\DiscoveryLocations;
use Stonewright\WpMcp\Authorization\Protocol\MetadataDocuments;
use Stonewright\WpMcp\Authorization\Protocol\TransportPolicy;
use Stonewright\WpMcp\Security\PluginEffectiveState;

/**
 * Enablement gate, transport policy, URLs and discovery documents of the site.
 *
 * OAuth surfaces exist only while the plugin is effectively enabled (operator
 * enablement, no domain-lock mismatch, dependencies loaded) and the transport is
 * allowed. Every published URL must be HTTPS, with two operator-declared exceptions:
 * an installation whose environment type is "local" may publish its own plain HTTP
 * origins, and a "development" installation may publish plain HTTP at a loopback host
 * (127.0.0.1, [::1] or localhost). Production and staging sites on plain HTTP publish
 * nothing.
 *
 * URLs: the issuer is the home URL without a trailing slash; the resource is the REST
 * URL of mcp/stonewright-oauth in the current permalink mode; the authorization and
 * consent pages are hidden admin pages; token, registration, revocation and
 * introspection are REST routes under stonewright/v1/oauth.
 */
final class SiteProfile {

	public const AUTHORIZE_PAGE = 'stonewright-oauth-authorize';
	public const CONSENT_PAGE = 'stonewright-oauth-consent';
	public const REST_NAMESPACE = 'stonewright/v1';

	/** REST route (below the namespace) of each endpoint. */
	public const ROUTES = [
		'token'         => 'oauth/token',
		'registration'  => 'oauth/register',
		'revocation'    => 'oauth/revoke',
		'introspection' => 'oauth/introspect',
	];

	/** Scopes a client may request. */
	public const SUPPORTED_SCOPES = [ 'mcp', 'read', 'write', 'offline_access' ];

	/** Scopes every grant carries and the protected resource requires. */
	public const GRANTED_SCOPES = [ 'mcp' ];

	/**
	 * The introspection endpoint accepts a WordPress administrator: HTTP Basic with an
	 * Application Password, or a signed-in session with a REST nonce.
	 */
	public const INTROSPECTION_AUTH_METHODS = [ 'client_secret_basic' ];

	private const URL_KEYS = [ 'issuer', 'resource', 'authorization_endpoint', 'consent_endpoint', 'token_endpoint', 'registration_endpoint', 'revocation_endpoint', 'introspection_endpoint' ];

	/** @var array<string, string> */
	private array $urls;

	/** @var list<string> */
	private array $resources;

	/**
	 * @param array<string, string> $urls      issuer, resource, authorization_endpoint, consent_endpoint, token_endpoint, registration_endpoint, revocation_endpoint and introspection_endpoint.
	 * @param list<string>          $resources Every resource identifier this site answers for; the canonical resource is added first.
	 * @throws \InvalidArgumentException When a URL is missing.
	 */
	public function __construct( private bool $enabled, private string $environment, array $urls, array $resources = [] ) {
		foreach ( self::URL_KEYS as $key ) {
			if ( ! isset( $urls[ $key ] ) || ! is_string( $urls[ $key ] ) || '' === $urls[ $key ] ) {
				throw new \InvalidArgumentException( 'Every site URL is required.' );
			}
		}
		$urls['issuer'] = rtrim( $urls['issuer'], '/' );
		$urls['resource'] = rtrim( $urls['resource'], '/' );
		$this->urls = $urls;
		$this->resources = array_values( array_unique( array_merge( [ $urls['resource'] ], $resources ) ) );
	}

	public static function wordpress(): self {
		$home = AuthorizationStorage::site_issuer();
		$resources = AuthorizationStorage::site_resources( $home );
		$urls = [
			'issuer'                 => $home,
			'resource'               => $resources[0],
			'authorization_endpoint' => (string) admin_url( 'admin.php?page=' . self::AUTHORIZE_PAGE ),
			'consent_endpoint'       => (string) admin_url( 'admin.php?page=' . self::CONSENT_PAGE ),
		];
		foreach ( self::ROUTES as $name => $route ) {
			$urls[ $name . '_endpoint' ] = (string) rest_url( self::REST_NAMESPACE . '/' . $route );
		}
		$environment = function_exists( 'wp_get_environment_type' ) ? (string) wp_get_environment_type() : 'production';
		return new self( PluginEffectiveState::is_effectively_enabled(), $environment, $urls, $resources );
	}

	public function enabled(): bool {
		return $this->enabled;
	}

	/** The WordPress environment type the transport policy was decided with. */
	public function environment(): string {
		return $this->environment;
	}

	/** Whether OAuth discovery, routes, pages and bearer challenges are served. */
	public function available(): bool {
		return $this->enabled && $this->transport_allowed();
	}

	/** Whether every published URL satisfies the transport policy. */
	public function transport_allowed(): bool {
		$policy = $this->transport();
		try {
			foreach ( self::URL_KEYS as $key ) {
				$policy->require_secure( $this->urls[ $key ] );
			}
		} catch ( OAuthFault $refused ) {
			return false;
		}
		return true;
	}

	/** Transport policy for the site's own endpoint URLs. */
	public function transport(): TransportPolicy {
		$origins = [];
		if ( 'local' === $this->environment ) {
			foreach ( self::URL_KEYS as $key ) {
				$origin = self::plain_origin( $this->urls[ $key ] );
				if ( null !== $origin ) {
					$origins[] = $origin;
				}
			}
		}
		return new TransportPolicy( $this->development(), array_values( array_unique( $origins ) ) );
	}

	/** Transport policy for links a client supplies (logo, terms): loopback HTTP only in development. */
	public function link_transport(): TransportPolicy {
		return new TransportPolicy( $this->development() );
	}

	public function issuer(): string {
		return $this->urls['issuer'];
	}

	/** Canonical resource identifier (the REST URL of the protected MCP route). */
	public function resource(): string {
		return $this->urls['resource'];
	}

	/** @return list<string> */
	public function resources(): array {
		return $this->resources;
	}

	/**
	 * URL of an endpoint: authorization, consent, token, registration, revocation or introspection.
	 *
	 * @throws \InvalidArgumentException For another name.
	 */
	public function endpoint( string $name ): string {
		$url = $this->urls[ $name . '_endpoint' ] ?? null;
		if ( null === $url ) {
			throw new \InvalidArgumentException( 'Unknown endpoint.' );
		}
		return $url;
	}

	public function consent_url( string $pending_key ): string {
		$base = $this->urls['consent_endpoint'];
		return $base . ( str_contains( $base, '?' ) ? '&' : '?' ) . 'token=' . rawurlencode( $pending_key );
	}

	/** The resource_metadata URL of bearer challenges. */
	public function protected_resource_metadata_url(): string {
		return $this->urls['issuer'] . '/.well-known/oauth-protected-resource';
	}

	/**
	 * Authorization server metadata (RFC 8414) with the issuer identification,
	 * client ID metadata document and introspection authentication declarations.
	 *
	 * @return array<string, mixed>
	 * @throws OAuthFault When a URL breaks the transport policy.
	 */
	public function authorization_server_metadata(): array {
		$endpoints = [ 'issuer' => $this->urls['issuer'] ];
		foreach ( [ 'authorization', 'token', 'registration', 'revocation', 'introspection' ] as $name ) {
			$endpoints[ $name . '_endpoint' ] = $this->urls[ $name . '_endpoint' ];
		}
		return ( new MetadataDocuments( $this->transport() ) )->authorization_server(
			$endpoints,
			[
				'scopes_supported'                               => self::SUPPORTED_SCOPES,
				'introspection_endpoint_auth_methods_supported'  => self::INTROSPECTION_AUTH_METHODS,
				'authorization_response_iss_parameter_supported' => true,
				'client_id_metadata_document_supported'          => true,
			]
		);
	}

	/**
	 * Protected resource metadata (RFC 9728), keys in the order clients have seen.
	 *
	 * @return array<string, mixed>
	 * @throws OAuthFault When a URL breaks the transport policy.
	 */
	public function protected_resource_metadata(): array {
		$document = ( new MetadataDocuments( $this->transport() ) )->protected_resource( $this->urls['resource'], [ $this->urls['issuer'] ], self::GRANTED_SCOPES );
		return [
			'resource'                 => $document['resource'],
			'authorization_servers'    => $document['authorization_servers'],
			'bearer_methods_supported' => $document['bearer_methods_supported'],
			'scopes_supported'         => $document['scopes_supported'],
		];
	}

	/**
	 * Which document a request path serves: "resource", "server" or null.
	 *
	 * Served locations, where H is the path of the issuer: H/.well-known/oauth-protected-resource,
	 * the RFC 9728 location of the canonical resource (path and query must match),
	 * H/.well-known/oauth-authorization-server and H/.well-known/openid-configuration, and
	 * the RFC 8414 forms /.well-known/oauth-authorization-server/H and
	 * /.well-known/openid-configuration/H. Matching is exact; a trailing slash is another path.
	 */
	public function discovery_document( string $path, string $query ): ?string {
		foreach ( $this->discovery_locations() as [ $location_path, $location_query, $document ] ) {
			if ( $path === $location_path && ( null === $location_query || $query === $location_query ) ) {
				return $document;
			}
		}
		return null;
	}

	/** @return list<array{0: string, 1: ?string, 2: string}> Path, required query or null, document. */
	private function discovery_locations(): array {
		$home = rtrim( (string) parse_url( $this->urls['issuer'], PHP_URL_PATH ), '/' );
		$locations = [
			[ $home . '/.well-known/oauth-protected-resource', null, 'resource' ],
			[ $home . '/.well-known/oauth-authorization-server', null, 'server' ],
			[ $home . '/.well-known/openid-configuration', null, 'server' ],
			[ '/.well-known/oauth-authorization-server' . $home, null, 'server' ],
			[ '/.well-known/openid-configuration' . $home, null, 'server' ],
		];
		try {
			$suffixed = ( new DiscoveryLocations( $this->transport() ) )->protected_resource( $this->urls['resource'] );
		} catch ( OAuthFault $refused ) {
			return $locations;
		}
		$parts = parse_url( $suffixed );
		if ( is_array( $parts ) ) {
			$locations[] = [ (string) ( $parts['path'] ?? '' ), $parts['query'] ?? null, 'resource' ];
		}
		return $locations;
	}

	private function development(): bool {
		return in_array( $this->environment, [ 'local', 'development' ], true );
	}

	private static function plain_origin( string $url ): ?string {
		$parts = parse_url( $url );
		if ( ! is_array( $parts ) || 'http' !== ( $parts['scheme'] ?? null ) || ! isset( $parts['host'] ) || isset( $parts['user'] ) || isset( $parts['pass'] ) ) {
			return null;
		}
		return 'http://' . strtolower( $parts['host'] ) . ( isset( $parts['port'] ) ? ':' . $parts['port'] : '' );
	}
}
