<?php
/**
 * Admin journal page and asset loader for the native block serialization queue.
 * SPDX-License-Identifier: GPL-2.0-or-later
 *
 * @package Stonewright
 */

declare( strict_types=1 );

namespace Stonewright\WpMcp\Gutenberg\BrowserQueue;

use Stonewright\WpMcp\Gutenberg\Finalizer\BlockQueue;
use Stonewright\WpMcp\Security\Permissions;

/** Hidden admin journal that prepares queued block changes in native editors; saving stays with the finalize ability. */
final class QueueConsole {

	public const PAGE = 'stonewright-block-finalizer';

	private static bool $attached = false;

	public static function attach_hooks(): void {
		if ( self::$attached ) {
			return;
		}
		self::$attached = true;
		add_action( 'admin_menu', [ self::class, 'attach_page' ] );
		add_action( 'admin_enqueue_scripts', [ self::class, 'enqueue' ] );
		add_action( 'rest_api_init', [ QueueEndpoint::class, 'attach_routes' ] );
	}

	/** Registers the journal under options.php: reachable by URL for post editors, never listed in a menu. */
	public static function attach_page(): void {
		add_submenu_page( 'options.php', 'Block serialization journal', 'Block serialization journal', 'edit_posts', self::PAGE, [ self::class, 'render' ] );
	}

	public static function session_link( string $token = '', string $session = '' ): string {
		if ( '' === $token ) {
			$issued = BlockQueue::issue_token( $session );
			if ( is_array( $issued ) ) {
				$token = (string) $issued['token'];
			}
		}
		return add_query_arg( array_filter( [ 'page' => self::PAGE, 'stonewright_queue_token' => $token ] ), admin_url( 'admin.php' ) );
	}

	/** @param array<string,mixed> $scope */
	public static function note_live( array $scope ): void {
		set_transient( 'stonewright_queue_live_' . (int) $scope['owner_user_id'], [ 'seen_at' => time(), 'session_id' => (string) $scope['session_id'] ], 45 );
	}

	/** @return array<string,mixed> */
	public static function runtime_summary(): array {
		$live = get_transient( 'stonewright_queue_live_' . get_current_user_id() );
		return [ 'online' => is_array( $live ) && (int) ( $live['seen_at'] ?? 0 ) >= time() - 45, 'queued_count' => BlockQueue::pending_count(), 'failed_count' => BlockQueue::failed_count() ];
	}

	/** @return list<array<string,mixed>> */
	public static function target_summaries(): array {
		$out = [];
		foreach ( BlockQueue::list_for_viewer() as $row ) {
			if ( ! in_array( $row['status'], [ 'queued', 'failed' ], true ) ) {
				continue;
			}
			$issued = BlockQueue::issue_token( (string) $row['session_id'] );
			if ( $issued instanceof \WP_Error ) {
				continue;
			}
			$out[] = [ 'post_id' => (int) $row['post_id'], 'change_id' => (string) $row['id'], 'status' => (string) $row['status'], 'pending_count' => 'queued' === $row['status'] ? 1 : 0, 'failed_count' => 'failed' === $row['status'] ? 1 : 0, 'editor_frame_url' => add_query_arg( [ 'post' => (int) $row['post_id'], 'action' => 'edit', 'stonewright_queue_token' => (string) $issued['token'] ], admin_url( 'post.php' ) ), 'queue_url' => self::session_link( (string) $issued['token'] ) ];
		}
		return $out;
	}

	public static function enqueue( string $hook = '' ): void {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- Read-only asset routing; the queue token is verified before it grants anything.
		$page = isset( $_GET['page'] ) && is_string( $_GET['page'] ) ? sanitize_key( wp_unslash( $_GET['page'] ) ) : '';
		$token = isset( $_GET['stonewright_queue_token'] ) && is_string( $_GET['stonewright_queue_token'] ) ? wp_unslash( $_GET['stonewright_queue_token'] ) : '';
		$scope = '' !== $token ? BlockQueue::verify_token( $token ) : null;
		$native = 'post.php' === $hook && is_array( $scope ) && isset( $_GET['post'] ) && (int) $_GET['post'] === (int) $scope['post_id'];
		// phpcs:enable WordPress.Security.NonceVerification.Recommended
		if ( self::PAGE !== $page && ! $native ) {
			return;
		}
		if ( ! Permissions::edit_posts() || ( is_array( $scope ) && ! Permissions::edit_post( (int) $scope['post_id'] ) ) ) {
			return;
		}
		$base = plugins_url( 'assets/admin/', STONEWRIGHT_DIR . 'stonewright.php' );
		wp_enqueue_style( 'stonewright-block-queue', $base . 'block-queue.css', [], STONEWRIGHT_VERSION );
		wp_enqueue_script( 'stonewright-block-queue', $base . 'block-queue.js', [ 'wp-blocks', 'wp-data', 'wp-block-library', 'wp-api-fetch' ], STONEWRIGHT_VERSION, true );
		wp_add_inline_script( 'stonewright-block-queue', 'window.stonewrightBlockQueue=' . wp_json_encode( [ 'base' => rest_url( 'stonewright/v1/block-finalizer/' ), 'nonce' => wp_create_nonce( 'wp_rest' ), 'token' => is_array( $scope ) ? $token : '', 'mode' => get_option( 'stonewright_mode', 'development' ), 'native' => $native, 'targets' => $native ? [] : self::target_summaries(), 'maxBytes' => BlockQueue::MAX_SERIALIZED_BYTES ] ) . ';', 'before' );
	}

	public static function render(): void {
		if ( ! Permissions::edit_posts() ) {
			wp_die( esc_html__( 'Queue access is unavailable.', 'stonewright' ) );
		}
		echo '<main class="wrap sw-queue-console"><h1>' . esc_html__( 'Block serialization journal', 'stonewright' ) . '</h1><p>' . esc_html__( 'Keep this tab open while native editors prepare queued blocks. Content is saved only after the finalize ability verifies it.', 'stonewright' ) . '</p><p data-queue-status role="status" aria-live="polite"></p><dl data-queue-counts></dl><button type="button" data-queue-resume>' . esc_html__( 'Resume processing', 'stonewright' ) . '</button><ol data-queue-journal></ol><div data-queue-frames hidden></div></main>';
	}
}
