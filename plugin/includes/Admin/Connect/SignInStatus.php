<?php
/**
 * OAuth sign-in status of the site for the admin connect panel.
 * SPDX-License-Identifier: GPL-2.0-or-later
 *
 * @package Stonewright
 */

declare( strict_types=1 );

namespace Stonewright\WpMcp\Admin\Connect;

use Stonewright\WpMcp\Admin\ConnectClientConfig;
use Stonewright\WpMcp\Authorization\Model\OAuthFault;
use Stonewright\WpMcp\Authorization\WordPress\HttpSurface;
use Stonewright\WpMcp\Authorization\WordPress\SiteProfile;
use Stonewright\WpMcp\Security\PluginEffectiveState;

/**
 * Whether this site serves OAuth sign-in right now, what its transport is, every reason
 * it does not, and the addresses a client needs. The site profile decides; this class
 * only explains its decision. Whether the site looks local is a hint for the limits of
 * hosted clients, never a policy input.
 *
 * @phpstan-type Status array{available: bool, enabled: bool, transport_allowed: bool, transport: string, transport_label: string, issues: list<array{reason: string, remedy: string}>, mcp_url: string, discovery_url: string, password_url: string, server_name: string, host: string, secure: bool, local: bool, local_reason: string, loopback: bool}
 */
final class SignInStatus {

	private const LOCAL_SUFFIXES = [ '.test', '.local', '.localhost', '.localdomain', '.lan', '.internal', '.home.arpa', '.example', '.invalid' ];

	/** @return Status */
	public static function current(): array {
		return self::describe( HttpSurface::site() );
	}

	/** @return Status */
	public static function describe( SiteProfile $site ): array {
		$issuer    = $site->issuer();
		$resource  = $site->resource();
		$host      = strtolower( (string) parse_url( $issuer, PHP_URL_HOST ) );
		$secure    = self::https( $issuer ) && self::https( $resource );
		$allowed   = $site->transport_allowed();
		$transport = self::transport( $allowed, $secure, $site->environment() );
		$issues    = [];
		if ( ! $site->enabled() ) {
			$issues[] = self::disabled_issue();
		}
		if ( ! $allowed ) {
			$issues[] = self::transport_issue( $site, $host );
		}
		$local_reason = self::local_reason( $host, $site->environment() );
		return [
			'available'         => $site->available(),
			'enabled'           => $site->enabled(),
			'transport_allowed' => $allowed,
			'transport'         => $transport,
			'transport_label'   => self::transport_label( $transport ),
			'issues'            => $issues,
			'mcp_url'           => $resource,
			'discovery_url'     => $site->protected_resource_metadata_url(),
			'password_url'      => ConnectClientConfig::mcp_endpoint_url(),
			'server_name'       => ConnectClientConfig::mcp_server_name(),
			'host'              => $host,
			'secure'            => $secure,
			'local'             => '' !== $local_reason,
			'local_reason'      => $local_reason,
			'loopback'          => self::loopback( $host ),
		];
	}

	private static function transport( bool $allowed, bool $secure, string $environment ): string {
		if ( ! $allowed ) {
			return 'refused';
		}
		if ( $secure ) {
			return 'https';
		}
		return 'local' === $environment ? 'local-http' : 'loopback-http';
	}

	private static function transport_label( string $transport ): string {
		return match ( $transport ) {
			'https'         => __( 'HTTPS', 'stonewright' ),
			'local-http'    => __( 'Plain HTTP, accepted because the environment type is local', 'stonewright' ),
			'loopback-http' => __( 'Plain HTTP at a loopback address, accepted in development', 'stonewright' ),
			default         => __( 'Not accepted for OAuth', 'stonewright' ),
		};
	}

