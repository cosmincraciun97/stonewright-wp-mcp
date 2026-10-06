<?php
/**
 * REST receipts for browser-side native block serialization.
 * SPDX-License-Identifier: AGPL-3.0-or-later
 *
 * @package Stonewright
 */

declare( strict_types=1 );

namespace Stonewright\WpMcp\Gutenberg\BrowserQueue;

use Stonewright\WpMcp\Gutenberg\Finalizer\BlockQueue;
use Stonewright\WpMcp\Security\AuditLog;
use Stonewright\WpMcp\Abilities\Gutenberg\CancelFinalizerChanges;

/** Session-bound serialization receipts; post persistence remains an ability. */
final class QueueEndpoint {

	public static function attach_routes(): void {
		foreach ( [ 'heartbeat', 'claim', 'result', 'cancel' ] as $method ) {
			register_rest_route( 'stonewright/v1', '/block-finalizer/' . $method, [ 'methods' => 'POST', 'permission_callback' => [ QueueRequestGuard::class, 'permission' ], 'callback' => [ self::class, $method ] ] );
		}
	}

	/** @return array<string,mixed>|\WP_Error */
	public static function heartbeat( \WP_REST_Request $request ): array|\WP_Error {
		$scope = QueueRequestGuard::authorize( $request );
		if ( $scope instanceof \WP_Error ) {
			return $scope;
		}
		$args = self::arguments( $request, [ 'token', 'lease_id' ] );
		if ( $args instanceof \WP_Error ) {
			return $args;
		}
		$renewed = 0;
		if ( isset( $args['lease_id'] ) ) {
			$lease = self::identity( $args['lease_id'] );
			if ( $lease instanceof \WP_Error ) {
				return $lease;
			}
			$renewed = BlockQueue::renew_lease_for_scope( $scope, $lease, 45 );
			if ( $renewed instanceof \WP_Error ) {
				return $renewed;
			}
		}
		QueueConsole::note_live( $scope );
		$items = [];
		foreach ( BlockQueue::list_for_viewer( (int) $scope['post_id'] ) as $row ) {
			$record = BlockQueue::get( (string) $row['id'] );
			if ( ! is_array( $record ) || ! self::matches_scope( $record, $scope ) ) {
				continue;
			}
			$items[] = [ 'id' => (string) $row['id'], 'post_id' => (int) $row['post_id'], 'status' => (string) $row['status'], 'retryable' => ! in_array( $row['status'], [ 'serialized', 'failed', 'persisted', 'cancelled' ], true ) ];
		}
		return [ 'online' => true, 'renewed' => $renewed, 'counts' => BlockQueue::counts_for_scope( $scope ), 'items' => $items, 'retryable' => false ];
	}

	/** @return array<string,mixed>|\WP_Error */
	public static function cancel( \WP_REST_Request $request ): array|\WP_Error {
		$scope = QueueRequestGuard::authorize( $request );
		if ( $scope instanceof \WP_Error ) {
			return $scope;
		}
		$args = self::arguments( $request, [ 'token', 'change_ids', 'dry_run', 'confirm_cancel', 'confirmation_token' ] );
		if ( $args instanceof \WP_Error ) {
			return $args;
		}
		$ids = BlockQueue::canonicalize_change_ids( $args['change_ids'] ?? null );
		if ( $ids instanceof \WP_Error ) {
			return $ids;
		}
		if ( ! isset( $args['dry_run'] ) || ! is_bool( $args['dry_run'] ) || ( ! $args['dry_run'] && ( $args['confirm_cancel'] ?? null ) !== true ) || ( isset( $args['confirmation_token'] ) && ( ! is_string( $args['confirmation_token'] ) || strlen( $args['confirmation_token'] ) > 2048 ) ) ) {
			return self::invalid();
		}
		foreach ( $ids as $id ) {
			$record = BlockQueue::get( $id );
			if ( ! is_array( $record ) || ! self::matches_scope( $record, $scope ) ) {
				return self::forbidden();
			}
		}
		$input = [ 'change_ids' => $ids, 'dry_run' => $args['dry_run'] ];
		if ( isset( $args['confirmation_token'] ) ) {
			$input['confirmation_token'] = $args['confirmation_token'];
		}
		$ability = new CancelFinalizerChanges();
		$allowed = $ability->permission_callback( $input );
		if ( $allowed instanceof \WP_Error ) {
			return $allowed;
		}
		return $allowed ? $ability->execute( $input ) : self::forbidden();
	}

	/** @return array<string,mixed>|\WP_Error */
	public static function claim( \WP_REST_Request $request ): array|\WP_Error {
		$scope = QueueRequestGuard::authorize( $request );
		if ( $scope instanceof \WP_Error ) {
			return $scope;
		}
		$args = self::arguments( $request, [ 'token', 'lease_id' ] );
		if ( $args instanceof \WP_Error ) {
			return $args;
		}
		$lease = self::identity( $args['lease_id'] ?? null );
		if ( $lease instanceof \WP_Error ) {
			return $lease;
		}
		$claimed = BlockQueue::lease_pending_for_scope( $scope, $lease, 45 );
		if ( $claimed instanceof \WP_Error ) {
			return $claimed;
		}
		QueueConsole::note_live( $scope );
		$items = [];
		foreach ( $claimed as $record ) {
			$items[] = [ 'id' => (string) $record['id'], 'post_id' => (int) $record['post_id'], 'block_spec' => $record['block_spec'], 'status' => (string) $record['status'] ];
		}
		return [ 'items' => $items, 'counts' => BlockQueue::counts_for_scope( $scope ), 'retryable' => true ];
	}

