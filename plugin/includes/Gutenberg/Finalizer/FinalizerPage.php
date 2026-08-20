<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\Gutenberg\Finalizer;

use Stonewright\WpMcp\Admin\AdminShell;

/**
 * Hidden admin page that loads the live block editor scripts and serializes
 * queued `{name, attributes, innerBlocks}` specs. Persistence stays in
 * stonewright/blocks-finalize-batch.
 */
final class FinalizerPage {

	public const SLUG       = 'stonewright-block-finalizer';
	public const CAPABILITY = 'edit_posts';

	public static function register(): void {
		add_action( 'admin_menu', [ self::class, 'add_submenu' ] );
		add_action( 'admin_enqueue_scripts', [ self::class, 'enqueue' ] );
		add_action( 'rest_api_init', [ self::class, 'register_rest' ] );
		add_action( 'wp_ajax_stonewright_block_finalizer_pending', [ self::class, 'ajax_pending' ] );
		add_action( 'wp_ajax_stonewright_block_finalizer_result', [ self::class, 'ajax_result' ] );
	}

	public static function add_submenu(): void {
		add_submenu_page(
			'',
			__( 'Block finalizer', 'stonewright' ),
			__( 'Block finalizer', 'stonewright' ),
			self::CAPABILITY,
			self::SLUG,
			[ self::class, 'render' ]
		);
	}

