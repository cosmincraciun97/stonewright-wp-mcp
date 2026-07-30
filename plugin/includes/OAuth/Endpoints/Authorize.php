<?php
/**
 * SPDX-FileCopyrightText: 2026 Ovation S.r.l. <dev@novamira.ai>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * Derived from includes/oauth/endpoints/authorize.php
 * Source SHA-256: d17fa44479a3a1c6dba5bff5772dd91040564b4b3826429e3aad7092ae73b0b7
 *
 * @package Stonewright\WpMcp
 */

declare( strict_types=1 );

namespace Stonewright\WpMcp\OAuth\Endpoints;

use League\OAuth2\Server\RedirectUriValidators\RedirectUriValidator;
use Stonewright\WpMcp\OAuth\Bootstrap;
use Stonewright\WpMcp\OAuth\Repositories\ClientRepository;
use Stonewright\WpMcp\Security\Permissions;

defined( 'ABSPATH' ) || exit;

/**
 * WordPress-admin authorization endpoint.
 */
final class Authorize {

	public const PENDING_PREFIX = 'stonewright_oauth_pending_';

	public const PAGE_SLUG = 'stonewright-oauth-authorize';

	public static function register(): void {
		$hook = add_submenu_page(
			'',
			'Authorize Application',
			'',
			'manage_options',
			self::PAGE_SLUG,
			[ self::class, 'render' ]
		);
		if ( is_string( $hook ) && '' !== $hook ) {
			add_action( 'load-' . $hook, [ self::class, 'handle' ] );
		}
	}

	public static function can_authorize(): bool {
		return Permissions::manage_options();
	}

	public static function handle(): void {
		if ( ! self::can_authorize() ) {
			wp_die(
				esc_html__( 'You are not allowed to authorize Stonewright applications.', 'stonewright' ),
				'',
				[ 'response' => 403 ]
			);
		}

		$response_type = self::get_param( 'response_type' );
		if ( 'code' !== $response_type ) {
			wp_die( esc_html__( 'response_type must be "code".', 'stonewright' ), '', [ 'response' => 400 ] );
		}

		$client_id = self::get_param( 'client_id' );
		if ( '' === $client_id ) {
			wp_die( esc_html__( 'client_id is required.', 'stonewright' ), '', [ 'response' => 400 ] );
		}

		$redirect_uri = self::get_param( 'redirect_uri' );
		if ( '' === $redirect_uri ) {
			wp_die( esc_html__( 'redirect_uri is required.', 'stonewright' ), '', [ 'response' => 400 ] );
		}

		$code_challenge = self::get_param( 'code_challenge' );
		if ( '' === $code_challenge ) {
			wp_die( esc_html__( 'code_challenge is required (PKCE mandatory).', 'stonewright' ), '', [ 'response' => 400 ] );
		}

		$code_challenge_method = self::get_param( 'code_challenge_method' );
		if ( 'S256' !== $code_challenge_method ) {
			wp_die( esc_html__( 'code_challenge_method must be "S256".', 'stonewright' ), '', [ 'response' => 400 ] );
		}

		$scope_parts = array_values(
			array_intersect(
				array_filter( explode( ' ', self::get_param( 'scope' ) ) ),
				Bootstrap::supported_scopes()
			)
		);
		if ( [] === $scope_parts ) {
			$scope_parts = [ 'mcp' ];
		}
		$scope = implode( ' ', $scope_parts );

		$resource = self::get_param( 'resource' );
		if ( ! Bootstrap::resource_request_allowed( $resource, Bootstrap::resource_identifier() ) ) {
			wp_die(
				esc_html__( 'The requested resource is not served by this authorization server.', 'stonewright' ),
				'',
				[ 'response' => 400 ]
			);
		}

		$state  = self::get_param( 'state' );
		$client = ( new ClientRepository() )->getClientEntity( $client_id );
		if ( null === $client ) {
			wp_die( esc_html__( 'Unknown client_id.', 'stonewright' ), '', [ 'response' => 400 ] );
		}

		$uri_validator = new RedirectUriValidator( $client->getRedirectUri() );
		if ( ! $uri_validator->validateRedirectUri( $redirect_uri ) ) {
			wp_die( esc_html__( 'redirect_uri not registered for this client.', 'stonewright' ), '', [ 'response' => 400 ] );
		}

		$token = bin2hex( random_bytes( 16 ) );
		set_transient(
			self::PENDING_PREFIX . $token,
			[
				'client_id'             => $client_id,
				'redirect_uri'          => $redirect_uri,
				'code_challenge'        => $code_challenge,
				'code_challenge_method' => $code_challenge_method,
				'scope'                 => $scope,
				'state'                 => $state,
				'user_id'               => get_current_user_id(),
			],
			600
		);

		wp_safe_redirect( admin_url( 'admin.php?page=stonewright-oauth-consent&token=' . rawurlencode( $token ) ) );
		exit;
	}

	public static function render(): void {
		wp_die( esc_html__( 'Invalid authorization request.', 'stonewright' ), '', [ 'response' => 400 ] );
	}

	public static function get_param( string $key ): string {
		$raw = $_GET[ $key ] ?? ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		return is_string( $raw ) ? sanitize_text_field( $raw ) : '';
	}

	/**
	 * Repair clients that append a second question mark to the admin URL.
	 */
	public static function repair_folded_request(): void {
		$page = $_GET['page'] ?? null; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( ! is_admin() || ! is_string( $page ) || ! str_starts_with( $page, self::PAGE_SLUG . '?' ) ) {
			return;
		}

		$parts        = explode( '?', $page, 2 );
		$_GET['page'] = $parts[0];
		$recovered    = [];
		parse_str( $parts[1] ?? '', $recovered );
		foreach ( $recovered as $key => $value ) {
			// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- cross-site OAuth request repair.
			if ( ! array_key_exists( $key, $_GET ) ) {
				$_GET[ $key ] = $value;
			}
		}

		foreach ( [ 'REQUEST_URI', 'QUERY_STRING' ] as $server_key ) {
			$raw = (string) ( $_SERVER[ $server_key ] ?? '' );
			if ( '' !== $raw ) {
				$_SERVER[ $server_key ] = str_replace( self::PAGE_SLUG . '?', self::PAGE_SLUG . '&', $raw );
			}
		}
	}
}