	/** @return array<string,mixed>|\WP_Error */
	public static function result( \WP_REST_Request $request ): array|\WP_Error {
		$scope = QueueRequestGuard::authorize( $request );
		if ( $scope instanceof \WP_Error ) {
			return $scope;
		}
		$args = self::arguments( $request, [ 'token', 'lease_id', 'result_id', 'change_id', 'status', 'html', 'html_hash', 'error_code', 'message' ] );
		if ( $args instanceof \WP_Error ) {
			return $args;
		}
		foreach ( [ 'lease_id', 'result_id', 'change_id' ] as $key ) {
			$identity = self::identity( $args[ $key ] ?? null );
			if ( $identity instanceof \WP_Error ) {
				return $identity;
			}
		}
		$status = $args['status'] ?? '';
		if ( ! in_array( $status, [ 'serialized', 'failed' ], true ) ) {
			return self::invalid();
		}
		$html = $args['html'] ?? '';
		if ( ! is_string( $html ) || strlen( $html ) > BlockQueue::MAX_SERIALIZED_BYTES ) {
			return self::invalid();
		}
		return BlockQueue::with_lock( static fn () => self::accept_result( $args, $scope ) );
	}

	/**
	 * @param array<string,mixed> $args
	 * @param array<string,mixed> $scope
	 * @return array<string,mixed>|\WP_Error
	 */
	private static function accept_result( array $args, array $scope ): array|\WP_Error {
		$status = $args['status'];
		$html = $args['html'] ?? '';
		$record = BlockQueue::get( $args['change_id'] );
		if ( ! is_array( $record ) || ! self::matches_scope( $record, $scope ) ) {
			return self::forbidden();
		}
		if ( (string) ( $record['result_id'] ?? '' ) === $args['result_id'] && (string) $record['status'] !== $status ) {
			return self::conflict();
		}
		if ( is_array( $record ) && (string) ( $record['result_id'] ?? '' ) === $args['result_id'] && 'serialized' === $status && (string) ( $record['serialized_html_hash'] ?? '' ) !== hash( 'sha256', $html ) ) {
			return self::conflict();
		}
		if ( 'serialized' === $status ) {
			$hash = $args['html_hash'] ?? null;
			if ( ! is_string( $hash ) || ! preg_match( '/^[a-f0-9]{64}$/D', $hash ) || ! hash_equals( hash( 'sha256', $html ), $hash ) ) {
				return self::invalid();
			}
			$receipt = BlockQueue::accept_serialized_result( $args['change_id'], $html, $hash, $scope, $args['lease_id'], $args['result_id'] );
		} else {
			$code = $args['error_code'] ?? '';
			$message = $args['message'] ?? '';
			if ( ! is_string( $code ) || ! preg_match( '/^[a-z0-9_-]{1,64}$/D', $code ) || ! is_string( $message ) || strlen( $message ) > 500 ) {
				return self::invalid();
			}
			if ( (string) ( $record['result_id'] ?? '' ) === $args['result_id'] && ( (string) ( $record['error_code'] ?? '' ) !== sanitize_key( $code ) || (string) ( $record['error'] ?? '' ) !== sanitize_text_field( $message ) ) ) {
				return self::conflict();
			}
			$receipt = BlockQueue::accept_failed_result( $args['change_id'], $message, '', $code, $scope, $args['lease_id'], $args['result_id'] );
		}
		$error_data = $receipt instanceof \WP_Error ? $receipt->get_error_data() : null;
		AuditLog::record_rest_mutation( '/stonewright/v1/block-finalizer/result', 'POST', [ 'change_id' => $args['change_id'], 'queue_status' => $status, 'html_hash' => hash( 'sha256', $html ), 'effect_verified' => is_array( $receipt ), 'retryable' => is_array( $error_data ) && ! empty( $error_data['retryable'] ) ], $receipt instanceof \WP_Error ? 'error' : 'ok' );
		return $receipt;
	}

	/**
	 * @param list<string> $allowed
	 * @return array<string,mixed>|\WP_Error
	 */
	private static function arguments( \WP_REST_Request $request, array $allowed ): array|\WP_Error {
		$args = QueueRequestGuard::envelope( $request );
		if ( $args instanceof \WP_Error ) {
			return $args;
		}
		if ( array_diff( array_keys( $args ), $allowed ) ) {
			return self::invalid();
		}
		return $args;
	}

	private static function identity( mixed $value ): string|\WP_Error {
		return is_string( $value ) && preg_match( '/^[a-zA-Z0-9_-]{1,96}$/D', $value ) ? $value : self::invalid();
	}

	/**
	 * @param array<string,mixed> $record
	 * @param array<string,mixed> $scope
	 */
	private static function matches_scope( array $record, array $scope ): bool {
		return (int) ( $record['owner_user_id'] ?? 0 ) === (int) $scope['owner_user_id'] && (int) ( $record['post_id'] ?? 0 ) === (int) $scope['post_id'] && (string) ( $record['session_id'] ?? '' ) === (string) $scope['session_id'];
	}

	private static function forbidden(): \WP_Error {
		return new \WP_Error( 'stonewright_queue_forbidden', 'Queue result is unavailable in this scope.', [ 'status' => 403, 'retryable' => false ] );
	}

	private static function conflict(): \WP_Error {
		return new \WP_Error( 'stonewright_queue_result_conflict', 'A result identity cannot be reused with different bytes.', [ 'status' => 409, 'retryable' => false ] );
	}

	private static function invalid(): \WP_Error {
		return new \WP_Error( 'stonewright_queue_args', 'Invalid bounded queue request.', [ 'status' => 400, 'retryable' => false ] );
	}
}