	public static function enqueue( string $hook_suffix = '' ): void {
		$page = isset( $_GET['page'] ) ? sanitize_key( (string) wp_unslash( (string) $_GET['page'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( self::SLUG !== $page && ! str_contains( $hook_suffix, self::SLUG ) ) {
			return;
		}

		do_action( 'enqueue_block_editor_assets' );
		do_action( 'enqueue_block_assets' );

		$version = defined( 'STONEWRIGHT_VERSION' ) ? (string) STONEWRIGHT_VERSION : '0.1.0';
		$base    = defined( 'STONEWRIGHT_URL' ) ? (string) STONEWRIGHT_URL : '';
		$src     = $base . 'blocks/finalizer/finalizer.js';
		wp_enqueue_script(
			'stonewright-block-finalizer',
			$src,
			[ 'wp-blocks', 'wp-block-editor', 'wp-data', 'wp-api-fetch', 'wp-element', 'wp-dom-ready' ],
			$version,
			true
		);
	}

	public static function render(): void {
		if ( ! current_user_can( self::CAPABILITY ) ) {
			wp_die( esc_html__( 'You do not have permission to run the block finalizer.', 'stonewright' ) );
		}

		$token = isset( $_GET['token'] ) ? sanitize_text_field( wp_unslash( (string) $_GET['token'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( '' === $token ) {
			$issued = BlockQueue::issue_token();
			$token  = $issued['token'];
		}

		$count   = BlockQueue::pending_count();
		$rest    = rest_url( 'stonewright/v1/block-finalizer/' );
		$ajax    = admin_url( 'admin-ajax.php' );
		$config = [
			'token'       => $token,
			'restBase'    => $rest,
			'ajaxUrl'     => $ajax,
			'nonce'       => function_exists( 'wp_create_nonce' ) ? wp_create_nonce( 'wp_rest' ) : '',
			'queuedCount' => $count,
		];

		AdminShell::open( self::SLUG, [ 'title' => __( 'Block finalizer', 'stonewright' ) ] );
		echo '<div class="stonewright-block-finalizer-page">';
		echo '<header class="stonewright-page-header"><div><h1>' . esc_html__( 'Block finalizer', 'stonewright' ) . '</h1>';
		echo '<p>' . esc_html__( 'Keep this page open while a session runs. It serializes queued block specs with the live editor registry; Stonewright still persists through the guarded finalize ability.', 'stonewright' ) . '</p></div></header>';
		echo '<p class="stonewright-finalizer-count"><strong>' . esc_html__( 'Queued changes:', 'stonewright' ) . '</strong> ';
		echo '<span id="stonewright-finalizer-queued-count">' . esc_html( (string) $count ) . '</span></p>';
		echo '<iframe class="stonewright-block-finalizer-frame" hidden title="' . esc_attr( __( 'Block serializer', 'stonewright' ) ) . '"></iframe>';
		echo '<script>window.stonewrightBlockFinalizer = ' . wp_json_encode( $config ) . ';</script>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- JSON config.
		echo '</div>';
		AdminShell::close();
	}

	public static function register_rest(): void {
		register_rest_route(
			'stonewright/v1',
			'/block-finalizer/pending',
			[
				'methods'             => 'GET',
				'permission_callback' => [ self::class, 'rest_permission' ],
				'callback'            => [ self::class, 'rest_pending' ],
				'args'                => [
					'token' => [
						'type'     => 'string',
						'required' => true,
					],
				],
			]
		);
		register_rest_route(
			'stonewright/v1',
			'/block-finalizer/result',
			[
				'methods'             => 'POST',
				'permission_callback' => [ self::class, 'rest_permission' ],
				'callback'            => [ self::class, 'rest_result' ],
			]
		);
	}

	public static function rest_permission( \WP_REST_Request $request ): bool|\WP_Error {
		$token = (string) $request->get_param( 'token' );
		if ( '' === $token ) {
			$body = $request->get_json_params();
			$token = is_array( $body ) ? (string) ( $body['token'] ?? '' ) : '';
		}
		$verified = BlockQueue::verify_token( $token );
		if ( $verified instanceof \WP_Error ) {
			return $verified;
		}
		if ( ! is_user_logged_in() || ! current_user_can( self::CAPABILITY ) ) {
			return new \WP_Error(
				'stonewright_finalizer_forbidden',
				__( 'Invalid block finalizer token.', 'stonewright' ),
				[ 'status' => 403 ]
			);
		}
		return true;
	}

	public static function rest_pending( \WP_REST_Request $request ): \WP_REST_Response|\WP_Error {
		$token    = (string) $request->get_param( 'token' );
		$verified = BlockQueue::verify_token( $token );
		if ( $verified instanceof \WP_Error ) {
			return $verified;
		}
		$items = BlockQueue::pending_for_session( $verified['s'], true );
		return rest_ensure_response(
			[
				'items'        => $items,
				'queued_count' => BlockQueue::pending_count(),
			]
		);
	}

	public static function rest_result( \WP_REST_Request $request ): \WP_REST_Response|\WP_Error {
		$body      = $request->get_json_params();
		if ( [] === $body ) {
			$body = $request->get_params();
		}
		$change_id = sanitize_text_field( (string) ( $body['change_id'] ?? '' ) );
		$html      = (string) ( $body['html'] ?? '' );
		$hash      = (string) ( $body['html_hash'] ?? $body['hash'] ?? '' );
		$errors    = isset( $body['errors'] ) && is_array( $body['errors'] ) ? $body['errors'] : [];
		if ( [] !== $errors ) {
			$first = $errors[0];
			$message = is_array( $first ) ? (string) ( $first['message'] ?? 'validation failed' ) : (string) $first;
			BlockQueue::mark_failed( $change_id, $message );
			return rest_ensure_response(
				[
					'ok'     => false,
					'status' => 'failed',
					'errors' => array_slice( $errors, 0, 20 ),
				]
			);
		}
		$stored = BlockQueue::store_serialized( $change_id, $html, $hash );
		if ( $stored instanceof \WP_Error ) {
			return $stored;
		}
		return rest_ensure_response(
			[
				'ok'     => true,
				'status' => 'serialized',
			]
		);
	}

	public static function ajax_pending(): void {
		$token    = isset( $_REQUEST['token'] ) ? sanitize_text_field( wp_unslash( (string) $_REQUEST['token'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$verified = BlockQueue::verify_token( $token );
		if ( $verified instanceof \WP_Error ) {
			wp_send_json_error( [ 'code' => $verified->get_error_code(), 'message' => $verified->get_error_message() ], 403 );
			return;
		}
		if ( ! current_user_can( self::CAPABILITY ) ) {
			wp_send_json_error( [ 'code' => 'stonewright_finalizer_forbidden' ], 403 );
			return;
		}
		wp_send_json_success(
			[
				'items'        => BlockQueue::pending_for_session( $verified['s'], true ),
				'queued_count' => BlockQueue::pending_count(),
			]
		);
	}

	public static function ajax_result(): void {
		$token    = isset( $_POST['token'] ) ? sanitize_text_field( wp_unslash( (string) $_POST['token'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Missing
		$verified = BlockQueue::verify_token( $token );
		if ( $verified instanceof \WP_Error ) {
			wp_send_json_error( [ 'code' => $verified->get_error_code(), 'message' => $verified->get_error_message() ], 403 );
			return;
		}
		if ( ! current_user_can( self::CAPABILITY ) ) {
			wp_send_json_error( [ 'code' => 'stonewright_finalizer_forbidden' ], 403 );
			return;
		}
		$change_id = isset( $_POST['change_id'] ) ? sanitize_text_field( wp_unslash( (string) $_POST['change_id'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Missing
		$html      = isset( $_POST['html'] ) ? wp_unslash( (string) $_POST['html'] ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Missing,WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
		$hash      = isset( $_POST['html_hash'] ) ? sanitize_text_field( wp_unslash( (string) $_POST['html_hash'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Missing
		$stored    = BlockQueue::store_serialized( $change_id, $html, $hash );
		if ( $stored instanceof \WP_Error ) {
			wp_send_json_error( [ 'code' => $stored->get_error_code(), 'message' => $stored->get_error_message() ], 400 );
			return;
		}
		wp_send_json_success( [ 'status' => 'serialized' ] );
	}

	public static function url( string $token = '' ): string {
		if ( '' === $token ) {
			$token = BlockQueue::issue_token()['token'];
		}
		return add_query_arg(
			[
				'page'  => self::SLUG,
				'token' => $token,
			],
			admin_url( 'admin.php' )
		);
	}
}
