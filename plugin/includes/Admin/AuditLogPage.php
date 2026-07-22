<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\Admin;

use Stonewright\WpMcp\Security\AuditLog;
use Stonewright\WpMcp\Security\ErrorPatterns;

/**
 * Admin page that lists recent audit log entries.
 *
 * Read-only. Surfaces who/what/when for every Stonewright-owned mutation:
 * abilities that pass through AbilityKernel::audit() and POST/PUT/PATCH/DELETE
 * routes under the stonewright/v1 namespace (central REST audit middleware).
 * Not a global WordPress REST traffic log.
 */
final class AuditLogPage {

	public const SLUG       = 'stonewright-audit-log';
	public const CAPABILITY = 'manage_options';

	public static function register(): void {
		add_action( 'admin_menu', [ self::class, 'add_submenu' ] );
		add_action( 'admin_post_stonewright_dismiss_error_pattern', [ self::class, 'handle_dismiss_pattern' ] );
	}

	public static function handle_dismiss_pattern(): void {
		if ( ! current_user_can( self::CAPABILITY ) ) {
			wp_die( esc_html__( 'Forbidden', 'stonewright' ), '', [ 'response' => 403 ] );
		}
		check_admin_referer( 'stonewright_dismiss_error_pattern' );
		$signature = isset( $_POST['signature'] ) ? sanitize_text_field( wp_unslash( (string) $_POST['signature'] ) ) : '';
		if ( '' !== $signature ) {
			ErrorPatterns::dismiss( $signature );
		}
		wp_safe_redirect( admin_url( 'admin.php?page=' . self::SLUG ) );
		exit;
	}

	public static function add_submenu(): void {
		// IA group: Safety & Diagnostics (nested with Memory/Skills) — slug unchanged.
		add_submenu_page(
			ConfigurationPage::SLUG,
			__( 'Audit Log', 'stonewright' ),
			__( 'Safety: Audit', 'stonewright' ),
			self::CAPABILITY,
			self::SLUG,
			[ self::class, 'render' ]
		);
	}

	public static function render(): void {
		if ( ! current_user_can( self::CAPABILITY ) ) {
			wp_die( esc_html__( 'You do not have permission to view the Stonewright audit log.', 'stonewright' ) );
		}

		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- Read-only GET filters.
		$page     = isset( $_GET['paged'] ) ? max( 1, (int) $_GET['paged'] ) : 1;
		$filters  = self::filters_from_request();
		// phpcs:enable
		$per_page = 50;
		$rows     = AuditLog::recent( $per_page, $page, $filters );
		$total    = AuditLog::count( $filters );

		AdminShell::open( self::SLUG );
		echo '<div class="sw-audit-page stonewright-audit-log-page">';
		echo '<header class="stonewright-page-header"><div>';
		echo '<h1>' . esc_html__( 'Audit Log', 'stonewright' ) . '</h1>';
		echo '<p>' . esc_html__( 'Every Stonewright mutation (abilities and stonewright/v1 write routes) records one redacted row here. The log is append-only. Unrelated WordPress REST traffic is not logged.', 'stonewright' ) . '</p>';
		echo '</div></header>';

		self::render_recurring_errors();
		self::render_filters( $filters );
		self::render_log_table( $rows, $page, $per_page, $filters, $total );

		echo '</div>';
		AdminShell::close();
	}

