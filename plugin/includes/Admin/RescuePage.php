<?php
/**
 * Stonewright > Rescue admin page.
 * SPDX-License-Identifier: GPL-2.0-or-later
 *
 * @package Stonewright
 */

declare( strict_types=1 );

namespace Stonewright\WpMcp\Admin;

use Stonewright\WpMcp\Core\RescueInstaller;
use Stonewright\WpMcp\Security\ChangeJournal;
use Stonewright\WpMcp\Security\ChangeJournalFile;
use Stonewright\WpMcp\Security\ChangeLedger;
use Stonewright\WpMcp\Security\ConfirmationToken;
use Stonewright\WpMcp\Security\Permissions;
use Stonewright\WpMcp\Security\ProbeToken;
use Stonewright\WpMcp\Security\RescueRollback;
use Stonewright\WpMcp\Security\RollbackRecipes;

/**
 * Lists the changes that need attention and rolls one back without an agent.
 *
 * The page needs nothing but WordPress and Stonewright, so it keeps working in safe mode.
 * Every action needs manage_options and a nonce. In production-safe mode a rollback also needs
 * a confirmation token, which the page issues for exactly one change set and one action.
 *
 * Markup is server-rendered and every action is a plain form post. The script only adds the
 * confirmation dialog, the copy buttons and a busy state. Its stylesheet comes from the page
 * style map in AdminBootstrap.
 */
final class RescuePage {

	public const SLUG       = 'stonewright-rescue';
	public const CAPABILITY = 'manage_options';

	private const TOKEN_TTL = 600;

	/** The words typed in the confirmation dialog in production-safe mode. */
	private const PHRASE = 'ROLL BACK';

	/** The class that issues safe-mode links. It is optional, so it is named rather than imported. */
	private const KEYS_CLASS = '\Stonewright\WpMcp\Security\RescueKeys';

	/** @var callable(int):?string|false|null null uses RescueKeys when it exists; false means no safe mode. */
	private static $safe_mode_resolver = null;

	public static function register(): void {
		add_action( 'init', [ self::class, 'add_to_menu_registry' ] );
		add_action( 'admin_menu', [ self::class, 'add_submenu' ] );
		add_action( 'admin_post_stonewright_rescue_rollback', [ self::class, 'handle_rollback' ] );
		add_action( 'admin_post_stonewright_rescue_recheck', [ self::class, 'handle_recheck' ] );
		add_action( 'admin_post_stonewright_rescue_safe_mode', [ self::class, 'handle_safe_mode' ] );
		add_action( 'admin_enqueue_scripts', [ self::class, 'enqueue' ] );
	}

	public static function add_submenu(): void {
		add_submenu_page(
			ConfigurationPage::SLUG,
			__( 'Rescue', 'stonewright' ),
			__( 'Rescue', 'stonewright' ),
			self::CAPABILITY,
			self::SLUG,
			[ self::class, 'render' ]
		);
	}

	/** The tab of the Activity hub. It is registered on `init`, where labels can be translated; render() makes sure it exists too. */
	public static function add_to_menu_registry(): void {
		MenuRegistry::add(
			self::SLUG,
			__( 'Rescue', 'stonewright' ),
			'activity',
			[
				'order'       => 30,
				'beta'        => true,
				'lede'        => self::lede(),
				'count'       => static fn (): int => count( ChangeJournal::open_incidents() ) + count( ChangeJournal::unconfirmed() ),
				'count_label' => __( 'needing attention', 'stonewright' ),
			]
		);
	}

