<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\Admin;

use Stonewright\WpMcp\OAuth\Repositories\ClientRepository;
use Stonewright\WpMcp\Security\AuditLog;
use Stonewright\WpMcp\Security\AuditEvent;
use Stonewright\WpMcp\Security\ErrorPatterns;
use Stonewright\WpMcp\Security\IncidentStore;
use Stonewright\WpMcp\Security\SensitiveContent;

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
		add_action( 'admin_post_stonewright_audit_export', [ self::class, 'handle_export' ] );
	}

	public static function handle_export(): void {
		if ( ! current_user_can( self::CAPABILITY ) ) {
			wp_die( esc_html__( 'Forbidden', 'stonewright' ), '', [ 'response' => 403 ] );
		}
		check_admin_referer( 'stonewright_audit_export', '_stonewright_nonce' );
		$format = isset( $_POST['format'] ) ? sanitize_key( wp_unslash( (string) $_POST['format'] ) ) : '';
		if ( ! in_array( $format, [ 'json', 'csv' ], true ) ) {
			wp_die( esc_html__( 'Unsupported audit export format.', 'stonewright' ), '', [ 'response' => 400 ] );
		}
		$filters = self::filters_from_source( $_POST );
		$export  = self::build_export( AuditLog::recent( 5000, 1, $filters ), $format );
		if ( $export instanceof \WP_Error ) {
			wp_die( esc_html( $export->get_error_message() ), '', [ 'response' => 400 ] );
		}

		header( 'Content-Type: ' . ( 'json' === $format ? 'application/json' : 'text/csv' ) . '; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename="stonewright-audit-redacted.' . $format . '"' );
		echo $export; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Download body is allowlisted, redacted, and secret-scanned.
		exit;
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
		$counts   = self::view_counts( $filters );
		$incident_states = self::incident_state_map();

		AdminShell::open( self::SLUG );
		echo '<div class="sw-audit-page stonewright-audit-log-page">';
		echo '<header class="stonewright-page-header"><div>';
		echo '<h1>' . esc_html__( 'Audit Log', 'stonewright' ) . '</h1>';
		echo '<p>' . esc_html__( 'Every Stonewright mutation (abilities and stonewright/v1 write routes) records one redacted row here. The log is append-only. Unrelated WordPress REST traffic is not logged.', 'stonewright' ) . '</p>';
		echo '</div></header>';
		if ( get_option( 'stonewright_audit_degraded', false ) ) {
			echo '<div class="notice notice-error"><p><strong>' . esc_html__( 'Audit coverage degraded.', 'stonewright' ) . '</strong> ' . esc_html__( 'A mutation audit row failed to persist. Stop write work until database health is repaired and a later audit insert succeeds.', 'stonewright' ) . '</p></div>';
		}

		self::render_incident_summary();
		self::render_recurring_errors();
		self::render_views( $filters, $counts );
		self::render_filters( $filters );
		self::render_export_controls( $filters );
		self::render_log_table( $rows, $page, $per_page, $filters, $total, $incident_states );

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

	private static function render_incident_summary(): void {
		$counts = IncidentStore::counts();
		echo '<section class="sw-card sw-incident-summary" aria-labelledby="sw-incident-summary-title">';
		echo '<div class="sw-section__head"><h2 id="sw-incident-summary-title">' . esc_html__( 'Incident lifecycle', 'stonewright' ) . '</h2>';
		echo '<p class="sw-section__sub">' . esc_html__( 'Incidents open only after their threshold; generic success never closes one without matching evidence.', 'stonewright' ) . '</p></div>';
		echo '<div class="sw-actions">';
		foreach ( [ 'open' => __( 'Open', 'stonewright' ), 'observing' => __( 'Observing', 'stonewright' ), 'resolved' => __( 'Resolved', 'stonewright' ), 'suppressed' => __( 'Suppressed', 'stonewright' ) ] as $state => $label ) {
			echo '<span class="sw-badge ' . esc_attr( 'open' === $state ? 'sw-badge--error' : 'sw-badge--muted' ) . '">' . esc_html( $label . ': ' . (int) ( $counts[ $state ] ?? 0 ) ) . '</span>';
		}
		echo '</div></section>';
	}

	/**
	 * @return array<string, mixed>
	 */
	private static function filters_from_request(): array {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended
		return self::filters_from_source( $_GET );
		// phpcs:enable
	}

	/**
	 * @param array<string, mixed> $source
	 * @return array<string, mixed>
	 */
	private static function filters_from_source( array $source ): array {
		$filters = [];
		if ( ! empty( $source['ability'] ) ) {
			$filters['ability'] = mb_substr( sanitize_text_field( wp_unslash( (string) $source['ability'] ) ), 0, 190 );
		}
		if ( ! empty( $source['status'] ) ) {
			$status = sanitize_key( wp_unslash( (string) $source['status'] ) );
			if ( in_array( $status, AuditLog::STATUSES, true ) ) {
				$filters['status'] = $status;
			}
		}
		if ( ! empty( $source['user'] ) ) {
			$filters['user'] = absint( $source['user'] );
		}
		if ( ! empty( $source['from'] ) ) {
			$filters['from'] = sanitize_text_field( wp_unslash( (string) $source['from'] ) );
		}
		if ( ! empty( $source['to'] ) ) {
			$filters['to'] = sanitize_text_field( wp_unslash( (string) $source['to'] ) );
		}
		foreach ( [ 'backend', 'operation_class', 'verification_status', 'rollback_status', 'severity', 'event_type', 'root_error_code' ] as $key ) {
			if ( ! empty( $source[ $key ] ) ) {
				$filters[ $key ] = sanitize_key( wp_unslash( (string) $source[ $key ] ) );
			}
		}
		if ( ! empty( $source['change_set_id'] ) ) {
			$filters['change_set_id'] = mb_substr( sanitize_text_field( wp_unslash( (string) $source['change_set_id'] ) ), 0, 96 );
		}
		if ( ! empty( $source['normalized_path'] ) ) {
			$path = str_replace( '\\', '/', sanitize_text_field( wp_unslash( (string) $source['normalized_path'] ) ) );
			$path = preg_replace( '#/{2,}#', '/', $path ) ?? $path;
			$filters['normalized_path'] = mb_substr( ltrim( $path, '/' ), 0, 255 );
		}
		if ( ! empty( $source['category'] ) ) {
			$category = strtoupper( sanitize_key( wp_unslash( (string) $source['category'] ) ) );
			if ( in_array( $category, AuditEvent::CATEGORIES, true ) ) {
				$filters['category'] = $category;
			}
		}
		if ( ! empty( $source['outcome'] ) ) {
			$outcome = strtoupper( sanitize_key( wp_unslash( (string) $source['outcome'] ) ) );
			if ( in_array( $outcome, AuditEvent::OUTCOMES, true ) ) {
				$filters['outcome'] = $outcome;
			}
		}
		if ( ! empty( $source['incident_id'] ) ) {
			$incident_id = strtolower( sanitize_text_field( wp_unslash( (string) $source['incident_id'] ) ) );
			if ( 1 === preg_match( '/^[a-f0-9]{64}$/', $incident_id ) ) {
				$filters['incident_id'] = $incident_id;
			}
		}
		$view = isset( $source['view'] ) ? sanitize_key( wp_unslash( (string) $source['view'] ) ) : 'all';
		if ( 'incidents' === $view ) {
			$filters['event_type'] = 'incident';
			$view                  = 'all';
		}
		if ( in_array( $view, AuditLog::ADMIN_VIEWS, true ) && 'all' !== $view ) {
			$filters['view'] = $view;
		}
		return $filters;
	}

	/**
	 * @param array<string, mixed> $filters
	 */
	private static function render_filters( array $filters ): void {
		$action = admin_url( 'admin.php' );
		?>
		<form class="sw-audit-filters" method="get" action="<?php echo esc_url( $action ); ?>">
			<input type="hidden" name="page" value="<?php echo esc_attr( self::SLUG ); ?>"/>
			<?php if ( isset( $filters['view'] ) ) : ?>
				<input type="hidden" name="view" value="<?php echo esc_attr( (string) $filters['view'] ); ?>"/>
			<?php endif; ?>
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
				<span class="screen-reader-text"><?php esc_html_e( 'Verification', 'stonewright' ); ?></span>
				<select name="verification_status">
					<option value=""><?php esc_html_e( 'All verification', 'stonewright' ); ?></option>
					<option value="verified" <?php selected( ( $filters['verification_status'] ?? '' ), 'verified' ); ?>><?php esc_html_e( 'Verified', 'stonewright' ); ?></option>
					<option value="failed" <?php selected( ( $filters['verification_status'] ?? '' ), 'failed' ); ?>><?php esc_html_e( 'Failed', 'stonewright' ); ?></option>
					<option value="blocked" <?php selected( ( $filters['verification_status'] ?? '' ), 'blocked' ); ?>><?php esc_html_e( 'Blocked', 'stonewright' ); ?></option>
				</select>
			</label>
			<label>
				<span class="screen-reader-text"><?php esc_html_e( 'Rollback', 'stonewright' ); ?></span>
				<select name="rollback_status">
					<option value=""><?php esc_html_e( 'All rollback states', 'stonewright' ); ?></option>
					<option value="not_needed" <?php selected( ( $filters['rollback_status'] ?? '' ), 'not_needed' ); ?>><?php esc_html_e( 'Not needed', 'stonewright' ); ?></option>
					<option value="succeeded" <?php selected( ( $filters['rollback_status'] ?? '' ), 'succeeded' ); ?>><?php esc_html_e( 'Succeeded', 'stonewright' ); ?></option>
					<option value="failed" <?php selected( ( $filters['rollback_status'] ?? '' ), 'failed' ); ?>><?php esc_html_e( 'Failed', 'stonewright' ); ?></option>
				</select>
			</label>
			<label>
				<span class="screen-reader-text"><?php esc_html_e( 'Operation class', 'stonewright' ); ?></span>
				<input type="search" name="operation_class" value="<?php echo esc_attr( (string) ( $filters['operation_class'] ?? '' ) ); ?>" placeholder="<?php esc_attr_e( 'Operation class', 'stonewright' ); ?>"/>
			</label>
			<label>
				<span class="screen-reader-text"><?php esc_html_e( 'Category', 'stonewright' ); ?></span>
				<select name="category">
					<option value=""><?php esc_html_e( 'All categories', 'stonewright' ); ?></option>
					<?php foreach ( AuditEvent::CATEGORIES as $category ) : ?>
						<option value="<?php echo esc_attr( $category ); ?>" <?php selected( ( $filters['category'] ?? '' ), $category ); ?>><?php echo esc_html( $category ); ?></option>
					<?php endforeach; ?>
				</select>
			</label>
			<label>
				<span class="screen-reader-text"><?php esc_html_e( 'Outcome', 'stonewright' ); ?></span>
				<select name="outcome">
					<option value=""><?php esc_html_e( 'All outcomes', 'stonewright' ); ?></option>
					<?php foreach ( AuditEvent::OUTCOMES as $outcome ) : ?>
						<option value="<?php echo esc_attr( $outcome ); ?>" <?php selected( ( $filters['outcome'] ?? '' ), $outcome ); ?>><?php echo esc_html( $outcome ); ?></option>
					<?php endforeach; ?>
				</select>
			</label>
			<label>
				<span class="screen-reader-text"><?php esc_html_e( 'Root error code', 'stonewright' ); ?></span>
				<input type="search" name="root_error_code" value="<?php echo esc_attr( (string) ( $filters['root_error_code'] ?? '' ) ); ?>" placeholder="<?php esc_attr_e( 'Root error code', 'stonewright' ); ?>"/>
			</label>
			<label>
				<span class="screen-reader-text"><?php esc_html_e( 'Normalized path', 'stonewright' ); ?></span>
				<input type="search" name="normalized_path" value="<?php echo esc_attr( (string) ( $filters['normalized_path'] ?? '' ) ); ?>" placeholder="<?php esc_attr_e( 'Path, e.g. verify/readback', 'stonewright' ); ?>"/>
			</label>
			<label>
				<span class="screen-reader-text"><?php esc_html_e( 'Change set ID', 'stonewright' ); ?></span>
				<input type="search" name="change_set_id" value="<?php echo esc_attr( (string) ( $filters['change_set_id'] ?? '' ) ); ?>" placeholder="<?php esc_attr_e( 'Change set ID', 'stonewright' ); ?>"/>
			</label>
			<label>
				<span class="screen-reader-text"><?php esc_html_e( 'Status', 'stonewright' ); ?></span>
				<select name="status">
					<option value=""><?php esc_html_e( 'All statuses', 'stonewright' ); ?></option>
					<option value="ok" <?php selected( ( $filters['status'] ?? '' ), 'ok' ); ?>><?php esc_html_e( 'OK', 'stonewright' ); ?></option>
					<option value="error" <?php selected( ( $filters['status'] ?? '' ), 'error' ); ?>><?php esc_html_e( 'Error', 'stonewright' ); ?></option>
					<option value="blocked" <?php selected( ( $filters['status'] ?? '' ), 'blocked' ); ?>><?php esc_html_e( 'Blocked', 'stonewright' ); ?></option>
					<option value="auth" <?php selected( ( $filters['status'] ?? '' ), 'auth' ); ?>><?php esc_html_e( 'Auth', 'stonewright' ); ?></option>
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
	 * Renders the log table and pagination.
	 *
	 * @param array<int, array<string, mixed>> $rows
	 * @param array<string, mixed>             $filters
	 * @param array<string, string>            $incident_states
	 */
	private static function render_log_table( array $rows, int $page, int $per_page, array $filters = [], ?int $total = null, array $incident_states = [] ): void {
		if ( empty( $rows ) ) {
			echo '<div class="sw-empty-state stonewright-empty-state">';
			if ( [] === $filters && [] === $incident_states ) {
				echo '<p>' . esc_html__( 'No audit entries have been recorded.', 'stonewright' ) . '</p>';
			} else {
				echo '<p>' . esc_html__( 'No audit entries match this view and filter set.', 'stonewright' ) . '</p>';
				if ( [] !== $incident_states ) {
					echo '<p>' . esc_html__( 'Lifecycle incidents still exist; changing an audit filter does not remove or resolve them.', 'stonewright' ) . '</p>';
				}
			}
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

		echo '<div class="sw-audit-table-scroll">';
		echo '<table class="wp-list-table widefat fixed striped sw-audit-table">';
		echo '<thead><tr>';
		echo '<th>' . esc_html__( 'ID', 'stonewright' ) . '</th>';
		echo '<th>' . esc_html__( 'Ability / route', 'stonewright' ) . '</th>';
		echo '<th>' . esc_html__( 'User', 'stonewright' ) . '</th>';
		echo '<th>' . esc_html__( 'Status', 'stonewright' ) . '</th>';
		echo '<th>' . esc_html__( 'Effect', 'stonewright' ) . '</th>';
		echo '<th>' . esc_html__( 'Time (UTC)', 'stonewright' ) . '</th>';
		echo '<th>' . esc_html__( 'Details', 'stonewright' ) . '</th>';
		echo '</tr></thead><tbody>';

		$oauth_client_ids = [];
		foreach ( $rows as $row ) {
			$client_id = self::oauth_client_id_from_row( $row );
			if ( '' !== $client_id ) {
				$oauth_client_ids[] = $client_id;
			}
		}
		$oauth_client_names = ( new ClientRepository() )->names_by_ids( $oauth_client_ids );

		foreach ( $rows as $row ) {
			$user      = get_user_by( 'id', (int) $row['user_id'] );
			$client_id = self::oauth_client_id_from_row( $row );
			if ( $user ) {
				$user_html = esc_html( $user->user_login );
			} elseif ( '' !== $client_id && isset( $oauth_client_names[ $client_id ] ) ) {
				$user_html = esc_html( sprintf( /* translators: %s: OAuth client name */ __( 'OAuth: %s', 'stonewright' ), $oauth_client_names[ $client_id ] ) );
			} elseif ( '' !== $client_id ) {
				$user_html = '<em>' . esc_html__( 'OAuth client', 'stonewright' ) . '</em>';
			} elseif ( (int) ( $row['user_id'] ?? 0 ) > 0 ) {
				$user_html = '<em>' . esc_html__( 'Deleted user', 'stonewright' ) . '</em>';
			} else {
				$user_html = '<em>' . esc_html__( 'System', 'stonewright' ) . '</em>';
			}
			$status    = strtolower( (string) $row['result_status'] );
			$badge     = match ( $status ) {
				'ok'      => 'sw-badge--ok',
				'blocked' => 'sw-badge--warn',
				'auth'    => 'sw-badge--auth',
				default   => 'sw-badge--error',
			};
			$details_raw = (string) ( $row['redacted_details'] ?? '' );
			if ( '' === $details_raw ) {
				$details_raw = (string) ( $row['sanitized_args'] ?? '' );
			}
			$details     = self::pretty_redacted_details( $details_raw );
			$root_error = (string) ( $row['root_error_code'] ?? $row['error_code'] ?? '' );
			$retry_after = max( 0, (int) ( $row['retry_after_seconds'] ?? 0 ) );
			$incident_id = strtolower( (string) ( $row['incident_id'] ?? '' ) );
			$incident_state = isset( $incident_states[ $incident_id ] ) ? $incident_states[ $incident_id ] : '';

			echo '<tr class="sw-audit-row">';
			echo '<td data-label="' . esc_attr( __( 'ID', 'stonewright' ) ) . '">' . (int) $row['id'] . '</td>';
			echo '<td data-label="' . esc_attr( __( 'Ability / route', 'stonewright' ) ) . '"><code title="' . esc_attr( (string) $row['ability_name'] ) . '">' . esc_html( (string) $row['ability_name'] ) . '</code></td>';
			echo '<td data-label="' . esc_attr( __( 'User', 'stonewright' ) ) . '">' . wp_kses_post( $user_html ) . '</td>';
			echo '<td data-label="' . esc_attr( __( 'Status', 'stonewright' ) ) . '"><span class="sw-badge ' . esc_attr( $badge ) . '">' . esc_html( strtoupper( $status ) ) . '</span></td>';
			echo '<td data-label="' . esc_attr( __( 'Effect', 'stonewright' ) ) . '">';
			$resource = trim( (string) ( $row['resource_type'] ?? '' ) . ' ' . (string) ( $row['resource_ref'] ?? '' ) );
			$verify   = (string) ( $row['verification_status'] ?? '' );
			$rollback = (string) ( $row['rollback_status'] ?? '' );
			if ( '' !== $resource ) {
				echo '<code>' . esc_html( $resource ) . '</code><br>';
			}
			if ( '' !== $verify ) {
				echo '<span>' . esc_html( 'verify: ' . $verify ) . '</span>';
			}
			if ( '' !== $rollback && 'not_needed' !== $rollback ) {
				echo '<br><span>' . esc_html( 'rollback: ' . $rollback ) . '</span>';
			}
			if ( '' === $resource && '' === $verify && ( '' === $rollback || 'not_needed' === $rollback ) ) {
				echo '<span class="sw-muted">—</span>';
			}
			echo '</td>';
			echo '<td data-label="' . esc_attr( __( 'Time (UTC)', 'stonewright' ) ) . '">' . esc_html( (string) $row['created_at'] ) . '</td>';
			echo '<td data-label="' . esc_attr( __( 'Details', 'stonewright' ) ) . '">';
			$category = (string) ( $row['category'] ?? '' );
			$outcome  = (string) ( $row['outcome'] ?? '' );
			if ( '' !== $category || '' !== $outcome ) {
				echo '<div><span class="sw-badge sw-badge--muted">' . esc_html( trim( $category . ' · ' . $outcome, ' ·' ) ) . '</span></div>';
			}
			if ( '' !== $root_error ) {
				echo '<div class="sw-audit-error-cause"><code>' . esc_html( $root_error ) . '</code></div>';
			}
			$normalized_path = (string) ( $row['normalized_path'] ?? '' );
			if ( '' !== $normalized_path ) {
				echo '<div><strong>' . esc_html__( 'Path:', 'stonewright' ) . '</strong> <code>' . esc_html( $normalized_path ) . '</code></div>';
			}
			$change_set_id = (string) ( $row['change_set_id'] ?? '' );
			if ( '' !== $change_set_id ) {
				echo '<div><strong>' . esc_html__( 'Change set:', 'stonewright' ) . '</strong> <code>' . esc_html( $change_set_id ) . '</code></div>';
			}
			if ( $retry_after > 0 ) {
				echo '<div><strong>' . esc_html__( 'Retry after:', 'stonewright' ) . '</strong> ' . esc_html( sprintf( /* translators: %d: seconds */ _n( '%d second', '%d seconds', $retry_after, 'stonewright' ), $retry_after ) ) . '</div>';
			}
			if ( 1 === preg_match( '/^[a-f0-9]{64}$/', $incident_id ) ) {
				$incident_url = add_query_arg( [ 'page' => MemoryInstructionsPage::SLUG, 'type' => 'incidents', 'incident_id' => $incident_id ], admin_url( 'admin.php' ) ) . '#stonewright-incident-' . $incident_id;
				echo '<div><strong>' . esc_html__( 'Incident:', 'stonewright' ) . '</strong> <a href="' . esc_url( $incident_url ) . '"><code>' . esc_html( substr( $incident_id, 0, 12 ) ) . '…</code> ' . esc_html( '' !== $incident_state ? $incident_state : __( 'recorded', 'stonewright' ) ) . '</a></div>';
			}
			if ( '' !== $details ) {
				$payload_id = 'sw-audit-details-' . (int) $row['id'];
				echo '<details class="sw-audit-details">';
				echo '<summary>' . esc_html__( 'View redacted details', 'stonewright' ) . '</summary>';
				echo '<pre id="' . esc_attr( $payload_id ) . '" class="sw-audit-payload">' . esc_html( $details ) . '</pre>';
				echo '</details>';
				echo '<button type="button" class="sw-btn sw-btn--ghost sw-btn--sm sw-audit-copy" data-stonewright-copy="' . esc_attr( $payload_id ) . '">' . esc_html__( 'Copy redacted details', 'stonewright' ) . '</button>';
			} elseif ( '' === $root_error && '' === $category && '' === $outcome && '' === $incident_id && 0 === $retry_after ) {
				echo '<span class="sw-muted">' . esc_html__( '—', 'stonewright' ) . '</span>';
			}
			echo '</td>';
			echo '</tr>';
		}

		echo '</tbody></table>';
		echo '</div>';

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

	/**
	 * @param array<string, mixed> $row Audit row.
	 */
	private static function oauth_client_id_from_row( array $row ): string {
		if ( ! str_starts_with( (string) ( $row['ability_name'] ?? '' ), 'oauth/' ) ) {
			return '';
		}
		foreach ( [ 'redacted_details', 'sanitized_args' ] as $payload_key ) {
			$details = json_decode( (string) ( $row[ $payload_key ] ?? '' ), true );
			if ( is_array( $details ) && isset( $details['client_id'] ) && is_scalar( $details['client_id'] ) ) {
				return mb_substr( sanitize_text_field( (string) $details['client_id'] ), 0, AuditLog::AUTH_DIAGNOSTIC_MAX_LENGTH );
			}
		}

		return '';
	}

	/** @param array<string, mixed> $filters @return array<string, int> */
	private static function view_counts( array $filters ): array {
		unset( $filters['view'] );
		$counts = [];
		foreach ( AuditLog::ADMIN_VIEWS as $view ) {
			$view_filters         = $filters;
			$view_filters['view'] = $view;
			$counts[ $view ]      = AuditLog::count( $view_filters );
		}
		return $counts;
	}

	/**
	 * @param array<string, mixed> $filters
	 * @param array<string, int>   $counts
	 */
	private static function render_views( array $filters, array $counts ): void {
		$current = (string) ( $filters['view'] ?? 'all' );
		$base    = $filters;
		unset( $base['view'] );
		$labels = [
			'all'       => __( 'All', 'stonewright' ),
			'errors'    => __( 'Errors', 'stonewright' ),
			'retryable' => __( 'Retryable', 'stonewright' ),
			'blocked'   => __( 'Blocked / Safety', 'stonewright' ),
			'auth'      => __( 'Auth', 'stonewright' ),
			'resolved'  => __( 'Resolved', 'stonewright' ),
		];

		echo '<ul class="subsubsub sw-audit-views">';
		$last = array_key_last( $labels );
		foreach ( $labels as $view => $label ) {
			$query = array_merge( [ 'page' => self::SLUG ], $base );
			if ( 'all' !== $view ) {
				$query['view'] = $view;
			}
			$url = add_query_arg( $query, admin_url( 'admin.php' ) );
			echo '<li><a class="' . esc_attr( $view === $current ? 'current' : '' ) . '" href="' . esc_url( $url ) . '">' . esc_html( $label . ' (' . (int) ( $counts[ $view ] ?? 0 ) . ')' ) . '</a>';
			if ( $view !== $last ) {
				echo ' | ';
			}
			echo '</li>';
		}
		echo '</ul>';
	}

	/** @param array<string, mixed> $filters */
	private static function render_export_controls( array $filters ): void {
		echo '<div class="sw-actions sw-audit-export-actions">';
		foreach ( [ 'json' => __( 'Export redacted JSON', 'stonewright' ), 'csv' => __( 'Export redacted CSV', 'stonewright' ) ] as $format => $label ) {
			echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" class="stonewright-inline-form">';
			echo '<input type="hidden" name="action" value="stonewright_audit_export" />';
			echo '<input type="hidden" name="format" value="' . esc_attr( $format ) . '" />';
			foreach ( $filters as $key => $value ) {
				if ( is_scalar( $value ) ) {
					echo '<input type="hidden" name="' . esc_attr( (string) $key ) . '" value="' . esc_attr( (string) $value ) . '" />';
				}
			}
			wp_nonce_field( 'stonewright_audit_export', '_stonewright_nonce' );
			echo '<button type="submit" class="sw-btn sw-btn--secondary sw-btn--sm">' . esc_html( $label ) . '</button>';
			echo '</form>';
		}
		echo '</div>';
	}

	/** @return array<string, string> */
	private static function incident_state_map(): array {
		$states = [];
		foreach ( IncidentStore::recent( 500 ) as $incident ) {
			$id = (string) ( $incident['incident_id'] ?? '' );
			if ( 1 === preg_match( '/^[a-f0-9]{64}$/', $id ) ) {
				$states[ $id ] = (string) ( $incident['state'] ?? 'observing' );
			}
		}
		return $states;
	}

	private static function pretty_redacted_details( string $raw ): string {
		if ( '' === $raw ) {
			return '';
		}
		$decoded = json_decode( $raw, true );
		if ( is_array( $decoded ) ) {
			$decoded = AuditLog::redact_sensitive( $decoded );
			$pretty  = wp_json_encode( $decoded, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES );
			if ( ! is_string( $pretty ) || SensitiveContent::contains( $pretty ) ) {
				return '{"redaction_error":"sensitive_content_blocked"}';
			}
			return $pretty;
		}
		return SensitiveContent::contains( $raw ) ? '{"redaction_error":"sensitive_content_blocked"}' : mb_substr( sanitize_textarea_field( $raw ), 0, 2000 );
	}

	/**
	 * Build bounded, allowlisted export. Raw request payloads never leave DB.
	 *
	 * @param array<int, array<string, mixed>> $rows
	 * @return string|\WP_Error
	 */
	public static function build_export( array $rows, string $format ): string|\WP_Error {
		$format = sanitize_key( $format );
		if ( ! in_array( $format, [ 'json', 'csv' ], true ) ) {
			return new \WP_Error( 'stonewright_audit_export_format_invalid', __( 'Audit export format must be json or csv.', 'stonewright' ), [ 'status' => 400 ] );
		}
		$export_rows = [];
		foreach ( array_slice( $rows, 0, 5000 ) as $row ) {
			$details = self::export_details( (string) ( $row['redacted_details'] ?? '' ) );
			if ( $details instanceof \WP_Error ) {
				return $details;
			}
			$export_rows[] = [
				'id'                  => max( 0, (int) ( $row['id'] ?? 0 ) ),
				'event_id'            => self::safe_export_text( $row['event_id'] ?? '', 36 ),
				'created_at'          => self::safe_export_text( $row['created_at'] ?? '', 32 ),
				'ability_name'        => self::safe_export_text( $row['ability_name'] ?? '', 190 ),
				'result_status'       => self::safe_export_text( $row['result_status'] ?? '', 32 ),
				'category'            => self::safe_export_text( $row['category'] ?? '', 32 ),
				'outcome'             => self::safe_export_text( $row['outcome'] ?? '', 24 ),
				'severity_level'      => self::safe_export_text( $row['severity_level'] ?? '', 16 ),
				'root_error_code'     => self::safe_export_text( $row['root_error_code'] ?? '', 190 ),
				'resource_type'       => self::safe_export_text( $row['resource_type'] ?? '', 96 ),
				'resource_key_hash'   => self::safe_export_hash( $row['resource_key_hash'] ?? '' ),
				'normalized_path'     => self::safe_export_text( $row['normalized_path'] ?? '', 255 ),
				'change_set_id'       => self::safe_export_text( $row['change_set_id'] ?? '', 96 ),
				'transaction_id'      => self::safe_export_text( $row['transaction_id'] ?? '', 96 ),
				'retryable'           => ! empty( $row['retryable'] ),
				'retry_after_seconds' => max( 0, min( 86400, (int) ( $row['retry_after_seconds'] ?? 0 ) ) ),
				'incident_id'         => self::safe_export_hash( $row['incident_id'] ?? '' ),
				'redacted_details'    => $details,
			];
		}

		if ( 'json' === $format ) {
			$output = wp_json_encode( $export_rows, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES );
			$output = is_string( $output ) ? $output : '';
		} else {
			$stream = fopen( 'php://temp', 'w+' );
			if ( false === $stream ) {
				return new \WP_Error( 'stonewright_audit_export_failed', __( 'Could not create audit export stream.', 'stonewright' ), [ 'status' => 500 ] );
			}
			$headers = [] === $export_rows ? array_keys( self::empty_export_row() ) : array_keys( $export_rows[0] );
			fputcsv( $stream, $headers, ',', '"', '' );
			foreach ( $export_rows as $row ) {
				$row['redacted_details'] = wp_json_encode( $row['redacted_details'], JSON_UNESCAPED_SLASHES );
				$row['retryable'] = $row['retryable'] ? 'true' : 'false';
				fputcsv( $stream, array_map( [ self::class, 'csv_safe_cell' ], array_values( $row ) ), ',', '"', '' );
			}
			rewind( $stream );
			$output = stream_get_contents( $stream );
			fclose( $stream );
			$output = is_string( $output ) ? $output : '';
		}

		if ( '' === $output || SensitiveContent::contains( $output ) ) {
			return new \WP_Error( 'stonewright_audit_export_sensitive_content_blocked', __( 'Audit export was blocked because secret-like content remained after redaction.', 'stonewright' ), [ 'status' => 400 ] );
		}
		return $output;
	}

	private static function csv_safe_cell( mixed $value ): mixed {
		if ( ! is_string( $value ) || '' === $value ) {
			return $value;
		}
		return 1 === preg_match( '/^[=+\-@\t\r]/', $value ) ? "'" . $value : $value;
	}

	/** @return array<string, mixed>|\WP_Error */
	private static function export_details( string $raw ): array|\WP_Error {
		if ( '' === $raw ) {
			return [];
		}
		$decoded = json_decode( $raw, true );
		if ( ! is_array( $decoded ) ) {
			return [];
		}
		$decoded = AuditLog::redact_sensitive( $decoded );
		$encoded = wp_json_encode( $decoded, JSON_UNESCAPED_SLASHES );
		if ( ! is_string( $encoded ) || SensitiveContent::contains( $encoded ) ) {
			return new \WP_Error( 'stonewright_audit_export_sensitive_content_blocked', __( 'Audit export was blocked because secret-like content remained after redaction.', 'stonewright' ), [ 'status' => 400 ] );
		}
		return $decoded;
	}

	/** @return array<string, mixed> */
	private static function empty_export_row(): array {
		return [
			'id'                  => 0,
			'event_id'            => '',
			'created_at'          => '',
			'ability_name'        => '',
			'result_status'       => '',
			'category'            => '',
			'outcome'             => '',
			'severity_level'      => '',
			'root_error_code'     => '',
			'resource_type'       => '',
			'resource_key_hash'   => '',
			'normalized_path'     => '',
			'change_set_id'       => '',
			'transaction_id'      => '',
			'retryable'           => false,
			'retry_after_seconds' => 0,
			'incident_id'         => '',
			'redacted_details'    => [],
		];
	}

	private static function safe_export_text( mixed $value, int $max ): string {
		return is_scalar( $value ) ? mb_substr( sanitize_text_field( (string) $value ), 0, $max ) : '';
	}

	private static function safe_export_hash( mixed $value ): string {
		$value = is_scalar( $value ) ? strtolower( trim( (string) $value ) ) : '';
		return 1 === preg_match( '/^[a-f0-9]{64}$/', $value ) ? $value : '';
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
