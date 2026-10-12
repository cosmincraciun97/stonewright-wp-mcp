<?php
/**
 * Stonewright > Rescue admin page.
 * SPDX-License-Identifier: GPL-2.0-or-later
 *
 * @package Stonewright
 */

declare( strict_types=1 );

namespace Stonewright\WpMcp\Admin;

use Stonewright\WpMcp\Admin\Ui\Button;
use Stonewright\WpMcp\Admin\Ui\FormField;
use Stonewright\WpMcp\Core\RescueInstaller;
use Stonewright\WpMcp\Security\AccessDenied;
use Stonewright\WpMcp\Security\ChangeJournal;
use Stonewright\WpMcp\Security\ChangeJournalFile;
use Stonewright\WpMcp\Security\ChangeLedger;
use Stonewright\WpMcp\Security\ChangeRollback;
use Stonewright\WpMcp\Security\ConfirmationToken;
use Stonewright\WpMcp\Security\Permissions;
use Stonewright\WpMcp\Security\ProbeToken;
use Stonewright\WpMcp\Security\RescueRollback;
use Stonewright\WpMcp\Security\Rollback\RollbackFamilies;
use Stonewright\WpMcp\Security\RollbackRecipes;

/**
 * Lists the changes that need attention and rolls one back without an agent.
 *
 * The page needs nothing but WordPress and Stonewright, so it keeps working in safe mode and when the change ledger is
 * not readable: it is an entry point of its own (`page=stonewright-rescue`), and the Rescue tab of the Changes page
 * (`page=stonewright-changes&tab=rescue`) prints the same content. Both are chosen before the ledger is asked anything.
 *
 * Every action needs manage_options and a nonce. In production-safe mode a rollback also needs a confirmation token,
 * which the page issues for exactly one change set and one action, and the phrase ROLL BACK, which the server compares.
 *
 * Markup is server-rendered. The first press of Roll back is a link to a review (`review=<id>`) that changes nothing and
 * holds the one form that does; Cancel is a link back. The script only adds a dialog in place of the review, the copy
 * buttons and a busy state: without it, or when it does not load, or when the browser has no `showModal`, the link is
 * followed and the review is the page. The stylesheet comes from the page style map in AdminBootstrap.
 */
final class RescuePage {

	public const SLUG       = 'stonewright-rescue';
	public const CAPABILITY = 'manage_options';

	private const TOKEN_TTL = 600;

	/** The words typed to confirm a rollback in production-safe mode. */
	private const PHRASE = 'ROLL BACK';

	/** The class that issues safe-mode links. It is optional, so it is named rather than imported. */
	private const KEYS_CLASS = '\Stonewright\WpMcp\Security\RescueKeys';

	/** How many rows of one page get the plan of the change ledger for their confirmation. */
	private const PLAN_BUDGET = 10;

	/** Outcomes that ran nothing and can be fixed on the review itself. */
	private const RETRYABLE = [ 'confirmation_required', 'confirmation_invalid', 'confirmation_phrase', 'drift', 'changed_since_preview' ];

	/** @var callable(int):?string|false|null null uses RescueKeys when it exists; false means no safe mode. */
	private static $safe_mode_resolver = null;

	/** @var array<string, array<string, mixed>|null> Ledger rows already asked for on this request. */
	private static array $ledger_rows = [];

	/** @var bool Whether the ledger failed on this request: it is not asked again. */
	private static bool $ledger_failed = false;

	/** @var int How many more plans of the change ledger this request may ask for. */
	private static int $plans_left = self::PLAN_BUDGET;

	public static function register(): void {
		add_action( 'init', [ self::class, 'add_to_menu_registry' ] );
		add_action( 'admin_menu', [ self::class, 'add_submenu' ] );
		add_action( 'admin_post_stonewright_rescue_rollback', [ self::class, 'handle_rollback' ] );
		add_action( 'admin_post_stonewright_rescue_recheck', [ self::class, 'handle_recheck' ] );
		add_action( 'admin_post_stonewright_rescue_safe_mode', [ self::class, 'handle_safe_mode' ] );
		add_action( 'admin_enqueue_scripts', [ self::class, 'enqueue' ] );
		add_filter( 'submenu_file', [ self::class, 'highlight_submenu' ] );
	}

	/**
	 * The page stays at its own address, as an entry point that needs nothing else. Its menu item is registered like any
	 * other, so WordPress opens the Stonewright menu on it, and MenuOrder hides it: the Changes entry has the Rescue tab.
	 */
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

	/** On this page the Changes entry is the one marked in the open menu: it holds this page as its Rescue tab. */
	public static function highlight_submenu( mixed $submenu_file ): mixed {
		return self::on_this_page() ? ChangesPage::SLUG : $submenu_file;
	}