	/**
	 * Load the page script. The stylesheet is enqueued by AdminBootstrap's page style map.
	 */
	public static function enqueue( string $hook_suffix = '' ): void {
		$page = isset( $_GET['page'] ) ? sanitize_key( (string) wp_unslash( (string) $_GET['page'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( self::SLUG !== $page && ! str_contains( $hook_suffix, self::SLUG ) ) {
			return;
		}
		$version = defined( 'STONEWRIGHT_VERSION' ) ? (string) constant( 'STONEWRIGHT_VERSION' ) : '0.1.0';
		$base    = defined( 'STONEWRIGHT_URL' ) ? (string) constant( 'STONEWRIGHT_URL' ) : '';
		wp_enqueue_script( 'stonewright-admin-rescue', $base . 'assets/admin/pages/rescue.js', [ 'stonewright-admin' ], $version, true );
	}

	/**
	 * Replace how the safe-mode link is made. For tests.
	 *
	 * @param callable(int):?string|false|null $resolver A resolver, false for "no safe mode", null for the default.
	 */
	public static function set_safe_mode_resolver( callable|false|null $resolver ): void {
		self::$safe_mode_resolver = $resolver;
	}

	// -----------------------------------------------------------------------
	// Rendering.
	// -----------------------------------------------------------------------

	public static function render(): void {
		if ( ! current_user_can( self::CAPABILITY ) ) {
			wp_die( esc_html__( 'You do not have permission to view Stonewright Rescue.', 'stonewright' ) );
		}
		self::add_to_menu_registry();
		// Bring in anything a fatal recorded while the database was unavailable.
		ChangeJournal::sync_from_file();

		$open        = ChangeJournal::open_incidents();
		$unconfirmed = ChangeJournal::unconfirmed();
		$attention   = array_merge( $open, $unconfirmed );
		$listed      = array_column( $attention, 'id' );
		$recent      = array_values(
			array_filter(
				ChangeJournal::recent( ChangeJournalFile::MAX_ENTRIES ),
				static fn ( array $entry ): bool => ! in_array( $entry['id'], $listed, true )
			)
		);
		// The health probe loads this page to see that wp-admin renders. Nobody reads that response,
		// so it gets no confirmation tokens.
		$guarded     = Permissions::is_production_safe() && ! ProbeToken::is_probe_request();

		ob_start();
		self::render_changes_link();
		self::render_safe_mode_action();
		AdminShell::open(
			self::SLUG,
			[
				'title'   => __( 'Rescue', 'stonewright' ),
				'lede'    => self::lede(),
				'actions' => (string) ob_get_clean(),
			]
		);
		echo '<div class="sw-rescue-page stonewright-rescue-page" data-sw-rescue data-sw-rescue-probe="ok">';
		self::render_result();
		if ( 'unavailable' === ChangeJournal::storage_status()['mirror'] ) {
			echo '<div class="sw-rescue-notice sw-rescue-notice--warn" role="status"><p>' . esc_html__( 'The journal file could not be written, so a fatal cannot be matched to a change while the database is down. Rollbacks from this page still work. Check that the uploads folder is writable.', 'stonewright' ) . '</p></div>';
		}
		self::render_callouts( $guarded );
		self::render_stats( count( $open ), count( $unconfirmed ), RescueInstaller::summary() );
		self::render_attention( $attention, $guarded, count( $unconfirmed ) );
		self::render_recent( $recent, $guarded );
		echo '<p class="screen-reader-text" role="status" aria-live="polite" data-sw-rescue-live></p>';
		echo '</div>';
		AdminShell::close();
	}

	private static function lede(): string {
		return __( 'Roll back a change that stopped the site from loading, or check that it loads again.', 'stonewright' );
	}

	/** History and diffs of every change live on the Changes page; Rescue stays the page for incidents. */
	private static function render_changes_link(): void {
		echo '<a class="sw-btn sw-btn--secondary" href="' . esc_url( ChangesPage::url() ) . '">' . esc_html__( 'View changes', 'stonewright' ) . '</a>';
	}

	/**
	 * A link to the diff of a change when the change ledger has a row for it. The ledger is read only through its
	 * public API, and a ledger that cannot be read leaves the link out: Rescue must keep working without it.
	 *
	 * @param array<string, mixed> $entry
	 */
	private static function render_diff_link( array $entry ): void {
		$id = (string) $entry['id'];
		try {
			$known = null !== ChangeLedger::get( $id );
		} catch ( \Throwable $failure ) {
			unset( $failure );
			$known = false;
		}
		if ( ! $known ) {
			return;
		}
		/* translators: %s: short change set id */
		$name = sprintf( __( 'View diff of change set %s', 'stonewright' ), self::short_id( $id ) );
		echo '<a class="sw-btn sw-btn--secondary sw-btn--sm" href="' . esc_url( ChangesPage::diff_url( $id ) ) . '" aria-label="' . esc_attr( $name ) . '">' . esc_html__( 'View diff', 'stonewright' ) . '</a>';
	}
	private static function render_safe_mode_action(): void {
		if ( ! self::safe_mode_available() ) {
			return;
		}
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" class="sw-rescue-header__action">';
		self::hidden( 'action', 'stonewright_rescue_safe_mode' );
		self::nonce_input( 'stonewright_rescue_safe_mode' );
		echo '<button type="submit" class="sw-btn sw-btn--secondary">' . esc_html__( 'Open in safe mode', 'stonewright' ) . '</button>';
		echo '<p class="description">' . esc_html__( 'Loads WordPress with only Stonewright and a default theme. The link works once.', 'stonewright' ) . '</p>';
		echo '</form>';
	}

	/**
	 * What the last action did, with the id of its audit receipt.
	 */
	private static function render_result(): void {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- Read-only outcome code after a redirect.
		$code     = isset( $_GET['rescue'] ) ? sanitize_key( (string) wp_unslash( (string) $_GET['rescue'] ) ) : '';
		$incident = isset( $_GET['incident'] ) ? self::clean_id( (string) wp_unslash( (string) $_GET['incident'] ) ) : '';
		// phpcs:enable
		$messages = self::outcome_messages();
		if ( '' === $code || ! isset( $messages[ $code ] ) ) {
			return;
		}
		[ $kind, $text, $receipt ] = $messages[ $code ];
		echo '<div class="sw-rescue-notice sw-rescue-notice--' . esc_attr( $kind ) . '" role="' . ( 'danger' === $kind ? 'alert' : 'status' ) . '" data-sw-rescue-result>';
		echo '<p>' . esc_html( $text ) . '</p>';
		if ( $receipt && '' !== $incident ) {
			$audit = add_query_arg(
				[
					'page'          => 'stonewright-audit-log',
					'ability'       => 'stonewright/rescue-rollback',
					'change_set_id' => $incident,
				],
				admin_url( 'admin.php' )
			);
			echo '<p>' . esc_html__( 'Receipt', 'stonewright' ) . ' <code class="sw-rescue-id">' . esc_html( $incident ) . '</code> ';
			self::copy_button( $incident, __( 'Copy receipt id', 'stonewright' ) );
			echo ' <a href="' . esc_url( $audit ) . '">' . esc_html__( 'View in Audit log', 'stonewright' ) . '</a></p>';
		}
		echo '</div>';
	}

	private static function render_callouts( bool $guarded ): void {
		echo '<div class="sw-rescue-callout sw-rescue-callout--warn" role="note">';
		echo '<p><strong>' . esc_html__( 'What Rescue does.', 'stonewright' ) . '</strong> ' . esc_html__( 'After each risky change Stonewright makes, it checks that the site still loads and, when it does not, restores the changed item to the state recorded just before.', 'stonewright' ) . '</p>';
		echo '<p><strong>' . esc_html__( 'What it never does.', 'stonewright' ) . '</strong> ' . esc_html__( 'It never touches anything Stonewright did not change, and it cannot repair WordPress core, wp-config.php or a database that is down.', 'stonewright' ) . '</p>';
		echo '</div>';
		if ( $guarded ) {
			echo '<div class="sw-rescue-callout sw-rescue-callout--info" role="note"><p>' . esc_html__( 'Production-safe mode is on. A rollback or a re-check needs a one-time confirmation token. This page issues one for that change set only, and it expires after ten minutes. Typing ROLL BACK in the dialog confirms a rollback.', 'stonewright' ) . '</p></div>';
		}
	}

	/**
	 * @param array{state:string,safe_mode:bool} $helper
	 */
	private static function render_stats( int $open, int $unverified, array $helper ): void {
		$last = self::last_rollback();
		echo '<dl class="sw-rescue-stats" data-sw-rescue-stats>';
		self::stat( __( 'Open incidents', 'stonewright' ), esc_html( (string) $open ) );
		self::stat( __( 'Not verified', 'stonewright' ), esc_html( (string) $unverified ) );
		self::stat( __( 'Last rollback', 'stonewright' ), null === $last ? esc_html__( 'None yet', 'stonewright' ) : self::time_html( $last, 'j M Y, H:i' ) );
		self::stat( __( 'Mode', 'stonewright' ), esc_html( self::mode_label() ) );
		self::stat( __( 'Rescue helper', 'stonewright' ), '<span data-sw-rescue-helper="' . esc_attr( $helper['state'] ) . '">' . esc_html( self::helper_label( $helper['state'] ) ) . '</span>' );
		echo '</dl>';
		if ( ! $helper['safe_mode'] ) {
			echo '<p class="sw-rescue-note">' . esc_html( self::helper_note( $helper['state'] ) ) . '</p>';
		}
	}

	private static function helper_label( string $state ): string {
		return match ( $state ) {
			'installed'          => __( 'Installed', 'stonewright' ),
			'not_loaded'         => __( 'Installed, loads on the next request', 'stonewright' ),
			'modified'           => __( 'Changed on disk', 'stonewright' ),
			'unwritable'         => __( 'Not installed (folder not writable)', 'stonewright' ),
			'file_mods_disabled' => __( 'Not installed (file changes are off)', 'stonewright' ),
			'source_invalid'     => __( 'Not installed (bundled file damaged)', 'stonewright' ),
			default              => __( 'Not installed', 'stonewright' ),
		};
	}

	/** Says what is lost without the helper, so a missing button is never a mystery. */
	private static function helper_note( string $state ): string {
		if ( 'not_loaded' === $state ) {
			return __( 'Safe mode is not available until the next request loads the rescue helper. Reload this page.', 'stonewright' );
		}
		return __( 'Safe mode is not available, and a fatal error after a change is not recorded automatically, because the rescue helper is not in place. Stonewright installs it when it is activated and repairs it on a wp-admin page load.', 'stonewright' );
	}

	/**
	 * Open incidents first, then the changes no health check reported back on.
	 *
	 * @param list<array<string, mixed>> $attention
	 */
	private static function render_attention( array $attention, bool $guarded, int $unverified ): void {
		echo '<section aria-labelledby="sw-rescue-attention-title">';
		/* translators: %d: number of changes that need attention */
		echo '<h2 id="sw-rescue-attention-title">' . esc_html( sprintf( __( 'Needs attention (%d)', 'stonewright' ), count( $attention ) ) ) . '</h2>';
		if ( [] === $attention ) {
			echo '<div class="sw-rescue-empty"><p><strong>' . esc_html__( 'Nothing to rescue.', 'stonewright' ) . '</strong> ' . esc_html__( 'Every change Stonewright made has been checked, or has been rolled back.', 'stonewright' ) . '</p></div>';
			echo '</section>';
			return;
		}
		if ( $unverified > 0 ) {
			echo '<p class="sw-rescue-note">' . esc_html__( 'Not verified means the change was made but no health check reported back. This usually means the host blocks requests from the site to itself. Check these again, or roll them back.', 'stonewright' ) . '</p>';
		}
		echo '<table class="sw-rescue-table" data-sw-rescue-table="attention">';
		self::table_head( true, __( 'Changes that need attention', 'stonewright' ) );
		echo '<tbody>';
		foreach ( $attention as $entry ) {
			self::render_row( $entry, true, $guarded );
		}
		echo '</tbody></table></section>';
	}

	/**
	 * The changes the journal keeps (the last 50), each with the way to roll it back when it can be.
	 *
	 * @param list<array<string, mixed>> $recent
	 */
	private static function render_recent( array $recent, bool $guarded ): void {
		echo '<section aria-labelledby="sw-rescue-recent-title">';
		echo '<h2 id="sw-rescue-recent-title">' . esc_html__( 'Recent changes', 'stonewright' ) . '</h2>';
		if ( [] === $recent ) {
			echo '<p class="sw-rescue-note">' . esc_html__( 'No other risky change has been journaled yet.', 'stonewright' ) . '</p></section>';
			return;
		}
		echo '<p class="sw-rescue-note">' . esc_html__( 'A change that passed its health check can still be rolled back. The journal keeps the last 50 changes.', 'stonewright' ) . '</p>';
		echo '<table class="sw-rescue-table" data-sw-rescue-table="recent">';
		self::table_head( true, __( 'Recent changes', 'stonewright' ) );
		echo '<tbody>';
		foreach ( $recent as $entry ) {
			self::render_row( $entry, true, $guarded, false );
		}
		echo '</tbody></table></section>';
	}

	private static function table_head( bool $actions, string $caption ): void {
		$headings = [ __( 'Change set', 'stonewright' ), __( 'When', 'stonewright' ), __( 'What changed', 'stonewright' ), __( 'Status', 'stonewright' ) ];
		if ( $actions ) {
			$headings[] = __( 'Actions', 'stonewright' );
		}
		echo '<caption class="screen-reader-text">' . esc_html( $caption ) . '</caption><thead><tr>';
		foreach ( $headings as $heading ) {
			echo '<th scope="col">' . esc_html( $heading ) . '</th>';
		}
		echo '</tr></thead>';
	}

	/**
	 * One change set. Each cell repeats its heading so a row still reads on its own at 400px.
	 *
	 * @param array<string, mixed> $entry
	 * @param bool                 $actionable Whether the row has the actions column.
	 * @param bool                 $attention  Whether the row is one that needs attention: it shows the health check, the recipe and the agent prompt.
	 */
	private static function render_row( array $entry, bool $actionable, bool $guarded, bool $attention = true ): void {
		$id    = (string) $entry['id'];
		$short = self::short_id( $id );
		$state = (string) $entry['state'];

		echo '<tr data-sw-rescue-row="' . esc_attr( $id ) . '">';
		echo '<th scope="row" data-label="' . esc_attr( __( 'Change set', 'stonewright' ) ) . '"><code class="sw-rescue-id">' . esc_html( $short ) . '</code> ';
		/* translators: %s: short change set id */
		self::copy_button( $id, sprintf( __( 'Copy change set id %s', 'stonewright' ), $short ) );
		echo '</th>';
		echo '<td data-label="' . esc_attr( __( 'When', 'stonewright' ) ) . '">' . self::time_html( self::when( $entry ), 'j M Y, H:i' ) . '<span class="sw-rescue-sub">' . esc_html( sprintf( /* translators: %s: how long ago, for example "3 hours ago" */ __( 'Changed %s', 'stonewright' ), self::age( (int) $entry['armed_at'] ) ) ) . '</span></td>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- time_html() escapes.

		echo '<td data-label="' . esc_attr( __( 'What changed', 'stonewright' ) ) . '"><strong>' . esc_html( self::what_changed( $entry ) ) . '</strong>';
		$by = '<code>' . esc_html( (string) $entry['ability'] ) . '</code>';
		if ( '' !== (string) $entry['client'] ) {
			/* translators: 1: ability name, 2: agent client */
			$line = sprintf( esc_html__( 'Changed by %1$s through %2$s', 'stonewright' ), $by, '<code>' . esc_html( (string) $entry['client'] ) . '</code>' );
		} else {
			/* translators: %s: ability name */
			$line = sprintf( esc_html__( 'Changed by %s', 'stonewright' ), $by );
		}
		echo '<span class="sw-rescue-sub">' . $line . '</span>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Built from escaped parts.
		$origin = self::rollback_origin( $entry );
		if ( '' !== $origin ) {
			echo '<span class="sw-rescue-sub">' . esc_html( $origin ) . '</span>';
		}
		if ( $attention ) {
			echo '<span class="sw-rescue-sub">' . esc_html__( 'Health check:', 'stonewright' ) . ' ' . self::evidence_html( $entry ) . '</span>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- evidence_html() escapes.
			echo '<span class="sw-rescue-sub">' . esc_html__( 'Rollback:', 'stonewright' ) . ' ' . esc_html( RollbackRecipes::describe( $entry ) ) . self::rollback_note( $entry ) . '</span>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- rollback_note() escapes.
			if ( in_array( $state, [ 'incident', 'rollback_failed' ], true ) ) {
				self::render_prompt( $entry );
			}
		}
		echo '</td>';

		echo '<td data-label="' . esc_attr( __( 'Status', 'stonewright' ) ) . '"><span class="sw-badge sw-rescue-badge--' . esc_attr( self::badge_kind( $state ) ) . '">' . esc_html( self::state_label( $state ) ) . '</span></td>';

		if ( $actionable ) {
			echo '<td data-label="' . esc_attr( __( 'Actions', 'stonewright' ) ) . '">';
			self::render_actions( $entry, $guarded );
			self::render_diff_link( $entry );
			echo '</td>';
		}
		echo '</tr>';
	}

	/**
	 * The prompt an agent can be handed, in a disclosure so the table stays short.
	 *
	 * @param array<string, mixed> $entry
	 */
	private static function render_prompt( array $entry ): void {
		$prompt_id = 'sw-rescue-prompt-' . md5( (string) $entry['id'] );
		echo '<details class="sw-rescue-prompt"><summary>' . esc_html__( 'Prompt for your agent', 'stonewright' ) . '</summary>';
		echo '<label class="screen-reader-text" for="' . esc_attr( $prompt_id ) . '">' . esc_html__( 'Prompt for your agent', 'stonewright' ) . '</label>';
		echo '<textarea id="' . esc_attr( $prompt_id ) . '" rows="6" readonly>' . esc_textarea( self::prompt_for( $entry ) ) . '</textarea>';
		echo '<button type="button" class="sw-btn sw-btn--secondary sw-btn--sm" hidden data-sw-rescue-copy="' . esc_attr( $prompt_id ) . '">' . esc_html__( 'Copy prompt for your agent', 'stonewright' ) . '</button>';
		echo '</details>';
	}

	/**
	 * The row actions. Every name says which change set it acts on.
	 *
	 * @param array<string, mixed> $entry
	 */
	private static function render_actions( array $entry, bool $guarded ): void {
		$id        = (string) $entry['id'];
		$short     = self::short_id( $id );
		$hash      = md5( $id );
		$available = RollbackRecipes::available( $entry );
		$retry     = 'rollback_failed' === (string) $entry['state'];
		$state     = (string) $entry['state'];

		if ( 'rolled_back' === $state ) {
			echo '<p class="sw-rescue-note">' . esc_html__( 'Already rolled back.', 'stonewright' ) . '</p>';
			return;
		}
		if ( ! in_array( $state, RescueRollback::ROLLBACK_STATES, true ) ) {
			return;
		}
		if ( ChangeJournal::is_claimed( $entry ) ) {
			echo '<p class="sw-rescue-note">' . esc_html__( 'A rollback of this change set is running.', 'stonewright' ) . '</p>';
			return;
		}
		echo '<div class="sw-actions">';
		if ( $available ) {
			/* translators: %s: short change set id */
			$name = $retry ? __( 'Roll back change set %s again', 'stonewright' ) : __( 'Roll back change set %s', 'stonewright' );
			echo '<button type="submit" form="sw-rescue-form-' . esc_attr( $hash ) . '" class="sw-btn sw-btn--danger sw-btn--sm" data-sw-rescue-open="sw-rescue-dlg-' . esc_attr( $hash ) . '" aria-label="' . esc_attr( sprintf( $name, $short ) ) . '">' . esc_html( $retry ? __( 'Try again', 'stonewright' ) : __( 'Roll back', 'stonewright' ) ) . '</button>';
		} elseif ( 'verified' === $state ) {
			echo '<p class="sw-rescue-note">' . esc_html__( 'No automatic rollback is available for this change.', 'stonewright' ) . '</p>';
		} else {
			echo '<p class="sw-rescue-note">' . esc_html__( 'No automatic rollback is available for this change. Undo it by hand, then check the site again.', 'stonewright' ) . '</p>';
		}
		if ( 'verified' !== $state ) {
			self::render_recheck_form( $id, $short, $guarded );
		}
		echo '</div>';
		if ( $available ) {
			self::render_dialog( $entry, $guarded );
		}
	}

	private static function render_recheck_form( string $id, string $short, bool $guarded ): void {
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" data-sw-rescue-form>';
		self::hidden( 'action', 'stonewright_rescue_recheck' );
		self::hidden( 'incident_id', $id );
		self::nonce_input( 'stonewright_rescue_recheck' );
		if ( $guarded ) {
			self::hidden( 'confirmation_token', ConfirmationToken::issue( 'stonewright/rescue-rollback', [ 'incident_id' => $id, 'action' => 'recheck' ], self::TOKEN_TTL ) );
		}
		/* translators: %s: short change set id */
		$name = sprintf( __( 'Check change set %s again', 'stonewright' ), $short );
		echo '<button type="submit" class="sw-btn sw-btn--secondary sw-btn--sm" data-sw-rescue-submit data-sw-busy-label="' . esc_attr( __( 'Checking the site...', 'stonewright' ) ) . '" aria-label="' . esc_attr( $name ) . '">' . esc_html__( 'Check again', 'stonewright' ) . '</button>';
		echo '</form>';
	}

	/**
	 * The confirmation for one rollback. The form is always in the page, so without the script the
	 * row button posts it directly; the script shows it as a dialog first.
	 *
	 * @param array<string, mixed> $entry
	 */
	private static function render_dialog( array $entry, bool $guarded ): void {
		$id     = (string) $entry['id'];
		$short  = self::short_id( $id );
		$hash   = md5( $id );
		$dialog = 'sw-rescue-dlg-' . $hash;

		echo '<dialog class="sw-rescue-dialog" id="' . esc_attr( $dialog ) . '" aria-labelledby="' . esc_attr( $dialog ) . '-title" data-sw-rescue-dialog>';
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" id="sw-rescue-form-' . esc_attr( $hash ) . '" data-sw-rescue-form>';
		/* translators: %s: short change set id */
		echo '<h2 id="' . esc_attr( $dialog ) . '-title">' . esc_html( sprintf( __( 'Roll back change set %s?', 'stonewright' ), $short ) ) . '</h2>';
		echo '<dl class="sw-rescue-kv">';
		self::kv( __( 'What changed', 'stonewright' ), esc_html( self::what_changed( $entry ) ) );
		self::kv( __( 'Changed by', 'stonewright' ), '<code>' . esc_html( (string) $entry['ability'] ) . '</code>' );
		self::kv( __( 'Restores', 'stonewright' ), esc_html( RollbackRecipes::describe( $entry ) ) );
		echo '</dl>';
		echo '<p>' . esc_html__( 'This puts the item back as it was before the change (anything saved to it since is overwritten), then checks that the site loads.', 'stonewright' ) . '</p>';
		if ( 'verified' === (string) $entry['state'] ) {
			echo '<p>' . esc_html__( 'This change passed its health check. Rolling it back undoes a change that worked. The current state is saved first; if the site stops loading after the undo, it is put back.', 'stonewright' ) . '</p>';
		}
		self::render_newer_warning( $entry );
		self::hidden( 'action', 'stonewright_rescue_rollback' );
		self::hidden( 'incident_id', $id );
		self::nonce_input( 'stonewright_rescue_rollback' );
		if ( $guarded ) {
			self::hidden( 'confirmation_token', ConfirmationToken::issue( 'stonewright/rescue-rollback', [ 'incident_id' => $id ], self::TOKEN_TTL ) );
			$phrase_id = 'sw-rescue-phrase-' . $hash;
			echo '<p class="sw-rescue-field"><label for="' . esc_attr( $phrase_id ) . '">' . esc_html__( 'Type ROLL BACK to confirm', 'stonewright' ) . '</label>';
			echo '<input type="text" id="' . esc_attr( $phrase_id ) . '" name="confirm_phrase" value="" autocomplete="off" spellcheck="false" data-sw-rescue-phrase="' . esc_attr( self::PHRASE ) . '" /></p>';
		}
		echo '<p class="sw-rescue-busy" role="status" data-sw-rescue-status></p>';
		echo '<div class="sw-actions">';
		echo '<button type="button" class="sw-btn sw-btn--secondary" autofocus data-sw-rescue-cancel>' . esc_html__( 'Cancel', 'stonewright' ) . '</button>';
		echo '<button type="submit" class="sw-btn sw-rescue-solid" data-sw-rescue-submit data-sw-busy-label="' . esc_attr( __( 'Rolling back and checking the site...', 'stonewright' ) ) . '">' . esc_html__( 'Roll back', 'stonewright' ) . '</button>';
		echo '</div>';
		echo '</form></dialog>';
	}

	/**
	 * A change to the same item that was made after this one, which a rollback would overwrite.
	 *
	 * @param array<string, mixed> $entry
	 */
	private static function render_newer_warning( array $entry ): void {
		$newer = ChangeJournal::newer_changes( $entry );
		if ( [] === $newer ) {
			return;
		}
		$ids = [];
		foreach ( array_slice( $newer, 0, 3 ) as $other ) {
			$ids[] = '<code class="sw-rescue-id">' . esc_html( self::short_id( (string) $other['id'] ) ) . '</code>';
		}
		$text = esc_html__( 'A newer change to this item was made after this one (%s). Rolling this back also overwrites it.', 'stonewright' );
		echo '<p class="sw-rescue-warning" role="note">' . sprintf( $text, implode( ', ', $ids ) ) . '</p>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped text and escaped ids.
	}

	/** How long ago, in the largest whole unit: just now, minutes, hours or days. */
	private static function age( int $since ): string {
		$seconds = max( 0, ChangeJournal::now() - $since );
		if ( $seconds < 60 ) {
			return __( 'just now', 'stonewright' );
		}
		if ( $seconds < 3600 ) {
			$minutes = (int) floor( $seconds / 60 );
			/* translators: %d: number of minutes */
			return sprintf( _n( '%d minute ago', '%d minutes ago', $minutes, 'stonewright' ), $minutes );
		}
		if ( $seconds < 172800 ) {
			$hours = (int) floor( $seconds / 3600 );
			/* translators: %d: number of hours */
			return sprintf( _n( '%d hour ago', '%d hours ago', $hours, 'stonewright' ), $hours );
		}
		$days = (int) floor( $seconds / 86400 );
		/* translators: %d: number of days */
		return sprintf( _n( '%d day ago', '%d days ago', $days, 'stonewright' ), $days );
	}

	private static function stat( string $label, string $html ): void {
		echo '<div><dt>' . esc_html( $label ) . '</dt><dd>' . $html . '</dd></div>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Every caller passes escaped markup.
	}

	private static function kv( string $label, string $html ): void {
		echo '<div><dt>' . esc_html( $label ) . '</dt><dd>' . $html . '</dd></div>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Every caller passes escaped markup.
	}

	private static function hidden( string $name, string $value ): void {
		echo '<input type="hidden" name="' . esc_attr( $name ) . '" value="' . esc_attr( $value ) . '" />';
	}

	/** A nonce field without an id, so several forms on one page never share one. */
	private static function nonce_input( string $action ): void {
		self::hidden( '_stonewright_nonce', wp_create_nonce( $action ) );
	}

	/** A control that only the script reveals: the page itself needs no clipboard. */
	private static function copy_button( string $value, string $label ): void {
		echo '<button type="button" class="sw-btn sw-btn--ghost sw-btn--sm" hidden data-sw-rescue-copy-text="' . esc_attr( $value ) . '" aria-label="' . esc_attr( $label ) . '">' . esc_html__( 'Copy', 'stonewright' ) . '</button>';
	}

	private static function time_html( int $timestamp, string $format ): string {
		return '<time datetime="' . esc_attr( gmdate( 'Y-m-d\TH:i:s\Z', $timestamp ) ) . '">' . esc_html( (string) wp_date( $format, $timestamp ) ) . '</time>';
	}

	/**
	 * The probe evidence as one short line.
	 *
	 * @param array<string, mixed> $entry
	 */
	private static function evidence_html( array $entry ): string {
		$probe = is_array( $entry['probe'] ?? null ) ? $entry['probe'] : null;
		if ( null === $probe ) {
			return esc_html__( 'No result was recorded.', 'stonewright' );
		}
		$labels = [
			'home'   => __( 'Home page', 'stonewright' ),
			'admin'  => __( 'wp-admin', 'stonewright' ),
			'rest'   => __( 'REST API', 'stonewright' ),
			'post'   => __( 'Post page', 'stonewright' ),
			'custom' => __( 'Custom URL', 'stonewright' ),
		];
		$parts  = [];
		foreach ( is_array( $probe['legs'] ?? null ) ? $probe['legs'] : [] as $leg ) {
			$name = (string) ( $leg['leg'] ?? '' );
			$part = esc_html( $labels[ $name ] ?? $name ) . ' ' . esc_html( (string) ( $leg['status'] ?? '' ) );
			if ( (int) ( $leg['http'] ?? 0 ) > 0 ) {
				$part .= ', HTTP ' . esc_html( (string) (int) $leg['http'] );
			}
			if ( '' !== (string) ( $leg['reason'] ?? '' ) ) {
				$part .= ' <code>' . esc_html( (string) $leg['reason'] ) . '</code>';
			}
			$parts[] = $part;
		}
		return [] === $parts ? esc_html( (string) ( $probe['status'] ?? 'unavailable' ) ) : implode( '; ', $parts );
	}

	/**
	 * What the automatic rollback already tried, when it did.
	 *
	 * @param array<string, mixed> $entry
	 */
	private static function rollback_note( array $entry ): string {
		$rollback = is_array( $entry['rollback'] ?? null ) ? $entry['rollback'] : null;
		if ( null === $rollback || '' === (string) ( $rollback['status'] ?? '' ) ) {
			return '';
		}
		$text = sprintf(
			/* translators: 1: rollback status, 2: reason code */
			__( 'Last attempt: %1$s%2$s.', 'stonewright' ),
			(string) $rollback['status'],
			'' !== (string) ( $rollback['detail'] ?? '' ) ? ' (' . (string) $rollback['detail'] . ')' : ''
		);
		return ' <em>' . esc_html( $text ) . '</em>';
	}

	/**
	 * The words an agent is given to recover.
	 *
	 * @param array<string, mixed> $entry
	 */
	public static function prompt_for( array $entry ): string {
		$id = (string) $entry['id'];
		return sprintf(
			"Stonewright Rescue: change set %1\$s (%2\$s) left this WordPress site failing and is still open.\n"
			. "1. Call stonewright-rescue-status and read incident %1\$s.\n"
			. "2. Call stonewright-rescue-rollback with incident_id \"%1\$s\". Add dry_run true first to see the plan. In production-safe mode get a confirmation token first.\n"
			. "3. The result must say site_status healthy. If it does not, stop and report it.\n"
			. 'Do not retry the same change until the incident is closed.',
			$id,
			(string) $entry['ability']
		);
	}

	// -----------------------------------------------------------------------
	// Requests.
	// -----------------------------------------------------------------------

	public static function handle_rollback(): void {
		self::redirect_with( self::process_rollback_request() );
	}

	public static function handle_recheck(): void {
		self::redirect_with( self::process_recheck_request() );
	}

	public static function handle_safe_mode(): void {
		$result = self::process_safe_mode_request();
		if ( is_string( $result['url'] ) && '' !== $result['url'] ) {
			wp_safe_redirect( $result['url'] );
			exit;
		}
		self::redirect_with( [ 'code' => $result['code'], 'incident_id' => '' ] );
	}

	/**
	 * @return array{code:string,incident_id:string}
	 */
	public static function process_rollback_request(): array {
		$id      = self::guarded_incident_id( 'stonewright_rescue_rollback' );
		$refused = self::confirmation_refusal( $id, [ 'incident_id' => $id ] );
		if ( null !== $refused ) {
			return [ 'code' => $refused, 'incident_id' => $id ];
		}
		// The administrator who pressed the button is the approval that undoing a verified change to code needs.
		$result = RescueRollback::run( $id, [ 'by' => 'admin-page', 'user_id' => (int) get_current_user_id(), 'human_approved' => true ] );
		RescueRollback::audit_outcome( $id, $result, 'admin-page', 'rollback' );

		if ( $result instanceof \WP_Error ) {
			return [
				'code'        => match ( $result->get_error_code() ) {
					'stonewright_rescue_incident_not_found' => 'not_found',
					'stonewright_rescue_not_open'           => 'not_open',
					'stonewright_rescue_no_recipe'          => 'no_recipe',
					'stonewright_rescue_in_progress'        => 'in_progress',
					'stonewright_rescue_undo_capture_failed' => 'undo_refused',
					'stonewright_rescue_undo_reverted'      => 'healthy' === (string) ( $result->get_error_data()['site_status'] ?? '' ) ? 'undo_reverted' : 'undo_reverted_still_failing',
					default                                 => 'rollback_failed',
				},
				'incident_id' => $id,
			];
		}
		return [
			'code'        => match ( (string) ( $result['site_status'] ?? '' ) ) {
				'healthy'       => 'rolled_back',
				'still_failing' => 'rolled_back_still_failing',
				default         => 'rolled_back_unverified',
			},
			'incident_id' => $id,
		];
	}

	/**
	 * @return array{code:string,incident_id:string}
	 */
	public static function process_recheck_request(): array {
		$id      = self::guarded_incident_id( 'stonewright_rescue_recheck' );
		$refused = self::confirmation_refusal( $id, [ 'incident_id' => $id, 'action' => 'recheck' ] );
		if ( null !== $refused ) {
			return [ 'code' => $refused, 'incident_id' => $id ];
		}
		$result = RescueRollback::recheck( $id, [ 'user_id' => (int) get_current_user_id() ] );
		RescueRollback::audit_outcome( $id, $result, 'admin-page', 'recheck' );

		if ( $result instanceof \WP_Error ) {
			return [
				'code'        => match ( $result->get_error_code() ) {
					'stonewright_rescue_incident_not_found' => 'not_found',
					'stonewright_rescue_in_progress'        => 'in_progress',
					default                                 => 'not_open',
				},
				'incident_id' => $id,
			];
		}
		return [ 'code' => ! empty( $result['resolved'] ) ? 'rechecked_resolved' : 'rechecked_open', 'incident_id' => $id ];
	}

	/**
	 * Issue the safe-mode link, only now that the administrator asked for it.
	 *
	 * @return array{code:string,url:?string}
	 */
	public static function process_safe_mode_request(): array {
		self::guard_request( 'stonewright_rescue_safe_mode' );
		$url = self::resolve_safe_mode( (int) get_current_user_id() );
		return null === $url
			? [ 'code' => 'safe_mode_unavailable', 'url' => null ]
			: [ 'code' => 'safe_mode', 'url' => $url ];
	}

	/**
	 * @param array{code:string,incident_id:string} $outcome
	 */
	private static function redirect_with( array $outcome ): void {
		wp_safe_redirect(
			add_query_arg(
				[
					'page'     => self::SLUG,
					'rescue'   => $outcome['code'],
					'incident' => $outcome['incident_id'],
				],
				admin_url( 'admin.php' )
			)
		);
		exit;
	}

	/** Capability and nonce, or the request ends. */
	private static function guard_request( string $nonce_action ): void {
		if ( ! current_user_can( self::CAPABILITY ) ) {
			wp_die( esc_html__( 'Forbidden', 'stonewright' ), '', [ 'response' => 403 ] );
		}
		$nonce = isset( $_POST['_stonewright_nonce'] ) ? sanitize_text_field( wp_unslash( (string) $_POST['_stonewright_nonce'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Missing
		if ( false === wp_verify_nonce( $nonce, $nonce_action ) ) {
			wp_die( esc_html__( 'Forbidden', 'stonewright' ), '', [ 'response' => 403 ] );
		}
	}

	private static function guarded_incident_id( string $nonce_action ): string {
		self::guard_request( $nonce_action );
		return isset( $_POST['incident_id'] ) ? self::clean_id( (string) wp_unslash( (string) $_POST['incident_id'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Missing
	}

	/**
	 * In production-safe mode the action needs a token issued for exactly this change set and action.
	 *
	 * @param array<string, mixed> $args What the token was issued over.
	 * @return string|null An outcome code when the action is refused.
	 */
	private static function confirmation_refusal( string $id, array $args ): ?string {
		if ( ! Permissions::is_production_safe() ) {
			return null;
		}
		$token = isset( $_POST['confirmation_token'] ) ? sanitize_text_field( wp_unslash( (string) $_POST['confirmation_token'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Missing
		if ( '' === $token ) {
			return 'confirmation_required';
		}
		return ConfirmationToken::verify_or_error( $token, 'stonewright/rescue-rollback', $args ) instanceof \WP_Error ? 'confirmation_invalid' : null;
	}

	private static function safe_mode_available(): bool {
		if ( false === self::$safe_mode_resolver ) {
			return false;
		}
		if ( null !== self::$safe_mode_resolver ) {
			return true;
		}
		// The link needs the helper: without it nothing would redeem the key.
		return class_exists( self::KEYS_CLASS ) && RescueInstaller::summary()['safe_mode'];
	}

	private static function resolve_safe_mode( int $user_id ): ?string {
		if ( false === self::$safe_mode_resolver ) {
			return null;
		}
		if ( null !== self::$safe_mode_resolver ) {
			$url = ( self::$safe_mode_resolver )( $user_id );
			return is_string( $url ) && '' !== $url ? $url : null;
		}
		$keys = self::KEYS_CLASS;
		if ( ! class_exists( $keys ) ) {
			return null;
		}
		$url = $keys::safe_boot_url( $user_id );
		return is_string( $url ) && '' !== $url ? $url : null;
	}

	// -----------------------------------------------------------------------
	// Wording.
	// -----------------------------------------------------------------------

	private static function clean_id( string $value ): string {
		return 1 === preg_match( '/^[A-Za-z0-9][A-Za-z0-9._:-]{0,95}$/D', $value ) ? $value : '';
	}

	/** The eight characters after the prefix: what the page shows and speaks for a change set. */
	private static function short_id( string $id ): string {
		return substr( (string) preg_replace( '/^cs[-_]/i', '', $id ), 0, 8 );
	}

	/** @param array<string, mixed> $entry */
	private static function what_changed( array $entry ): string {
		$key = (string) $entry['resource_key'];
		return match ( (string) $entry['resource_type'] ) {
			'post'        => sprintf( /* translators: %s: post ID */ __( 'Post %s', 'stonewright' ), $key ),
			'theme_file'  => sprintf( /* translators: %s: theme file */ __( 'Theme file %s', 'stonewright' ), $key ),
			'option'      => sprintf( /* translators: %s: option names */ __( 'Options %s', 'stonewright' ), $key ),
			'plugin'      => sprintf( /* translators: %s: plugin file */ __( 'Plugin %s', 'stonewright' ), $key ),
			'sandbox'     => sprintf( /* translators: %s: sandbox file */ __( 'Sandbox file %s', 'stonewright' ), $key ),
			'custom_code' => sprintf( /* translators: %s: snippet */ __( 'Custom code %s', 'stonewright' ), $key ),
			default       => $key,
		};
	}

	/** @param array<string, mixed> $entry */
	private static function when( array $entry ): int {
		$incident = is_array( $entry['incident'] ?? null ) ? $entry['incident'] : null;
		if ( null !== $incident && (int) $incident['recorded_at'] > 0 ) {
			return (int) $incident['recorded_at'];
		}
		return (int) ( $entry['settled_at'] ?? 0 ) > 0 ? (int) $entry['settled_at'] : (int) $entry['armed_at'];
	}

	/**
	 * One line about how a rolled-back change was undone, and by whom.
	 *
	 * @param array<string, mixed> $entry
	 */
	private static function rollback_origin( array $entry ): string {
		if ( 'verified' === (string) $entry['state'] && 'undo_reverted' === (string) ( $entry['note'] ?? '' ) ) {
			return __( 'An undo was tried and put back because the site stopped loading', 'stonewright' );
		}
		if ( 'rolled_back' !== (string) $entry['state'] || ! is_array( $entry['rollback'] ?? null ) ) {
			return '';
		}
		$user = (int) ( $entry['rollback']['user'] ?? 0 );
		$who  = '';
		if ( $user > 0 ) {
			$account = function_exists( 'get_user_by' ) ? get_user_by( 'id', $user ) : false;
			$who     = is_object( $account ) && '' !== (string) ( $account->display_name ?? '' ) ? (string) $account->display_name : sprintf( /* translators: %d: user ID */ __( 'user %d', 'stonewright' ), $user );
		}
		return match ( (string) ( $entry['rollback']['by'] ?? '' ) ) {
			'admin-page' => '' !== $who
				? sprintf( /* translators: %s: user name */ __( 'Rolled back from this page by %s', 'stonewright' ), $who )
				: __( 'Rolled back from this page', 'stonewright' ),
			'ability'    => '' !== $who
				? sprintf( /* translators: %s: user name */ __( 'Rolled back through an agent call as %s', 'stonewright' ), $who )
				: __( 'Rolled back through an agent call', 'stonewright' ),
			'wp-cli'     => '' !== $who
				? sprintf( /* translators: %s: user name */ __( 'Rolled back with WP-CLI as %s', 'stonewright' ), $who )
				: __( 'Rolled back with WP-CLI', 'stonewright' ),
			'auto'       => __( 'Rolled back automatically after a failed health check', 'stonewright' ),
			default      => '',
		};
	}

	/** When the most recent rollback finished, or null when none has. */
	private static function last_rollback(): ?int {
		$last = 0;
		foreach ( ChangeJournal::recent( ChangeJournalFile::MAX_ENTRIES ) as $entry ) {
			if ( 'rolled_back' !== $entry['state'] ) {
				continue;
			}
			$rollback = is_array( $entry['rollback'] ?? null ) ? $entry['rollback'] : [];
			$at       = (int) ( $rollback['at'] ?? 0 ) > 0 ? (int) $rollback['at'] : (int) ( $entry['settled_at'] ?? 0 );
			$last     = max( $last, $at );
		}
		return $last > 0 ? $last : null;
	}

	private static function mode_label(): string {
		return match ( (string) get_option( 'stonewright_mode', 'development' ) ) {
			'production-safe' => __( 'Production-safe', 'stonewright' ),
			'staging'         => __( 'Staging', 'stonewright' ),
			default           => __( 'Development', 'stonewright' ),
		};
	}

	private static function state_label( string $state ): string {
		return match ( $state ) {
			'rollback_failed' => __( 'Rollback failed', 'stonewright' ),
			'incident'        => __( 'Fatal recorded', 'stonewright' ),
			'armed'           => __( 'Not verified', 'stonewright' ),
			'verified'        => __( 'Verified', 'stonewright' ),
			'rolled_back'     => __( 'Rolled back', 'stonewright' ),
			default           => $state,
		};
	}

	private static function badge_kind( string $state ): string {
		return match ( $state ) {
			'rollback_failed', 'incident' => 'danger',
			'armed'                       => 'warn',
			'verified'                    => 'ok',
			default                       => 'info',
		};
	}

	/**
	 * Outcome code => kind, text, and whether an audit row was written that a receipt can point to.
	 *
	 * @return array<string, array{0:string,1:string,2:bool}>
	 */
	private static function outcome_messages(): array {
		return [
			'rolled_back'               => [ 'ok', __( 'The change was rolled back and the site loads again.', 'stonewright' ), true ],
			'rolled_back_still_failing' => [ 'warn', __( 'The change was rolled back, but the site still fails to load. The fault may not come from this change.', 'stonewright' ), true ],
			'rolled_back_unverified'    => [ 'warn', __( 'The change was rolled back. The health check afterwards was unavailable, so recovery is not confirmed.', 'stonewright' ), true ],
			'rollback_failed'           => [ 'danger', __( 'The rollback did not complete. Try safe mode, or undo the change by hand and check the site again.', 'stonewright' ), true ],
			'undo_reverted'             => [ 'warn', __( 'The undo would have broken the site, so it was put back. The change is still in effect and the site loads.', 'stonewright' ), true ],
			'undo_reverted_still_failing' => [ 'danger', __( 'The undo made the site stop loading and was put back, but the site still fails to load. The fault may not come from the undo. Try safe mode, or check the site.', 'stonewright' ), true ],
			'undo_refused'              => [ 'warn', __( 'The undo was refused: the current state could not be saved first, so there would be no way back. Nothing was changed.', 'stonewright' ), true ],
			'rechecked_resolved'        => [ 'ok', __( 'The site loads. The incident is closed.', 'stonewright' ), true ],
			'rechecked_open'            => [ 'warn', __( 'The site still does not pass the health check, so the incident stays open.', 'stonewright' ), true ],
			'confirmation_required'     => [ 'danger', __( 'Production-safe mode needs the confirmation on the form. Reload the page and try again.', 'stonewright' ), false ],
			'confirmation_invalid'      => [ 'danger', __( 'The confirmation expired or does not match. Reload the page and try again.', 'stonewright' ), false ],
			'not_found'                 => [ 'danger', __( 'No incident has that id.', 'stonewright' ), true ],
			'not_open'                  => [ 'info', __( 'That change is already verified or rolled back.', 'stonewright' ), true ],
			'no_recipe'                 => [ 'danger', __( 'No automatic rollback is available for this change.', 'stonewright' ), true ],
			'in_progress'               => [ 'info', __( 'A rollback of this change set is already running. Reload this page in a minute.', 'stonewright' ), true ],
			'safe_mode_unavailable'     => [ 'warn', __( 'Safe mode is not available on this site.', 'stonewright' ), false ],
		];
	}
}
