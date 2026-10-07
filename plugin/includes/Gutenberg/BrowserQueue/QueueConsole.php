<?php
/**
 * Admin journal page and asset loader for the native block serialization queue.
 * SPDX-License-Identifier: GPL-2.0-or-later
 *
 * @package Stonewright
 */

declare( strict_types=1 );

namespace Stonewright\WpMcp\Gutenberg\BrowserQueue;

use Stonewright\WpMcp\Admin\AdminShell;
use Stonewright\WpMcp\Admin\MenuRegistry;
use Stonewright\WpMcp\Admin\Ui\Button;
use Stonewright\WpMcp\Admin\Ui\Card;
use Stonewright\WpMcp\Admin\Ui\EmptyState;
use Stonewright\WpMcp\Admin\Ui\Html;
use Stonewright\WpMcp\Admin\Ui\Notice;
use Stonewright\WpMcp\Admin\Ui\Scope;
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
		self::add_to_menu_registry();
		add_action( 'admin_menu', [ self::class, 'attach_page' ] );
		add_action( 'admin_enqueue_scripts', [ self::class, 'enqueue' ] );
		add_action( 'rest_api_init', [ QueueEndpoint::class, 'attach_routes' ] );
	}

	/** The tab of the Activity hub. Idempotent: attach_hooks() and render() both make sure it exists. */
	private static function add_to_menu_registry(): void {
		MenuRegistry::add(
			self::PAGE,
			__( 'Block queue', 'stonewright' ),
			'activity',
			[
				'order'       => 20,
				'beta'        => true,
				'in_menu'     => false,
				'capability'  => 'edit_posts',
				'lede'        => self::lede(),
				'count'       => static fn (): int => BlockQueue::pending_count() + BlockQueue::failed_count(),
				'count_label' => __( 'queued or failed changes', 'stonewright' ),
			]
		);
	}

	/** Registers the journal under options.php: reachable by URL for post editors, never listed in a menu. */
	public static function attach_page(): void {
		add_submenu_page( 'options.php', __( 'Block queue', 'stonewright' ), __( 'Block queue', 'stonewright' ), 'edit_posts', self::PAGE, [ self::class, 'render' ] );
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

	private static function lede(): string {
		return __( 'Keep this tab open while native editors prepare queued blocks. Content is saved only after the finalize ability verifies it.', 'stonewright' );
	}

	public static function render(): void {
		if ( ! Permissions::edit_posts() ) {
			wp_die( esc_html__( 'Queue access is unavailable.', 'stonewright' ) );
		}
		self::add_to_menu_registry();
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only: the token is verified before it shows anything.
		$token     = isset( $_GET['stonewright_queue_token'] ) && is_string( $_GET['stonewright_queue_token'] ) ? wp_unslash( $_GET['stonewright_queue_token'] ) : '';
		$connected = '' !== $token && is_array( BlockQueue::verify_token( $token ) );

		AdminShell::open(
			self::PAGE,
			[
				'title'   => __( 'Block queue', 'stonewright' ),
				'lede'    => self::lede(),
				'hub'     => 'activity',
				'beta'    => true,
				'actions' => $connected ? Button::render( __( 'Resume processing', 'stonewright' ), [ 'attrs' => [ 'data-queue-resume' => true ] ] ) : '',
			]
		);
		echo Scope::wrap( self::console_html( $connected ), [ 'page' => true, 'class' => 'sw-queue-console' ] ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Markup built by the Ui helpers, which escape every value.
		AdminShell::close();
	}

	/** The queued and failed counts as one band; the script replaces them with the session's own counts. */
	private static function counts_html(): string {
		$stat = static fn ( string $label, int $value ): string => Html::element(
			'div',
			[ 'class' => 'sw-ui-stat' ],
			Html::element( 'span', [ 'class' => 'sw-ui-stat__label' ], Html::text( $label ) ) . Html::element( 'span', [ 'class' => 'sw-ui-stat__value' ], Html::text( (string) $value ) )
		);

		return Html::element(
			'div',
			[ 'class' => 'sw-ui-stats', 'role' => 'group', 'aria-label' => __( 'Block changes by state', 'stonewright' ), 'data-queue-counts' => true ],
			$stat( __( 'Queued', 'stonewright' ), BlockQueue::pending_count() ) . $stat( __( 'Failed', 'stonewright' ), BlockQueue::failed_count() )
		);
	}

	private static function console_html( bool $connected ): string {
		if ( ! $connected ) {
			return self::counts_html() . Card::render(
				__( 'Queue session', 'stonewright' ),
				EmptyState::render(
					__( 'No queue session is open', 'stonewright' ),
					__( 'The block queue works with a session your agent starts. Open the link your agent returns after it queues a block change. Nothing is saved from this page.', 'stonewright' ),
					[ 'variant' => 'first-run', 'icon' => 'plug' ]
				)
			);
		}

		$status  = Notice::render(
			'info',
			__( 'Queue session', 'stonewright' ),
			'',
			[ 'text_html' => Html::element( 'span', [ 'data-queue-status' => true ], Html::text( __( 'Connecting to the queue.', 'stonewright' ) ) ) ]
		);
		$head    = Html::element(
			'tr',
			[],
			Html::element( 'th', [ 'scope' => 'col' ], Html::text( __( 'Change', 'stonewright' ) ) )
				. Html::element( 'th', [ 'scope' => 'col' ], Html::text( __( 'State', 'stonewright' ) ) )
				. Html::element( 'th', [ 'scope' => 'col' ], Html::text( __( 'Details', 'stonewright' ) ) )
				. Html::element( 'th', [ 'scope' => 'col', 'class' => 'sw-ui-table__actions' ], Html::element( 'span', [ 'class' => 'sw-ui-visually-hidden' ], Html::text( __( 'Actions', 'stonewright' ) ) ) )
		);
		$table   = Html::element(
			'table',
			[ 'class' => 'sw-ui-table sw-ui-table--stack' ],
			Html::element( 'caption', [ 'class' => 'sw-ui-visually-hidden' ], Html::text( __( 'Block changes in this session', 'stonewright' ) ) )
				. Html::element( 'thead', [], $head )
				. Html::element( 'tbody', [ 'data-queue-journal' => true ], '' )
		);
		$empty   = Html::element(
			'div',
			[ 'data-queue-empty' => true ],
			EmptyState::render( __( 'No block changes in this session yet.', 'stonewright' ), __( 'Changes appear here as the editor prepares them.', 'stonewright' ), [ 'variant' => 'inline' ] )
		);
		$journal = Card::render( __( 'Changes in this session', 'stonewright' ), $table . $empty, [ 'flush' => true ] );

		return $status . self::counts_html() . $journal . Html::element( 'div', [ 'data-queue-frames' => true, 'hidden' => true ], '' );
	}
}
