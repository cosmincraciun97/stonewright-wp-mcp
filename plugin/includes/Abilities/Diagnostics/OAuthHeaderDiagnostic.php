<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\Abilities\Diagnostics;

use Stonewright\WpMcp\Abilities\AbilityKernel;
use Stonewright\WpMcp\OAuth\Middleware;
use Stonewright\WpMcp\Security\Permissions;

/**
 * Read-only request-local OAuth/header diagnostic.
 *
 * It deliberately reports booleans and route classification only. Header
 * values, token material, cookies, and proxy-provided identity are never
 * returned.
 */
final class OAuthHeaderDiagnostic extends AbilityKernel {

	public function name(): string {
		return 'stonewright/oauth-header-diagnostic';
	}

	public function label(): string {
		return __( 'Diagnose OAuth header delivery', 'stonewright' );
	}

	public function description(): string {
		return __( 'Separates OAuth MCP header delivery from ordinary REST and Application Password authentication without returning credentials or changing authentication state.', 'stonewright' );
	}

	public function category(): string {
		return 'diagnostics';
	}

	public function input_schema(): array {
		return [
			'type'                 => 'object',
			'additionalProperties' => false,
			'properties'           => [
				'route' => [ 'type' => 'string', 'maxLength' => 255 ],
			],
		];
	}

	public function output_schema(): array {
		return [
			'type'       => 'object',
			'properties' => [
				'ok'                               => [ 'type' => 'boolean' ],
				'route'                            => [ 'type' => 'string' ],
				'oauth_route'                      => [ 'type' => 'boolean' ],
				'ordinary_rest_route'              => [ 'type' => 'boolean' ],
				'header_source'                    => [ 'type' => 'string' ],
				'header_seen_by_server'            => [ 'type' => 'boolean' ],
				'bearer_parsed'                    => [ 'type' => 'boolean' ],
				'auth_succeeded'                   => [ 'type' => 'boolean' ],
				'proxy_strip_suspected'            => [ 'type' => 'boolean' ],
				'application_password_path_succeeded' => [ 'type' => 'boolean' ],
				'no_secret_values_returned'        => [ 'type' => 'boolean' ],
			],
		];
	}

	public function permission_callback( array $args ): bool|\WP_Error {
		return Permissions::read();
	}

	public function execute( array $args ): array|\WP_Error {
		$route = $this->route( isset( $args['route'] ) && is_scalar( $args['route'] ) ? (string) $args['route'] : '' );
		$route = '/' . ltrim( $route, '/' );
		$is_oauth_route = Middleware::is_mcp_route( $route );
		$is_rest_route  = '' !== $route && ( str_starts_with( $route, '/wp-json/' ) || str_starts_with( $route, '/rest/' ) || str_starts_with( $route, '/mcp/' ) );
		$server_header  = trim( (string) ( $_SERVER['HTTP_AUTHORIZATION'] ?? '' ) );
		$redirect_header = trim( (string) ( $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? '' ) );
		$header_seen    = '' !== $server_header || '' !== $redirect_header;
		$authorization  = Middleware::get_authorization_header();
		$bearer_parsed  = Middleware::has_bearer_authorization( $authorization );
		$basic_parsed   = 1 === preg_match( '/^\s*Basic\s+\S/i', $authorization );
		$current_user   = get_current_user_id() > 0;
		$auth_succeeded = $is_oauth_route
			? ( $bearer_parsed && Middleware::current_user_can_access_mcp() )
			: $current_user;
		$proxy_signal = '' !== (string) ( $_SERVER['HTTP_X_FORWARDED_FOR'] ?? '' )
			|| '' !== (string) ( $_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '' )
			|| '' !== (string) ( $_SERVER['HTTP_X_FORWARDED_HOST'] ?? '' )
			|| '' !== (string) ( $_SERVER['HTTP_VIA'] ?? '' );

		return [
			'ok'                               => true,
			'route'                            => sanitize_text_field( $route ),
			'oauth_route'                      => $is_oauth_route,
			'ordinary_rest_route'              => $is_rest_route && ! $is_oauth_route,
			'header_source'                    => '' !== $server_header ? 'server' : ( '' !== $redirect_header ? 'redirect' : 'missing' ),
			'header_seen_by_server'            => $header_seen,
			'bearer_parsed'                    => $bearer_parsed,
			'auth_succeeded'                   => $auth_succeeded,
			'proxy_strip_suspected'            => $is_oauth_route && ! $header_seen && $proxy_signal,
			'application_password_path_succeeded' => ! $is_oauth_route && $basic_parsed && $current_user,
			'no_secret_values_returned'        => true,
		];
	}

	private function route( string $provided ): string {
		$provided = trim( $provided );
		if ( '' !== $provided ) {
			return mb_substr( sanitize_text_field( $provided ), 0, 255 );
		}
		$rest_route = $_GET['rest_route'] ?? ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only route classification; no state-changing action.
		if ( is_string( $rest_route ) && '' !== trim( $rest_route ) ) {
			return '/' . ltrim( sanitize_text_field( $rest_route ), '/' );
		}
		$uri  = (string) ( $_SERVER['REQUEST_URI'] ?? '' );
		$path = parse_url( $uri, PHP_URL_PATH );
		if ( ! is_string( $path ) || '' === $path ) {
			return '';
		}
		if ( preg_match( '#(/mcp/stonewright-oauth(?:/[^/]*)*)#', rawurldecode( $path ), $matches ) ) {
			return (string) $matches[1];
		}
		return mb_substr( sanitize_text_field( $path ), 0, 255 );
	}
}
