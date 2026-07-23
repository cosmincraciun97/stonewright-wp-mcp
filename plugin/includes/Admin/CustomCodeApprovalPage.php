<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\Admin;

use Stonewright\WpMcp\Security\CustomCodeGrant;

/**
 * Human approval boundary for exact custom-code dry-run candidates.
 */
final class CustomCodeApprovalPage {

	public const SLUG       = 'stonewright-custom-code-approval';
	public const CAPABILITY = 'manage_options';

	public static function register(): void {
		add_action( 'admin_menu', [ self::class, 'add_submenu' ] );
		add_action( 'admin_post_stonewright_custom_code_approve', [ self::class, 'handle_approve' ] );
	}

	public static function add_submenu(): void {
		add_submenu_page(
			ConfigurationPage::SLUG,
			__( 'Custom Code Approval', 'stonewright' ),
			__( 'Safety: Code approval', 'stonewright' ),
			self::CAPABILITY,
			self::SLUG,
			[ self::class, 'render' ]
		);
	}

	public static function render(): void {
		if ( ! current_user_can( self::CAPABILITY ) ) {
			wp_die( esc_html__( 'You do not have permission to approve custom code.', 'stonewright' ) );
		}

		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- Read-only proposal/result lookup.
		$proposal_id = isset( $_GET['proposal_id'] ) ? sanitize_text_field( wp_unslash( (string) $_GET['proposal_id'] ) ) : '';
		$result_id   = isset( $_GET['result_id'] ) ? sanitize_text_field( wp_unslash( (string) $_GET['result_id'] ) ) : '';
		// phpcs:enable

		AdminShell::open( self::SLUG );
		echo '<div class="stonewright-custom-code-approval-page">';
		echo '<header class="stonewright-page-header"><div><h1>' . esc_html__( 'Custom Code Approval', 'stonewright' ) . '</h1>';
		echo '<p>' . esc_html__( 'Approve only the exact dry-run candidate shown here. Grants expire quickly, work once, and cannot be broadened to another path or hash.', 'stonewright' ) . '</p></div></header>';

		if ( '' !== $result_id ) {
			self::render_grant_result( $result_id );
		} elseif ( '' !== $proposal_id ) {
			self::render_proposal( $proposal_id );
		} else {
			echo '<div class="notice notice-info"><p>' . esc_html__( 'No proposal selected. Run theme-file-patch with dry_run:true and open the returned approval URL.', 'stonewright' ) . '</p></div>';
		}

		echo '</div>';
		AdminShell::close();
	}

	public static function handle_approve(): void {
		if ( ! current_user_can( self::CAPABILITY ) ) {
			wp_die( esc_html__( 'Forbidden', 'stonewright' ), '', [ 'response' => 403 ] );
		}
		check_admin_referer( 'stonewright_custom_code_approve', '_stonewright_nonce' );
		$proposal_id = isset( $_POST['proposal_id'] ) ? sanitize_text_field( wp_unslash( (string) $_POST['proposal_id'] ) ) : '';
		$result      = CustomCodeGrant::approve_proposal( $proposal_id );
		$result_id   = wp_generate_uuid4();
		set_transient(
			'sw_cc_result_' . $result_id,
			$result instanceof \WP_Error
				? [
					'ok'      => false,
					'code'    => $result->get_error_code(),
					'message' => $result->get_error_message(),
				]
				: array_merge( [ 'ok' => true ], $result ),
			300
		);
		wp_safe_redirect(
			add_query_arg(
				[
					'page'      => self::SLUG,
					'result_id' => $result_id,
				],
				admin_url( 'admin.php' )
			)
		);
		exit;
	}

	private static function render_proposal( string $proposal_id ): void {
		$proposal = CustomCodeGrant::proposal( $proposal_id );
		if ( $proposal instanceof \WP_Error ) {
			echo '<div class="notice notice-error"><p>' . esc_html( $proposal->get_error_message() ) . '</p></div>';
			return;
		}

		$gap  = is_array( $proposal['native_gap'] ?? null ) ? $proposal['native_gap'] : [];
		$diff = is_array( $proposal['diff_preview'] ?? null ) ? $proposal['diff_preview'] : [];
		echo '<div class="sw-card stonewright-panel">';
		echo '<h2>' . esc_html__( 'Exact candidate', 'stonewright' ) . '</h2>';
		echo '<dl>';
		self::row( __( 'Path', 'stonewright' ), (string) $proposal['path'] );
		self::row( __( 'Language', 'stonewright' ), strtoupper( (string) $proposal['language'] ) );
		self::row( __( 'Risk', 'stonewright' ), (string) $proposal['risk_class'] );
		self::row( __( 'Changed bytes', 'stonewright' ), (string) $proposal['changed_bytes'] );
		self::row( __( 'Candidate SHA-256', 'stonewright' ), (string) $proposal['after_sha256'] );
		self::row( __( 'Native gap', 'stonewright' ), (string) ( $gap['reason'] ?? '' ) );
		echo '</dl>';
		echo '<h3>' . esc_html__( 'Bounded diff preview', 'stonewright' ) . '</h3>';
		echo '<pre class="sw-audit-payload">' . esc_html( (string) ( $diff['preview'] ?? '' ) ) . '</pre>';
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
		echo '<input type="hidden" name="action" value="stonewright_custom_code_approve" />';
		echo '<input type="hidden" name="proposal_id" value="' . esc_attr( $proposal_id ) . '" />';
		wp_nonce_field( 'stonewright_custom_code_approve', '_stonewright_nonce' );
		echo '<button type="submit" class="button button-primary">' . esc_html__( 'Issue one-time grant', 'stonewright' ) . '</button>';
		echo '</form></div>';
	}

	private static function render_grant_result( string $result_id ): void {
		$result = get_transient( 'sw_cc_result_' . $result_id );
		if ( ! is_array( $result ) ) {
			echo '<div class="notice notice-error"><p>' . esc_html__( 'Grant result expired. Run dry_run again.', 'stonewright' ) . '</p></div>';
			return;
		}
		delete_transient( 'sw_cc_result_' . $result_id );
		if ( empty( $result['ok'] ) ) {
			echo '<div class="notice notice-error"><p>' . esc_html( (string) ( $result['message'] ?? 'Approval failed.' ) ) . '</p></div>';
			return;
		}
		echo '<div class="notice notice-success"><p><strong>' . esc_html__( 'Grant issued.', 'stonewright' ) . '</strong> ' . esc_html__( 'Copy it now; this screen will not show it again.', 'stonewright' ) . '</p></div>';
		echo '<textarea class="large-text code" rows="5" readonly>' . esc_textarea( (string) $result['token'] ) . '</textarea>';
		echo '<p><code>' . esc_html( (string) $result['path'] ) . '</code> · <code>' . esc_html( (string) $result['after_sha256'] ) . '</code></p>';
	}

	private static function row( string $label, string $value ): void {
		echo '<dt><strong>' . esc_html( $label ) . '</strong></dt><dd><code>' . esc_html( $value ) . '</code></dd>';
	}
}
