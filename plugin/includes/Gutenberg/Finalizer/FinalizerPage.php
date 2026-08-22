<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\Gutenberg\Finalizer;

use Stonewright\WpMcp\Admin\AdminShell;

/**
 * Visible Block Editor Queue page that loads the live block editor scripts and
 * serializes queued `{name, attributes, innerBlocks}` specs. Persistence stays
 * in stonewright/blocks-finalize-batch.
 */
final class FinalizerPage {

	public const SLUG             = 'stonewright-block-finalizer';
	public const CAPABILITY       = 'edit_posts';
	public const ONLINE_TRANSIENT = 'stonewright_finalizer_online';
	public const ONLINE_TTL       = 45;

	public static function register(): void {
		add_action( 'admin_menu', [ self::class, 'add_submenu' ] );
		add_action( 'admin_enqueue_scripts', [ self::class, 'enqueue' ] );
		add_action( 'enqueue_block_editor_assets', [ self::class, 'lock_editor_persistence' ] );
		add_filter( 'block_editor_settings_all', [ self::class, 'disable_editor_autosave' ] );
		add_filter( 'rest_pre_dispatch', [ self::class, 'reject_finalizer_live_writes' ], 0, 3 );
		add_filter( 'heartbeat_received', [ self::class, 'drop_heartbeat_autosave' ], 0, 2 );
		add_action( 'rest_api_init', [ self::class, 'register_rest' ] );
		add_action( 'wp_ajax_stonewright_block_finalizer_pending', [ self::class, 'ajax_pending' ] );
		add_action( 'wp_ajax_stonewright_block_finalizer_result', [ self::class, 'ajax_result' ] );
		add_filter( 'admin_title', [ self::class, 'filter_admin_title' ], 10, 2 );
	}

	public static function add_submenu(): void {
		add_submenu_page(
			'stonewright',
			__( 'Block Editor Queue', 'stonewright' ),
			AdminShell::experimental_menu_title( __( 'Block Editor Queue', 'stonewright' ) ),
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
			[ 'wp-blocks', 'wp-block-editor', 'wp-data', 'wp-api-fetch', 'wp-element', 'wp-dom-ready', 'wp-block-library' ],
			$version,
			true
		);
	}

