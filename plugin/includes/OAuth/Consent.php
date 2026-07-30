<?php
/**
 * SPDX-FileCopyrightText: 2026 Ovation S.r.l. <dev@novamira.ai>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * Derived from includes/oauth/consent.php
 * Source SHA-256: 6039c9ff2cc93130fd3163b30f26b19c87d98273dfd390323e8622e0a992188b
 *
 * @package Stonewright\WpMcp
 */

declare( strict_types=1 );

namespace Stonewright\WpMcp\OAuth;

use League\OAuth2\Server\Exception\OAuthServerException;
use Stonewright\WpMcp\OAuth\Endpoints\Authorize;
use Stonewright\WpMcp\OAuth\Repositories\ClientRepository;
use Stonewright\WpMcp\OAuth\Repositories\UserEntity;
use Stonewright\WpMcp\Security\Permissions;

defined( 'ABSPATH' ) || exit;

/**
 * Interactive WordPress-admin OAuth consent page.
 */
final class Consent {

	public const PAGE_SLUG = 'stonewright-oauth-consent';

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
			add_action( 'load-' . $hook, [ self::class, 'handle_load' ] );
		}
	}

	public static function can_authorize(): bool {
		return is_user_logged_in() && Permissions::manage_options();
	}

	public static function handle_load(): void {
		if ( 'POST' !== (string) ( $_SERVER['REQUEST_METHOD'] ?? '' ) ) {
			return;
		}
		$context = self::resolve_pending();
		if ( null !== $context ) {
			self::render_post(
				$context['token'],
				$context['pending'],
				$context['redirect_uri'],
				$context['state']
			);
		}
	}

	public static function render(): void {
		$context = self::resolve_pending();
		if ( null !== $context ) {
			self::render_form( $context['token'], $context['client_name'], $context['redirect_uri'] );
		}
	}

	/**
	 * @return array{token:string,pending:array<array-key,mixed>,redirect_uri:string,state:string,client_name:string}|null
	 */
	public static function resolve_pending(): ?array {
		if ( ! is_user_logged_in() ) {
			wp_die( 'You must be logged in.', '', [ 'response' => 403 ] );
		}
		if ( ! Permissions::manage_options() ) {
			wp_die( 'You are not allowed to authorize Stonewright applications.', '', [ 'response' => 403 ] );
		}

		$raw_token = $_GET['token'] ?? ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$token     = is_string( $raw_token ) ? sanitize_text_field( $raw_token ) : '';
		if ( '' === $token ) {
			wp_die( 'Missing consent token.', '', [ 'response' => 400 ] );
		}

		$pending = get_transient( Authorize::PENDING_PREFIX . $token );
		if ( false === $pending || ! is_array( $pending ) ) {
			wp_die( 'Invalid or expired consent token.', '', [ 'response' => 400 ] );
		}
		if ( (int) ( $pending['user_id'] ?? 0 ) !== get_current_user_id() ) {
			wp_die( 'Session mismatch.', '', [ 'response' => 403 ] );
		}

		$client_id = (string) ( $pending['client_id'] ?? '' );
		$client    = ( new ClientRepository() )->getClientEntity( $client_id );
		if ( null === $client ) {
			delete_transient( Authorize::PENDING_PREFIX . $token );
			wp_die( 'The application is no longer registered.', '', [ 'response' => 400 ] );
		}

		return [
			'token'        => $token,
			'pending'      => $pending,
			'redirect_uri' => (string) ( $pending['redirect_uri'] ?? '' ),
			'state'        => (string) ( $pending['state'] ?? '' ),
			'client_name'  => $client->getName(),
		];
	}

	/**
	 * @param array<array-key, mixed> $pending Pending authorization.
	 */
	public static function render_post(
		string $token,
		array $pending,
		string $redirect_uri,
		string $state
	): void {
		check_admin_referer( 'stonewright_oauth_consent_' . $token );

		if ( array_key_exists( 'deny', $_POST ) ) {
			delete_transient( Authorize::PENDING_PREFIX . $token );
			// phpcs:ignore WordPress.Security.SafeRedirect.wp_redirect_wp_redirect -- validated registered OAuth redirect may use a custom scheme.
			wp_redirect( add_query_arg( [ 'error' => 'access_denied', 'state' => $state ], $redirect_uri ) );
			exit;
		}

		try {
			$client_id             = (string) ( $pending['client_id'] ?? '' );
			$code_challenge        = (string) ( $pending['code_challenge'] ?? '' );
			$code_challenge_method = (string) ( $pending['code_challenge_method'] ?? '' );
			$scope                 = (string) ( $pending['scope'] ?? 'mcp' );
			$user_id               = (int) ( $pending['user_id'] ?? 0 );
			$server                = ServerFactory::authorization_server();
			$request               = Bridge::psr7_from_globals()->withQueryParams(
				[
					'response_type'         => 'code',
					'client_id'             => $client_id,
					'redirect_uri'          => $redirect_uri,
					'code_challenge'        => $code_challenge,
					'code_challenge_method' => $code_challenge_method,
					'scope'                 => $scope,
					'state'                 => $state,
				]
			);
			$authorization = $server->validateAuthorizationRequest( $request );
			$user          = new UserEntity();
			$user->setIdentifier( (string) $user_id );
			$authorization->setUser( $user );
			$authorization->setAuthorizationApproved( true );

			delete_transient( Authorize::PENDING_PREFIX . $token );
			$response = $server->completeAuthorizationRequest( $authorization, Bridge::new_psr7_response() );
			// phpcs:ignore WordPress.Security.SafeRedirect.wp_redirect_wp_redirect -- League returns the previously validated registered OAuth redirect.
			wp_redirect( $response->getHeaderLine( 'Location' ) );
			exit;
		} catch ( OAuthServerException $exception ) {
			delete_transient( Authorize::PENDING_PREFIX . $token );
			// phpcs:ignore WordPress.Security.SafeRedirect.wp_redirect_wp_redirect -- validated registered OAuth redirect may use a custom scheme.
			wp_redirect(
				add_query_arg(
					[
						'error'             => $exception->getErrorType(),
						'error_description' => $exception->getMessage(),
						'state'             => $state,
					],
					$redirect_uri
				)
			);
			exit;
		} catch ( \Throwable $exception ) {
			unset( $exception );
			delete_transient( Authorize::PENDING_PREFIX . $token );
			wp_die( 'An error occurred during authorization. Please try again.', '', [ 'response' => 500 ] );
		}
	}

	public static function render_form( string $token, string $client_name, string $redirect_uri ): void {
		$destination = self::redirect_destination_label( $redirect_uri );

		echo '<div class="wrap">';
		echo '<h1>' . esc_html__( 'Authorize Application', 'stonewright' ) . '</h1>';
		echo '<p><strong>' . esc_html( $client_name ) . '</strong> ';
		echo esc_html__( 'is requesting MCP access to your WordPress site.', 'stonewright' ) . '</p>';
		echo '<p><strong>' . esc_html__( 'Redirect destination:', 'stonewright' ) . '</strong> ';
		echo esc_html( $destination ) . '</p>';
		echo '<p class="description">';
		echo esc_html__( 'Only authorize applications you trust. The application name is provided by the connecting client.', 'stonewright' );
		echo '</p><form method="post">';
		wp_nonce_field( 'stonewright_oauth_consent_' . $token );
		echo '<button type="submit" name="approve" value="1" class="button button-primary">';
		echo esc_html__( 'Authorize', 'stonewright' ) . '</button> ';
		echo '<button type="submit" name="deny" value="1" class="button">';
		echo esc_html__( 'Deny', 'stonewright' ) . '</button></form></div>';
	}

	public static function redirect_destination_label( string $redirect_uri ): string {
		$parsed = parse_url( $redirect_uri );
		if ( ! is_array( $parsed ) ) {
			return $redirect_uri;
		}
		$scheme = strtolower( (string) ( $parsed['scheme'] ?? '' ) );
		$host   = strtolower( (string) ( $parsed['host'] ?? '' ) );
		if ( '' === $host ) {
			return '' !== $scheme ? $scheme . ':' : $redirect_uri;
		}
		return $scheme . '://' . $host;
	}
}