	private static function render_recurring_errors(): void {
		$patterns = ErrorPatterns::recurring( 10 );
		if ( [] === $patterns ) {
			return;
		}

		echo '<section class="sw-recurring-errors" aria-labelledby="sw-recurring-errors-title">';
		echo '<div class="sw-section__head">';
		echo '<h2 id="sw-recurring-errors-title">' . esc_html__( 'Recurring errors', 'stonewright' ) . '</h2>';
		echo '<p class="sw-section__sub">' . esc_html__( 'Patterns that failed more than once. Agents see the top three at task-start.', 'stonewright' ) . '</p>';
		echo '</div>';
		echo '<ul class="sw-recurring-errors__list">';
		foreach ( $patterns as $p ) {
			$ability = (string) ( $p['ability'] ?? '' );
			$count   = (int) ( $p['count'] ?? 0 );
			$msg     = (string) ( $p['message'] ?? '' );
			$code    = (string) ( $p['error_code'] ?? '' );
			$repair  = (string) ( $p['repair'] ?? '' );
			$sig     = (string) ( $p['signature'] ?? '' );
			$view    = admin_url( 'admin.php?page=' . self::SLUG . '&status=error&ability=' . rawurlencode( $ability ) );
			echo '<li class="sw-recurring-errors__item">';
			echo '<div class="sw-recurring-errors__main">';
			echo '<code>' . esc_html( $ability ) . '</code> ';
			echo '<span class="sw-badge sw-badge--error">' . esc_html( (string) $count ) . '×</span> ';
			if ( '' !== $code ) {
				echo '<code class="sw-recurring-errors__code">' . esc_html( $code ) . '</code> ';
			}
			echo '<span class="sw-recurring-errors__msg">' . esc_html( $msg ) . '</span>';
			if ( '' !== $repair ) {
				echo '<p class="sw-recurring-errors__repair"><strong>' . esc_html__( 'Repair', 'stonewright' ) . ':</strong> ' . esc_html( $repair ) . '</p>';
			}
			echo '<span class="sw-recurring-errors__meta">' . esc_html( sprintf( /* translators: %s: datetime */ __( 'Last seen %s', 'stonewright' ), (string) ( $p['last_seen'] ?? '' ) ) ) . '</span>';
			echo '</div>';
			echo '<div class="sw-actions">';
			echo '<a class="sw-btn sw-btn--ghost sw-btn--sm" href="' . esc_url( $view ) . '">' . esc_html__( 'View occurrences', 'stonewright' ) . '</a>';
			echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" class="stonewright-inline-form">';
			echo '<input type="hidden" name="action" value="stonewright_dismiss_error_pattern" />';
			echo '<input type="hidden" name="signature" value="' . esc_attr( $sig ) . '" />';
			wp_nonce_field( 'stonewright_dismiss_error_pattern' );
			echo '<button type="submit" class="sw-btn sw-btn--ghost sw-btn--sm">' . esc_html__( 'Dismiss', 'stonewright' ) . '</button>';
			echo '</form>';
			echo '</div>';
			echo '</li>';
		}
		echo '</ul></section>';
	}

	/**
	 * Renders the audit log table without the outer wrap/h1.
	 * Used when embedding inside another page (e.g. SandboxPage Audit tab).
	 */
	public static function render_inline(): void {
		if ( ! current_user_can( self::CAPABILITY ) ) {
			return;
		}

		// phpcs:disable WordPress.Security.NonceVerification.Recommended
		$page    = isset( $_GET['paged'] ) ? max( 1, (int) $_GET['paged'] ) : 1;
		$filters = self::filters_from_request();
		// phpcs:enable
		$per_page = 50;
		$rows     = AuditLog::recent( $per_page, $page, $filters );
		$total    = AuditLog::count( $filters );

		echo '<p>' . esc_html__( 'Every Stonewright mutation (abilities and stonewright/v1 write routes) records one redacted row here. The log is append-only.', 'stonewright' ) . '</p>';
		self::render_filters( $filters );
		self::render_log_table( $rows, $page, $per_page, $filters, $total );
	}

	/**
	 * @return array{ability?: string, status?: string, user?: int, from?: string, to?: string}
	 */
	private static function filters_from_request(): array {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended
		$filters = [];
		if ( ! empty( $_GET['ability'] ) ) {
			$filters['ability'] = sanitize_text_field( wp_unslash( (string) $_GET['ability'] ) );
		}
		if ( ! empty( $_GET['status'] ) ) {
			$status = sanitize_key( wp_unslash( (string) $_GET['status'] ) );
			if ( in_array( $status, [ 'ok', 'error', 'blocked' ], true ) ) {
				$filters['status'] = $status;
			}
		}
		if ( ! empty( $_GET['user'] ) ) {
			$filters['user'] = absint( $_GET['user'] );
		}
		if ( ! empty( $_GET['from'] ) ) {
			$filters['from'] = sanitize_text_field( wp_unslash( (string) $_GET['from'] ) );
		}
		if ( ! empty( $_GET['to'] ) ) {
			$filters['to'] = sanitize_text_field( wp_unslash( (string) $_GET['to'] ) );
		}
		// phpcs:enable
		return $filters;
	}