	public static function render(): void {
		if ( ! current_user_can( self::CAPABILITY ) ) {
			wp_die( esc_html__( 'You do not have permission to run the block finalizer.', 'stonewright' ) );
		}

		$token = isset( $_GET['token'] ) ? sanitize_text_field( wp_unslash( (string) $_GET['token'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( '' !== $token ) {
			$verified = BlockQueue::verify_token( $token );
			if ( $verified instanceof \WP_Error ) {
				$token = '';
			}
		}
		$owned = BlockQueue::owned_sessions();
		if ( '' === $token ) {
			foreach ( $owned as $session ) {
				if ( (int) $session['queued_count'] <= 0 ) {
					continue;
				}
				$issued = BlockQueue::issue_token( (string) $session['session_id'] );
				if ( is_array( $issued ) ) {
					$token = $issued['token'];
				}
				break;
			}
		}

		$count        = BlockQueue::pending_count();
		$owned_queued = 0;
		foreach ( $owned as $session ) {
			$owned_queued += (int) $session['queued_count'];
		}
		$foreign = BlockQueue::viewer_has_foreign_records();
		$rest    = rest_url( 'stonewright/v1/block-finalizer/' );
		$ajax    = admin_url( 'admin-ajax.php' );
		self::mark_online();
		$config = [
			'token'       => $token,
			'restBase'    => $rest,
			'ajaxUrl'     => $ajax,
			'nonce'       => function_exists( 'wp_create_nonce' ) ? wp_create_nonce( 'wp_rest' ) : '',
			'queuedCount' => $owned_queued,
		];

		AdminShell::open( self::SLUG, [ 'title' => __( 'Block Editor Queue', 'stonewright' ) ] );
		$idle = 0 === $owned_queued;
		echo '<div class="stonewright-block-finalizer-page">';
		echo '<header class="stonewright-page-header"><div><h1>' . esc_html__( 'Block Editor Queue', 'stonewright' ) . '</h1>';
		echo '<p>' . esc_html__( 'Live editor bridge for queued Gutenberg changes.', 'stonewright' ) . '</p></div></header>';
		echo '<section class="sw-finalizer-panel" aria-live="polite">';
		echo '<p>' . esc_html__( 'Stonewright serializes queued block changes through the native WordPress editor so hashed HTML can be saved with the existing backup, confirmation, and audit gates.', 'stonewright' ) . '</p>';
		echo '<p><strong>' . esc_html__( 'Keep this tab open while an agent session runs — queued block changes are serialized here.', 'stonewright' ) . '</strong></p>';
		if ( $foreign ) {
			echo '<p class="sw-finalizer-owner-mismatch" role="status">';
			echo esc_html__( 'These queued changes belong to another user.', 'stonewright' );
			echo ' ';
			echo esc_html__( 'Cancel them with stonewright/blocks-finalizer-cancel.', 'stonewright' );
			echo '</p>';
		}
		echo '<p class="sw-finalizer-status' . ( $idle ? '' : ' is-busy' ) . '" id="stonewright-finalizer-status">';
		echo esc_html(
			$idle
				? __( 'Nothing to serialize. The queue is ready.', 'stonewright' )
				: __( 'Serializing queued block changes…', 'stonewright' )
		);
		echo '</p>';
		echo '<div class="sw-finalizer-strip" id="stonewright-finalizer-strip">';
		echo '<span class="sw-finalizer-online" id="stonewright-finalizer-online" data-online="true" role="status">' . esc_html__( 'Online', 'stonewright' ) . '</span>';
		echo '<span class="sw-finalizer-poll">' . esc_html__( 'Last poll:', 'stonewright' ) . ' <time id="stonewright-finalizer-last-poll">—</time></span>';
		echo '<span class="sw-finalizer-metric">' . esc_html__( 'Queued', 'stonewright' ) . ' <strong id="stonewright-finalizer-queued-count">' . esc_html( (string) $count ) . '</strong></span>';
		echo '<span class="sw-finalizer-metric">' . esc_html__( 'Applied', 'stonewright' ) . ' <strong id="stonewright-finalizer-applied-count">0</strong></span>';
		echo '<span class="sw-finalizer-metric">' . esc_html__( 'Failed', 'stonewright' ) . ' <strong id="stonewright-finalizer-failed-count">0</strong></span>';
		echo '</div>';
		echo '<ul class="sw-finalizer-items" id="stonewright-finalizer-items" aria-live="polite"></ul>';
		echo '</section>';
		echo '<iframe id="stonewright-finalizer-frame" class="stonewright-block-finalizer-frame" hidden title="' . esc_attr( __( 'Block serializer', 'stonewright' ) ) . '"></iframe>';
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
		register_rest_route(
			'stonewright/v1',
			'/block-finalizer/heartbeat',
			[
				'methods'             => 'POST',
				'permission_callback' => [ self::class, 'rest_permission' ],
				'callback'            => [ self::class, 'rest_heartbeat' ],
			]
		);
	}

	public static function rest_heartbeat( \WP_REST_Request $request ): \WP_REST_Response|\WP_Error {
		$token    = self::request_token( $request );
		$verified = BlockQueue::verify_token( $token );
		if ( $verified instanceof \WP_Error ) {
			return $verified;
		}
		self::mark_online();
		return rest_ensure_response(
			[
				'ok'     => true,
				'online' => true,
			]
		);
	}

	public static function filter_admin_title( string $admin_title, string $title ): string {
		$page = isset( $_GET['page'] ) ? sanitize_key( (string) wp_unslash( (string) $_GET['page'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( self::SLUG !== $page ) {
			return $admin_title;
		}
		$label = __( 'Block Editor Queue', 'stonewright' );
		if ( str_starts_with( $admin_title, $label ) || str_starts_with( $title, $label ) ) {
			return $admin_title;
		}
		return $label . $admin_title;
	}

	public static function rest_permission( \WP_REST_Request $request ): bool|\WP_Error {
		$token = self::request_token( $request );
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
		$counts = BlockQueue::counts_for_scope( $verified );
		$items  = self::with_editor_urls( BlockQueue::pending_for_scope( $verified, true ) );
		return rest_ensure_response(
			[
				'items'        => $items,
				'queued_count' => $counts['queued'],
				'failed_count' => $counts['failed'],
			]
		);
	}

	public static function rest_result( \WP_REST_Request $request ): \WP_REST_Response|\WP_Error {
		$token    = self::request_token( $request );
		$verified = BlockQueue::verify_token( $token );
		if ( $verified instanceof \WP_Error ) {
			return $verified;
		}
		$body      = $request->get_json_params();
		if ( [] === $body ) {
			$body = $request->get_params();
		}
		$change_id        = sanitize_text_field( (string) ( $body['change_id'] ?? '' ) );
		$html             = (string) ( $body['html'] ?? '' );
		$hash             = (string) ( $body['html_hash'] ?? $body['hash'] ?? '' );
		$hash_unavailable = ! empty( $body['hash_unavailable'] );
		$hash             = self::resolve_html_hash( $html, $hash, $hash_unavailable );
		$errors           = isset( $body['errors'] ) && is_array( $body['errors'] ) ? $body['errors'] : [];
		if ( [] !== $errors ) {
			$first   = $errors[0];
			$code    = is_array( $first ) ? sanitize_key( (string) ( $first['code'] ?? '' ) ) : '';
			$message = is_array( $first ) ? (string) ( $first['message'] ?? $first['code'] ?? 'validation failed' ) : (string) $first;
			if ( '' !== $code && ! str_contains( $message, $code ) ) {
				$message = $code . ': ' . $message;
			}
			if ( '' === $html ) {
				return rest_ensure_response(
					[
						'ok'        => false,
						'status'    => 'queued',
						'retryable' => true,
						'errors'    => array_slice( $errors, 0, 20 ),
					]
				);
			}
			$failed = BlockQueue::mark_failed( $change_id, $message, $html, $code, $verified );
			if ( $failed instanceof \WP_Error ) {
				return $failed;
			}
			return rest_ensure_response(
				[
					'ok'     => false,
					'status' => 'failed',
					'errors' => array_slice( $errors, 0, 20 ),
				]
			);
		}
		$stored = BlockQueue::store_serialized( $change_id, $html, $hash, $verified );
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
		$counts = BlockQueue::counts_for_scope( $verified );
		wp_send_json_success(
			[
				'items'        => self::with_editor_urls( BlockQueue::pending_for_scope( $verified, true ) ),
				'queued_count' => $counts['queued'],
				'failed_count' => $counts['failed'],
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
		$change_id        = isset( $_POST['change_id'] ) ? sanitize_text_field( wp_unslash( (string) $_POST['change_id'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Missing
		$html             = isset( $_POST['html'] ) ? wp_unslash( (string) $_POST['html'] ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Missing,WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
		$hash             = isset( $_POST['html_hash'] ) ? sanitize_text_field( wp_unslash( (string) $_POST['html_hash'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Missing
		$hash_unavailable = ! empty( $_POST['hash_unavailable'] ); // phpcs:ignore WordPress.Security.NonceVerification.Missing
		$hash             = self::resolve_html_hash( $html, $hash, $hash_unavailable );
		$stored           = BlockQueue::store_serialized( $change_id, $html, $hash, $verified );
		if ( $stored instanceof \WP_Error ) {
			$status = (int) ( $stored->get_error_data()['status'] ?? 400 );
			wp_send_json_error( [ 'code' => $stored->get_error_code(), 'message' => $stored->get_error_message() ], $status );
			return;
		}
		wp_send_json_success( [ 'status' => 'serialized' ] );
	}

	public static function url( string $token = '', string $session_id = '' ): string {
		if ( '' === $token ) {
			$issued = BlockQueue::issue_token( $session_id );
			$token  = is_array( $issued ) ? (string) $issued['token'] : '';
		}
		$args = [
			'page' => self::SLUG,
		];
		if ( '' !== $token ) {
			$args['token'] = $token;
		}
		return add_query_arg( $args, admin_url( 'admin.php' ) );
	}

	public static function editor_url_for_post( int $post_id ): string {
		if ( $post_id <= 0 ) {
			return '';
		}
		return add_query_arg(
			[
				'post'                   => $post_id,
				'action'                 => 'edit',
				'stonewright_finalizer'  => '1',
			],
			admin_url( 'post.php' )
		);
	}

	/**
	 * Block Editor Queue iframes must never persist queued markup to the live post.
	 *
	 * @param mixed $result
	 * @param mixed $server
	 * @return mixed
	 */
	public static function reject_finalizer_live_writes( $result, $server, $request ) {
		unset( $server );
		if ( $result instanceof \WP_Error ) {
			return $result;
		}
		if ( ! $request instanceof \WP_REST_Request ) {
			return $result;
		}
		if ( ! self::is_finalizer_rest_request( $request ) ) {
			return $result;
		}
		$method = strtoupper( (string) $request->get_method() );
		if ( ! in_array( $method, [ 'POST', 'PUT', 'PATCH', 'DELETE' ], true ) ) {
			return $result;
		}
		$route = (string) $request->get_route();
		if ( str_contains( $route, 'stonewright' ) ) {
			return $result;
		}
		$is_autosave = str_contains( $route, '/autosaves' );
		$is_post     = (bool) preg_match( '#/wp/v2/(pages|posts|blocks|templates|template-parts)(/|$)#', $route );
		if ( ! $is_autosave && ! $is_post ) {
			return $result;
		}

		return new \WP_Error(
			'stonewright_finalizer_write_blocked',
			__( 'The Block Editor Queue iframe cannot persist to the live post. Apply through stonewright/blocks-finalize-batch.', 'stonewright' ),
			[ 'status' => 403 ]
		);
	}

	public static function lock_editor_persistence(): void {
		if ( ! self::is_finalizer_editor_request() ) {
			return;
		}
		$script = <<<'JS'
(function (wp) {
	if (!wp || !wp.data || typeof wp.data.dispatch !== 'function') {
		return;
	}
	try {
		var editor = wp.data.dispatch('core/editor');
		if (editor && typeof editor.lockPostAutosaving === 'function') {
			editor.lockPostAutosaving('stonewright-block-finalizer');
		}
		if (editor && typeof editor.lockPostSaving === 'function') {
			editor.lockPostSaving('stonewright-block-finalizer');
		}
	} catch (err) {}
	if (wp.apiFetch && typeof wp.apiFetch.use === 'function') {
		wp.apiFetch.use(function (options, next) {
			options = options || {};
			options.headers = options.headers || {};
			options.headers['X-Stonewright-Finalizer'] = '1';
			return next(options);
		});
	}
})(window.wp);
JS;
		if ( function_exists( 'wp_add_inline_script' ) ) {
			wp_add_inline_script( 'wp-editor', $script, 'after' );
			wp_add_inline_script( 'wp-api-fetch', $script, 'after' );
		}
	}

	/**
	 * @param array<string, mixed> $settings
	 * @return array<string, mixed>
	 */
	public static function disable_editor_autosave( array $settings ): array {
		if ( ! self::is_finalizer_editor_request() ) {
			return $settings;
		}
		$settings['autosaveInterval']      = 0;
		$settings['localAutosaveInterval'] = 0;
		return $settings;
	}

	/**
	 * @param array<string, mixed> $response
	 * @param array<string, mixed> $data
	 * @return array<string, mixed>
	 */
	public static function drop_heartbeat_autosave( array $response, array $data ): array {
		if ( isset( $data['wp_autosave'] ) && self::is_finalizer_editor_request() ) {
			unset( $response['wp_autosave'] );
		}
		return $response;
	}

	private static function is_finalizer_editor_request(): bool {
		$flag = isset( $_GET['stonewright_finalizer'] ) ? sanitize_text_field( wp_unslash( (string) $_GET['stonewright_finalizer'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( '1' === $flag ) {
			return true;
		}
		$referer = isset( $_SERVER['HTTP_REFERER'] ) ? (string) $_SERVER['HTTP_REFERER'] : '';
		return str_contains( $referer, 'stonewright_finalizer=1' );
	}

	private static function is_finalizer_rest_request( \WP_REST_Request $request ): bool {
		$header = (string) $request->get_header( 'X-Stonewright-Finalizer' );
		if ( '1' === $header ) {
			return true;
		}
		$param = (string) $request->get_param( 'stonewright_finalizer' );
		if ( '1' === $param ) {
			return true;
		}
		return self::is_finalizer_editor_request();
	}

	public static function is_online(): bool {
		$beat = get_transient( self::ONLINE_TRANSIENT );
		return false !== $beat && is_numeric( $beat );
	}

	/**
	 * @return list<array<string, mixed>>
	 */
	public static function pending_targets(): array {
		$targets      = [];
		$session_urls = [];
		foreach ( BlockQueue::owned_sessions() as $session ) {
			$issued = BlockQueue::issue_token( (string) $session['session_id'] );
			$session_urls[ $session['session_id'] ] = is_array( $issued ) ? self::url( $issued['token'] ) : self::url( '' );
		}
		$page_url = self::url( '' );
		foreach ( BlockQueue::list_for_viewer() as $item ) {
			$status     = (string) ( $item['status'] ?? '' );
			$post_id    = (int) ( $item['post_id'] ?? 0 );
			$session_id = (string) ( $item['session_id'] ?? '' );
			$queue_url  = $session_urls[ $session_id ] ?? $page_url;
			if ( ! isset( $targets[ $post_id ] ) ) {
				$targets[ $post_id ] = [
					'post_id'          => $post_id,
					'change_id'        => (string) ( $item['id'] ?? '' ),
					'status'           => $status,
					'pending_count'    => 0,
					'failed_count'     => 0,
					'editor_frame_url' => self::editor_url_for_post( $post_id ),
					'queue_url'        => $queue_url,
				];
			}
			if ( 'failed' === $status ) {
				++$targets[ $post_id ]['failed_count'];
			} elseif ( ! in_array( $status, [ 'persisted', 'cancelled' ], true ) ) {
				++$targets[ $post_id ]['pending_count'];
				$targets[ $post_id ]['change_id'] = (string) ( $item['id'] ?? '' );
				$targets[ $post_id ]['status']    = $status;
			}
		}

		return array_values(
			array_filter(
				$targets,
				static fn( array $target ): bool => $target['pending_count'] > 0 || $target['failed_count'] > 0
			)
		);
	}

	private static function mark_online(): void {
		set_transient( self::ONLINE_TRANSIENT, time(), self::ONLINE_TTL );
	}

	private static function request_token( \WP_REST_Request $request ): string {
		$token = (string) $request->get_param( 'token' );
		if ( '' !== $token ) {
			return $token;
		}
		$body = $request->get_json_params();
		return is_array( $body ) ? (string) ( $body['token'] ?? '' ) : '';
	}

	/**
	 * @param list<array<string, mixed>> $items
	 * @return list<array<string, mixed>>
	 */
	private static function with_editor_urls( array $items ): array {
		foreach ( $items as $index => $item ) {
			$post_id = (int) ( $item['post_id'] ?? 0 );
			$items[ $index ]['editor_url'] = self::editor_url_for_post( $post_id );
		}
		return $items;
	}

	private static function resolve_html_hash( string $html, string $hash, bool $hash_unavailable ): string {
		if ( $hash_unavailable && ( '' === $hash || 'pending' === $hash ) ) {
			return hash( 'sha256', $html );
		}
		return $hash;
	}
}
