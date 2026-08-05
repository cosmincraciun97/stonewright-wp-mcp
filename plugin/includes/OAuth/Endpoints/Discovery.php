<?php
/**
 * SPDX-FileCopyrightText: 2026 Ovation S.r.l. <dev@novamira.ai>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * Derived from includes/oauth/endpoints/discovery.php
 * Source SHA-256: a1da521450077e0204ed6999f42dda5a2af716cd1697c8d0e6370b2d3cb10c6b
 *
 * @package Stonewright\WpMcp
 */

declare( strict_types=1 );

namespace Stonewright\WpMcp\OAuth\Endpoints;

use Stonewright\WpMcp\OAuth\Bootstrap;

defined( 'ABSPATH' ) || exit;

/**
 * RFC 9728/RFC 8414/OIDC metadata discovery.
 */
final class Discovery {

	public const PROTECTED_RESOURCE = '/.well-known/oauth-protected-resource';

	public const AUTHORIZATION_SERVER = '/.well-known/oauth-authorization-server';

	public const OPENID_CONFIGURATION = '/.well-known/openid-configuration';

	public static function register(): void {
		add_action( 'init', [ self::class, 'maybe_serve' ], 1 );
	}

	public static function protected_resource_metadata_url(): string {
		return home_url( self::PROTECTED_RESOURCE );
	}

	/**
	 * @return array{protected_resource:list<string>,authorization_server:list<string>}
	 */
	public static function discovery_paths( string $home_url, string $resource ): array {
		$home_path     = rtrim( (string) parse_url( $home_url, PHP_URL_PATH ), '/' );
		$resource_path = (string) parse_url( $resource, PHP_URL_PATH );
		$map           = self::discovery_url_paths( $home_path, $resource_path );

		return [
			'protected_resource'  => array_values( array_unique( [ $map['pr_append'], $map['pr_insert'] ] ) ),
			'authorization_server' => array_values(
				array_unique(
					[
						$map['as_oidc_append'],
						$map['as_oauth_append'],
						$map['as_oauth_insert'],
						$map['as_oidc_insert'],
					]
				)
			),
		];
	}

	/**
	 * @return array{pr_append:string,pr_insert:string,as_oidc_append:string,as_oauth_append:string,as_oauth_insert:string,as_oidc_insert:string}
	 */
	public static function discovery_url_paths( string $home_path, string $resource_path ): array {
		return [
			'pr_append'       => $home_path . self::PROTECTED_RESOURCE,
			'pr_insert'       => self::PROTECTED_RESOURCE . $resource_path,
			'as_oidc_append'  => $home_path . self::OPENID_CONFIGURATION,
			'as_oauth_append' => $home_path . self::AUTHORIZATION_SERVER,
			'as_oauth_insert' => self::AUTHORIZATION_SERVER . $home_path,
			'as_oidc_insert'  => self::OPENID_CONFIGURATION . $home_path,
		];
	}

	/**
	 * @return list<array{url:string,field:string,expected:string,requirement:string,group:string,label:string}>
	 */
	public static function discovery_probes( string $home_url, string $resource ): array {
		$parts         = parse_url( $home_url );
		$port          = is_array( $parts ) ? ( $parts['port'] ?? null ) : null;
		$scheme        = is_array( $parts ) ? (string) ( $parts['scheme'] ?? 'https' ) : 'https';
		$host          = is_array( $parts ) ? (string) ( $parts['host'] ?? '' ) : '';
		$origin        = $scheme . '://' . $host . ( null !== $port ? ':' . $port : '' );
		$home_path     = rtrim( (string) parse_url( $home_url, PHP_URL_PATH ), '/' );
		$resource_path = (string) parse_url( $resource, PHP_URL_PATH );
		$map           = self::discovery_url_paths( $home_path, $resource_path );
		$group         = 'authorization_server';
		$candidates    = [
			[ $origin . $map['pr_append'], 'resource', $resource, 'required', '', 'protected-resource (append)' ],
			[ $origin . $map['pr_insert'], 'resource', $resource, 'optional', '', 'protected-resource (insert)' ],
			[ $origin . $map['as_oidc_append'], 'issuer', $home_url, 'any', $group, 'authorization-server (OIDC append)' ],
			[ $origin . $map['as_oauth_append'], 'issuer', $home_url, 'any', $group, 'authorization-server (OAuth append)' ],
			[ $origin . $map['as_oauth_insert'], 'issuer', $home_url, 'any', $group, 'authorization-server (RFC 8414 insert)' ],
			[ $origin . $map['as_oidc_insert'], 'issuer', $home_url, 'any', $group, 'authorization-server (OIDC insert)' ],
		];
		$probes        = [];
		$seen          = [];

		foreach ( $candidates as [ $url, $field, $expected, $requirement, $candidate_group, $label ] ) {
			if ( isset( $seen[ $url ] ) ) {
				continue;
			}
			$seen[ $url ] = true;
			$probes[]     = compact( 'url', 'field', 'expected', 'requirement', 'label' ) + [ 'group' => $candidate_group ];
		}

		return $probes;
	}

	/**
	 * @return array<string, mixed>
	 */
	public static function protected_resource_document(): array {
		return [
			'resource'                 => Bootstrap::resource_identifier(),
			'authorization_servers'    => [ home_url() ],
			'bearer_methods_supported' => [ 'header' ],
			'scopes_supported'         => Bootstrap::resource_scopes(),
		];
	}

	/**
	 * @return array<string, mixed>
	 */
	public static function authorization_server_document(): array {
		$base = rest_url( 'stonewright/v1/oauth' );
		return [
			'issuer'                                => home_url(),
			'authorization_endpoint'                 => admin_url( 'admin.php?page=stonewright-oauth-authorize' ),
			'token_endpoint'                         => $base . '/token',
			'registration_endpoint'                  => $base . '/register',
			'revocation_endpoint'                    => $base . '/revoke',
			'introspection_endpoint'                 => $base . '/introspect',
			'response_types_supported'               => [ 'code' ],
			'grant_types_supported'                  => [ 'authorization_code', 'refresh_token' ],
			'code_challenge_methods_supported'       => [ 'S256' ],
			'token_endpoint_auth_methods_supported'  => [ 'none' ],
			'scopes_supported'                       => Bootstrap::supported_scopes(),
		];
	}

	/**
	 * Serve metadata before WordPress template output.
	 */
	public static function maybe_serve(): void {
		$path = parse_url( (string) ( $_SERVER['REQUEST_URI'] ?? '' ), PHP_URL_PATH );
		if ( ! is_string( $path ) ) {
			return;
		}

		$paths = self::discovery_paths( home_url(), Bootstrap::resource_identifier() );
		if ( in_array( $path, $paths['protected_resource'], true ) ) {
			self::serve( self::protected_resource_document() );
		}
		if ( in_array( $path, $paths['authorization_server'], true ) ) {
			self::serve( self::authorization_server_document() );
		}
	}

	/**
	 * @param array<string, mixed> $document Metadata.
	 */
	private static function serve( array $document ): void {
		header( 'Content-Type: application/json; charset=UTF-8' );
		echo wp_json_encode( $document ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		exit;
	}
}
