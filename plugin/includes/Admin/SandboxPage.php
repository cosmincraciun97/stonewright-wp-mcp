<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\Admin;

use Stonewright\WpMcp\Admin\AuditLogPage;
use Stonewright\WpMcp\Admin\Pages\SandboxLibraryPage;
use Stonewright\WpMcp\Admin\Ui\Badge;
use Stonewright\WpMcp\Admin\Ui\Button;
use Stonewright\WpMcp\Admin\Ui\Card;
use Stonewright\WpMcp\Admin\Ui\EmptyState;
use Stonewright\WpMcp\Admin\Ui\Html;
use Stonewright\WpMcp\Admin\Ui\KvList;
use Stonewright\WpMcp\Admin\Ui\Nonce;
use Stonewright\WpMcp\Admin\Ui\Notice;
use Stonewright\WpMcp\Admin\Ui\Scope;
use Stonewright\WpMcp\Admin\Ui\Table;
use Stonewright\WpMcp\Sandbox\SandboxFiles;

/**
 * Stonewright Sandbox admin page.
 *
 * Provides a file manager UI for wp-content/stonewright-sandbox/ drafts.
 * Activating a draft copies it to mu-plugins/ after StaticGuard passes.
 * Crashed files are auto-disabled by CrashRecovery.
 *
 * Built from the shared UI layer. The page is the Custom code hub's first page: its tabs (Drafts, Library, Active,
 * Crash recovery) are the hub's own tab bar, so the page prints no tabs of its own.
 */
final class SandboxPage {

	public const SLUG        = 'stonewright-sandbox';
	private const CAPABILITY = 'manage_options';
	private const NONCE_ACTION = 'stonewright_sandbox';

	// -------------------------------------------------------------------------
	// Registration
	// -------------------------------------------------------------------------

	public static function register(): void {
		add_action( 'admin_menu', [ self::class, 'add_submenu' ] );
		add_action( 'admin_post_stonewright_sandbox_save',   [ self::class, 'handle_save' ] );
		add_action( 'admin_post_stonewright_sandbox_create', [ self::class, 'handle_create' ] );
		add_action( 'admin_post_stonewright_sandbox_action', [ self::class, 'handle_action' ] );
	}

	public static function add_submenu(): void {
		// Hub: Custom code. The slug stays stonewright-sandbox; MenuOrder names the sidebar entry.
		add_submenu_page(
			'stonewright',
			__( 'Custom code', 'stonewright' ),
			__( 'Custom code', 'stonewright' ),
			self::CAPABILITY,
			self::SLUG,
			[ self::class, 'render' ]
		);
	}

	// -------------------------------------------------------------------------
	// Render
	// -------------------------------------------------------------------------