	/** @return array{reason: string, remedy: string} */
	private static function disabled_issue(): array {
		$turn_on = __( 'Turn on Stonewright abilities in step 1 and save.', 'stonewright' );
		$diagnose = __( 'Open Troubleshoot and run diagnostics.', 'stonewright' );
		return match ( PluginEffectiveState::effective_state() ) {
			PluginEffectiveState::STATE_DISABLED_BY_OPERATOR    => self::issue( __( 'Stonewright abilities are turned off, so this site does not offer OAuth sign-in.', 'stonewright' ), $turn_on ),
			PluginEffectiveState::STATE_BLOCKED_DOMAIN_MISMATCH => self::issue( __( "The domain lock does not match this site's address, so Stonewright and its OAuth sign-in are blocked.", 'stonewright' ), __( 'Review the domain lock below the setup steps.', 'stonewright' ) ),
			PluginEffectiveState::STATE_BLOCKED_DEPENDENCY      => self::issue( __( 'A required component did not load, so Stonewright and its OAuth sign-in are blocked.', 'stonewright' ), $diagnose ),
			PluginEffectiveState::STATE_BLOCKED_SECURITY_POLICY => self::issue( __( 'A security policy blocks Stonewright, so OAuth sign-in is off.', 'stonewright' ), $diagnose ),
			default                                             => self::issue( __( 'Stonewright is not active, so OAuth sign-in is off.', 'stonewright' ), $turn_on ),
		};
	}

	/** @return array{reason: string, remedy: string} */
	private static function transport_issue( SiteProfile $site, string $host ): array {
		$refused = self::refused_url( $site );
		$local   = __( 'If the site only runs on this computer, set WP_ENVIRONMENT_TYPE to local in wp-config.php.', 'stonewright' );
		if ( 'http' === strtolower( (string) parse_url( $refused, PHP_URL_SCHEME ) ) ) {
			if ( 'development' === $site->environment() ) {
				return self::issue(
					/* translators: %s: site host name. */
					sprintf( __( 'This site uses plain HTTP at %s. A development site may use plain HTTP only at localhost, 127.0.0.1 or [::1].', 'stonewright' ), $host ),
					__( 'Serve the site over HTTPS or from a loopback address.', 'stonewright' ) . ' ' . $local
				);
			}
			return self::issue(
				/* translators: %s: WordPress environment type. */
				sprintf( __( 'This site uses plain HTTP and its environment type is %s, so OAuth sign-in is refused.', 'stonewright' ), $site->environment() ),
				__( 'Serve the site over HTTPS.', 'stonewright' ) . ' ' . $local
			);
		}
		return self::issue(
			/* translators: %s: refused URL. */
			sprintf( __( 'The address %s is not accepted for OAuth.', 'stonewright' ), $refused ),
			__( 'Check the WordPress Address and Site Address under Settings > General.', 'stonewright' )
		);
	}

	/** The first published URL the site's transport policy refuses. */
	private static function refused_url( SiteProfile $site ): string {
		$urls = [ $site->issuer(), $site->resource() ];
		foreach ( [ 'authorization', 'consent', 'token', 'registration', 'revocation', 'introspection' ] as $endpoint ) {
			$urls[] = $site->endpoint( $endpoint );
		}
		$policy = $site->transport();
		foreach ( $urls as $url ) {
			try {
				$policy->require_secure( $url );
			} catch ( OAuthFault $refused ) {
				return $url;
			}
		}
		return $site->issuer();
	}

	/** @return array{reason: string, remedy: string} */
	private static function issue( string $reason, string $remedy ): array {
		return [
			'reason' => $reason,
			'remedy' => $remedy,
		];
	}

	/** Why the site looks reachable only from this computer or its network, or an empty string. */
	private static function local_reason( string $host, string $environment ): string {
		if ( 'local' === $environment ) {
			return __( 'its environment type is local', 'stonewright' );
		}
		$bare = trim( $host, '[]' );
		if ( '' === $bare ) {
			return '';
		}
		if ( self::loopback( $host ) ) {
			return __( 'it runs at a loopback address', 'stonewright' );
		}
		if ( false !== filter_var( $bare, FILTER_VALIDATE_IP ) ) {
			return false === filter_var( $bare, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE )
				? __( 'its address is private or reserved', 'stonewright' )
				: '';
		}
		if ( ! str_contains( $bare, '.' ) ) {
			return __( 'its host name has no public domain', 'stonewright' );
		}
		foreach ( self::LOCAL_SUFFIXES as $suffix ) {
			if ( str_ends_with( $bare, $suffix ) ) {
				return __( 'its domain is reserved for local use', 'stonewright' );
			}
		}
		return '';
	}

	private static function loopback( string $host ): bool {
		$bare = trim( $host, '[]' );
		return 'localhost' === $bare || '::1' === $bare || str_ends_with( $bare, '.localhost' ) || str_starts_with( $bare, '127.' );
	}

	private static function https( string $url ): bool {
		return 'https' === strtolower( (string) parse_url( $url, PHP_URL_SCHEME ) );
	}
}
