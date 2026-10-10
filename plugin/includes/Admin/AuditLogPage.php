<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\Admin;

use Stonewright\WpMcp\Admin\Ui\Badge;
use Stonewright\WpMcp\Admin\Ui\Button;
use Stonewright\WpMcp\Admin\Ui\Card;
use Stonewright\WpMcp\Admin\Ui\EmptyState;
use Stonewright\WpMcp\Admin\Ui\Html;
use Stonewright\WpMcp\Admin\Ui\Icon;
use Stonewright\WpMcp\Admin\Ui\KvList;
use Stonewright\WpMcp\Admin\Ui\Notice;
use Stonewright\WpMcp\Admin\Ui\Scope;
use Stonewright\WpMcp\Admin\Ui\Table;
use Stonewright\WpMcp\Authorization\WordPress\ClientNames;
use Stonewright\WpMcp\Security\AuditLog;
use Stonewright\WpMcp\Security\AuditEvent;
use Stonewright\WpMcp\Security\ErrorPatterns;
use Stonewright\WpMcp\Security\IncidentStore;
use Stonewright\WpMcp\Security\RemediationHints;
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

	/**
	 * Incidents the last purge in this request deleted with the log.
	 *
	 * @var int
	 */
	private static int $last_purged_incidents = 0;

	public static function last_purged_incidents(): int {
		return self::$last_purged_incidents;
	}

	public static function register(): void {
		add_action( 'admin_menu', [ self::class, 'add_submenu' ] );
		add_action( 'admin_post_stonewright_dismiss_error_pattern', [ self::class, 'handle_dismiss_pattern' ] );
		add_action( 'admin_post_stonewright_audit_export', [ self::class, 'handle_export' ] );
		add_action( 'admin_post_stonewright_audit_purge', [ self::class, 'handle_purge' ] );
		add_action( 'admin_enqueue_scripts', [ self::class, 'enqueue' ] );
		AuditLineageDrawer::register();
	}

	public static function enqueue( string $hook_suffix = '' ): void {
		$page = isset( $_GET['page'] ) ? sanitize_key( (string) wp_unslash( (string) $_GET['page'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( self::SLUG !== $page && ! str_contains( $hook_suffix, self::SLUG ) ) {
			return;
		}
		$version = defined( 'STONEWRIGHT_VERSION' ) ? (string) STONEWRIGHT_VERSION : '0.1.0';
		$base    = defined( 'STONEWRIGHT_URL' ) ? (string) STONEWRIGHT_URL : '';
		wp_enqueue_script(
			'stonewright-admin-audit',
			$base . 'assets/admin/pages/audit.js',
			[ 'stonewright-ui' ],
			$version,
			true
		);
	}

	public static function handle_purge(): void {
		$count = self::process_purge_request();
		wp_safe_redirect(
			add_query_arg(
				[
					'page'      => self::SLUG,
					'purged'    => (string) $count,
					'incidents' => (string) self::$last_purged_incidents,
				],
				admin_url( 'admin.php' )
			)
		);
		exit;
	}

	/**
	 * Capability, nonce, and typed confirmation, then wipe events, incidents and pattern
	 * summaries and write one `audit_log_purged` receipt.
	 */
	public static function process_purge_request(): int {
		if ( ! current_user_can( self::CAPABILITY ) ) {
			wp_die( esc_html__( 'Forbidden', 'stonewright' ), '', [ 'response' => 403 ] );
		}
		$nonce = isset( $_POST['_stonewright_nonce'] ) ? sanitize_text_field( wp_unslash( (string) $_POST['_stonewright_nonce'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Missing
		if ( false === wp_verify_nonce( $nonce, 'stonewright_audit_purge' ) ) {
			wp_die( esc_html__( 'Forbidden', 'stonewright' ), '', [ 'response' => 403 ] );
		}
		$phrase = isset( $_POST['confirm_phrase'] ) ? sanitize_text_field( wp_unslash( (string) $_POST['confirm_phrase'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Missing
		if ( 'DELETE' !== $phrase ) {
			wp_die( esc_html__( 'Type DELETE to confirm this purge.', 'stonewright' ), '', [ 'response' => 400 ] );
		}

		$count = AuditLog::purge_all();
		// An incident points at audit events; with every event gone it has nothing left to show, so it goes too.
		self::$last_purged_incidents = IncidentStore::purge_all();
		ErrorPatterns::clear();
		AuditLog::record(
			'audit_log_purged',
			[
				'actor'     => (int) get_current_user_id(),
				'count'     => $count,
				'incidents' => self::$last_purged_incidents,
				'_meta' => [
					'operation_class' => 'audit_purge',
					'resource_type'   => 'audit_log',
					'resource_ref'    => 'all',
				],
			],
			'ok'
		);
		return $count;
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
		// Hub: Activity. The slug stays stonewright-audit-log; MenuOrder names the sidebar entry.
		add_submenu_page(
			ConfigurationPage::SLUG,
			__( 'Audit log', 'stonewright' ),
			__( 'Audit log', 'stonewright' ),
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
		$page    = isset( $_GET['paged'] ) ? max( 1, (int) $_GET['paged'] ) : 1;
		$filters = self::filters_from_request();
		// phpcs:enable
		$per_page        = 50;
		$rows            = AuditLog::recent( $per_page, $page, $filters );
		$total           = AuditLog::count( $filters );
		$counts          = self::view_counts( $filters );
		$incident_states = self::incident_state_map();
		$all_count       = AuditLog::count();
		$incident_total  = (int) array_sum( IncidentStore::counts() );

		AdminShell::open( self::SLUG, [ 'actions' => self::header_actions( $filters ) ] );

		$html  = self::flash_html();
		$html .= self::degraded_html();
		$html .= self::incident_summary_html();
		$html .= self::recurring_errors_html();
		$html .= self::views_html( $filters, $counts );
		$html .= self::filters_html( $filters, $total, $page, $per_page );
		// Mount point for the change set chip and the lineage drawer; also runs when no row matches.
		ob_start();
		do_action( 'stonewright_audit_log_toolbar', $filters, $rows, $incident_states );
		$html .= (string) ob_get_clean();
		$html .= self::log_html( $rows, $page, $per_page, $filters, $total, $incident_states );
		$html .= self::purge_dialog_html( $all_count, $incident_total );

		echo Scope::wrap( $html, [ 'page' => true, 'class' => 'sw-audit-page' ] ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Markup built by the Ui helpers, which escape every value.
		AdminShell::close();
	}

	/**
	 * The page header's actions: both exports and the way into the delete dialog. None is primary.
	 *
	 * @param array<string, mixed> $filters
	 */
	private static function header_actions( array $filters ): string {
		$html = '';
		foreach ( [ 'json' => __( 'Export redacted JSON', 'stonewright' ), 'csv' => __( 'Export redacted CSV', 'stonewright' ) ] as $format => $label ) {
			$fields = self::hidden( 'action', 'stonewright_audit_export' ) . self::hidden( 'format', $format );
			foreach ( $filters as $key => $value ) {
				if ( is_scalar( $value ) ) {
					$fields .= self::hidden( (string) $key, (string) $value );
				}
			}
			$fields .= self::hidden( '_stonewright_nonce', wp_create_nonce( 'stonewright_audit_export' ) );
			$html   .= Html::element(
				'form',
				[ 'method' => 'post', 'action' => admin_url( 'admin-post.php' ) ],
				$fields . Button::render( $label, [ 'size' => 'sm', 'type' => 'submit' ] )
			);
		}

		return $html . Button::render(
			__( 'Delete all logs', 'stonewright' ),
			[
				'variant' => 'danger',
				'size'    => 'sm',
				'attrs'   => [ 'data-sw-ui-dialog-open' => '#sw-audit-purge-dialog', 'aria-haspopup' => 'dialog' ],
			]
		);
	}

	private static function hidden( string $name, string $value ): string {
		return Html::void( 'input', [ 'type' => 'hidden', 'name' => $name, 'value' => $value ] );
	}

	/** What the last delete did, said once after the redirect. */
	private static function flash_html(): string {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- Read-only counts after a redirect.
		if ( ! isset( $_GET['purged'] ) ) {
			return '';
		}
		$purged    = max( 0, (int) $_GET['purged'] );
		$incidents = isset( $_GET['incidents'] ) ? max( 0, (int) $_GET['incidents'] ) : null;
		// phpcs:enable
		$events_text = sprintf( /* translators: %d: number of deleted audit events */ _n( '%d audit event', '%d audit events', $purged, 'stonewright' ), $purged );
		if ( null === $incidents ) {
			$text = sprintf( /* translators: %s: "3 audit events" */ __( 'Deleted %s and all pattern summaries. One audit_log_purged receipt remains.', 'stonewright' ), $events_text );
		} else {
			$incidents_text = sprintf( /* translators: %d: number of deleted incidents */ _n( '%d incident', '%d incidents', $incidents, 'stonewright' ), $incidents );
			$text           = sprintf( /* translators: 1: number of audit events with the word, 2: number of incidents with the word */ __( 'Deleted %1$s, %2$s and all pattern summaries. One audit_log_purged receipt remains.', 'stonewright' ), $events_text, $incidents_text );
		}

		return Notice::render( 'ok', __( 'Audit log deleted', 'stonewright' ), $text );
	}

	private static function degraded_html(): string {
		if ( ! get_option( 'stonewright_audit_degraded', false ) ) {
			return '';
		}

		return Notice::render(
			'danger',
			__( 'Audit coverage degraded', 'stonewright' ),
			__( 'A mutation audit row failed to persist. Stop write work until database health is repaired and a later audit insert succeeds.', 'stonewright' )
		);
	}

	/** The incident lifecycle as one band: four counts and what each state means. */
	private static function incident_summary_html(): string {
		$counts = IncidentStore::counts();
		$meta   = [
			'open'       => __( 'Failed past the threshold', 'stonewright' ),
			'observing'  => __( 'Seen, below the threshold', 'stonewright' ),
			'resolved'   => __( 'Closed by a verified repair', 'stonewright' ),
			'suppressed' => __( 'Set aside by an operator', 'stonewright' ),
		];
		$labels = [
			'open'       => __( 'Open', 'stonewright' ),
			'observing'  => __( 'Observing', 'stonewright' ),
			'resolved'   => __( 'Resolved', 'stonewright' ),
			'suppressed' => __( 'Suppressed', 'stonewright' ),
		];
		$stats = '';
		foreach ( $labels as $state => $label ) {
			$stats .= Html::element(
				'div',
				[ 'class' => 'sw-ui-stat' ],
				Html::element( 'span', [ 'class' => 'sw-ui-stat__label' ], Html::text( $label ) )
					. Html::element( 'span', [ 'class' => 'sw-ui-stat__value' ], Html::text( (string) (int) ( $counts[ $state ] ?? 0 ) ) )
					. Html::element( 'span', [ 'class' => 'sw-ui-stat__meta' ], Html::text( $meta[ $state ] ) )
			);
		}

		return Card::render(
			__( 'Incident lifecycle', 'stonewright' ),
			Html::element( 'div', [ 'class' => 'sw-ui-stats', 'role' => 'group', 'aria-label' => __( 'Incident counts by state', 'stonewright' ) ], $stats ),
			[
				'desc'  => __( 'Incidents open only after their threshold; a generic success never closes one without matching evidence.', 'stonewright' ),
				'class' => 'sw-incident-summary',
			]
		);
	}

	/** Patterns that failed more than once, one row each, with how to see them and how to set one aside. */
	private static function recurring_errors_html(): string {
		$patterns = ErrorPatterns::recurring( 10 );
		if ( [] === $patterns ) {
			return '';
		}

		$rows    = [];
		$dialogs = '';
		foreach ( $patterns as $p ) {
			$ability = (string) ( $p['ability'] ?? '' );
			$count   = (int) ( $p['count'] ?? 0 );
			$msg     = (string) ( $p['message'] ?? '' );
			$code    = (string) ( $p['error_code'] ?? '' );
			$repair  = (string) ( $p['repair'] ?? '' );
			$sig     = (string) ( $p['signature'] ?? '' );
			$mode    = (string) ( $p['mode'] ?? '' );
			$target  = (string) ( $p['target'] ?? '' );
			$args    = [ 'page' => self::SLUG, 'status' => 'error', 'ability' => $ability ];
			if ( '' !== $code ) {
				$args['error_code'] = $code;
			}
			if ( '' !== $sig ) {
				$args['signature'] = $sig;
			}
			$hash = substr( md5( $sig . '|' . $ability . '|' . $code ), 0, 10 );

			$tags = '';
			if ( '' !== $code ) {
				$tags .= Badge::tag( $code );
			}
			if ( '' !== $mode ) {
				/* translators: %s: site mode such as production-safe */
				$tags .= Badge::tag( sprintf( __( 'Mode: %s', 'stonewright' ), $mode ) );
			}
			if ( '' !== $target ) {
				/* translators: %s: the item the failures concern */
				$tags .= Badge::tag( sprintf( __( 'Target: %s', 'stonewright' ), $target ) );
			}
			$primary = Html::element( 'code', [ 'class' => 'sw-ui-table__primary sw-audit-ability' ], Html::text( $ability ) )
				. ( '' !== $tags ? Html::element( 'span', [ 'class' => 'sw-audit-tags' ], $tags ) : '' )
				. ( '' !== $msg ? Html::element( 'span', [ 'class' => 'sw-ui-table__meta' ], Html::text( $msg ) ) : '' )
				. ( '' !== $repair ? Html::element( 'span', [ 'class' => 'sw-ui-table__meta' ], Html::element( 'strong', [], Html::text( __( 'Repair:', 'stonewright' ) ) ) . ' ' . Html::text( $repair ) ) : '' );

			$actions = Button::render(
				__( 'View occurrences', 'stonewright' ),
				[ 'href' => add_query_arg( $args, admin_url( 'admin.php' ) ), 'size' => 'sm', 'context' => sprintf( /* translators: %s: ability name */ __( 'of %s', 'stonewright' ), $ability ) ]
			) . Button::render(
				__( 'Dismiss', 'stonewright' ),
				[
					'size'    => 'sm',
					'type'    => 'submit',
					'form'    => 'sw-audit-dismiss-form-' . $hash,
					'context' => sprintf( /* translators: %s: ability name */ __( 'pattern for %s', 'stonewright' ), $ability ),
					'attrs'   => [ 'data-sw-ui-dialog-open' => '#sw-audit-dismiss-' . $hash, 'aria-haspopup' => 'dialog' ],
				]
			);
			$rows[]   = [
				'pattern' => [ 'html' => $primary ],
				'seen'    => [ 'html' => Badge::render( sprintf( /* translators: %d: number of failures */ _n( '%d time', '%d times', $count, 'stonewright' ), $count ), [ 'variant' => 'danger' ] ) ],
				'last'    => [ 'html' => self::time_html( (string) ( $p['last_seen'] ?? '' ) ) ],
				'actions' => [ 'html' => Button::group( [ $actions ], true ) ],
			];
			$dialogs .= self::dismiss_dialog_html( $hash, $ability, $sig );
		}

		return Card::render(
			__( 'Recurring errors', 'stonewright' ),
			Table::render(
				[
					[ 'key' => 'pattern', 'label' => __( 'Pattern', 'stonewright' ), 'primary' => true ],
					[ 'key' => 'seen', 'label' => __( 'Seen', 'stonewright' ) ],
					[ 'key' => 'last', 'label' => __( 'Last seen', 'stonewright' ), 'secondary' => true ],
					[ 'key' => 'actions', 'label' => __( 'Actions', 'stonewright' ), 'actions' => true ],
				],
				$rows,
				[ 'caption' => __( 'Recurring error patterns', 'stonewright' ), 'class' => 'sw-audit-patterns' ]
			),
			[
				'desc'  => __( 'Patterns that failed more than once. Agents see the top three at task-start.', 'stonewright' ),
				'flush' => true,
				'id'    => 'sw-recurring-errors',
			]
		) . $dialogs;
	}

	/** The confirmation for setting one pattern aside. The form is always in the page, so without script the row button posts it. */
	private static function dismiss_dialog_html( string $hash, string $ability, string $signature ): string {
		$id   = 'sw-audit-dismiss-' . $hash;
		$form = Html::element(
			'form',
			[ 'method' => 'post', 'action' => admin_url( 'admin-post.php' ), 'id' => 'sw-audit-dismiss-form-' . $hash ],
			Html::element( 'div', [ 'class' => 'sw-ui-dialog__header' ], Html::element( 'h2', [ 'class' => 'sw-ui-dialog__title', 'id' => $id . '-title' ], Html::text( __( 'Dismiss this recurring error pattern?', 'stonewright' ) ) ) )
				. Html::element(
					'div',
					[ 'class' => 'sw-ui-dialog__body' ],
					Html::element( 'p', [], Html::text( sprintf( /* translators: %s: ability name */ __( 'The pattern for %s leaves the summary. Its audit rows stay in the log, and the pattern returns if the error recurs.', 'stonewright' ), $ability ) ) )
						. self::hidden( 'action', 'stonewright_dismiss_error_pattern' )
						. self::hidden( 'signature', $signature )
						. self::hidden( '_wpnonce', wp_create_nonce( 'stonewright_dismiss_error_pattern' ) )
				)
				. Html::element(
					'div',
					[ 'class' => 'sw-ui-dialog__footer' ],
					Button::render( __( 'Cancel', 'stonewright' ), [ 'attrs' => [ 'data-sw-ui-dialog-close' => true, 'autofocus' => true ] ] )
					. Button::render( __( 'Dismiss pattern', 'stonewright' ), [ 'type' => 'submit', 'variant' => 'danger-solid' ] )
				)
		);

		return Html::element( 'dialog', [ 'id' => $id, 'class' => 'sw-ui-dialog', 'aria-labelledby' => $id . '-title' ], $form );
	}

	/**
	 * The views (all, errors, retryable, blocked, auth, resolved) as links that look like pressed filter chips.
	 *
	 * @param array<string, mixed> $filters
	 * @param array<string, int>   $counts
	 */
	private static function views_html( array $filters, array $counts ): string {
		$current = (string) ( $filters['view'] ?? 'all' );
		$base    = $filters;
		unset( $base['view'] );
		$labels = [
			'all'       => __( 'All', 'stonewright' ),
			'errors'    => __( 'Errors', 'stonewright' ),
			'retryable' => __( 'Retryable', 'stonewright' ),
			'blocked'   => __( 'Blocked or safety', 'stonewright' ),
			'auth'      => __( 'Auth', 'stonewright' ),
			'resolved'  => __( 'Resolved', 'stonewright' ),
		];

		$hide_empty = isset( $filters['signature'] ) || isset( $filters['error_code'] );
		$chips      = '';
		foreach ( $labels as $view => $label ) {
			$count = (int) ( $counts[ $view ] ?? 0 );
			if ( $hide_empty && 0 === $count && $view !== $current && 'all' !== $view ) {
				continue;
			}
			$query = array_merge( [ 'page' => self::SLUG ], $base );
			if ( 'all' !== $view ) {
				$query['view'] = $view;
			}
			$chips .= Html::element(
				'a',
				[
					'class'        => 'sw-ui-chip-filter',
					'href'         => add_query_arg( $query, admin_url( 'admin.php' ) ),
					'aria-current' => $view === $current ? 'true' : null,
				],
				Html::text( $label ) . ' ' . Badge::count( $count )
			);
		}

		return Html::element( 'nav', [ 'class' => 'sw-audit-views', 'aria-label' => __( 'Log views', 'stonewright' ) ], Html::element( 'div', [ 'class' => 'sw-ui-actions' ], $chips ) );
	}

	/**
	 * The filter toolbar. Which filters match part of what is typed and which match the whole value is stated on
	 * the form, and the rule of each field is under it.
	 *
	 * @param array<string, mixed> $filters
	 */
	private static function filters_html( array $filters, int $total, int $page, int $per_page ): string {
		$more_keys = [ 'verification_status', 'rollback_status', 'operation_class', 'category', 'outcome', 'root_error_code', 'normalized_path', 'change_set_id', 'user' ];
		$more_open = false;
		foreach ( $more_keys as $key ) {
			if ( ! empty( $filters[ $key ] ) ) {
				$more_open = true;
				break;
			}
		}

		$hidden = self::hidden( 'page', self::SLUG );
		foreach ( [ 'view', 'error_code', 'signature' ] as $key ) {
			if ( isset( $filters[ $key ] ) ) {
				$hidden .= self::hidden( $key, (string) $filters[ $key ] );
			}
		}

		$status_options = [
			''        => __( 'All statuses', 'stonewright' ),
			'ok'      => __( 'OK', 'stonewright' ),
			'error'   => __( 'Error', 'stonewright' ),
			'blocked' => __( 'Blocked', 'stonewright' ),
			'auth'    => __( 'Auth', 'stonewright' ),
		];
		$primary = self::field( 'ability', __( 'Ability', 'stonewright' ), self::input( 'ability', 'search', (string) ( $filters['ability'] ?? '' ) ), __( 'Contains', 'stonewright' ), 'md' )
			. self::field( 'status', __( 'Status', 'stonewright' ), self::select( 'status', $status_options, (string) ( $filters['status'] ?? '' ) ), __( 'Exact', 'stonewright' ), 'sm' )
			. self::field( 'from', __( 'From', 'stonewright' ), self::input( 'from', 'date', (string) ( $filters['from'] ?? '' ) ), __( 'Whole day, UTC', 'stonewright' ), 'sm' )
			. self::field( 'to', __( 'To', 'stonewright' ), self::input( 'to', 'date', (string) ( $filters['to'] ?? '' ) ), __( 'Whole day, UTC', 'stonewright' ), 'sm' );

		$verification = [
			''         => __( 'All verification', 'stonewright' ),
			'verified' => __( 'Verified', 'stonewright' ),
			'failed'   => __( 'Failed', 'stonewright' ),
			'blocked'  => __( 'Blocked', 'stonewright' ),
		];
		$rollback     = [
			''           => __( 'All rollback states', 'stonewright' ),
			'not_needed' => __( 'Not needed', 'stonewright' ),
			'succeeded'  => __( 'Succeeded', 'stonewright' ),
			'failed'     => __( 'Failed', 'stonewright' ),
		];
		$categories   = [ '' => __( 'All categories', 'stonewright' ) ];
		foreach ( AuditEvent::CATEGORIES as $category ) {
			$categories[ $category ] = ucfirst( strtolower( $category ) );
		}
		$outcomes = [ '' => __( 'All outcomes', 'stonewright' ) ];
		foreach ( AuditEvent::OUTCOMES as $outcome ) {
			$outcomes[ $outcome ] = ucfirst( strtolower( $outcome ) );
		}
		$exact    = __( 'Exact', 'stonewright' );
		$contains = __( 'Contains', 'stonewright' );
		$more     = self::field( 'verification_status', __( 'Verification', 'stonewright' ), self::select( 'verification_status', $verification, (string) ( $filters['verification_status'] ?? '' ) ), $exact, 'md' )
			. self::field( 'rollback_status', __( 'Rollback', 'stonewright' ), self::select( 'rollback_status', $rollback, (string) ( $filters['rollback_status'] ?? '' ) ), $exact, 'md' )
			. self::field( 'category', __( 'Category', 'stonewright' ), self::select( 'category', $categories, (string) ( $filters['category'] ?? '' ) ), $exact, 'md' )
			. self::field( 'outcome', __( 'Outcome', 'stonewright' ), self::select( 'outcome', $outcomes, (string) ( $filters['outcome'] ?? '' ) ), $exact, 'md' )
			. self::field( 'operation_class', __( 'Operation class', 'stonewright' ), self::input( 'operation_class', 'search', (string) ( $filters['operation_class'] ?? '' ) ), $contains, 'md' )
			. self::field( 'root_error_code', __( 'Root error code', 'stonewright' ), self::input( 'root_error_code', 'search', (string) ( $filters['root_error_code'] ?? '' ) ), $contains, 'md' )
			. self::field( 'normalized_path', __( 'Path', 'stonewright' ), self::input( 'normalized_path', 'search', (string) ( $filters['normalized_path'] ?? '' ) ), $contains, 'md' )
			. self::field( 'change_set_id', __( 'Change set ID', 'stonewright' ), self::input( 'change_set_id', 'search', (string) ( $filters['change_set_id'] ?? '' ) ), $exact, 'md' )
			. self::field( 'user', __( 'User ID', 'stonewright' ), self::input( 'user', 'number', isset( $filters['user'] ) ? (string) (int) $filters['user'] : '', [ 'min' => '0' ] ), $exact, 'sm' );

		$total_pages = (int) max( 1, (int) ceil( $total / max( 1, $per_page ) ) );
		$meta        = sprintf( /* translators: 1: current page, 2: total pages, 3: number of entries */ __( 'Page %1$d of %2$d · %3$s', 'stonewright' ), $page, $total_pages, sprintf( _n( '%d entry', '%d entries', $total, 'stonewright' ), $total ) );

		$buttons = Button::group(
			[
				Button::render( __( 'Filter', 'stonewright' ), [ 'variant' => 'primary', 'type' => 'submit', 'attrs' => [ 'data-sw-audit-filter' => true ] ] ),
				Button::render( __( 'Reset filters', 'stonewright' ), [ 'variant' => 'tertiary', 'href' => admin_url( 'admin.php?page=' . self::SLUG ) ] ),
			]
		);
		$rules   = Html::element(
			'p',
			[ 'class' => 'sw-ui-field__help sw-audit-rules' ],
			Html::text( __( 'Ability, operation class, root error code and path match part of what you type, in any case. Status, category, outcome, verification, rollback, user ID and change set ID match exactly.', 'stonewright' ) )
		);

		$body = Html::element( 'div', [ 'class' => 'sw-audit-filters__primary' ], $primary . $buttons )
			. Html::element(
				'details',
				[ 'class' => 'sw-ui-disclosure sw-audit-filters__more', 'open' => $more_open ],
				Html::element( 'summary', [], Icon::render( 'chev-r' ) . Html::text( __( 'More filters', 'stonewright' ) ) )
					. Html::element( 'div', [ 'class' => 'sw-ui-disclosure__body sw-audit-filters__grid' ], $more )
			)
			. $rules
			. Html::element( 'p', [ 'class' => 'sw-ui-toolbar__meta', 'role' => 'status' ], Html::text( $meta ) );

		return Html::element(
			'form',
			[ 'class' => 'sw-ui-toolbar sw-audit-filters', 'method' => 'get', 'action' => admin_url( 'admin.php' ), 'role' => 'search', 'aria-label' => __( 'Filter the audit log', 'stonewright' ) ],
			$hidden . $body
		);
	}

	/** One labelled control with the rule it filters by under it. */
	private static function field( string $name, string $label, string $control, string $rule, string $size ): string {
		$id = 'sw-audit-f-' . $name;

		return Html::element(
			'div',
			[ 'class' => 'sw-ui-field sw-ui-field--' . $size ],
			Html::element( 'label', [ 'class' => 'sw-ui-field__label', 'for' => $id ], Html::text( $label ) )
				. $control
				. Html::element( 'span', [ 'class' => 'sw-ui-field__help', 'id' => $id . '-rule' ], Html::text( $rule ) )
		);
	}

	/** @param array<string, string> $extra */
	private static function input( string $name, string $type, string $value, array $extra = [] ): string {
		return Html::void( 'input', array_merge( [ 'class' => 'sw-ui-input', 'type' => $type, 'id' => 'sw-audit-f-' . $name, 'name' => $name, 'value' => $value, 'aria-describedby' => 'sw-audit-f-' . $name . '-rule' ], $extra ) );
	}

	/** @param array<string, string> $options */
	private static function select( string $name, array $options, string $current ): string {
		$inner = '';
		foreach ( $options as $value => $text ) {
			$inner .= Html::element( 'option', [ 'value' => (string) $value, 'selected' => (string) $value === $current ], Html::text( $text ) );
		}

		return Html::element( 'select', [ 'class' => 'sw-ui-select', 'id' => 'sw-audit-f-' . $name, 'name' => $name, 'aria-describedby' => 'sw-audit-f-' . $name . '-rule' ], $inner );
	}

	/**
	 * The log: a table of events with one Details button each, the pagination and the drawer the buttons open.
	 *
	 * @param array<int, array<string, mixed>> $rows
	 * @param array<string, mixed>             $filters
	 * @param array<string, string>            $incident_states
	 */
	private static function log_html( array $rows, int $page, int $per_page, array $filters, int $total, array $incident_states ): string {
		if ( [] === $rows ) {
			return self::empty_html( $filters, $incident_states );
		}

		$total_pages = (int) max( 1, (int) ceil( max( 0, $total ) / max( 1, $per_page ) ) );

		$oauth_client_ids = [];
		foreach ( $rows as $row ) {
			$client_id = self::oauth_client_id_from_row( $row );
			if ( '' !== $client_id ) {
				$oauth_client_ids[] = $client_id;
			}
		}
		$oauth_client_names = ClientNames::lookup( $oauth_client_ids );
		$repair_index       = self::repair_index();

		$table_rows = [];
		$panels     = '';
		foreach ( $rows as $row ) {
			$id        = (int) $row['id'];
			$user_text = self::user_text( $row, $oauth_client_names );
			$status    = strtolower( (string) $row['result_status'] );
			[ $variant, $icon, $status_label ] = match ( $status ) {
				'ok'      => [ 'ok', 'check', __( 'OK', 'stonewright' ) ],
				'blocked' => [ 'warn', 'alert', __( 'Blocked', 'stonewright' ) ],
				'auth'    => [ 'info', 'info', __( 'Auth', 'stonewright' ) ],
				default   => [ 'danger', 'x', __( 'Error', 'stonewright' ) ],
			};
			$details_raw = (string) ( $row['redacted_details'] ?? '' );
			$details     = self::expanded_row_details( $row, $details_raw, $repair_index );
			$pairs       = self::detail_pairs( $row, $details_raw, $repair_index );
			$is_problem  = self::row_is_problem( $row );
			$ability     = (string) $row['ability_name'];
			$resource    = trim( (string) ( $row['resource_type'] ?? '' ) . ' ' . (string) ( $row['resource_ref'] ?? '' ) );

			$event_meta = '#' . $id . ( '' !== $resource ? ' · ' . $resource : '' );
			$event      = Html::element( 'code', [ 'class' => 'sw-ui-table__primary sw-audit-ability' ], Html::text( $ability ) )
				. Html::element( 'span', [ 'class' => 'sw-ui-table__meta' ], Html::text( $event_meta ) );
			if ( '' !== (string) ( $row['change_set_id'] ?? '' ) ) {
				ob_start();
				do_action( 'stonewright_audit_log_change_set_cell', $row );
				$event .= (string) ob_get_clean();
			}

			$category = (string) ( $row['category'] ?? '' );
			$outcome  = (string) ( $row['outcome'] ?? '' );
			$result   = Badge::render( $status_label, [ 'variant' => $variant, 'icon' => $icon ] );
			if ( '' !== $category || '' !== $outcome ) {
				$result .= Html::element( 'span', [ 'class' => 'sw-ui-table__meta' ], Html::text( ucfirst( strtolower( trim( $category . ', ' . $outcome, ', ' ) ) ) ) );
			}
			$verify = (string) ( $row['verification_status'] ?? '' );
			if ( '' !== $verify ) {
				$result .= Html::element( 'span', [ 'class' => 'sw-ui-table__meta' ], Html::text( sprintf( /* translators: %s: verification state */ __( 'Verification: %s', 'stonewright' ), $verify ) ) );
			}
			$root_error = $is_problem ? (string) ( $row['root_error_code'] ?? $row['error_code'] ?? '' ) : '';
			if ( '' !== $root_error ) {
				$result .= Html::element( 'code', [ 'class' => 'sw-ui-table__meta sw-audit-cause' ], Html::text( $root_error ) );
			}

			$table_rows[] = [
				'event'   => [ 'html' => $event ],
				'result'  => [ 'html' => Html::element( 'div', [ 'class' => 'sw-audit-cell' ], $result ) ],
				'user'    => $user_text,
				'when'    => [ 'html' => self::time_html( (string) $row['created_at'] ) ],
				'actions' => [
					'html' => Button::render(
						__( 'Details', 'stonewright' ),
						[
							'size'    => 'sm',
							'context' => sprintf( /* translators: 1: event number, 2: ability name */ __( 'of event %1$d, %2$s', 'stonewright' ), $id, $ability ),
							'attrs'   => [
								'hidden'                  => true,
								'data-sw-audit-open'      => 'sw-audit-row-' . $id,
								'data-sw-audit-title'     => sprintf( /* translators: %d: event number */ __( 'Event %d', 'stonewright' ), $id ),
								'data-sw-ui-dialog-open'  => '#sw-audit-drawer',
								'aria-haspopup'           => 'dialog',
								'aria-controls'           => 'sw-audit-drawer',
							],
						]
					),
				],
			];
			$panels .= self::row_panel_html( $row, $user_text, $status_label, $variant, $icon, $pairs, $details, $incident_states, $is_problem );
		}

		$table = Table::render(
			[
				[ 'key' => 'event', 'label' => __( 'Event', 'stonewright' ), 'primary' => true ],
				[ 'key' => 'result', 'label' => __( 'Result', 'stonewright' ) ],
				[ 'key' => 'user', 'label' => __( 'User', 'stonewright' ), 'secondary' => true ],
				[ 'key' => 'when', 'label' => __( 'When', 'stonewright' ) ],
				[ 'key' => 'actions', 'label' => __( 'Details', 'stonewright' ), 'actions' => true ],
			],
			$table_rows,
			[ 'caption' => __( 'Audit log entries', 'stonewright' ), 'class' => 'sw-audit-table' ]
		);

		return Html::element( 'div', [ 'class' => 'sw-audit-log' ], $table . self::pagination_html( $filters, $page, $total_pages ) )
			. self::drawer_html( $panels );
	}

	/**
	 * The user of a row as text: a WordPress login, an OAuth client by name, or the reason there is none.
	 *
	 * @param array<string, mixed>  $row
	 * @param array<string, string> $oauth_client_names
	 */
	private static function user_text( array $row, array $oauth_client_names ): string {
		$user      = get_user_by( 'id', (int) $row['user_id'] );
		$client_id = self::oauth_client_id_from_row( $row );
		if ( $user ) {
			return (string) $user->user_login;
		}
		if ( '' !== $client_id && isset( $oauth_client_names[ $client_id ] ) ) {
			return sprintf( /* translators: %s: OAuth client name */ __( 'OAuth: %s', 'stonewright' ), $oauth_client_names[ $client_id ] );
		}
		if ( '' !== $client_id ) {
			return __( 'OAuth client', 'stonewright' );
		}
		if ( (int) ( $row['user_id'] ?? 0 ) > 0 ) {
			return __( 'Deleted user', 'stonewright' );
		}

		return __( 'System', 'stonewright' );
	}

	/**
	 * Why the log shows nothing: it is empty, the filters match nothing, or the pattern's rows were pruned.
	 *
	 * @param array<string, mixed>  $filters
	 * @param array<string, string> $incident_states
	 */
	private static function empty_html( array $filters, array $incident_states ): string {
		if ( self::is_pruned_pattern_view( $filters ) ) {
			return EmptyState::render(
				__( 'Events for this pattern were pruned by retention — the pattern summary above is the surviving record', 'stonewright' ),
				__( 'Export the pattern summary if you still need it, then reset filters to return to the live log.', 'stonewright' ),
				[ 'variant' => 'no-results', 'actions_html' => Button::render( __( 'Reset filters', 'stonewright' ), [ 'size' => 'sm', 'href' => admin_url( 'admin.php?page=' . self::SLUG ) ] ) ]
			);
		}
		if ( [] === $filters && [] === $incident_states ) {
			return EmptyState::render(
				__( 'No audit entries have been recorded.', 'stonewright' ),
				__( 'Run a Stonewright mutation or wait for an agent session — new events appear here.', 'stonewright' ),
				[ 'variant' => 'first-run' ]
			);
		}
		$text = __( 'Reset filters or wait for a matching event. Lifecycle incidents stay until they are repaired.', 'stonewright' );
		if ( [] !== $incident_states ) {
			$text .= ' ' . __( 'Lifecycle incidents still exist; changing an audit filter does not remove or resolve them.', 'stonewright' );
		}

		return EmptyState::render(
			__( 'No audit entries match this view and filter set.', 'stonewright' ),
			$text,
			[ 'variant' => 'no-results', 'actions_html' => Button::render( __( 'Reset filters', 'stonewright' ), [ 'size' => 'sm', 'href' => admin_url( 'admin.php?page=' . self::SLUG ) ] ) ]
		);
	}

	/** @param array<string, mixed> $filters */
	private static function pagination_html( array $filters, int $page, int $total_pages ): string {
		if ( $total_pages < 2 ) {
			return '';
		}
		$query   = array_merge( [ 'page' => self::SLUG ], $filters );
		$buttons = [];
		if ( $page > 1 ) {
			$buttons[] = Button::render( __( 'Newer entries', 'stonewright' ), [ 'size' => 'sm', 'href' => add_query_arg( array_merge( $query, [ 'paged' => $page - 1 ] ), admin_url( 'admin.php' ) ) ] );
		}
		if ( $page < $total_pages ) {
			$buttons[] = Button::render( __( 'Older entries', 'stonewright' ), [ 'size' => 'sm', 'href' => add_query_arg( array_merge( $query, [ 'paged' => $page + 1 ] ), admin_url( 'admin.php' ) ) ] );
		}

		return Html::element( 'nav', [ 'class' => 'sw-audit-pages', 'aria-label' => __( 'Audit log pages', 'stonewright' ) ], Button::group( $buttons ) );
	}

	/** One row's facts and redacted payload, shown in the drawer when its Details button is used. */
	private static function row_panel_html( array $row, string $user_text, string $status_label, string $variant, string $icon, array $pairs, string $details, array $incident_states, bool $is_problem ): string {
		$id    = (int) $row['id'];
		$items = [];
		foreach ( $pairs as $pair ) {
			if ( in_array( $pair['label'], [ __( 'Mode', 'stonewright' ), __( 'Target', 'stonewright' ) ], true ) ) {
				continue;
			}
			$items[] = [ 'label' => $pair['label'], 'value' => $pair['value'] ];
		}
		$items[] = [ 'label' => __( 'Event', 'stonewright' ), 'value' => '#' . $id ];
		$items[] = [ 'label' => __( 'Ability or route', 'stonewright' ), 'value_html' => Html::element( 'code', [], Html::text( (string) $row['ability_name'] ) ) ];
		$items[] = [ 'label' => __( 'Time', 'stonewright' ), 'value_html' => self::time_html( (string) $row['created_at'] ) . ' ' . Html::element( 'span', [ 'class' => 'sw-ui-field__help' ], Html::text( sprintf( /* translators: %s: UTC time */ __( '%s UTC', 'stonewright' ), (string) $row['created_at'] ) ) ) ];
		$items[] = [ 'label' => __( 'User', 'stonewright' ), 'value' => $user_text ];
		$items[] = [ 'label' => __( 'Status', 'stonewright' ), 'value_html' => Badge::render( $status_label, [ 'variant' => $variant, 'icon' => $icon ] ) ];
		$plain   = [
			__( 'Category', 'stonewright' )     => ucfirst( strtolower( (string) ( $row['category'] ?? '' ) ) ),
			__( 'Outcome', 'stonewright' )      => ucfirst( strtolower( (string) ( $row['outcome'] ?? '' ) ) ),
			__( 'Resource', 'stonewright' )     => trim( (string) ( $row['resource_type'] ?? '' ) . ' ' . (string) ( $row['resource_ref'] ?? '' ) ),
			__( 'Verification', 'stonewright' ) => (string) ( $row['verification_status'] ?? '' ),
			__( 'Execution', 'stonewright' )    => (string) ( $row['execution_status'] ?? '' ),
			__( 'Mode', 'stonewright' )         => (string) ( $row['mode'] ?? '' ),
			__( 'Path', 'stonewright' )         => (string) ( $row['normalized_path'] ?? '' ),
		];
		$rollback = (string) ( $row['rollback_status'] ?? '' );
		if ( 'not_needed' !== $rollback ) {
			$plain[ __( 'Rollback', 'stonewright' ) ] = $rollback;
		}
		$retry_after = max( 0, (int) ( $row['retry_after_seconds'] ?? 0 ) );
		if ( $retry_after > 0 ) {
			$plain[ __( 'Retry after', 'stonewright' ) ] = sprintf( /* translators: %d: seconds */ _n( '%d second', '%d seconds', $retry_after, 'stonewright' ), $retry_after );
		}
		foreach ( $plain as $label => $value ) {
			if ( '' !== $value ) {
				$items[] = [ 'label' => $label, 'value' => $value ];
			}
		}
		$incident_id = $is_problem ? strtolower( (string) ( $row['incident_id'] ?? '' ) ) : '';
		if ( 1 === preg_match( '/^[a-f0-9]{64}$/', $incident_id ) ) {
			$state        = isset( $incident_states[ $incident_id ] ) ? $incident_states[ $incident_id ] : __( 'recorded', 'stonewright' );
			$incident_url = add_query_arg( [ 'page' => MemoryInstructionsPage::SLUG, 'type' => 'incidents', 'incident_id' => $incident_id ], admin_url( 'admin.php' ) ) . '#stonewright-incident-' . $incident_id;
			$items[]      = [
				'label'      => __( 'Incident', 'stonewright' ),
				'value_html' => Html::element( 'a', [ 'class' => 'sw-ui-link', 'href' => $incident_url ], Html::element( 'code', [], Html::text( substr( $incident_id, 0, 12 ) . '…' ) ) . ' ' . Html::text( $state ) ),
			];
		}

		$payload = '';
		if ( '' !== $details ) {
			$payload_id = 'sw-audit-payload-' . $id;
			$payload    = Html::element(
				'div',
				[ 'class' => 'sw-ui-code' ],
				Html::element(
					'div',
					[ 'class' => 'sw-ui-code__head' ],
					Html::element( 'span', [], Html::text( __( 'Redacted details', 'stonewright' ) ) )
						. Button::render(
							__( 'Copy', 'stonewright' ),
							[
								'size'    => 'xs',
								'context' => sprintf( /* translators: %d: event number */ __( 'redacted details of event %d', 'stonewright' ), $id ),
								'attrs'   => [
									'data-sw-ui-copy'              => '#' . $payload_id,
									'data-sw-ui-copy-status'       => '#' . $payload_id . '-status',
									'data-sw-ui-copied-label'      => __( 'Copied', 'stonewright' ),
									'data-sw-ui-copy-failed-label' => __( 'Press Ctrl+C', 'stonewright' ),
								],
							]
						)
				)
				. Html::element( 'pre', [ 'class' => 'sw-ui-code__body', 'id' => $payload_id, 'tabindex' => '0', 'aria-label' => sprintf( /* translators: %d: event number */ __( 'Redacted details of event %d', 'stonewright' ), $id ) ], Html::element( 'code', [], Html::text( $details ) ) )
			)
			. Html::element( 'span', [ 'class' => 'sw-ui-visually-hidden', 'role' => 'status', 'id' => $payload_id . '-status' ], '' );
		}

		return Html::element( 'section', [ 'id' => 'sw-audit-row-' . $id, 'class' => 'sw-audit-panel', 'data-sw-audit-panel' => true, 'hidden' => true ], KvList::render( $items, [ 'label' => sprintf( /* translators: %d: event number */ __( 'Facts of event %d', 'stonewright' ), $id ) ] ) . $payload );
	}

	/** The one drawer every Details button opens: the layer's drawer, holding one hidden panel per row. */
	private static function drawer_html( string $panels ): string {
		return Html::element(
			'dialog',
			[ 'id' => 'sw-audit-drawer', 'class' => 'sw-ui-dialog sw-ui-drawer', 'aria-labelledby' => 'sw-audit-drawer-title', 'data-sw-ui-light-dismiss' => true, 'data-sw-audit-drawer' => true ],
			Html::element(
				'div',
				[ 'class' => 'sw-ui-dialog__header sw-ui-dialog__header--bar' ],
				Html::element( 'h2', [ 'class' => 'sw-ui-dialog__title', 'id' => 'sw-audit-drawer-title' ], Html::text( __( 'Event details', 'stonewright' ) ) )
				. Button::render( __( 'Close', 'stonewright' ), [ 'size' => 'sm', 'icon' => 'x', 'icon_only' => true, 'context' => __( 'event details', 'stonewright' ), 'attrs' => [ 'data-sw-ui-dialog-close' => true, 'autofocus' => true ] ] )
			)
			. Html::element( 'div', [ 'class' => 'sw-ui-dialog__body' ], $panels )
		);
	}

	/** The typed confirmation for deleting everything. It says what goes: events, incidents and pattern summaries. */
	private static function purge_dialog_html( int $all_count, int $incident_total ): string {
		$text   = sprintf(
			/* translators: 1: number of audit events, 2: number of incidents */
			__( 'This permanently deletes %1$s, %2$s and all pattern summaries. One audit_log_purged receipt remains. Type DELETE to confirm.', 'stonewright' ),
			sprintf( _n( '%d audit event', '%d audit events', $all_count, 'stonewright' ), $all_count ),
			sprintf( _n( '%d incident', '%d incidents', $incident_total, 'stonewright' ), $incident_total )
		);
		$field  = Html::element(
			'div',
			[ 'class' => 'sw-ui-field' ],
			Html::element( 'label', [ 'class' => 'sw-ui-field__label', 'for' => 'sw-audit-purge-input' ], Html::text( __( 'Type DELETE', 'stonewright' ) ) )
				. Html::void( 'input', [ 'class' => 'sw-ui-input', 'type' => 'text', 'id' => 'sw-audit-purge-input', 'name' => 'confirm_phrase', 'value' => '', 'autocomplete' => 'off', 'data-sw-ui-confirm-phrase' => 'DELETE' ] )
		);
		$form   = Html::element(
			'form',
			[ 'method' => 'post', 'action' => admin_url( 'admin-post.php' ) ],
			Html::element( 'div', [ 'class' => 'sw-ui-dialog__header' ], Html::element( 'h2', [ 'class' => 'sw-ui-dialog__title', 'id' => 'sw-audit-purge-title' ], Html::text( __( 'Delete all logs?', 'stonewright' ) ) ) )
				. Html::element(
					'div',
					[ 'class' => 'sw-ui-dialog__body' ],
					Html::element( 'p', [], Html::text( $text ) ) . self::hidden( 'action', 'stonewright_audit_purge' ) . self::hidden( '_stonewright_nonce', wp_create_nonce( 'stonewright_audit_purge' ) ) . $field
				)
				. Html::element(
					'div',
					[ 'class' => 'sw-ui-dialog__footer' ],
					Button::render( __( 'Cancel', 'stonewright' ), [ 'attrs' => [ 'data-sw-ui-dialog-close' => true, 'autofocus' => true ] ] )
					. Button::render( __( 'Delete all logs', 'stonewright' ), [ 'type' => 'submit', 'variant' => 'danger-solid', 'disabled' => true, 'attrs' => [ 'data-sw-ui-confirm-submit' => true ] ] )
				)
		);

		return Html::element( 'dialog', [ 'id' => 'sw-audit-purge-dialog', 'class' => 'sw-ui-dialog', 'aria-labelledby' => 'sw-audit-purge-title' ], $form );
	}

	/**
	 * A UTC time as a <time> element: the site's time on screen, the UTC time in the title.
	 */
	private static function time_html( string $utc ): string {
		$utc = trim( $utc );
		if ( '' === $utc ) {
			return Html::text( '—' );
		}
		$stamp = strtotime( 1 === preg_match( '/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', $utc ) ? $utc . ' UTC' : $utc );
		if ( false === $stamp ) {
			return Html::text( $utc );
		}

		return Html::element(
			'time',
			[ 'datetime' => gmdate( 'Y-m-d\TH:i:s\Z', $stamp ), 'title' => gmdate( 'Y-m-d H:i:s', $stamp ) . ' UTC' ],
			Html::text( (string) wp_date( 'j M Y, H:i', $stamp ) )
		);
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
		foreach ( [ 'backend', 'verification_status', 'rollback_status', 'severity', 'event_type', 'error_code' ] as $key ) {
			if ( ! empty( $source[ $key ] ) ) {
				$filters[ $key ] = sanitize_key( wp_unslash( (string) $source[ $key ] ) );
			}
		}
		// Text filters match part of the stored value, so dots and other punctuation stay.
		foreach ( [ 'operation_class', 'root_error_code' ] as $key ) {
			if ( ! empty( $source[ $key ] ) ) {
				$filters[ $key ] = mb_substr( sanitize_text_field( wp_unslash( (string) $source[ $key ] ) ), 0, 190 );
			}
		}
		if ( ! empty( $source['signature'] ) ) {
			$signature = strtolower( sanitize_text_field( wp_unslash( (string) $source['signature'] ) ) );
			if ( 1 === preg_match( '/^[a-f0-9]{64}$/', $signature ) ) {
				$filters['signature'] = $signature;
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

	/**
	 * True when this view is a recurring-pattern occurrence list whose audit rows
	 * are gone (retention) while the pattern summary is still on the page.
	 *
	 * @param array<string, mixed> $filters
	 */
	private static function is_pruned_pattern_view( array $filters ): bool {
		$code = isset( $filters['error_code'] ) ? sanitize_key( (string) $filters['error_code'] ) : '';
		$sig  = isset( $filters['signature'] ) ? strtolower( sanitize_text_field( (string) $filters['signature'] ) ) : '';
		if ( '' === $code && 1 !== preg_match( '/^[a-f0-9]{64}$/', $sig ) ) {
			return false;
		}
		foreach ( ErrorPatterns::recurring( 10 ) as $pattern ) {
			$pattern_sig  = (string) ( $pattern['signature'] ?? '' );
			$pattern_code = (string) ( $pattern['error_code'] ?? '' );
			if ( 64 === strlen( $sig ) && 64 === strlen( $pattern_sig ) && hash_equals( $pattern_sig, $sig ) ) {
				return true;
			}
			if ( '' !== $code && $pattern_code === $code ) {
				return true;
			}
		}
		return false;
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
	 * Expand an audit row into the operator-facing details payload.
	 *
	 * Error rows always include error_code, error_message, target, mode, and
	 * remediation when those values can be derived — never verification_status alone.
	 *
	 * @param array<string, mixed>  $row
	 * @param array<string, string> $repair_index
	 */
	private static function expanded_row_details( array $row, string $details_raw, array $repair_index = [] ): string {
		$decoded = json_decode( $details_raw, true );
		$details = is_array( $decoded ) ? AuditLog::redact_sensitive( $decoded ) : [];
		$status  = strtolower( (string) ( $row['result_status'] ?? '' ) );
		if ( in_array( $status, [ 'error', 'blocked', 'auth' ], true ) ) {
			$error_code    = self::first_detail_text( [ $details['error_code'] ?? null, $row['error_code'] ?? null, $row['root_error_code'] ?? null ], 190 );
			$cause_code    = self::first_detail_text( [ $details['root_error_code'] ?? null, $row['root_error_code'] ?? null, $error_code ], 190 );
			$error_message = self::first_detail_text( [ $details['error_message'] ?? null ], 500 );
			$target        = self::first_detail_text( [ $details['target'] ?? null, $details['target_id'] ?? null, $row['resource_ref'] ?? null ], 64 );
			$mode          = self::first_detail_text( [ $row['mode'] ?? null, $details['mode'] ?? null ], 32 );
			$hint_code     = self::first_detail_text( [ $details['remediation_code'] ?? null, $row['remediation_code'] ?? null, $cause_code, $error_code ], 190 );
			$ability       = (string) ( $row['ability_name'] ?? '' );
			$remediation   = '';
			if ( '' !== $hint_code ) {
				$hint    = RemediationHints::for_code( $cause_code, $ability, $error_code );
				$generic = RemediationHints::for_code( '', '' );
				if ( $hint !== $generic ) {
					$remediation = $hint;
				}
			}
			$pattern_key = strtolower( $ability . '|' . $error_code );
			$repair      = $remediation;
			if ( isset( $repair_index[ $pattern_key ] ) && '' !== $repair_index[ $pattern_key ] ) {
				$repair = $repair_index[ $pattern_key ];
			}
			$expanded = [];
			if ( '' !== $error_code ) {
				$expanded['error_code'] = $error_code;
			}
			if ( '' !== $error_message ) {
				$expanded['error_message'] = $error_message;
			}
			if ( '' !== $target ) {
				$expanded['target'] = $target;
			}
			if ( '' !== $mode ) {
				$expanded['mode'] = $mode;
			}
			if ( '' !== $remediation ) {
				$expanded['remediation'] = $remediation;
			}
			if ( '' !== $repair ) {
				$expanded['repair'] = $repair;
			}
			foreach ( $details as $key => $value ) {
				if ( isset( $expanded[ $key ] ) || in_array( (string) $key, [ 'target_id', 'remediation_code' ], true ) ) {
					continue;
				}
				$expanded[ $key ] = $value;
			}
			$details = $expanded;
		}
		if ( [] === $details ) {
			return self::pretty_redacted_details( (string) ( $row['sanitized_args'] ?? '' ) );
		}
		$pretty = wp_json_encode( $details, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES );
		if ( ! is_string( $pretty ) || SensitiveContent::contains( $pretty ) ) {
			return '{"redaction_error":"sensitive_content_blocked"}';
		}
		return $pretty;
	}

	/**
	 * @param array<string, mixed>  $row
	 * @param array<string, string> $repair_index
	 * @return list<array{label:string,value:string}>
	 */
	private static function detail_pairs( array $row, string $details_raw, array $repair_index ): array {
		$decoded = json_decode( $details_raw, true );
		$details = is_array( $decoded ) ? AuditLog::redact_sensitive( $decoded ) : [];
		if ( ! self::row_is_problem( $row ) ) {
			// A successful row has no cause and nothing to repair.
			$details = array_diff_key( $details, array_flip( [ 'error_code', 'root_error_code', 'error_message', 'remediation_code' ] ) );
			$row     = array_diff_key( $row, array_flip( [ 'error_code', 'root_error_code', 'remediation_code' ] ) );
		}
		$code    = self::first_detail_text( [ $details['error_code'] ?? null, $row['error_code'] ?? null, $row['root_error_code'] ?? null ], 190 );
		$cause   = self::first_detail_text( [ $details['root_error_code'] ?? null, $row['root_error_code'] ?? null, $code ], 190 );
		$message = self::first_detail_text( [ $details['error_message'] ?? null ], 500 );
		$mode    = self::first_detail_text( [ $row['mode'] ?? null, $details['mode'] ?? null ], 32 );
		$target  = self::first_detail_text( [ $details['target'] ?? null, $details['target_id'] ?? null, $row['resource_ref'] ?? null ], 64 );
		$ability = (string) ( $row['ability_name'] ?? '' );
		$repair  = '';
		$hint    = self::first_detail_text( [ $details['remediation_code'] ?? null, $row['remediation_code'] ?? null, $cause, $code ], 190 );
		if ( '' !== $hint ) {
			$text    = RemediationHints::for_code( $cause, $ability, $code );
			$generic = RemediationHints::for_code( '', '' );
			if ( $text !== $generic ) {
				$repair = $text;
			}
		}
		$pattern_key = strtolower( $ability . '|' . $code );
		if ( isset( $repair_index[ $pattern_key ] ) && '' !== $repair_index[ $pattern_key ] ) {
			$repair = $repair_index[ $pattern_key ];
		}
		$pairs = [];
		if ( '' !== $code ) {
			$pairs[] = [ 'label' => __( 'Code', 'stonewright' ), 'value' => $code ];
		}
		if ( '' !== $message ) {
			$pairs[] = [ 'label' => __( 'Message', 'stonewright' ), 'value' => $message ];
		}
		if ( '' !== $mode ) {
			$pairs[] = [ 'label' => __( 'Mode', 'stonewright' ), 'value' => $mode ];
		}
		if ( '' !== $target ) {
			$pairs[] = [ 'label' => __( 'Target', 'stonewright' ), 'value' => $target ];
		}
		if ( '' !== $repair ) {
			$pairs[] = [ 'label' => __( 'Repair', 'stonewright' ), 'value' => $repair ];
		}
		return $pairs;
	}

	/**
	 * Whether the row records a failure, refusal, or block. Successful rows,
	 * including rows stored before successes dropped their codes and incident
	 * ids, show no error cause, no repair hint, and no incident link.
	 *
	 * @param array<string, mixed> $row
	 */
	private static function row_is_problem( array $row ): bool {
		$outcome = strtoupper( (string) ( $row['outcome'] ?? '' ) );
		if ( '' !== $outcome ) {
			return AuditEvent::OUTCOME_SUCCESS !== $outcome;
		}
		return in_array( strtolower( (string) ( $row['result_status'] ?? '' ) ), [ 'error', 'blocked', 'auth' ], true );
	}

	/** @return array<string, string> */
	private static function repair_index(): array {
		$index = [];
		foreach ( ErrorPatterns::recurring( 50 ) as $pattern ) {
			$ability = (string) ( $pattern['ability'] ?? '' );
			$code    = (string) ( $pattern['error_code'] ?? '' );
			$repair  = (string) ( $pattern['repair'] ?? '' );
			if ( '' === $ability || '' === $code || '' === $repair ) {
				continue;
			}
			$index[ strtolower( $ability . '|' . $code ) ] = $repair;
		}
		return $index;
	}

	/**
	 * @param list<mixed> $candidates
	 */
	private static function first_detail_text( array $candidates, int $max ): string {
		foreach ( $candidates as $value ) {
			if ( is_scalar( $value ) && '' !== trim( (string) $value ) ) {
				return mb_substr( sanitize_text_field( (string) $value ), 0, $max );
			}
		}
		return '';
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
				'repair_of'           => self::safe_export_text( $row['repair_of'] ?? '', 96 ),
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
			'repair_of'           => '',
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
