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
 *
 * Audit: ordinary endpoint answers go through the audit log's OAuth recorder, which
 * coalesces repeated identical events. Security events (a replay that revokes a
 * family, a code replay, an explicit revocation and a duplicate delivery) are written
 * as their own rows every time, with the same allowlisted fields and no credential.
 */
final class HttpSurface {

	public const EVENT_REFRESH_REPLAY = 'refresh_replay_revoked';
	public const EVENT_CODE_REPLAY = 'code_replay_revoked';
	public const EVENT_REVOCATION = 'revocation';
	public const EVENT_REDELIVERY = 'refresh_redelivered';

	private const EVENTS = [ self::EVENT_REFRESH_REPLAY, self::EVENT_CODE_REPLAY, self::EVENT_REVOCATION, self::EVENT_REDELIVERY ];

	/** Response fields an audit row may hold, mapped to their audit key. */
	private const AUDIT_FIELDS = [
		'error'             => 'oauth_error',
		'error_description' => 'oauth_error_description',
		'hint'              => 'oauth_hint',
	];

	private const AUDIT_TEXT_LIMIT = 200;

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
		$event = $facts['event'] ?? null;
		try {
			if ( is_string( $event ) && in_array( $event, self::EVENTS, true ) ) {
				self::record_event( $ability, $event, $response, $context );
				return;
			}
			AuditLog::record_auth_event( $ability, $response, $context );
		} catch ( \Throwable $failure ) {
			Logger::warning( 'oauth_audit_failed', [ 'ability' => $ability, 'error_class' => get_class( $failure ) ] );
		}
	}

	/**
	 * Write one security event as its own row, outside the coalescing of ordinary
	 * answers. The row holds only the allowlisted response fields, the client identifier
	 * and the HTTP status; request values that are credentials are masked.
	 *
	 * @param array<string, mixed> $context client_id and sensitive_values.
	 */
	private static function record_event( string $ability, string $event, \WP_REST_Response $response, array $context ): void {
		$http = (int) $response->get_status();
		$body = $response->get_data();
		$body = is_array( $body ) ? $body : [];
		$secrets = array_values( array_filter( (array) ( $context['sensitive_values'] ?? [] ), static fn ( $value ): bool => is_string( $value ) && strlen( $value ) >= 8 ) );
		$args = [];
		foreach ( self::AUDIT_FIELDS as $source => $target ) {
			if ( isset( $body[ $source ] ) && is_scalar( $body[ $source ] ) ) {
				$value = self::audit_text( (string) $body[ $source ], $secrets );
				if ( '' !== $value ) {
					$args[ $target ] = $value;
				}
			}
		}
		$client_id = self::audit_text( (string) ( $context['client_id'] ?? '' ), $secrets );
		if ( '' !== $client_id ) {
			$args['client_id'] = $client_id;
		}
		$args['http_status'] = $http;
		$args['_meta'] = [
			'error_code'      => (string) ( $args['oauth_error'] ?? '' ),
			'operation_class' => 'oauth',
			'resource_type'   => 'oauth_endpoint',
			'resource_ref'    => $ability,
			'http_status'     => $http,
			'security_event'  => $event,
		];
		if ( isset( $args['oauth_error_description'] ) ) {
			$args['_meta']['public_message'] = $args['oauth_error_description'];
		}
		$status = $http >= 500 ? 'error' : ( $http >= 400 ? 'auth' : 'ok' );
		AuditLog::record( $ability, $args, $status );
	}

	/** @param list<string> $secrets Request values that must never be written. */
	private static function audit_text( string $value, array $secrets ): string {
		$value = sanitize_text_field( $value );
		if ( [] !== $secrets ) {
			$value = str_replace( $secrets, '[redacted]', $value );
		}
		return function_exists( 'mb_substr' ) ? mb_substr( $value, 0, self::AUDIT_TEXT_LIMIT ) : substr( $value, 0, self::AUDIT_TEXT_LIMIT );
	}
}
