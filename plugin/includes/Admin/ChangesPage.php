<?php
/**
 * Stonewright > Changes admin page.
 * SPDX-License-Identifier: GPL-2.0-or-later
 *
 * @package Stonewright
 */

declare( strict_types=1 );

namespace Stonewright\WpMcp\Admin;

use Stonewright\WpMcp\Admin\Ui\Badge;
use Stonewright\WpMcp\Admin\Ui\Button;
use Stonewright\WpMcp\Admin\Ui\EmptyState;
use Stonewright\WpMcp\Admin\Ui\Html;
use Stonewright\WpMcp\Admin\Ui\Notice;
use Stonewright\WpMcp\Admin\Ui\Scope;
use Stonewright\WpMcp\Admin\Ui\Table;
use Stonewright\WpMcp\Admin\Ui\UtcTime;
use Stonewright\WpMcp\Security\ChangeLedger;

/**
 * Lists the change ledger, newest first, and shows one change as a diff.
 *
 * Read-only: the page has no form that posts and registers no admin-post or AJAX action. The filters are a GET
 * form, each row's "View diff" is a link, and the diff is rendered on the server for the one change the address
 * names (`change`), so no request computes or prints more than one diff and no script fetches anything. Every
 * request needs manage_options, checked before the ledger is read. The page reads the ledger through its public
 * API only and prints diffs through ChangeDiff and DiffView, which mask secrets and never print an image.
 *
 * Query arguments: the filters (family, resource, ability, user, from, to, status, restorable), `paged`,
 * `change` (a change id) and `view` (diff, details or history).
 */
final class ChangesPage {

	public const SLUG       = 'stonewright-changes';
	public const CAPABILITY = 'manage_options';
	public const PER_PAGE   = 25;

	/** Status filter value => the ledger statuses it matches. */
	private const STATUS_GROUPS = [
		'verified'  => [ 'verified' ],
		'rolled_back' => [ 'rolled_back', 'rolled_back_by' ],
		'incident'  => [ 'incident', 'rollback_failed' ],
		'failed'    => [ 'failed' ],
		'unchecked' => [ 'armed', 'probe_unavailable' ],
	];

	public static function register(): void {
		add_action( 'init', [ self::class, 'add_to_menu_registry' ] );
		add_action( 'admin_menu', [ self::class, 'add_submenu' ] );
		add_action( 'admin_enqueue_scripts', [ self::class, 'enqueue' ] );
	}

	public static function add_submenu(): void {
		add_submenu_page(
			ConfigurationPage::SLUG,
			__( 'Changes', 'stonewright' ),
			__( 'Changes', 'stonewright' ),
			self::CAPABILITY,
			self::SLUG,
			[ self::class, 'render' ]
		);
	}

	/** The tab of the Activity hub. It is registered on `init`, where labels can be translated; render() makes sure it exists too. */
	public static function add_to_menu_registry(): void {
		MenuRegistry::add(
			self::SLUG,
			__( 'Changes', 'stonewright' ),
			'activity',
			[
				'order' => 40,
				'beta'  => true,
				'lede'  => self::lede(),
			]
		);
	}

	private static function lede(): string {
		return __( 'Browse the changes Stonewright made, see what each one changed and follow its rollbacks.', 'stonewright' );
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
		wp_enqueue_script( 'stonewright-admin-changes', $base . 'assets/admin/pages/changes.js', [ 'stonewright-ui' ], $version, true );
	}

	// -----------------------------------------------------------------------------------------------
	// Addresses, for the page itself and for the pages that link to it
	// -----------------------------------------------------------------------------------------------

	/**
	 * An address on this page. Empty values are left out.
	 *
	 * @param array<string, string|int> $args
	 */
	public static function url( array $args = [] ): string {
		$query = [ 'page' => self::SLUG ];
		foreach ( $args as $key => $value ) {
			if ( '' !== (string) $value && 0 !== $value ) {
				$query[ $key ] = (string) $value;
			}
		}

		return add_query_arg( $query, admin_url( 'admin.php' ) );
	}

	/** The address that opens the diff of one change. Rescue links to it. */
	public static function diff_url( string $change_id ): string {
		return self::url( [ 'change' => $change_id ] );
	}

	// -----------------------------------------------------------------------------------------------
	// Rendering
	// -----------------------------------------------------------------------------------------------

