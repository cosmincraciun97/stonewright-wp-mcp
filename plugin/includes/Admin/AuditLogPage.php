<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\Admin;

use Stonewright\WpMcp\Security\AuditLog;

/**
 * Admin page that lists recent audit log entries.
 *
 * Read-only. Surfaces who/what/when for every write ability and REST call
 * that goes through AbilityKernel::audit() or AuditLog::record() directly.
 */
final class AuditLogPage {

	public const SLUG       = 'stonewright-audit-log';
	public const CAPABILITY = 'manage_options';

	public static function register(): void {
		add_action( 'admin_menu', [ self::class, 'add_submenu' ] );
	}

	public static function add_submenu(): void {
		add_submenu_page(
			ConfigurationPage::SLUG,
			__( 'Audit Log', 'stonewright' ),
			__( 'Audit Log', 'stonewright' ),
			self::CAPABILITY,
			self::SLUG,
			[ self::class, 'render' ]
		);
	}

	public static function render(): void {
		if ( ! current_user_can( self::CAPABILITY ) ) {
			wp_die( esc_html__( 'You do not have permission to view the Stonewright audit log.', 'stonewright' ) );
		}

		$page     = isset( $_GET['paged'] ) ? max( 1, (int) $_GET['paged'] ) : 1; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$per_page = 50;
		$rows     = AuditLog::recent( $per_page, $page );

		echo '<div class="wrap">';
		echo '<h1>' . esc_html__( 'Audit Log', 'stonewright' ) . '</h1>';
		echo '<p>' . esc_html__( 'Every write ability and REST call records a row here. The log is append-only.', 'stonewright' ) . '</p>';

		self::render_log_table( $rows, $page, $per_page );

		echo '</div>';
	}

	/**
	 * Renders the audit log table without the outer wrap/h1.
	 * Used when embedding inside another page (e.g. SandboxPage Audit tab).
	 */
	public static function render_inline(): void {
		if ( ! current_user_can( self::CAPABILITY ) ) {
			return;
		}

		$page     = isset( $_GET['paged'] ) ? max( 1, (int) $_GET['paged'] ) : 1; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$per_page = 50;
		$rows     = AuditLog::recent( $per_page, $page );

		echo '<p>' . esc_html__( 'Every write ability and REST call records a row here. The log is append-only.', 'stonewright' ) . '</p>';
		self::render_log_table( $rows, $page, $per_page );
	}

	/**
	 * Renders the log table and pagination. Used by both render() and render_inline().
	 *
	 * @param array<int, array<string, mixed>> $rows
	 * @param int $page
	 * @param int $per_page
	 */
	private static function render_log_table( array $rows, int $page, int $per_page ): void {
		echo '<table class="wp-list-table widefat fixed striped">';
		echo '<thead><tr>';
		echo '<th>' . esc_html__( 'ID', 'stonewright' ) . '</th>';
		echo '<th>' . esc_html__( 'Ability', 'stonewright' ) . '</th>';
		echo '<th>' . esc_html__( 'User', 'stonewright' ) . '</th>';
		echo '<th>' . esc_html__( 'Status', 'stonewright' ) . '</th>';
		echo '<th>' . esc_html__( 'Time (UTC)', 'stonewright' ) . '</th>';
		echo '</tr></thead><tbody>';

		if ( empty( $rows ) ) {
			echo '<tr><td colspan="5">' . esc_html__( 'No audit entries yet.', 'stonewright' ) . '</td></tr>';
		} else {
			foreach ( $rows as $row ) {
				$user      = get_user_by( 'id', (int) $row['user_id'] );
				$user_html = $user ? esc_html( $user->user_login ) : '<em>' . esc_html__( '(unknown)', 'stonewright' ) . '</em>';
				$status    = (string) $row['result_status'];
				$badge     = 'ok' === $status ? 'background:#d1fae5;color:#065f46;' : 'background:#fee2e2;color:#991b1b;';

				echo '<tr>';
				echo '<td>' . (int) $row['id'] . '</td>';
				echo '<td><code>' . esc_html( (string) $row['ability_name'] ) . '</code></td>';
				echo '<td>' . wp_kses_post( $user_html ) . '</td>';
				echo '<td><span style="padding:2px 8px;border-radius:3px;font-size:11px;font-weight:600;' . esc_attr( $badge ) . '">' . esc_html( strtoupper( $status ) ) . '</span></td>';
				echo '<td>' . esc_html( (string) $row['created_at'] ) . '</td>';
				echo '</tr>';
			}
		}

		echo '</tbody></table>';

		echo '<p class="tablenav">';
		if ( $page > 1 ) {
			$prev = add_query_arg( [ 'page' => self::SLUG, 'paged' => $page - 1 ], admin_url( 'admin.php' ) );
			echo '<a class="button" href="' . esc_url( $prev ) . '">&laquo; ' . esc_html__( 'Newer', 'stonewright' ) . '</a> ';
		}
		if ( count( $rows ) === $per_page ) {
			$next = add_query_arg( [ 'page' => self::SLUG, 'paged' => $page + 1 ], admin_url( 'admin.php' ) );
			echo '<a class="button" href="' . esc_url( $next ) . '">' . esc_html__( 'Older', 'stonewright' ) . ' &raquo;</a>';
		}
		echo '</p>';
	}
}