	/**
	 * @param array{ability?: string, status?: string, user?: int, from?: string, to?: string} $filters
	 */
	private static function render_filters( array $filters ): void {
		$action = admin_url( 'admin.php' );
		?>
		<form class="sw-audit-filters" method="get" action="<?php echo esc_url( $action ); ?>">
			<input type="hidden" name="page" value="<?php echo esc_attr( self::SLUG ); ?>"/>
			<label>
				<span class="screen-reader-text"><?php esc_html_e( 'Ability', 'stonewright' ); ?></span>
				<input
					type="search"
					name="ability"
					value="<?php echo esc_attr( (string) ( $filters['ability'] ?? '' ) ); ?>"
					placeholder="<?php esc_attr_e( 'Ability', 'stonewright' ); ?>"
				/>
			</label>
			<label>
				<span class="screen-reader-text"><?php esc_html_e( 'Status', 'stonewright' ); ?></span>
				<select name="status">
					<option value=""><?php esc_html_e( 'All statuses', 'stonewright' ); ?></option>
					<option value="ok" <?php selected( ( $filters['status'] ?? '' ), 'ok' ); ?>><?php esc_html_e( 'OK', 'stonewright' ); ?></option>
					<option value="error" <?php selected( ( $filters['status'] ?? '' ), 'error' ); ?>><?php esc_html_e( 'Error', 'stonewright' ); ?></option>
					<option value="blocked" <?php selected( ( $filters['status'] ?? '' ), 'blocked' ); ?>><?php esc_html_e( 'Blocked', 'stonewright' ); ?></option>
				</select>
			</label>
			<label>
				<span class="screen-reader-text"><?php esc_html_e( 'User ID', 'stonewright' ); ?></span>
				<input
					type="number"
					name="user"
					min="0"
					value="<?php echo isset( $filters['user'] ) ? (int) $filters['user'] : ''; ?>"
					placeholder="<?php esc_attr_e( 'User ID', 'stonewright' ); ?>"
				/>
			</label>
			<label>
				<span><?php esc_html_e( 'From', 'stonewright' ); ?></span>
				<input type="date" name="from" value="<?php echo esc_attr( (string) ( $filters['from'] ?? '' ) ); ?>"/>
			</label>
			<label>
				<span><?php esc_html_e( 'To', 'stonewright' ); ?></span>
				<input type="date" name="to" value="<?php echo esc_attr( (string) ( $filters['to'] ?? '' ) ); ?>"/>
			</label>
			<div class="sw-actions">
				<button type="submit" class="sw-btn sw-btn--secondary sw-btn--sm"><?php esc_html_e( 'Filter', 'stonewright' ); ?></button>
				<a class="sw-btn sw-btn--ghost sw-btn--sm" href="<?php echo esc_url( admin_url( 'admin.php?page=' . self::SLUG ) ); ?>">
					<?php esc_html_e( 'Reset', 'stonewright' ); ?>
				</a>
			</div>
		</form>
		<?php
	}