	public static function render(): void {
		if ( ! current_user_can( self::CAPABILITY ) ) {
			wp_die( esc_html__( 'You do not have permission to view Stonewright changes.', 'stonewright' ), '', [ 'response' => 403 ] );
		}
		self::add_to_menu_registry();

		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- Read-only GET filters and one change id; the page changes nothing.
		$source = $_GET;
		// phpcs:enable
		$filters = self::read_filters( $source );
		$page    = isset( $source['paged'] ) && is_scalar( $source['paged'] ) ? max( 1, (int) $source['paged'] ) : 1;
		$list    = ChangeLedger::list( $filters['ledger'], self::PER_PAGE, $page );
		if ( [] === $list['items'] && $list['total'] > 0 && $page > 1 ) {
			$page = max( 1, (int) $list['pages'] );
			$list = ChangeLedger::list( $filters['ledger'], self::PER_PAGE, $page );
		}

		$carry = $filters['form'];
		if ( $page > 1 ) {
			$carry['paged'] = (string) $page;
		}

		$html  = '';
		$panel = '';
		$asked = isset( $source['change'] ) && is_scalar( $source['change'] ) ? trim( (string) wp_unslash( (string) $source['change'] ) ) : '';
		if ( '' !== $asked ) {
			$row = ChangeLedger::get( $asked );
			if ( null === $row ) {
				$html .= Notice::render( 'warn', __( 'That change is not in the history', 'stonewright' ), __( 'Its record may have been removed by retention, or the address is not a change id.', 'stonewright' ) );
			} else {
				$view  = isset( $source['view'] ) && is_scalar( $source['view'] ) ? sanitize_key( (string) wp_unslash( (string) $source['view'] ) ) : 'diff';
				$panel = ChangeDetail::render( $row, $view, $carry );
			}
		}

		$html .= self::filters_html( $filters['form'], $list, $page );
		$html .= self::timeline_html( $list, $filters, $carry, $page );
		$html .= $panel;

		AdminShell::open( self::SLUG, [ 'actions' => self::header_actions() ] );
		echo Scope::wrap( $html, [ 'page' => true, 'class' => 'sw-changes-page' ] ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Markup built by the Ui helpers, which escape every value.
		AdminShell::close();
	}

	/** Rescue is the page for incidents and safe mode; Changes is for history. */
	private static function header_actions(): string {
		return Button::render(
			__( 'Rescue', 'stonewright' ),
			[
				'href'    => admin_url( 'admin.php?page=' . RescuePage::SLUG ),
				'context' => __( 'for incidents and safe mode', 'stonewright' ),
			]
		);
	}

	// -----------------------------------------------------------------------------------------------
	// Filters
	// -----------------------------------------------------------------------------------------------

	/**
	 * What the address asks for. "form" holds the values as the form shows them (only those that were understood);
	 * "ledger" holds the filters of ChangeLedger::list(). A value that is not understood is dropped, never widened.
	 *
	 * @param array<string, mixed> $source
	 * @return array{form: array<string, string>, ledger: array<string, mixed>}
	 */
	private static function read_filters( array $source ): array {
		$form   = [];
		$ledger = [];
		$text   = static function ( string $key, int $max ) use ( $source ): string {
			if ( ! isset( $source[ $key ] ) || ! is_scalar( $source[ $key ] ) ) {
				return '';
			}
			$value = trim( (string) preg_replace( '/[\x00-\x1F\x7F]+/', ' ', (string) wp_unslash( (string) $source[ $key ] ) ) );

			return function_exists( 'mb_substr' ) ? mb_substr( $value, 0, $max ) : substr( $value, 0, $max );
		};

		$family = $text( 'family', 40 );
		if ( array_key_exists( $family, ChangeLabels::labels_by_family() ) ) {
			$form['family']   = $family;
			$ledger['family'] = $family;
		}

		$resource = $text( 'resource', 200 );
		if ( '' !== $resource ) {
			$form['resource']      = $resource;
			$ledger['resource_id'] = $resource;
		}

		$ability = strtolower( $text( 'ability', 120 ) );
		if ( '' !== $ability ) {
			$form['ability']      = $ability;
			$ledger['ability']    = str_contains( $ability, '/' ) ? $ability : 'stonewright/' . $ability;
		}

		$user = $text( 'user', 60 );
		if ( '' !== $user && '0' !== $user ) {
			$form['user'] = $user;
			$found        = ctype_digit( $user ) ? (int) $user : 0;
			if ( 0 === $found ) {
				$account = get_user_by( 'login', $user );
				$found   = is_object( $account ) ? (int) $account->ID : 0;
			}
			// An account that does not exist matches no change; it must never mean "any user".
			$ledger['actor'] = $found > 0 ? $found : -1;
		}

		foreach ( [ 'from' => 'since', 'to' => 'until' ] as $name => $key ) {
			$day = $text( $name, 10 );
			if ( 1 === preg_match( '/^(\d{4})-(\d{2})-(\d{2})$/D', $day, $parts ) && checkdate( (int) $parts[2], (int) $parts[3], (int) $parts[1] ) ) {
				$form[ $name ]  = $day;
				$ledger[ $key ] = $day;
			}
		}

		$status = $text( 'status', 20 );
		if ( isset( self::STATUS_GROUPS[ $status ] ) ) {
			$form['status']   = $status;
			$ledger['status'] = self::STATUS_GROUPS[ $status ];
		}

		if ( '1' === $text( 'restorable', 2 ) ) {
			$form['restorable']   = '1';
			$ledger['restorable'] = true;
		}

		return [ 'form' => $form, 'ledger' => $ledger ];
	}

	/**
	 * @param array<string, string>                                                        $form
	 * @param array{items:list<array<string,mixed>>,total:int,page:int,per_page:int,pages:int} $list
	 */
	private static function filters_html( array $form, array $list, int $page ): string {
		$family_options = [ '' => __( 'All families', 'stonewright' ) ] + ChangeLabels::labels_by_family();
		$status_options = [
			''            => __( 'All statuses', 'stonewright' ),
			'verified'    => __( 'Verified', 'stonewright' ),
			'rolled_back' => __( 'Rolled back', 'stonewright' ),
			'incident'    => __( 'Incident', 'stonewright' ),
			'failed'      => __( 'Failed', 'stonewright' ),
			'unchecked'   => __( 'Not verified', 'stonewright' ),
		];
		$exact          = __( 'Exact', 'stonewright' );

		$primary = self::field( 'family', __( 'Family', 'stonewright' ), self::select( 'family', $family_options, $form['family'] ?? '' ), $exact, 'md' )
			. self::field( 'resource', __( 'Resource', 'stonewright' ), self::input( 'resource', 'search', $form['resource'] ?? '' ), __( 'Exact: a post ID, an option name or a file path', 'stonewright' ), 'md' )
			. self::field( 'ability', __( 'Ability', 'stonewright' ), self::input( 'ability', 'search', $form['ability'] ?? '' ), __( 'Exact; the stonewright/ prefix is optional', 'stonewright' ), 'md' )
			. self::field( 'user', __( 'User', 'stonewright' ), self::input( 'user', 'search', $form['user'] ?? '' ), __( 'Exact: a user ID or login', 'stonewright' ), 'sm' )
			. self::field( 'from', __( 'From', 'stonewright' ), self::input( 'from', 'date', $form['from'] ?? '' ), __( 'Whole day, UTC', 'stonewright' ), 'sm' )
			. self::field( 'to', __( 'To', 'stonewright' ), self::input( 'to', 'date', $form['to'] ?? '' ), __( 'Whole day, UTC', 'stonewright' ), 'sm' )
			. self::field( 'status', __( 'Status', 'stonewright' ), self::select( 'status', $status_options, $form['status'] ?? '' ), $exact, 'sm' );

		$restorable = Html::element(
			'label',
			[ 'class' => 'sw-changes-check', 'for' => 'sw-changes-f-restorable' ],
			Html::void( 'input', [ 'type' => 'checkbox', 'id' => 'sw-changes-f-restorable', 'name' => 'restorable', 'value' => '1', 'checked' => '1' === ( $form['restorable'] ?? '' ) ] )
			. Html::text( __( 'Restorable only', 'stonewright' ) )
		);

		$pages = max( 1, (int) $list['pages'] );
		$meta  = sprintf(
			/* translators: 1: current page, 2: number of pages, 3: number of changes with the word */
			__( 'Page %1$d of %2$d · %3$s', 'stonewright' ),
			$page,
			$pages,
			sprintf( /* translators: %d: number of changes */ _n( '%d change', '%d changes', $list['total'], 'stonewright' ), $list['total'] )
		);

		$buttons = Button::group(
			[
				Button::render( __( 'Filter', 'stonewright' ), [ 'variant' => 'primary', 'type' => 'submit' ] ),
				Button::render( __( 'Reset filters', 'stonewright' ), [ 'variant' => 'tertiary', 'href' => self::url() ] ),
			]
		);

		return Html::element(
			'form',
			[ 'class' => 'sw-ui-toolbar sw-changes-filters', 'method' => 'get', 'action' => admin_url( 'admin.php' ), 'role' => 'search', 'aria-label' => __( 'Filter the changes', 'stonewright' ) ],
			Html::void( 'input', [ 'type' => 'hidden', 'name' => 'page', 'value' => self::SLUG ] )
			. Html::element( 'div', [ 'class' => 'sw-changes-filters__row' ], $primary . $restorable . $buttons )
			. Html::element( 'p', [ 'class' => 'sw-ui-toolbar__meta', 'role' => 'status' ], Html::text( $meta ) )
		);
	}

	/** One labelled control with the rule it filters by under it. */
	private static function field( string $name, string $label, string $control, string $rule, string $size ): string {
		$id = 'sw-changes-f-' . $name;

		return Html::element(
			'div',
			[ 'class' => 'sw-ui-field sw-ui-field--' . $size ],
			Html::element( 'label', [ 'class' => 'sw-ui-field__label', 'for' => $id ], Html::text( $label ) )
			. $control
			. Html::element( 'span', [ 'class' => 'sw-ui-field__help', 'id' => $id . '-rule' ], Html::text( $rule ) )
		);
	}

	private static function input( string $name, string $type, string $value ): string {
		return Html::void( 'input', [ 'class' => 'sw-ui-input', 'type' => $type, 'id' => 'sw-changes-f-' . $name, 'name' => $name, 'value' => $value, 'aria-describedby' => 'sw-changes-f-' . $name . '-rule' ] );
	}

	/** @param array<string, string> $options */
	private static function select( string $name, array $options, string $current ): string {
		$inner = '';
		foreach ( $options as $value => $text ) {
			$inner .= Html::element( 'option', [ 'value' => (string) $value, 'selected' => (string) $value === $current ], Html::text( $text ) );
		}

		return Html::element( 'select', [ 'class' => 'sw-ui-select', 'id' => 'sw-changes-f-' . $name, 'name' => $name, 'aria-describedby' => 'sw-changes-f-' . $name . '-rule' ], $inner );
	}

	// -----------------------------------------------------------------------------------------------
	// Timeline
	// -----------------------------------------------------------------------------------------------

	/**
	 * @param array{items:list<array<string,mixed>>,total:int,page:int,per_page:int,pages:int} $list
	 * @param array{form: array<string, string>, ledger: array<string, mixed>}                $filters
	 * @param array<string, string>                                                           $carry
	 */
	private static function timeline_html( array $list, array $filters, array $carry, int $page ): string {
		if ( [] === $list['items'] ) {
			return [] === $filters['ledger']
				? EmptyState::render(
					__( 'No changes have been recorded yet', 'stonewright' ),
					__( 'When Stonewright changes content, settings or code on this site, each change is recorded here with what it changed.', 'stonewright' ),
					[ 'variant' => 'first-run' ]
				)
				: EmptyState::render(
					__( 'No changes match these filters', 'stonewright' ),
					__( 'Reset the filters, or widen the date range.', 'stonewright' ),
					[ 'variant' => 'no-results', 'actions_html' => Button::render( __( 'Reset filters', 'stonewright' ), [ 'size' => 'sm', 'href' => self::url() ] ) ]
				);
		}

		$rows = [];
		foreach ( $list['items'] as $row ) {
			$id     = (string) $row['change_id'];
			$link   = Button::render(
				__( 'View diff', 'stonewright' ),
				[
					'size'    => 'sm',
					'href'    => self::url( array_merge( $filters['form'], [ 'change' => $id ], $page > 1 ? [ 'paged' => (string) $page ] : [] ) ),
					'context' => sprintf( /* translators: %s: short change id */ __( 'of change %s', 'stonewright' ), ChangeLabels::short_id( $id ) ),
					'attrs'   => [ 'data-sw-changes-open' => $id ],
				]
			);
			$rows[] = [
				'_id'     => 'sw-change-' . $id,
				'when'    => [ 'html' => UtcTime::render( (string) $row['created_at'] ) ],
				'change'  => [ 'html' => self::change_cell( $row ) ],
				'ability' => [ 'html' => Html::element( 'code', [], Html::text( (string) $row['ability'] ) ) ],
				'user'    => [ 'html' => Html::text( ChangeLabels::user( (int) $row['actor'] ) ) . ( '' !== (string) $row['client'] ? Html::element( 'span', [ 'class' => 'sw-ui-table__meta' ], Html::text( (string) $row['client'] ) ) : '' ) ],
				'status'  => [ 'html' => self::status_cell( $row ) ],
				'actions' => [ 'html' => $link ],
			];
		}

		$table = Table::render(
			[
				[ 'key' => 'when', 'label' => __( 'When', 'stonewright' ) ],
				[ 'key' => 'change', 'label' => __( 'Change', 'stonewright' ), 'primary' => true ],
				[ 'key' => 'ability', 'label' => __( 'Ability', 'stonewright' ), 'secondary' => true ],
				[ 'key' => 'user', 'label' => __( 'User', 'stonewright' ), 'secondary' => true ],
				[ 'key' => 'status', 'label' => __( 'Status', 'stonewright' ) ],
				[ 'key' => 'actions', 'label' => __( 'Diff', 'stonewright' ), 'actions' => true ],
			],
			$rows,
			[ 'caption' => __( 'Changes, newest first', 'stonewright' ), 'class' => 'sw-changes-table' ]
		);

		return Html::element( 'div', [ 'class' => 'sw-changes-list' ], $table . self::pagination_html( $filters['form'], $page, (int) $list['pages'] ) );
	}

	/**
	 * What changed: the family, the resource and the summary the writer gave.
	 *
	 * @param array<string, mixed> $row
	 */
	private static function change_cell( array $row ): string {
		$html = Html::element( 'span', [ 'class' => 'sw-ui-table__primary' ], Badge::tag( ChangeLabels::label_for_family( (string) $row['family'] ) ) . ' ' . ChangeLabels::resource_html( $row ) );
		if ( '' !== (string) $row['summary'] ) {
			$html .= Html::element( 'span', [ 'class' => 'sw-ui-table__meta' ], Html::text( (string) $row['summary'] ) );
		}

		return $html;
	}

	/**
	 * The result of the change, in a word, with its kind when it is a rollback or a redo and the reason when it cannot be restored.
	 *
	 * @param array<string, mixed> $row
	 */
	private static function status_cell( array $row ): string {
		$html = '';
		if ( 'change' !== (string) $row['kind'] ) {
			$html .= Badge::tag( ChangeLabels::kind( (string) $row['kind'] ) ) . ' ';
		}
		$html .= ChangeLabels::status_badge( (string) $row['status'] );
		if ( ! $row['restorable'] ) {
			$html .= Html::element( 'span', [ 'class' => 'sw-ui-table__meta' ], Html::text( sprintf( /* translators: %s: why, for example "the content was too large to store" */ __( 'Not restorable: %s', 'stonewright' ), ChangeLabels::reason( (string) $row['restorable_reason'] ) ) ) );
		}

		return $html;
	}

	/** @param array<string, string> $form */
	private static function pagination_html( array $form, int $page, int $pages ): string {
		if ( $pages < 2 ) {
			return '';
		}
		$buttons = [];
		if ( $page > 1 ) {
			$buttons[] = Button::render( __( 'Newer changes', 'stonewright' ), [ 'size' => 'sm', 'href' => self::url( array_merge( $form, $page - 1 > 1 ? [ 'paged' => (string) ( $page - 1 ) ] : [] ) ) ] );
		}
		if ( $page < $pages ) {
			$buttons[] = Button::render( __( 'Older changes', 'stonewright' ), [ 'size' => 'sm', 'href' => self::url( array_merge( $form, [ 'paged' => (string) ( $page + 1 ) ] ) ) ] );
		}

		return Html::element( 'nav', [ 'class' => 'sw-changes-pages', 'aria-label' => __( 'Changes pages', 'stonewright' ) ], Button::group( $buttons ) );
	}
}