	public static function render(): void {
		if ( ! current_user_can( self::CAPABILITY ) ) {
			return;
		}

		// Tab routing — ?tab= query param selects the active tab.
		$current_tab = isset( $_GET['tab'] ) ? sanitize_key( wp_unslash( (string) $_GET['tab'] ) ) : 'drafts'; // phpcs:ignore WordPress.Security.NonceVerification.Recommended

		$actions = '';
		if ( 'library' === $current_tab ) {
			ob_start();
			SandboxLibraryPage::render();
			$content = (string) ob_get_clean();
		} else {
			$content = match ( $current_tab ) {
				'mu-plugins'     => self::mu_plugins_tab_html(),
				'crash-recovery' => self::crash_recovery_tab_html(),
				'audit'          => self::audit_tab_html(),
				default          => self::drafts_tab_html( $actions ), // 'drafts' + any unknown tab
			};
		}

		AdminShell::open( self::SLUG, [ 'actions' => $actions ] );
		echo Scope::wrap( Html::element( 'div', [ 'class' => 'sw-code' ], $content ), [ 'page' => true ] ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Markup built by the Ui helpers, which escape every value; the Library prints its own through the same helpers.
		AdminShell::close();
	}

	/** The Drafts tab address, with extra query values. @param array<string, string> $args */
	private static function url( array $args = [] ): string {
		return add_query_arg( array_merge( [ 'page' => self::SLUG ], $args ), admin_url( 'admin.php' ) );
	}

	/**
	 * The Drafts tab: the file list, or a form to make a file, or a form to edit one.
	 *
	 * @param string $actions Set to the markup of the page's one primary action when the view has one for the header.
	 */
	private static function drafts_tab_html( string &$actions ): string {
		$edit_name = isset( $_GET['edit'] ) ? sanitize_file_name( wp_unslash( (string) $_GET['edit'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$new_file  = isset( $_GET['new'] ) && '' !== (string) $_GET['new']; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$files     = SandboxFiles::list_files();

		$html = self::result_notices_html();

		if ( '' !== $edit_name ) {
			return $html . self::editor_html( $edit_name );
		}
		if ( $new_file ) {
			return $html . self::new_file_html();
		}

		$html .= Notice::callout(
			'info',
			__( 'Where drafts live', 'stonewright' ),
			__( 'Drafts are stored in wp-content/stonewright-sandbox/. Activating a file copies it to mu-plugins/ after static analysis passes, and a file that crashes is switched off automatically.', 'stonewright' )
		);

		if ( [] === $files ) {
			return $html . Card::render(
				__( 'Drafts', 'stonewright' ),
				EmptyState::render(
					__( 'No sandbox files yet', 'stonewright' ),
					__( 'Sandbox files are PHP drafts that agents write and you review. Nothing runs until you activate a file.', 'stonewright' ),
					[
						'variant'      => 'first-run',
						'actions_html' => Button::render( __( 'New file', 'stonewright' ), [ 'variant' => 'primary', 'href' => self::url( [ 'new' => '1' ] ), 'icon' => 'plus' ] ),
					]
				)
			);
		}

		$actions = Button::render( __( 'New file', 'stonewright' ), [ 'variant' => 'primary', 'href' => self::url( [ 'new' => '1' ] ), 'icon' => 'plus' ] );

		$rows    = [];
		$dialogs = '';
		foreach ( $files as $file ) {
			$rows[]  = self::file_row( $file );
			$dialogs .= self::delete_dialog( $file['name'] );
		}

		return $html . Card::render(
			__( 'Drafts', 'stonewright' ),
			Table::render(
				[
					[ 'key' => 'file', 'label' => __( 'File', 'stonewright' ), 'primary' => true ],
					[ 'key' => 'status', 'label' => __( 'Status', 'stonewright' ) ],
					[ 'key' => 'actions', 'label' => __( 'Actions', 'stonewright' ), 'actions' => true ],
				],
				$rows,
				[ 'caption' => __( 'Sandbox drafts', 'stonewright' ), 'class' => 'sw-code__table' ]
			) . $dialogs,
			[
				'flush'        => true,
				'actions_html' => Badge::count( count( $files ) ),
			]
		);
	}

	/** Success and error notices after a form post. An error is announced and stays until the page is left. */
	private static function result_notices_html(): string {
		$html = '';

		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- Read-only result flags.
		if ( isset( $_GET['updated'] ) ) {
			$html .= Notice::render( 'ok', __( 'Action completed successfully.', 'stonewright' ) );
		}

		if ( isset( $_GET['error'] ) ) {
			$transient_key = 'stonewright_sandbox_error_' . get_current_user_id();
			$stored        = get_transient( $transient_key );
			if ( false !== $stored ) {
				delete_transient( $transient_key );
				$html .= Notice::render( 'danger', __( 'The change was not made', 'stonewright' ), (string) $stored );
			}
		}
		// phpcs:enable WordPress.Security.NonceVerification.Recommended

		return $html;
	}

	/**
	 * One row of the file list.
	 *
	 * @param array{name: string, status: string, size: int, modified: int, path: string} $file
	 * @return array<string, array<string, string>>
	 */
	private static function file_row( array $file ): array {
		$name     = $file['name'];
		$status   = $file['status'];
		$modified = Html::element(
			'time',
			[ 'datetime' => gmdate( 'c', $file['modified'] ), 'title' => gmdate( 'Y-m-d H:i', $file['modified'] ) . ' UTC' ],
			Html::text( (string) wp_date( 'Y-m-d H:i', $file['modified'] ) )
		);

		$buttons = [
			Button::render( __( 'Edit', 'stonewright' ), [ 'href' => self::url( [ 'edit' => rawurlencode( $name ) ] ), 'size' => 'sm', 'context' => $name ] ),
		];
		if ( 'draft' === $status ) {
			$buttons[] = self::action_form( $name, 'activate', __( 'Activate', 'stonewright' ) );
		}
		if ( 'active' === $status ) {
			$buttons[] = self::action_form( $name, 'deactivate', __( 'Deactivate', 'stonewright' ) );
			$buttons[] = self::action_form( $name, 'disable', __( 'Disable', 'stonewright' ) );
		}
		if ( 'disabled' === $status ) {
			$buttons[] = self::action_form( $name, 'enable', __( 'Enable', 'stonewright' ) );
		}
		$buttons[] = self::delete_form( $name );

		return [
			'file'    => [
				'html' => Html::element( 'span', [ 'class' => 'sw-ui-table__primary' ], Html::text( $name ) )
					. Html::element(
						'span',
						[ 'class' => 'sw-ui-table__meta' ],
						Html::text( size_format( $file['size'] ) ) . ' · ' . $modified
					),
			],
			'status'  => [ 'html' => self::status_badge( $status ) ],
			'actions' => [ 'html' => Html::element( 'div', [ 'class' => 'sw-ui-actions sw-ui-actions--end sw-code__actions' ], implode( '', $buttons ) ) ],
		];
	}

	/** A status as a badge: the word carries the meaning. */
	public static function status_badge( string $status ): string {
		return match ( $status ) {
			'active'   => Badge::render( __( 'Active', 'stonewright' ), [ 'variant' => 'ok', 'dot' => true ] ),
			'disabled' => Badge::render( __( 'Disabled', 'stonewright' ), [ 'dot' => true ] ),
			'crashed'  => Badge::render( __( 'Crashed', 'stonewright' ), [ 'variant' => 'danger', 'icon' => 'alert' ] ),
			default    => Badge::render( __( 'Draft', 'stonewright' ), [ 'variant' => 'warn', 'dot' => true ] ),
		};
	}

	/** The form behind one row action; it posts what handle_action() reads. */
	private static function action_form( string $name, string $action, string $label ): string {
		return Html::element(
			'form',
			[ 'method' => 'post', 'action' => admin_url( 'admin-post.php' ), 'class' => 'sw-code__inline-form' ],
			Html::void( 'input', [ 'type' => 'hidden', 'name' => 'action', 'value' => 'stonewright_sandbox_action' ] )
				. Html::void( 'input', [ 'type' => 'hidden', 'name' => 'stonewright_file_action', 'value' => $action ] )
				. Html::void( 'input', [ 'type' => 'hidden', 'name' => 'stonewright_filename', 'value' => $name ] )
				. Nonce::field( self::NONCE_ACTION, '_stonewright_nonce' )
				. Button::render( $label, [ 'type' => 'submit', 'size' => 'sm', 'context' => $name ] )
		);
	}

	/**
	 * The Delete form. Its button opens a confirmation dialog when script runs and submits the form directly when
	 * it does not, as it always did without script.
	 */
	private static function delete_form( string $name ): string {
		return Html::element(
			'form',
			[ 'method' => 'post', 'action' => admin_url( 'admin-post.php' ), 'class' => 'sw-code__inline-form', 'id' => self::delete_form_id( $name ) ],
			Html::void( 'input', [ 'type' => 'hidden', 'name' => 'action', 'value' => 'stonewright_sandbox_action' ] )
				. Html::void( 'input', [ 'type' => 'hidden', 'name' => 'stonewright_file_action', 'value' => 'delete' ] )
				. Html::void( 'input', [ 'type' => 'hidden', 'name' => 'stonewright_filename', 'value' => $name ] )
				. Nonce::field( self::NONCE_ACTION, '_stonewright_nonce' )
				. Button::render(
					__( 'Delete', 'stonewright' ),
					[
						'type'    => 'submit',
						'size'    => 'sm',
						'variant' => 'danger',
						'context' => $name,
						'attrs'   => [ 'data-sw-ui-dialog-open' => '#' . self::delete_dialog_id( $name ) ],
					]
				)
		);
	}

	private static function delete_form_id( string $name ): string {
		return 'sw-code-delete-form-' . sanitize_html_class( $name );
	}

	private static function delete_dialog_id( string $name ): string {
		return 'sw-code-delete-' . sanitize_html_class( $name );
	}

	/** The question asked before a file is deleted. Cancel takes focus; the confirming button is not primary. */
	private static function delete_dialog( string $name ): string {
		$id = self::delete_dialog_id( $name );

		return Html::element(
			'dialog',
			[ 'class' => 'sw-ui-dialog', 'id' => $id, 'aria-labelledby' => $id . '-title' ],
			Html::element(
				'div',
				[ 'class' => 'sw-ui-dialog__header' ],
				Html::element( 'h2', [ 'class' => 'sw-ui-dialog__title', 'id' => $id . '-title' ], Html::text( sprintf( /* translators: %s: file name */ __( 'Delete %s?', 'stonewright' ), $name ) ) )
			)
			. Html::element(
				'div',
				[ 'class' => 'sw-ui-dialog__body' ],
				Html::text( __( 'This removes the draft, its backups and its active copy, if it has one. It cannot be undone.', 'stonewright' ) )
			)
			. Html::element(
				'div',
				[ 'class' => 'sw-ui-dialog__footer' ],
				Button::render( __( 'Cancel', 'stonewright' ), [ 'attrs' => [ 'data-sw-ui-dialog-close' => true, 'autofocus' => true ] ] )
				. Button::render( __( 'Delete file', 'stonewright' ), [ 'type' => 'submit', 'variant' => 'danger-solid', 'form' => self::delete_form_id( $name ) ] )
			)
		);
	}

	/** The form that makes a new file. */
	private static function new_file_html(): string {
		$help_id = Html::unique_id( 'filename-help' );

		$form = Html::element(
			'form',
			[ 'method' => 'post', 'action' => admin_url( 'admin-post.php' ), 'class' => 'sw-code__form' ],
			Html::void( 'input', [ 'type' => 'hidden', 'name' => 'action', 'value' => 'stonewright_sandbox_create' ] )
				. Nonce::field( self::NONCE_ACTION, '_stonewright_nonce' )
				. Html::element(
					'div',
					[ 'class' => 'sw-ui-field sw-ui-field--md' ],
					Html::element( 'label', [ 'class' => 'sw-ui-field__label', 'for' => 'stonewright_new_filename' ], Html::text( __( 'File name', 'stonewright' ) ) )
					. Html::void(
						'input',
						[
							'type'             => 'text',
							'id'               => 'stonewright_new_filename',
							'name'             => 'stonewright_filename',
							'class'            => 'sw-ui-input',
							'placeholder'      => 'my-snippet.php',
							'required'         => true,
							'pattern'          => SandboxFiles::name_pattern(),
							'autocomplete'     => 'off',
							'autofocus'        => true,
							'aria-describedby' => $help_id,
						]
					)
					. Html::element( 'span', [ 'class' => 'sw-ui-field__help', 'id' => $help_id ], Html::text( __( 'Lowercase letters, digits, hyphens and underscores, ending in .php.', 'stonewright' ) ) )
				)
				. Html::element(
					'div',
					[ 'class' => 'sw-ui-field' ],
					Html::element( 'label', [ 'class' => 'sw-ui-field__label', 'for' => 'stonewright_new_contents' ], Html::text( __( 'Contents', 'stonewright' ) ) )
					. Html::element( 'textarea', [ 'id' => 'stonewright_new_contents', 'name' => 'stonewright_contents', 'class' => 'sw-ui-textarea sw-ui-textarea--code', 'rows' => '12', 'spellcheck' => 'false' ], '' )
				)
				. Html::element(
					'div',
					[ 'class' => 'sw-ui-actions' ],
					Button::render( __( 'Create file', 'stonewright' ), [ 'type' => 'submit', 'variant' => 'primary' ] )
					. Button::render( __( 'Cancel', 'stonewright' ), [ 'href' => self::url() ] )
				)
		);

		return Card::render( __( 'New file', 'stonewright' ), $form );
	}

	/** The form that edits one file, with the file's facts above it. */
	private static function editor_html( string $edit_name ): string {
		$back = Button::render( __( 'Back to drafts', 'stonewright' ), [ 'href' => self::url(), 'variant' => 'tertiary', 'size' => 'sm' ] );

		if ( ! SandboxFiles::valid_name( $edit_name ) ) {
			return Notice::render( 'danger', __( 'That is not a sandbox file name', 'stonewright' ), __( 'Choose a file from the list.', 'stonewright' ), [ 'actions_html' => $back ] );
		}

		$result = SandboxFiles::read( $edit_name );
		if ( is_wp_error( $result ) ) {
			return Notice::render( 'danger', __( 'The file could not be opened', 'stonewright' ), $result->get_error_message(), [ 'actions_html' => $back ] );
		}

		$facts = [ [ 'label' => __( 'File', 'stonewright' ), 'value_html' => Html::element( 'code', [], Html::text( $edit_name ) ) ] ];
		foreach ( SandboxFiles::list_files() as $file ) {
			if ( $file['name'] === $edit_name ) {
				$facts[] = [ 'label' => __( 'Status', 'stonewright' ), 'value_html' => self::status_badge( $file['status'] ) ];
				$facts[] = [ 'label' => __( 'Size', 'stonewright' ), 'value' => size_format( $file['size'] ) ];
				break;
			}
		}

		$form = Html::element(
			'form',
			[ 'method' => 'post', 'action' => admin_url( 'admin-post.php' ), 'class' => 'sw-code__form' ],
			Html::void( 'input', [ 'type' => 'hidden', 'name' => 'action', 'value' => 'stonewright_sandbox_save' ] )
				. Html::void( 'input', [ 'type' => 'hidden', 'name' => 'stonewright_filename', 'value' => $edit_name ] )
				. Nonce::field( self::NONCE_ACTION, '_stonewright_nonce' )
				. KvList::render( $facts, [ 'inline' => true ] )
				. Html::element(
					'div',
					[ 'class' => 'sw-ui-field' ],
					Html::element( 'label', [ 'class' => 'sw-ui-field__label', 'for' => 'stonewright_edit_contents' ], Html::text( __( 'Contents', 'stonewright' ) ) )
					. Html::element( 'textarea', [ 'id' => 'stonewright_edit_contents', 'name' => 'stonewright_contents', 'class' => 'sw-ui-textarea sw-ui-textarea--code', 'rows' => '25', 'spellcheck' => 'false' ], esc_textarea( $result ) )
				)
				. Html::element(
					'div',
					[ 'class' => 'sw-ui-actions' ],
					Button::render( __( 'Save changes', 'stonewright' ), [ 'type' => 'submit', 'variant' => 'primary' ] )
					. Button::render( __( 'Cancel', 'stonewright' ), [ 'href' => self::url() ] )
				)
		);

		return Card::render( sprintf( /* translators: %s: file name */ __( 'Edit %s', 'stonewright' ), $edit_name ), $form );
	}

	/**
	 * The Active tab: sandbox files currently running in mu-plugins/.
	 */
	private static function mu_plugins_tab_html(): string {
		$sandbox_dir = defined( 'WP_CONTENT_DIR' ) ? WP_CONTENT_DIR . '/stonewright-sandbox/' : '';
		$mu_dir      = SandboxFiles::mu_dir() . '/';
		if ( '' === $sandbox_dir ) {
			return Card::render(
				__( 'Active files', 'stonewright' ),
				EmptyState::render( __( 'The content folder is not defined', 'stonewright' ), __( 'WordPress did not define WP_CONTENT_DIR, so Stonewright cannot find the sandbox or mu-plugins folders.', 'stonewright' ), [ 'variant' => 'error' ] )
			);
		}

		$active = [];
		if ( is_dir( $mu_dir ) ) {
			$active = array_values(
				array_filter(
					array_column( SandboxFiles::list_files(), 'name' ),
					static fn( string $name ) => file_exists( $mu_dir . SandboxFiles::active_prefix() . $name )
				)
			);
		}

		if ( [] === $active ) {
			return Card::render(
				__( 'Active files', 'stonewright' ),
				EmptyState::render(
					__( 'No active files', 'stonewright' ),
					__( 'An active file is a draft you activated: it runs on every request as an MU plugin. Nothing is active yet.', 'stonewright' ),
					[ 'actions_html' => Button::render( __( 'Open drafts', 'stonewright' ), [ 'href' => self::url( [ 'tab' => 'drafts' ] ) ] ) ]
				)
			);
		}

		$rows = [];
		foreach ( $active as $name ) {
			$size   = size_format( (int) @filesize( $mu_dir . SandboxFiles::active_prefix() . $name ) ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
			$rows[] = [
				'file' => $name,
				'size' => $size,
			];
		}

		return Card::render(
			__( 'Active files', 'stonewright' ),
			Table::render(
				[
					[ 'key' => 'file', 'label' => __( 'File', 'stonewright' ), 'primary' => true ],
					[ 'key' => 'size', 'label' => __( 'Size', 'stonewright' ), 'numeric' => true ],
				],
				$rows,
				[ 'caption' => __( 'Sandbox files running as MU plugins', 'stonewright' ) ]
			),
			[ 'flush' => true, 'actions_html' => Badge::count( count( $active ) ) ]
		);
	}

	/**
	 * The Crash recovery tab: entries from the stonewright_crash_log option.
	 */
	private static function crash_recovery_tab_html(): string {
		/** @var mixed[] $log */
		$log = (array) get_option( 'stonewright_crash_log', [] );
		if ( [] === $log ) {
			return Card::render(
				__( 'Crash recovery', 'stonewright' ),
				EmptyState::render(
					__( 'No crashes recorded', 'stonewright' ),
					__( 'When an active sandbox file crashes WordPress, Stonewright switches it off and records the file, the time and the error here.', 'stonewright' ),
					[ 'actions_html' => Button::render( __( 'Open drafts', 'stonewright' ), [ 'href' => self::url( [ 'tab' => 'drafts' ] ) ] ) ]
				)
			);
		}

		$rows = [];
		foreach ( $log as $entry ) {
			$entry  = (array) $entry;
			$time   = (int) ( $entry['time'] ?? 0 );
			$rows[] = [
				'file'  => (string) ( $entry['file'] ?? '' ),
				'time'  => [
					'html' => Html::element(
						'time',
						[ 'datetime' => gmdate( 'c', $time ), 'title' => gmdate( 'Y-m-d H:i', $time ) . ' UTC' ],
						Html::text( (string) wp_date( 'Y-m-d H:i', $time ) )
					),
				],
				'error' => [ 'html' => Html::element( 'span', [ 'class' => 'sw-code__error' ], Html::text( (string) ( $entry['error'] ?? '' ) ) ) ],
			];
		}

		return Card::render(
			__( 'Crash recovery', 'stonewright' ),
			Table::render(
				[
					[ 'key' => 'file', 'label' => __( 'File', 'stonewright' ), 'primary' => true ],
					[ 'key' => 'time', 'label' => __( 'Time', 'stonewright' ) ],
					[ 'key' => 'error', 'label' => __( 'Error', 'stonewright' ) ],
				],
				$rows,
				[ 'caption' => __( 'Sandbox files that crashed', 'stonewright' ) ]
			),
			[ 'flush' => true, 'actions_html' => Badge::count( count( $log ) ) ]
		);
	}

	/**
	 * Preserves old ?tab=audit links while sending users to the dedicated page.
	 */
	private static function audit_tab_html(): string {
		// A card, so the notice is a heading of the page that screen readers and the browser tests can find.
		return Card::render(
			__( 'Audit Log moved', 'stonewright' ),
			Html::element( 'p', [], Html::text( __( 'The duplicate Sandbox view was removed. Use the full Audit Log for readable incidents, filters, payloads, and pagination.', 'stonewright' ) ) ),
			[ 'footer_html' => Button::render( __( 'Open Audit Log', 'stonewright' ), [ 'variant' => 'primary', 'href' => admin_url( 'admin.php?page=' . AuditLogPage::SLUG ) ] ) ]
		);
	}

	// -------------------------------------------------------------------------
	// Action handlers
	// -------------------------------------------------------------------------

	/**
	 * Handles saving an existing file (POST from inline editor).
	 */
	public static function handle_save(): void {
		if ( ! current_user_can( self::CAPABILITY ) ) {
			wp_die( esc_html__( 'Insufficient permissions.', 'stonewright' ) );
		}

		$nonce = isset( $_POST['_stonewright_nonce'] ) ? wp_unslash( (string) $_POST['_stonewright_nonce'] ) : '';
		if ( ! wp_verify_nonce( $nonce, self::NONCE_ACTION ) ) {
			wp_die( esc_html__( 'Security check failed.', 'stonewright' ) );
		}

		$name     = isset( $_POST['stonewright_filename'] ) ? sanitize_file_name( wp_unslash( (string) $_POST['stonewright_filename'] ) ) : '';
		$contents = isset( $_POST['stonewright_contents'] ) ? wp_unslash( (string) $_POST['stonewright_contents'] ) : '';

		$result = SandboxFiles::write( $name, $contents );

		self::redirect_with_result( $result, $name );
	}

	/**
	 * Handles creating a new file.
	 */
	public static function handle_create(): void {
		if ( ! current_user_can( self::CAPABILITY ) ) {
			wp_die( esc_html__( 'Insufficient permissions.', 'stonewright' ) );
		}

		$nonce = isset( $_POST['_stonewright_nonce'] ) ? wp_unslash( (string) $_POST['_stonewright_nonce'] ) : '';
		if ( ! wp_verify_nonce( $nonce, self::NONCE_ACTION ) ) {
			wp_die( esc_html__( 'Security check failed.', 'stonewright' ) );
		}

		$name     = isset( $_POST['stonewright_filename'] ) ? sanitize_file_name( wp_unslash( (string) $_POST['stonewright_filename'] ) ) : '';
		$contents = isset( $_POST['stonewright_contents'] ) ? wp_unslash( (string) $_POST['stonewright_contents'] ) : '';

		$result = SandboxFiles::write( $name, $contents );

		self::redirect_with_result( $result );
	}

	/**
	 * Handles all single-action operations: delete, activate, deactivate, disable, enable.
	 */
	public static function handle_action(): void {
		if ( ! current_user_can( self::CAPABILITY ) ) {
			wp_die( esc_html__( 'Insufficient permissions.', 'stonewright' ) );
		}

		$nonce = isset( $_POST['_stonewright_nonce'] ) ? wp_unslash( (string) $_POST['_stonewright_nonce'] ) : '';
		if ( ! wp_verify_nonce( $nonce, self::NONCE_ACTION ) ) {
			wp_die( esc_html__( 'Security check failed.', 'stonewright' ) );
		}

		$name        = isset( $_POST['stonewright_filename'] ) ? sanitize_file_name( wp_unslash( (string) $_POST['stonewright_filename'] ) ) : '';
		$file_action = isset( $_POST['stonewright_file_action'] ) ? sanitize_key( wp_unslash( (string) $_POST['stonewright_file_action'] ) ) : '';

		$result = match ( $file_action ) {
			'activate'   => SandboxFiles::activate( $name ),
			'deactivate' => SandboxFiles::deactivate( $name ),
			'disable'    => SandboxFiles::disable( $name ),
			'enable'     => SandboxFiles::enable( $name ),
			'delete'     => SandboxFiles::delete( $name ),
			default      => new \WP_Error( 'stonewright_sandbox_unknown_action', "Unknown action: {$file_action}" ),
		};

		self::redirect_with_result( $result );
	}

	// -------------------------------------------------------------------------
	// Private helpers
	// -------------------------------------------------------------------------

	/**
	 * Redirects to the sandbox page. On WP_Error, stores the message in a
	 * transient and appends ?error=1. On success, appends ?updated=1.
	 *
	 * @param bool|\WP_Error $result     Operation result.
	 * @param string         $edit_name  If set, redirect to editor for that file on success.
	 */
	private static function redirect_with_result( bool|\WP_Error $result, string $edit_name = '' ): void {
		$page_url = admin_url( 'admin.php?page=' . self::SLUG );

		if ( is_wp_error( $result ) ) {
			$user_id       = get_current_user_id();
			$transient_key = 'stonewright_sandbox_error_' . $user_id;
			set_transient( $transient_key, $result->get_error_message(), 60 );
			wp_safe_redirect( add_query_arg( 'error', '1', $page_url ) );
			exit;
		}

		$redirect = '' !== $edit_name
			? add_query_arg( [ 'updated' => '1', 'edit' => rawurlencode( $edit_name ) ], $page_url )
			: add_query_arg( 'updated', '1', $page_url );

		wp_safe_redirect( $redirect );
		exit;
	}
}