	/**
	 * Renders the log table and pagination. Used by both render() and render_inline().
	 *
	 * @param array<int, array<string, mixed>> $rows
	 * @param array{ability?: string, status?: string, user?: int, from?: string, to?: string} $filters
	 */
	private static function render_log_table( array $rows, int $page, int $per_page, array $filters = [], ?int $total = null ): void {
		if ( empty( $rows ) ) {
			echo '<div class="sw-empty-state stonewright-empty-state">';
			echo '<p>' . esc_html__( 'No audit entries yet.', 'stonewright' ) . '</p>';
			echo '</div>';
			return;
		}

		$total       = null === $total ? count( $rows ) : max( 0, $total );
		$total_pages = (int) max( 1, (int) ceil( $total / max( 1, $per_page ) ) );

		echo '<p class="sw-muted">' . esc_html(
			sprintf(
				/* translators: 1: current page, 2: total pages, 3: total rows */
				__( 'Page %1$d of %2$d · %3$d entries', 'stonewright' ),
				$page,
				$total_pages,
				$total
			)
		) . '</p>';

		echo '<table class="wp-list-table widefat fixed striped sw-audit-table">';
		echo '<thead><tr>';
		echo '<th>' . esc_html__( 'ID', 'stonewright' ) . '</th>';
		echo '<th>' . esc_html__( 'Ability / route', 'stonewright' ) . '</th>';
		echo '<th>' . esc_html__( 'User', 'stonewright' ) . '</th>';
		echo '<th>' . esc_html__( 'Status', 'stonewright' ) . '</th>';
		echo '<th>' . esc_html__( 'Time (UTC)', 'stonewright' ) . '</th>';
		echo '<th>' . esc_html__( 'Details', 'stonewright' ) . '</th>';
		echo '</tr></thead><tbody>';

		foreach ( $rows as $row ) {
			$user      = get_user_by( 'id', (int) $row['user_id'] );
			$user_html = $user ? esc_html( $user->user_login ) : '<em>' . esc_html__( '(unknown)', 'stonewright' ) . '</em>';
			$status    = strtolower( (string) $row['result_status'] );
			$badge     = match ( $status ) {
				'ok'      => 'sw-badge--ok',
				'blocked' => 'sw-badge--warn',
				default   => 'sw-badge--error',
			};
			$payload   = (string) ( $row['sanitized_args'] ?? '' );
			$pretty    = self::pretty_payload( $payload );
			$error_ui  = self::error_cause_from_payload( $payload );

			echo '<tr class="sw-audit-row">';
			echo '<td>' . (int) $row['id'] . '</td>';
			echo '<td><code>' . esc_html( (string) $row['ability_name'] ) . '</code></td>';
			echo '<td>' . wp_kses_post( $user_html ) . '</td>';
			echo '<td><span class="sw-badge ' . esc_attr( $badge ) . '">' . esc_html( strtoupper( $status ) ) . '</span></td>';
			echo '<td>' . esc_html( (string) $row['created_at'] ) . '</td>';
			echo '<td>';
			if ( in_array( $status, [ 'error', 'blocked' ], true ) && '' !== $error_ui ) {
				echo '<div class="sw-audit-error-cause">' . esc_html( $error_ui ) . '</div>';
			}
			if ( '' !== $pretty ) {
				echo '<details class="sw-audit-details">';
				echo '<summary>' . esc_html__( 'Payload', 'stonewright' ) . '</summary>';
				echo '<pre class="sw-audit-payload">' . esc_html( $pretty ) . '</pre>';
				echo '</details>';
			} elseif ( '' === $error_ui ) {
				echo '<span class="sw-muted">' . esc_html__( '—', 'stonewright' ) . '</span>';
			}
			echo '</td>';
			echo '</tr>';
		}

		echo '</tbody></table>';

		$query = array_merge( [ 'page' => self::SLUG ], $filters );
		echo '<p class="tablenav sw-actions">';
		if ( $page > 1 ) {
			$prev = add_query_arg( array_merge( $query, [ 'paged' => $page - 1 ] ), admin_url( 'admin.php' ) );
			echo '<a class="sw-btn sw-btn--secondary sw-btn--sm" href="' . esc_url( $prev ) . '">&laquo; ' . esc_html__( 'Newer', 'stonewright' ) . '</a> ';
		}
		if ( $page < $total_pages ) {
			$next = add_query_arg( array_merge( $query, [ 'paged' => $page + 1 ] ), admin_url( 'admin.php' ) );
			echo '<a class="sw-btn sw-btn--secondary sw-btn--sm" href="' . esc_url( $next ) . '">' . esc_html__( 'Older', 'stonewright' ) . ' &raquo;</a>';
		}
		echo '</p>';
	}

	private static function pretty_payload( string $raw ): string {
		if ( '' === $raw ) {
			return '';
		}
		$decoded = json_decode( $raw, true );
		if ( is_array( $decoded ) ) {
			$pretty = wp_json_encode( $decoded, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES );
			return is_string( $pretty ) ? $pretty : $raw;
		}
		return $raw;
	}

	/**
	 * Extract a human-readable error cause line from a sanitized_args JSON payload.
	 */
	public static function error_cause_from_payload( string $raw ): string {
		$decoded = json_decode( $raw, true );
		if ( ! is_array( $decoded ) ) {
			return '';
		}
		$meta = is_array( $decoded['_meta'] ?? null ) ? $decoded['_meta'] : [];
		$code = (string) ( $meta['error_code'] ?? '' );
		$msg  = (string) ( $meta['error_message'] ?? '' );
		if ( '' === $code && '' === $msg ) {
			return '';
		}
		if ( '' !== $code && '' !== $msg ) {
			return $code . ': ' . $msg;
		}
		return '' !== $code ? $code : $msg;
	}
}