	private static function on_this_page(): bool {
		$page = isset( $_GET['page'] ) ? sanitize_key( (string) wp_unslash( (string) $_GET['page'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended

		return self::SLUG === $page;
	}

	/**
	 * Registered on `init`, where labels can be translated; render() makes sure it exists too. The page is a view of the
	 * Rescue tab of the Changes page: it is not listed on its own in the band or the sidebar, and the Changes link of
	 * the band is the current one on it.
	 */
	public static function add_to_menu_registry(): void {
		// The Changes entry and its tabs come with it: this page is a view of the Rescue tab, and its parent has to be there.
		ChangesPage::add_to_menu_registry();
	}

	/** The registration of this page itself, which ChangesPage::add_to_menu_registry() makes after its own tabs. */
	public static function add_alias_to_menu_registry(): void {
		MenuRegistry::add(
			self::SLUG,
			__( 'Rescue', 'stonewright' ),
			'activity',
			[
				'order'       => 32,
				'beta'        => true,
				'in_menu'     => false,
				'parent'      => ChangesPage::SLUG,
				'parent_tab'  => ChangesPage::TAB_RESCUE,
				'lede'        => self::lede(),
				'count'       => static fn (): int => self::attention_count(),
				'count_label' => __( 'needing attention', 'stonewright' ),
			]
		);
	}

	/** Open incidents and changes no health check reported back on: the number beside Rescue. */
	public static function attention_count(): int {
		return count( ChangeJournal::open_incidents() ) + count( ChangeJournal::unconfirmed() );
	}

	/**
	 * Load the page script. The stylesheet is enqueued by AdminBootstrap's page style map.
	 */
	public static function enqueue( string $hook_suffix = '' ): void {
		$page = isset( $_GET['page'] ) ? sanitize_key( (string) wp_unslash( (string) $_GET['page'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( self::SLUG !== $page && ! str_contains( $hook_suffix, self::SLUG ) ) {
			return;
		}
		self::enqueue_script();
	}

	/** The assets of the Rescue tab of the Changes page: its stylesheet is not in that page's entry of the style map. */
	public static function enqueue_tab_assets(): void {
		$version = defined( 'STONEWRIGHT_VERSION' ) ? (string) constant( 'STONEWRIGHT_VERSION' ) : '0.1.0';
		$base    = defined( 'STONEWRIGHT_URL' ) ? (string) constant( 'STONEWRIGHT_URL' ) : '';
		wp_enqueue_style( 'stonewright-admin-pages/rescue', $base . 'assets/admin/pages/rescue.css', [ 'stonewright-admin-shell', 'stonewright-admin' ], $version );
		self::enqueue_script();
	}

	private static function enqueue_script(): void {
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

	/** Forget what one request learned about the ledger. For tests. */
	public static function reset_for_tests(): void {
		self::$ledger_rows   = [];
		self::$ledger_failed = false;
		self::$plans_left    = self::PLAN_BUDGET;
	}

	// -----------------------------------------------------------------------
	// Addresses.
	// -----------------------------------------------------------------------

	/**
	 * An address of the independent Rescue page. Empty values are left out.
	 *
	 * @param array<string, string> $args
	 */
	public static function url( array $args = [] ): string {
		$query = [ 'page' => self::SLUG ];
		foreach ( $args as $key => $value ) {
			if ( '' !== $value ) {
				$query[ $key ] = $value;
			}
		}
		return add_query_arg( $query, admin_url( 'admin.php' ) );
	}

	/** The address of the review of one rollback: it changes nothing and holds the form that does. */
	public static function review_url( string $id ): string {
		return self::url( [ 'review' => $id ] );
	}

	// -----------------------------------------------------------------------
	// Rendering.
	// -----------------------------------------------------------------------

	/** The page at its own address. */
	public static function render(): void {
		if ( ! current_user_can( self::CAPABILITY ) ) {
			AccessDenied::deny( esc_html__( 'You do not have permission to view Stonewright Rescue.', 'stonewright' ) );
		}
		self::add_to_menu_registry();
		AdminShell::open(
			self::SLUG,
			[
				'title' => __( 'Rescue', 'stonewright' ),
				'lede'  => self::lede(),
			]
		);
		self::render_content();
		AdminShell::close();
	}

	/**
	 * The Rescue tab of the Changes page: the same content in the frame of that page. The caller has checked the
	 * capability and has not asked the change ledger anything.
	 */
	public static function render_tab(): void {
		AdminShell::open( ChangesPage::SLUG );
		self::render_content();
		AdminShell::close();
	}

	/** Everything between the header and the end of the page. It reads the journal, and the ledger only for a link. */
	private static function render_content(): void {
		self::reset_for_tests();
		// Bring in anything a fatal recorded while the database was unavailable.
		ChangeJournal::sync_from_file();

		// The health probe loads this page to see that wp-admin renders. Nobody reads that response,
		// so it gets no confirmation tokens.
		$guarded = Permissions::is_production_safe() && ! ProbeToken::is_probe_request();

		echo '<div class="sw-ui sw-rescue-page stonewright-rescue-page" data-sw-rescue data-sw-rescue-probe="ok">';
		self::render_result();

		$review = self::requested_review();
		if ( '' !== $review ) {
			self::render_review( $review, $guarded );
			echo '<p class="screen-reader-text" role="status" aria-live="polite" data-sw-rescue-live></p>';
			echo '</div>';
			return;
		}

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

		if ( 'unavailable' === ChangeJournal::storage_status()['mirror'] ) {
			echo '<div class="sw-rescue-notice sw-rescue-notice--warn" role="status"><p>' . esc_html__( 'The journal file could not be written, so a fatal cannot be matched to a change while the database is down. Rollbacks from this page still work. Check that the uploads folder is writable.', 'stonewright' ) . '</p></div>';
		}
		self::render_safe_mode_panel();
		self::render_callouts( $guarded );
		self::render_stats( count( $open ), count( $unconfirmed ), RescueInstaller::summary() );
		self::render_attention( $attention, $guarded, count( $unconfirmed ) );
		self::render_fallback( $recent, $guarded );
		echo '<p class="screen-reader-text" role="status" aria-live="polite" data-sw-rescue-live></p>';
		echo '</div>';
	}

	private static function lede(): string {
		return self::tab_lede();
	}

	/** The line under the title of Rescue, on its own page and on the tab of the Changes page. */
	public static function tab_lede(): string {
		return __( 'Roll back a change that stopped the site from loading, or check that it loads again.', 'stonewright' );
	}

	/** The change a review is asked for, or an empty string. */
	private static function requested_review(): string {
		return isset( $_GET['review'] ) ? self::clean_id( (string) wp_unslash( (string) $_GET['review'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only: a review changes nothing.
	}

	/**
	 * A link to the diff of a change when the change ledger has a row for it. The ledger is read only through its
	 * public API, and a ledger that cannot be read leaves the link out: Rescue must keep working without it.
	 *
	 * @param array<string, mixed> $entry
	 */
	private static function diff_link( array $entry ): string {
		$id = (string) $entry['id'];
		if ( null === self::ledger_row( $id ) ) {
			return '';
		}
		/* translators: %s: short change set id */
		$name = sprintf( __( 'of change set %s', 'stonewright' ), self::short_id( $id ) );
		return Button::render( __( 'View diff', 'stonewright' ), [ 'size' => 'sm', 'href' => ChangesPage::diff_url( $id ), 'context' => $name ] );
	}

	/**
	 * The ledger row of a change, or null when there is none or the ledger cannot be read. A ledger that fails once is
	 * not asked again on this request.
	 *
	 * @return array<string, mixed>|null
	 */
	private static function ledger_row( string $id ): ?array {
		if ( self::$ledger_failed ) {
			return null;
		}
		if ( ! array_key_exists( $id, self::$ledger_rows ) ) {
			try {
				self::$ledger_rows[ $id ] = ChangeLedger::get( $id );
			} catch ( \Throwable $failure ) {
				unset( $failure );
				self::$ledger_failed      = true;
				self::$ledger_rows[ $id ] = null;
			}
		}
		return self::$ledger_rows[ $id ];
	}

	/**
	 * Whether the history keeps this change in a form it can undo: a row that is restorable, of a family with a handler.
	 * Such a change has its diff and its Undo on the History tab; everything else stays here.
	 *
	 * @param array<string, mixed> $entry
	 */
	private static function history_owns( array $entry ): bool {
		$row = self::ledger_row( (string) $entry['id'] );
		if ( null === $row || empty( $row['restorable'] ) ) {
			return false;
		}
		try {
			return null !== RollbackFamilies::handler_for( (string) $row['family'] );
		} catch ( \Throwable $failure ) {
			unset( $failure );
			return false;
		}
	}

	private static function render_safe_mode_panel(): void {
		if ( ! self::safe_mode_available() ) {
			return;
		}
		echo '<section class="sw-rescue-panel" aria-labelledby="sw-rescue-safe-title">';
		echo '<h2 id="sw-rescue-safe-title">' . esc_html__( 'Safe mode', 'stonewright' ) . '</h2>';
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
		self::hidden( 'action', 'stonewright_rescue_safe_mode' );
		self::nonce_input( 'stonewright_rescue_safe_mode' );
		echo self::actions( Button::render( __( 'Open in safe mode', 'stonewright' ), [ 'type' => 'submit', 'attrs' => [ 'aria-describedby' => 'sw-rescue-safe-help' ] ] ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Built by the Ui helpers.
		echo '<p class="sw-rescue-note" id="sw-rescue-safe-help">' . esc_html__( 'Loads WordPress with only Stonewright and a default theme. The link works once.', 'stonewright' ) . '</p>';
		echo '</form>';
		echo '</section>';
	}

	/**
	 * One row of actions: the layer's action group, so every control in it has the shared height and one gap, and the
	 * row wraps on a narrow screen. A form in the row prints no box of its own (see rescue.css), so its button is a
	 * member of the row.
	 *
	 * @param string $buttons Rendered buttons, links and forms built with the Ui helpers.
	 */
	private static function actions( string $buttons, bool $end = false ): string {
		return '<div class="sw-ui-actions' . ( $end ? ' sw-ui-actions--end' : '' ) . '" data-sw-actions>' . $buttons . '</div>';
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
			self::copy_button( $incident, __( 'receipt id', 'stonewright' ) );
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
			echo '<div class="sw-rescue-callout sw-rescue-callout--info" role="note"><p>' . esc_html__( 'Production-safe mode is on. A rollback or a re-check needs a one-time confirmation token. This page issues one for that change set only, and it expires after ten minutes. Typing ROLL BACK in the confirmation confirms a rollback.', 'stonewright' ) . '</p></div>';
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
			echo '<p class="sw-rescue-note">' . esc_html__( 'Not verified means no health check has reported on the change yet. It does not mean the site is broken. Check again, or roll the change back.', 'stonewright' ) . '</p>';
		}
		echo '<table class="sw-rescue-table" data-sw-rescue-table="attention">';
		self::table_head( __( 'Changes that need attention', 'stonewright' ) );
		echo '<tbody>';
		foreach ( $attention as $entry ) {
			self::render_row( $entry, $guarded );
		}
		echo '</tbody></table></section>';
	}

	/**
	 * The recent changes the history cannot undo, each with the way to roll it back when there is one: the journal is
	 * the only record of them, or the only one that can put them back. A change the history can undo is on the History
	 * tab, so it is not listed twice.
	 *
	 * @param list<array<string, mixed>> $recent
	 */
	private static function render_fallback( array $recent, bool $guarded ): void {
		$own      = [];
		$kept_in  = 0;
		foreach ( $recent as $entry ) {
			if ( self::history_owns( $entry ) ) {
				++$kept_in;
				continue;
			}
			$own[] = $entry;
		}

		echo '<section aria-labelledby="sw-rescue-recent-title" data-sw-rescue-fallback>';
		echo '<h2 id="sw-rescue-recent-title">' . esc_html__( 'Recent changes only Rescue keeps', 'stonewright' ) . '</h2>';
		if ( $kept_in > 0 ) {
			/* translators: %d: number of recent changes that are on the History tab */
			echo '<p class="sw-rescue-note">' . esc_html( sprintf( _n( '%d recent change is on the History tab, with its diff and Undo.', '%d recent changes are on the History tab, with their diffs and Undo.', $kept_in, 'stonewright' ), $kept_in ) ) . ' <a href="' . esc_url( ChangesPage::url() ) . '">' . esc_html__( 'Open History', 'stonewright' ) . '</a></p>';
		}
		if ( [] === $own ) {
			echo '<p class="sw-rescue-note">' . esc_html__( 'Every other recent change is in History, or none has been journaled yet.', 'stonewright' ) . '</p></section>';
			return;
		}
		echo '<p class="sw-rescue-note">' . esc_html__( 'History has no record of these changes, or cannot undo them, so they stay here. A change that passed its health check can still be rolled back. The journal keeps the last 50 changes.', 'stonewright' ) . '</p>';
		echo '<table class="sw-rescue-table" data-sw-rescue-table="recent">';
		self::table_head( __( 'Recent changes only Rescue keeps', 'stonewright' ) );
		echo '<tbody>';
		foreach ( $own as $entry ) {
			self::render_row( $entry, $guarded, false );
		}
		echo '</tbody></table></section>';
	}

	private static function table_head( string $caption ): void {
		$headings = [ __( 'Change', 'stonewright' ), __( 'When', 'stonewright' ), __( 'Status', 'stonewright' ), __( 'Actions', 'stonewright' ) ];
		echo '<caption class="screen-reader-text">' . esc_html( $caption ) . '</caption><thead><tr>';
		foreach ( $headings as $heading ) {
			echo '<th scope="col">' . esc_html( $heading ) . '</th>';
		}
		echo '</tr></thead>';
	}

	/**
	 * One change set. The object and what was done come first; the ability, the client, the ids and the codes of the
	 * health check are under Details. Each cell repeats its heading so a row still reads on its own at 400px.
	 *
	 * @param array<string, mixed> $entry
	 * @param bool                 $attention Whether the row is one that needs attention: it shows the health check, the recipe and the agent prompt.
	 */
	private static function render_row( array $entry, bool $guarded, bool $attention = true ): void {
		$id    = (string) $entry['id'];
		$state = (string) $entry['state'];

		echo '<tr data-sw-rescue-row="' . esc_attr( $id ) . '">';
		echo '<th scope="row" data-label="' . esc_attr( __( 'Change', 'stonewright' ) ) . '"><strong class="sw-rescue-object">' . esc_html( self::object_title( $entry ) ) . '</strong>';
		echo '<span class="sw-rescue-sub">' . esc_html( self::summary_line( $entry ) ) . '</span>';
		$origin = self::rollback_origin( $entry );
		if ( '' !== $origin ) {
			echo '<span class="sw-rescue-sub">' . esc_html( $origin ) . '</span>';
		}
		if ( $attention ) {
			$message = self::verification_message( $entry );
			if ( '' !== $message ) {
				echo '<span class="sw-rescue-sub" data-sw-rescue-verification="' . esc_attr( self::verification_kind( is_array( $entry['probe'] ?? null ) ? $entry['probe'] : [] ) ) . '">' . esc_html( $message ) . '</span>';
			}
		}
		self::render_details( $entry, $attention );
		echo '</th>';

		echo '<td data-label="' . esc_attr( __( 'When', 'stonewright' ) ) . '">' . self::time_html( self::when( $entry ), 'j M Y, H:i' ) . '<span class="sw-rescue-sub">' . esc_html( sprintf( /* translators: %s: how long ago, for example "3 hours ago" */ __( 'Changed %s', 'stonewright' ), self::age( (int) $entry['armed_at'] ) ) ) . '</span></td>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- time_html() escapes.
		echo '<td data-label="' . esc_attr( __( 'Status', 'stonewright' ) ) . '"><span class="sw-badge sw-rescue-badge--' . esc_attr( self::badge_kind( $state ) ) . '">' . esc_html( self::state_label( $state ) ) . '</span></td>';
		echo '<td data-label="' . esc_attr( __( 'Actions', 'stonewright' ) ) . '">';
		self::render_actions( $entry, $guarded );
		echo '</td>';
		echo '</tr>';
	}

	/**
	 * What the change was made to, as a person would name it: the title of a post, else the kind of object and its name.
	 *
	 * @param array<string, mixed> $entry
	 */
	private static function object_title( array $entry ): string {
		if ( 'post' === (string) $entry['resource_type'] ) {
			$named = ChangeLabels::resource( [ 'resource_type' => 'post', 'resource_id' => (string) $entry['resource_key'] ] );
			if ( ! $named['code'] && '' !== $named['text'] ) {
				return $named['text'];
			}
		}
		return self::what_changed( $entry );
	}

	/**
	 * What was done to it, in a line: the summary the history recorded when it has one, else the operation in words.
	 *
	 * @param array<string, mixed> $entry
	 */
	private static function summary_line( array $entry ): string {
		$row = self::ledger_row( (string) $entry['id'] );
		if ( null !== $row && '' !== (string) ( $row['summary'] ?? '' ) ) {
			return (string) $row['summary'];
		}
		/* translators: %s: the operation, for example "elementor v3 batch mutate" */
		return sprintf( __( 'Changed by %s', 'stonewright' ), ChangeLabels::ability_words( (string) $entry['ability'] ) );
	}

	/**
	 * The ids, the ability, the client, the health check and the recipe, for whoever needs them.
	 *
	 * @param array<string, mixed> $entry
	 */
	private static function render_details( array $entry, bool $attention ): void {
		$id    = (string) $entry['id'];
		$short = self::short_id( $id );
		$state = (string) $entry['state'];

		echo '<details class="sw-rescue-details"><summary>' . esc_html__( 'Details', 'stonewright' ) . '</summary>';
		echo '<dl class="sw-rescue-kv">';
		/* translators: %s: short change set id */
		self::kv( __( 'Change set', 'stonewright' ), '<code class="sw-rescue-id">' . esc_html( $short ) . '</code> ' . self::copy_button_html( $id, sprintf( __( 'change set id %s', 'stonewright' ), $short ) ) );
		self::kv( __( 'Ability', 'stonewright' ), '<code>' . esc_html( (string) $entry['ability'] ) . '</code>' );
		if ( '' !== (string) $entry['client'] ) {
			self::kv( __( 'Client', 'stonewright' ), '<code>' . esc_html( (string) $entry['client'] ) . '</code>' );
		}
		if ( $attention ) {
			self::kv( __( 'Health check', 'stonewright' ), self::evidence_html( $entry ) );
			self::kv( __( 'Rollback', 'stonewright' ), esc_html( RollbackRecipes::describe( $entry ) ) . self::rollback_note( $entry ) );
		}
		echo '</dl>';
		if ( $attention && in_array( $state, [ 'incident', 'rollback_failed' ], true ) ) {
			self::render_prompt( $entry );
		}
		echo '</details>';
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
		echo self::actions( Button::render( __( 'Copy prompt for your agent', 'stonewright' ), [ 'size' => 'sm', 'attrs' => [ 'hidden' => true, 'data-sw-rescue-copy' => $prompt_id ] ] ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Built by the Ui helpers.
		echo '</details>';
	}

	/**
	 * The row actions in one row, every name saying which change set it acts on. A note about an action that is
	 * missing goes under the row, so it never moves a button.
	 *
	 * @param array<string, mixed> $entry
	 */
	private static function render_actions( array $entry, bool $guarded ): void {
		$id        = (string) $entry['id'];
		$short     = self::short_id( $id );
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

		$buttons = '';
		if ( $available ) {
			$buttons .= Button::render(
				$retry ? __( 'Try again', 'stonewright' ) : __( 'Roll back', 'stonewright' ),
				[
					'variant' => 'danger',
					'size'    => 'sm',
					'href'    => self::review_url( $id ),
					/* translators: %s: short change set id */
					'context' => sprintf( $retry ? __( 'to roll back change set %s', 'stonewright' ) : __( 'change set %s', 'stonewright' ), $short ),
					'attrs'   => [ 'data-sw-rescue-open' => 'sw-rescue-dlg-' . md5( $id ) ],
				]
			);
		}
		if ( 'verified' !== $state ) {
			$buttons .= self::recheck_form( $id, $short, $guarded );
		}
		$buttons .= self::diff_link( $entry );

		echo self::actions( $buttons ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Built by the Ui helpers.
		if ( ! $available ) {
			echo '<p class="sw-rescue-note">' . esc_html( 'verified' === $state ? __( 'No automatic rollback is available for this change.', 'stonewright' ) : __( 'No automatic rollback is available for this change. Undo it by hand, then check the site again.', 'stonewright' ) ) . '</p>';
		}
		if ( $available ) {
			self::render_dialog( $entry, $guarded );
		}
	}

	private static function recheck_form( string $id, string $short, bool $guarded ): string {
		ob_start();
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" data-sw-rescue-form>';
		self::hidden( 'action', 'stonewright_rescue_recheck' );
		self::hidden( 'incident_id', $id );
		self::nonce_input( 'stonewright_rescue_recheck' );
		if ( $guarded ) {
			self::hidden( 'confirmation_token', ConfirmationToken::issue( 'stonewright/rescue-rollback', [ 'incident_id' => $id, 'action' => 'recheck' ], self::TOKEN_TTL ) );
		}
		/* translators: %s: short change set id */
		echo Button::render( __( 'Check again', 'stonewright' ), [ 'type' => 'submit', 'size' => 'sm', 'context' => sprintf( __( 'change set %s', 'stonewright' ), $short ), 'attrs' => [ 'data-sw-rescue-submit' => true, 'data-sw-busy-label' => __( 'Checking the site...', 'stonewright' ) ] ] ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Built by the Ui helpers.
		echo '</form>';
		return (string) ob_get_clean();
	}

	/**
	 * The dialog the script shows in place of the review. It holds the same form, so the page works the same either way,
	 * and a browser without `showModal` or a script that does not load follows the Roll back link to the review.
	 *
	 * @param array<string, mixed> $entry
	 */
	private static function render_dialog( array $entry, bool $guarded ): void {
		$id     = (string) $entry['id'];
		$dialog = 'sw-rescue-dlg-' . md5( $id );

		echo '<dialog class="sw-rescue-dialog" id="' . esc_attr( $dialog ) . '" aria-labelledby="' . esc_attr( $dialog ) . '-title" data-sw-rescue-dialog>';
		self::render_confirmation( $entry, $guarded, $dialog, true );
		echo '</dialog>';
	}

	/**
	 * The review of one rollback, at its own address: what it does, the form, and a Cancel that is a link.
	 */
	private static function render_review( string $id, bool $guarded ): void {
		$entry = ChangeJournal::get( $id );
		echo '<section class="sw-rescue-review" aria-labelledby="sw-rescue-review-title" data-sw-rescue-review>';
		if ( null === $entry || ! in_array( (string) $entry['state'], RescueRollback::ROLLBACK_STATES, true ) || ! RollbackRecipes::available( $entry ) || ChangeJournal::is_claimed( $entry ) ) {
			echo '<h2 id="sw-rescue-review-title">' . esc_html__( 'Nothing to roll back here', 'stonewright' ) . '</h2>';
			echo '<p>' . esc_html__( 'This change is not open for a rollback: it is already rolled back, a rollback of it is running, or no automatic rollback is available for it.', 'stonewright' ) . '</p>';
			echo self::actions( Button::render( __( 'Back to Rescue', 'stonewright' ), [ 'href' => self::url() ] ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Built by the Ui helpers.
			echo '</section>';
			return;
		}
		self::render_confirmation( $entry, $guarded, 'sw-rescue-review', false );
		echo '</section>';
	}

	/**
	 * The words and the form of one rollback's confirmation. $prefix names its elements, so a page of several dialogs has
	 * no two ids alike.
	 *
	 * @param array<string, mixed> $entry
	 */
	private static function render_confirmation( array $entry, bool $guarded, string $prefix, bool $dialog ): void {
		$id    = (string) $entry['id'];
		$short = self::short_id( $id );
		$hash  = md5( $id );
		$state = (string) $entry['state'];
		$plan  = $dialog ? null : self::plan_for( $id );

		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" id="sw-rescue-form-' . esc_attr( $hash ) . '" data-sw-rescue-form>';
		/* translators: %s: short change set id */
		echo '<h2 id="' . esc_attr( $prefix ) . '-title">' . esc_html( sprintf( __( 'Roll back change set %s?', 'stonewright' ), $short ) ) . '</h2>';
		echo '<dl class="sw-rescue-kv">';
		self::kv( __( 'What changed', 'stonewright' ), esc_html( self::what_changed( $entry ) ) );
		self::kv( __( 'Changed by', 'stonewright' ), '<code>' . esc_html( (string) $entry['ability'] ) . '</code>' );
		self::kv( __( 'Restores', 'stonewright' ), esc_html( RollbackRecipes::describe( $entry ) ) );
		echo '</dl>';
		echo '<p>' . esc_html__( 'This puts the item back as it was before the change (anything saved to it since is overwritten), then checks that the site loads.', 'stonewright' ) . '</p>';
		if ( 'verified' === $state ) {
			echo '<p>' . esc_html__( 'This change passed its health check. Rolling it back undoes a change that worked. The current state is saved first; if the site stops loading after the undo, it is put back.', 'stonewright' ) . '</p>';
		}
		self::render_newer_warning( $entry );
		self::hidden( 'action', 'stonewright_rescue_rollback' );
		self::hidden( 'incident_id', $id );
		self::nonce_input( 'stonewright_rescue_rollback' );
		if ( null !== $plan ) {
			self::hidden( 'expected_current_sha256', substr( (string) $plan['current_sha256'], 0, ChangeRollback::MIN_HASH_PREFIX ) );
		}
		if ( self::needs_drift_acknowledgement( $entry, $plan ) ) {
			echo FormField::checkbox( __( 'I understand that this overwrites changes made since.', 'stonewright' ), 'force_drift', [ 'id' => $prefix . '-drift', 'required' => true ] ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Built by the Ui helpers.
		}
		if ( $guarded ) {
			self::hidden( 'confirmation_token', ConfirmationToken::issue( 'stonewright/rescue-rollback', [ 'incident_id' => $id ], self::TOKEN_TTL ) );
			$phrase_id = 'sw-rescue-phrase-' . $hash;
			echo '<p class="sw-rescue-field"><label for="' . esc_attr( $phrase_id ) . '">' . esc_html__( 'Type ROLL BACK to confirm', 'stonewright' ) . '</label>';
			echo '<input type="text" class="sw-ui-input" id="' . esc_attr( $phrase_id ) . '" name="confirm_phrase" value="" autocomplete="off" spellcheck="false" data-sw-rescue-phrase="' . esc_attr( self::PHRASE ) . '" /></p>';
		}
		echo '<p class="sw-rescue-busy" role="status" data-sw-rescue-status></p>';
		$buttons = Button::render( __( 'Cancel', 'stonewright' ), [ 'href' => self::url(), 'attrs' => array_merge( [ 'data-sw-rescue-cancel' => true ], $dialog ? [ 'autofocus' => true ] : [] ) ] )
			. Button::render( __( 'Roll back', 'stonewright' ), [ 'type' => 'submit', 'variant' => 'danger-solid', 'attrs' => [ 'data-sw-rescue-submit' => true, 'data-sw-busy-label' => __( 'Rolling back and checking the site...', 'stonewright' ) ] ] );
		echo self::actions( $buttons, true ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Built by the Ui helpers.
		echo '</form>';
	}

	/**
	 * The plan the change ledger has for this change, when it has one: the hash of the live state the person is shown,
	 * which the post is checked against. At most a few per page; none when the ledger cannot say.
	 *
	 * @return array<string, mixed>|null
	 */
	private static function plan_for( string $id ): ?array {
		if ( self::$plans_left < 1 || null === self::ledger_row( $id ) ) {
			return null;
		}
		--self::$plans_left;
		try {
			$plan = ChangeRollback::plan( $id );
		} catch ( \Throwable $failure ) {
			unset( $failure );
			return null;
		}
		return $plan instanceof \WP_Error || ! isset( $plan['current_sha256'] ) ? null : $plan;
	}

	/**
	 * Whether a rollback of this change needs the person to say they know it overwrites later edits. A change that is
	 * open because a fatal was recorded, or because its own rollback failed, is recovery and asks for nothing more; a
	 * change that was only never verified, or that passed, is an ordinary rollback and does.
	 *
	 * @param array<string, mixed>      $entry
	 * @param array<string, mixed>|null $plan
	 */
	private static function needs_drift_acknowledgement( array $entry, ?array $plan ): bool {
		if ( ! in_array( (string) $entry['state'], [ 'armed', 'verified' ], true ) ) {
			return false;
		}
		return [] !== ChangeJournal::newer_changes( $entry ) || ( null !== $plan && ! empty( $plan['requires_force'] ) );
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
	private static function copy_button( string $value, string $object ): void {
		echo self::copy_button_html( $value, $object ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped in copy_button_html().
	}

	/** @param string $object What is copied, for the name of the button: "change set id 1a2b3c4d". */
	private static function copy_button_html( string $value, string $object ): string {
		return Button::render( __( 'Copy', 'stonewright' ), [ 'variant' => 'tertiary', 'size' => 'xs', 'context' => $object, 'attrs' => [ 'hidden' => true, 'data-sw-rescue-copy-text' => $value ] ] );
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
		$line = [] === $parts ? esc_html( (string) ( $probe['status'] ?? 'unavailable' ) ) : implode( '; ', $parts );
		if ( '' !== (string) ( $probe['unavailable_reason'] ?? '' ) ) {
			$line .= ' <code>' . esc_html( (string) $probe['unavailable_reason'] ) . '</code>';
		}
		return $line;
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
	// What a health check said.
	// -----------------------------------------------------------------------

	/**
	 * What a probe says, in one of five words, so the page can say three different things where it used to say one:
	 *
	 * - `healthy`     the checks passed;
	 * - `site_failed` the checks found the site failing;
	 * - `not_started` the check could not start (no token could be issued), so nothing was asked of the site;
	 * - `unreachable` the check started and got no verdict (no answer, a redirect, a login screen, an unexpected reply);
	 * - `unknown`     no result was recorded.
	 *
	 * Only `site_failed` says anything about the site. The others say something about the check.
	 *
	 * @param array<string, mixed> $probe A recorded probe, or an empty array.
	 */
	public static function verification_kind( array $probe ): string {
		$status = (string) ( $probe['status'] ?? '' );
		if ( 'passed' === $status ) {
			return 'healthy';
		}
		if ( 'failed' === $status ) {
			return 'site_failed';
		}
		$reasons = [ (string) ( $probe['unavailable_reason'] ?? '' ) ];
		foreach ( is_array( $probe['legs'] ?? null ) ? $probe['legs'] : [] as $leg ) {
			if ( is_array( $leg ) ) {
				if ( 'failed' === (string) ( $leg['status'] ?? '' ) ) {
					return 'site_failed';
				}
				$reasons[] = (string) ( $leg['reason'] ?? '' );
			}
		}
		$kinds = array_map( [ self::class, 'reason_kind' ], array_filter( $reasons, static fn ( string $reason ): bool => '' !== $reason ) );
		if ( in_array( 'site_failed', $kinds, true ) ) {
			return 'site_failed';
		}
		if ( in_array( 'not_started', $kinds, true ) ) {
			return 'not_started';
		}
		return [] === $kinds ? 'unknown' : 'unreachable';
	}

	/**
	 * The one place that says which reason code of a probe is which kind. A code that is not listed is a check that got
	 * no verdict, never a failed site: a new code of the probe is added here, and until it is, it reads as `unreachable`.
	 */
	private static function reason_kind( string $reason ): string {
		return match ( $reason ) {
			'token_unavailable'                               => 'not_started',
			'critical_error_page', 'fatal_marker', 'http_500' => 'site_failed',
			default                                           => 'unreachable',
		};
	}

	/**
	 * A sentence under the change that says what the health check did, in words that never call the site broken or
	 * blame the host unless the check found the site failing. The codes are in Details.
	 *
	 * @param array<string, mixed> $entry
	 */
	private static function verification_message( array $entry ): string {
		$state = (string) $entry['state'];
		$kind  = self::verification_kind( is_array( $entry['probe'] ?? null ) ? $entry['probe'] : [] );
		if ( 'incident' === $state ) {
			return __( 'A fatal error was recorded after this change.', 'stonewright' );
		}
		if ( 'rollback_failed' === $state ) {
			return __( 'The site failed after this change and the automatic rollback did not finish.', 'stonewright' );
		}
		return match ( $kind ) {
			'not_started' => __( 'The health check could not start, so this change has not been checked. That says nothing about the site.', 'stonewright' ),
			'unreachable' => __( 'The health check got no answer it could judge, so the result is unknown. The site may be fine.', 'stonewright' ),
			'site_failed' => __( 'The health check found the site failing after this change.', 'stonewright' ),
			default       => __( 'No health check has reported on this change yet. That says nothing about the site.', 'stonewright' ),
		};
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
		$id = self::guarded_incident_id( 'stonewright_rescue_rollback' );
		// The page asks for the phrase in production-safe mode; the script that holds the button back is not what enforces it.
		// It is compared before the token is looked at, so a refusal for a missing phrase does not spend the token.
		if ( Permissions::is_production_safe() && self::PHRASE !== self::posted( 'confirm_phrase' ) ) {
			return [ 'code' => 'confirmation_phrase', 'incident_id' => $id ];
		}
		$refused = self::confirmation_refusal( $id, [ 'incident_id' => $id ] );
		if ( null !== $refused ) {
			return [ 'code' => $refused, 'incident_id' => $id ];
		}

		$options = [ 'by' => 'admin-page', 'user_id' => (int) get_current_user_id(), 'human_approved' => true ];
		$entry   = '' === $id ? null : ChangeJournal::get( $id );
		if ( null !== $entry && in_array( (string) $entry['state'], RescueRollback::ROLLBACK_STATES, true ) ) {
			$force    = '1' === self::posted( 'force_drift' );
			$expected = self::posted( 'expected_current_sha256' );
			$plan     = '' !== $expected || self::needs_drift_acknowledgement( $entry, null ) ? self::fresh_plan( $id ) : null;
			// The person saw one state of the item and the item is in another one now: nothing runs.
			if ( '' !== $expected && null !== $plan && 0 !== strncmp( (string) $plan['current_sha256'], $expected, strlen( $expected ) ) ) {
				return [ 'code' => 'changed_since_preview', 'incident_id' => $id ];
			}
			// An ordinary rollback over later edits needs the box on the review ticked.
			if ( ! $force && self::needs_drift_acknowledgement( $entry, $plan ) ) {
				return [ 'code' => 'drift', 'incident_id' => $id ];
			}
			$options['force_drift']             = $force;
			$options['expected_current_sha256'] = $expected;
		}

		// The administrator who pressed the button is the approval that undoing a verified change to code needs.
		$result = RescueRollback::run( $id, $options );
		RescueRollback::audit_outcome( $id, $result, 'admin-page', 'rollback' );

		if ( $result instanceof \WP_Error ) {
			return [ 'code' => self::outcome_for_error( $result ), 'incident_id' => $id ];
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

	/** The plan of the change ledger for a post, without the budget of the page. @return array<string, mixed>|null */
	private static function fresh_plan( string $id ): ?array {
		self::$plans_left = max( self::$plans_left, 1 );
		return self::plan_for( $id );
	}

	/**
	 * The outcome code of a refused or failed rollback. One place, so the codes a newer engine adds are one line each.
	 */
	private static function outcome_for_error( \WP_Error $error ): string {
		$data = is_array( $error->get_error_data() ) ? $error->get_error_data() : [];
		return match ( $error->get_error_code() ) {
			'stonewright_rescue_incident_not_found'  => 'not_found',
			'stonewright_rescue_not_open'            => 'not_open',
			'stonewright_rescue_no_recipe'           => 'no_recipe',
			'stonewright_rescue_in_progress'         => 'in_progress',
			'stonewright_rescue_undo_capture_failed' => 'undo_refused',
			'stonewright_rescue_undo_reverted'       => 'healthy' === (string) ( $data['site_status'] ?? '' ) ? 'undo_reverted' : 'undo_reverted_still_failing',
			'stonewright_change_drift'               => 'drift',
			'stonewright_change_changed_since_preview' => 'changed_since_preview',
			default                                  => 'rollback_failed',
		};
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
		if ( ! empty( $result['resolved'] ) ) {
			return [ 'code' => 'rechecked_resolved', 'incident_id' => $id ];
		}
		return [
			'code'        => match ( self::verification_kind( is_array( $result['probe'] ?? null ) ? $result['probe'] : [] ) ) {
				'site_failed' => 'rechecked_site_failed',
				'not_started' => 'rechecked_not_started',
				'unreachable' => 'rechecked_unreachable',
				default       => 'rechecked_open',
			},
			'incident_id' => $id,
		];
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
	 * Back to the page with the outcome.
	 *
	 * @param array{code:string,incident_id:string} $outcome
	 */
	private static function redirect_with( array $outcome ): void {
		wp_safe_redirect( self::outcome_url( $outcome ) );
		exit;
	}

	/**
	 * Where an outcome is shown. A refusal that ran nothing and can be fixed goes back to the review, so the missing
	 * phrase or the unticked box is one step away.
	 *
	 * @param array{code:string,incident_id:string} $outcome
	 */
	public static function outcome_url( array $outcome ): string {
		$args = [
			'page'     => self::SLUG,
			'rescue'   => $outcome['code'],
			'incident' => $outcome['incident_id'],
		];
		if ( in_array( $outcome['code'], self::RETRYABLE, true ) && '' !== $outcome['incident_id'] ) {
			$args['review'] = $outcome['incident_id'];
		}
		return add_query_arg( $args, admin_url( 'admin.php' ) );
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

	/** A posted value, trimmed. Read only after guard_request(). */
	private static function posted( string $key ): string {
		return isset( $_POST[ $key ] ) && is_scalar( $_POST[ $key ] ) ? trim( sanitize_text_field( wp_unslash( (string) $_POST[ $key ] ) ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Read after the nonce check.
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
			'rolled_back_unverified'    => [ 'warn', __( 'The change was rolled back. The health check afterwards got no answer it could judge, so recovery is not confirmed. Check again, or look at the site.', 'stonewright' ), true ],
			'rollback_failed'           => [ 'danger', __( 'The rollback did not complete. Try safe mode, or undo the change by hand and check the site again.', 'stonewright' ), true ],
			'undo_reverted'             => [ 'warn', __( 'The undo would have broken the site, so it was put back. The change is still in effect and the site loads.', 'stonewright' ), true ],
			'undo_reverted_still_failing' => [ 'danger', __( 'The undo made the site stop loading and was put back, but the site still fails to load. The fault may not come from the undo. Try safe mode, or check the site.', 'stonewright' ), true ],
			'undo_refused'              => [ 'warn', __( 'The undo was refused: the current state could not be saved first, so there would be no way back. Nothing was changed.', 'stonewright' ), true ],
			'rechecked_resolved'        => [ 'ok', __( 'The site loads. The incident is closed.', 'stonewright' ), true ],
			'rechecked_open'            => [ 'warn', __( 'The incident stays open: the health check did not confirm that the site loads.', 'stonewright' ), true ],
			'rechecked_site_failed'     => [ 'danger', __( 'The health check found the site failing, so the incident stays open. Roll the change back, or try safe mode.', 'stonewright' ), true ],
			'rechecked_not_started'     => [ 'warn', __( 'The health check could not start, so nothing was checked and the incident stays open. That says nothing about the site. Check again in a moment.', 'stonewright' ), true ],
			'rechecked_unreachable'     => [ 'warn', __( 'The health check got no answer it could judge, so the incident stays open and the result is unknown. The site may be fine. Check again, or look at the site.', 'stonewright' ), true ],
			'confirmation_required'     => [ 'danger', __( 'Production-safe mode needs the confirmation on the form. Nothing was changed. Try again from the review below.', 'stonewright' ), false ],
			'confirmation_invalid'      => [ 'danger', __( 'The confirmation expired or does not match. Nothing was changed. Try again from the review below.', 'stonewright' ), false ],
			'confirmation_phrase'       => [ 'warn', __( 'Production-safe mode asks you to type ROLL BACK to confirm. Nothing was changed. Type it in the box and press the button again.', 'stonewright' ), false ],
			'drift'                     => [ 'warn', __( 'The item has changed since the recorded change. Nothing was changed. Review it below, and tick the box to overwrite those edits.', 'stonewright' ), false ],
			'changed_since_preview'     => [ 'warn', __( 'The item changed while the review was open. Nothing was changed. Review it again below.', 'stonewright' ), false ],
			'not_found'                 => [ 'danger', __( 'No incident has that id.', 'stonewright' ), true ],
			'not_open'                  => [ 'info', __( 'That change is already verified or rolled back.', 'stonewright' ), true ],
			'no_recipe'                 => [ 'danger', __( 'No automatic rollback is available for this change.', 'stonewright' ), true ],
			'in_progress'               => [ 'info', __( 'A rollback of this change set is already running. Reload this page in a minute.', 'stonewright' ), true ],
			'safe_mode_unavailable'     => [ 'warn', __( 'Safe mode is not available on this site.', 'stonewright' ), false ],
		];
	}
}
