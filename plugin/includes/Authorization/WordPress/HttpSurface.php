<?php
/**
 * WordPress hooks of the OAuth HTTP surface.
 * SPDX-License-Identifier: AGPL-3.0-or-later
 *
 * @package Stonewright
 */

declare( strict_types=1 );

namespace Stonewright\WpMcp\Authorization\WordPress;

use Stonewright\WpMcp\Security\AuditLog;
use Stonewright\WpMcp\Support\Logger;

/**
 * What the plugin bootstrap calls once per load (register()), next to
 * AuthorizationLifecycle::register():
 *
 * - parse_request: the well-known discovery documents, without rewrite rules;
 * - rest_api_init: the stonewright/v1/oauth routes;
 * - rest_pre_dispatch and rest_post_dispatch: bearer protection of mcp/stonewright-oauth;
 * - rest_pre_serve_request: the empty body of a revocation answer;
 * - admin_menu: the hidden authorization and consent pages.
 *
 * Each hook checks SiteProfile::available() when it runs, so enabling or disabling
 * OAuth takes effect on the next request. The use_*() seams let tests or another
 * composition supply the site profile, document resolver and request limiter.
 */
final class HttpSurface {

	private static ?SiteProfile $site = null;
	private static ?ClientDocuments $documents = null;
	private static ?RequestLimiter $limiter = null;

	public static function register(): void {
		add_action( 'parse_request', [ DiscoveryDocuments::class, 'serve' ], 0, 0 );
		add_action( 'rest_api_init', [ OAuthRestRoutes::class, 'register' ], 10, 0 );
		add_filter( 'rest_pre_serve_request', [ OAuthRestRoutes::class, 'serve_empty_body' ], 10, 3 );
		add_filter( 'rest_pre_dispatch', [ ProtectedResource::class, 'guard' ], 5, 3 );
		add_filter( 'rest_post_dispatch', [ ProtectedResource::class, 'finish' ], 10, 3 );
		add_action( 'admin_menu', [ AuthorizationPages::class, 'register_pages' ], 10, 0 );
	}

	public static function use_site( ?SiteProfile $site ): void {
		self::$site = $site;
	}

	public static function use_documents( ?ClientDocuments $documents ): void {
		self::$documents = $documents;
	}

	public static function use_limiter( ?RequestLimiter $limiter ): void {
		self::$limiter = $limiter;
	}

	public static function site(): SiteProfile {
		return self::$site ?? SiteProfile::wordpress();
	}

	public static function documents(): ClientDocuments {
		return self::$documents ?? new ClientDocuments( AuthorizationLifecycle::storage()->clients(), AuthorizationLifecycle::storage()->clock() );
	}

	public static function limiter(): RequestLimiter {
		return self::$limiter ?? new RequestLimiter( AuthorizationLifecycle::storage()->database(), AuthorizationLifecycle::storage()->clock() );
	}

	/** Record an endpoint answer in the audit log; a failing recorder never changes the answer. */
	public static function audit( string $ability, OAuthReply $reply ): void {
		self::record( $ability, $reply->audit_response(), $reply->audit );
	}

	/** Record an authorization page answer in the audit log. */
	public static function audit_page( string $ability, PageOutcome $outcome ): void {
		$body = is_array( $outcome->audit['body'] ?? null ) ? $outcome->audit['body'] : [];
		if ( PageOutcome::ERROR === $outcome->kind ) {
			$body = [
				'error'             => 'invalid_request',
				'error_description' => $outcome->message,
			];
		}
		self::record( $ability, new \WP_REST_Response( $body, $outcome->status ), $outcome->audit );
	}

	/** @param array<string, mixed> $facts */
	private static function record( string $ability, \WP_REST_Response $response, array $facts ): void {
		$context = [];
		if ( is_string( $facts['client_id'] ?? null ) && '' !== $facts['client_id'] ) {
			$context['client_id'] = $facts['client_id'];
		}
		if ( is_array( $facts['sensitive_values'] ?? null ) ) {
			$context['sensitive_values'] = array_values( array_filter( $facts['sensitive_values'], 'is_string' ) );
		}
		try {
			AuditLog::record_auth_event( $ability, $response, $context );
		} catch ( \Throwable $failure ) {
			Logger::warning( 'oauth_audit_failed', [ 'ability' => $ability, 'error_class' => get_class( $failure ) ] );
		}
	}
}
