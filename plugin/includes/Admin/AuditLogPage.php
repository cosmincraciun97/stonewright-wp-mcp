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

		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- Read-only GET filters.
		$page     = isset( $_GET['paged'] ) ? max( 1, (int) $_GET['paged'] ) : 1;
		$filters  = self::filters_from_request();
		// phpcs:enable
		$per_page = 50;
		$rows     = AuditLog::recent( $per_page, $page, $filters );

		AdminShell::open( self::SLUG );
		echo '<div class="sw-audit-page stonewright-audit-log-page">';
		echo '<header class="stonewright-page-header"><div>';
		echo '<h1>' . esc_html__( 'Audit Log', 'stonewright' ) . '</h1>';
		echo '<p>' . esc_html__( 'Every write ability and REST call records a row here. The log is append-only.', 'stonewright' ) . '</p>';
		echo '</div></header>';

		self::render_filters( $filters );
		self::render_log_table( $rows, $page, $per_page, $filters );

		echo '</div>';
		AdminShell::close();
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

		echo '<p>' . esc_html__( 'Every write ability and REST call records a row here. The log is append-only.', 'stonewright' ) . '</p>';
		self::render_filters( $filters );
		self::render_log_table( $rows, $page, $per_page, $filters );
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
			if ( in_array( $status, [ 'ok', 'error' ], true ) ) {
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
	private static function render_log_table( array $rows, int $page, int $per_page, array $filters = [] ): void {
		if ( empty( $rows ) ) {
			echo '<div class="sw-empty-state stonewright-empty-state">';
			echo '<p>' . esc_html__( 'No audit entries yet.', 'stonewright' ) . '</p>';
			echo '</div>';
			return;
		}

		echo '<table class="wp-list-table widefat fixed striped sw-audit-table">';
		echo '<thead><tr>';
		echo '<th>' . esc_html__( 'ID', 'stonewright' ) . '</th>';
		echo '<th>' . esc_html__( 'Ability', 'stonewright' ) . '</th>';
		echo '<th>' . esc_html__( 'User', 'stonewright' ) . '</th>';
		echo '<th>' . esc_html__( 'Status', 'stonewright' ) . '</th>';
		echo '<th>' . esc_html__( 'Time (UTC)', 'stonewright' ) . '</th>';
		echo '<th>' . esc_html__( 'Details', 'stonewright' ) . '</th>';
		echo '</tr></thead><tbody>';

		foreach ( $rows as $row ) {
			$user      = get_user_by( 'id', (int) $row['user_id'] );
			$user_html = $user ? esc_html( $user->user_login ) : '<em>' . esc_html__( '(unknown)', 'stonewright' ) . '</em>';
			$status    = strtolower( (string) $row['result_status'] );
			$badge     = 'ok' === $status ? 'sw-badge--ok' : 'sw-badge--error';
			$payload   = (string) ( $row['sanitized_args'] ?? '' );
			$pretty    = self::pretty_payload( $payload );

			echo '<tr class="sw-audit-row">';
			echo '<td>' . (int) $row['id'] . '</td>';
			echo '<td><code>' . esc_html( (string) $row['ability_name'] ) . '</code></td>';
			echo '<td>' . wp_kses_post( $user_html ) . '</td>';
			echo '<td><span class="sw-badge ' . esc_attr( $badge ) . '">' . esc_html( strtoupper( $status ) ) . '</span></td>';
			echo '<td>' . esc_html( (string) $row['created_at'] ) . '</td>';
			echo '<td>';
			if ( '' !== $pretty ) {
				echo '<details class="sw-audit-details">';
				echo '<summary>' . esc_html__( 'Payload', 'stonewright' ) . '</summary>';
				echo '<pre class="sw-audit-payload">' . esc_html( $pretty ) . '</pre>';
				echo '</details>';
			} else {
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
		if ( count( $rows ) === $per_page ) {
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
}
