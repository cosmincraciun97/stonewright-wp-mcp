<?php
/**
 * Request authorization for the browser serialization queue.
 * SPDX-License-Identifier: AGPL-3.0-or-later
 *
 * @package Stonewright
 */

declare( strict_types=1 );

namespace Stonewright\WpMcp\Gutenberg\BrowserQueue;

use Stonewright\WpMcp\Gutenberg\Finalizer\BlockQueue;
use Stonewright\WpMcp\Security\Permissions;

/** Browser authorization never grants content persistence authority. */
final class QueueRequestGuard {

	/** @return array<string,mixed>|\WP_Error */
	public static function authorize( \WP_REST_Request $request ): array|\WP_Error {
		$body = self::envelope( $request );
		if ( $body instanceof \WP_Error ) {
			return $body;
		}
		$nonce = (string) $request->get_header( 'X-WP-Nonce' );
		$origin = (string) $request->get_header( 'Origin' );
		if ( '' === $nonce || ! wp_verify_nonce( $nonce, 'wp_rest' ) ) {
			return new \WP_Error( 'stonewright_queue_nonce', 'The queue browser session must be refreshed.', [ 'status' => 403, 'retryable' => false ] );
		}
		$site_origin = self::origin( home_url() );
		if ( '' === $site_origin || ( '' !== $origin && ( '' === self::origin( $origin ) || self::origin( $origin ) !== $site_origin ) ) ) {
			return new \WP_Error( 'stonewright_queue_origin', 'Queue requests must use the same site origin.', [ 'status' => 403, 'retryable' => false ] );
		}
		$token = $body['token'] ?? null;
		if ( ! is_string( $token ) || strlen( $token ) > 2048 || ! Permissions::edit_posts() ) {
			return new \WP_Error( 'stonewright_queue_forbidden', 'Queue access is unavailable.', [ 'status' => 403, 'retryable' => false ] );
		}
		$scope = BlockQueue::verify_token( $token );
		if ( $scope instanceof \WP_Error ) {
			return new \WP_Error( 'stonewright_queue_forbidden', 'Queue access is unavailable.', [ 'status' => 403, 'retryable' => false ] );
		}
		if ( ! Permissions::edit_post( (int) $scope['post_id'] ) ) {
			return new \WP_Error( 'stonewright_queue_forbidden', 'The queued target cannot be edited.', [ 'status' => 403, 'retryable' => false ] );
		}
		if ( ! in_array( get_option( 'stonewright_mode', 'development' ), [ 'development', 'staging', 'production-safe' ], true ) ) {
			return new \WP_Error( 'stonewright_queue_mode', 'Unknown Stonewright safety mode.', [ 'status' => 403, 'retryable' => false ] );
		}
		return $scope;
	}

	public static function permission( \WP_REST_Request $request ): bool|\WP_Error {
		$scope = self::authorize( $request );
		return $scope instanceof \WP_Error ? $scope : true;
	}

	/**
	 * JSON body is the only authority envelope; URL routing fields are excluded.
	 *
	 * @return array<string,mixed>|\WP_Error
	 */
	public static function envelope( \WP_REST_Request $request ): array|\WP_Error {
		$body = $request->get_json_params();
		$encoded = is_array( $body ) ? wp_json_encode( $body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES ) : false;
		if ( ! is_array( $body ) || ( [] !== $body && array_is_list( $body ) ) || ! is_string( $encoded ) || strlen( $encoded ) > BlockQueue::MAX_SERIALIZED_BYTES + 8192 ) {
			return new \WP_Error( 'stonewright_queue_body', 'A bounded JSON object body is required.', [ 'status' => 400, 'retryable' => false ] );
		}
		return $body;
	}

	private static function origin( string $value ): string {
		$parts = wp_parse_url( $value );
		if ( ! is_array( $parts ) || empty( $parts['scheme'] ) || empty( $parts['host'] ) || isset( $parts['user'] ) || isset( $parts['pass'] ) || ! in_array( strtolower( (string) $parts['scheme'] ), [ 'https', 'http' ], true ) ) {
			return '';
		}
		$scheme = strtolower( (string) $parts['scheme'] );
		$port = (int) ( $parts['port'] ?? ( 'https' === $scheme ? 443 : 80 ) );
		return $scheme . '://' . strtolower( (string) $parts['host'] ) . ':' . $port;
	}
}
