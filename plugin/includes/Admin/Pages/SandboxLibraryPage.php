<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\Admin\Pages;

use Stonewright\WpMcp\Admin\AdminShell;
use Stonewright\WpMcp\Admin\SandboxPage;
use Stonewright\WpMcp\Admin\Ui\Badge;
use Stonewright\WpMcp\Admin\Ui\Button;
use Stonewright\WpMcp\Admin\Ui\Card;
use Stonewright\WpMcp\Admin\Ui\EmptyState;
use Stonewright\WpMcp\Admin\Ui\Html;
use Stonewright\WpMcp\Admin\Ui\Nonce;
use Stonewright\WpMcp\Admin\Ui\Notice;
use Stonewright\WpMcp\Admin\Ui\Scope;
use Stonewright\WpMcp\Admin\Ui\Table;
use Stonewright\WpMcp\Sandbox\SandboxFiles;
use Stonewright\WpMcp\Sandbox\SandboxManifest;
use Stonewright\WpMcp\Sandbox\StaticGuard;
use Stonewright\WpMcp\Security\AuditLog;
use Stonewright\WpMcp\Security\ConfirmationToken;
use Stonewright\WpMcp\Security\Permissions;

/**
 * Stonewright Sandbox Library admin page.
 *
 * Lists all sandbox draft files with tabs (Snippets, Elementor Widgets,
 * Generated Plugins), category + status filters, manifest-driven
 * metadata, an inline code editor, diff view, and rollback.
 *
 * Security invariants:
 * - Every state-changing action goes through a nonce-protected POST handler.
 * - File paths from user input are always resolved via realpath() and must stay
 *   inside the sandbox directory — any traversal attempt is rejected.
 * - When stonewright_mode === 'production-safe', activate/delete/edit/rollback
 *   require a ConfirmationToken issued server-side on the form render.
 * - StaticGuard::scan() runs before any PHP file is written or activated.
 * - AuditLog::record() is called after every successful state-change.
 */
final class SandboxLibraryPage {

	public const SLUG            = 'stonewright-sandbox-library';
	private const CAPABILITY     = 'manage_options';
	private const NONCE_ACTION   = 'stonewright_sandbox_library';

	/** Maximum file size (bytes) allowed for inline editing and diff view. */
	private const MAX_EDIT_BYTES = 262144;

	/** Allowed tab identifiers. */
	private const VALID_TABS = [ 'snippets', 'widgets', 'plugins' ];

	/** Allowed category filter values. */
	private const VALID_CATEGORIES = [ '', 'snippet', 'widget', 'plugin' ];

	/** Allowed status filter values. */
	private const VALID_STATUSES = [ '', 'pending', 'active' ];

	// -------------------------------------------------------------------------
	// Registration
	// -------------------------------------------------------------------------

	public static function register(): void {
		// Register hidden direct route for legacy/bookmarked Library URLs.
		// Direct route stays hidden; SandboxPage also embeds this as a tab.
		add_action( 'admin_menu', [ self::class, 'add_hidden_submenu' ] );
		add_action( 'admin_post_stonewright_sandbox_lib_action', [ self::class, 'handle_action' ] );
		add_action( 'admin_post_stonewright_sandbox_view', [ self::class, 'handle_view' ] );
		add_action( 'admin_post_stonewright_sandbox_widget_project', [ self::class, 'handle_widget_project' ] );
	}

	public static function add_hidden_submenu(): void {
		add_submenu_page(
			'',
			__( 'Sandbox Library', 'stonewright' ),
			__( 'Sandbox Library', 'stonewright' ),
			self::CAPABILITY,
			self::SLUG,
			[ self::class, 'render' ]
		);
	}

	/**
	 * Builds URLs for Sandbox Library views.
	 *
	 * The Library normally lives inside SandboxPage at
	 * page=stonewright-sandbox&tab=library. Direct legacy URLs with
	 * page=stonewright-sandbox-library remain registered for bookmarks.
	 *
	 * @param array<string, string|int> $args     Query args to append.
	 * @param bool                      $embedded True for the embedded Sandbox tab URL.
	 */
	public static function library_url( array $args = [], bool $embedded = true ): string {
		if ( $embedded ) {
			if ( isset( $args['tab'] ) ) {
				$args['library_tab'] = $args['tab'];
				unset( $args['tab'] );
			}
			$args = array_merge( [ 'page' => SandboxPage::SLUG, 'tab' => 'library' ], $args );
		} else {
			if ( isset( $args['library_tab'] ) ) {
				$args['tab'] = $args['library_tab'];
				unset( $args['library_tab'] );
			}
			$args = array_merge( [ 'page' => self::SLUG ], $args );
		}

		return add_query_arg( $args, admin_url( 'admin.php' ) );
	}

	// -------------------------------------------------------------------------
	// Render
	// -------------------------------------------------------------------------

	/**
	 * Renders the Library. Inside the Custom code hub (the Library tab) the hub prints the frame and this prints
	 * the content; at the old direct address it prints its own frame around the same content.
	 */
	public static function render(): void {
		if ( ! current_user_can( self::CAPABILITY ) ) {
			wp_die(
				esc_html__( 'You do not have permission to view this page.', 'stonewright' ),
				esc_html__( 'Forbidden', 'stonewright' ),
				[ 'response' => 403 ]
			);
		}

		$embedded = self::is_embedded_request();

		// phpcs:disable WordPress.Security.NonceVerification.Recommended
		$tab_param  = $embedded ? 'library_tab' : 'tab';
		$active_tab = isset( $_GET[ $tab_param ] ) ? sanitize_key( wp_unslash( (string) $_GET[ $tab_param ] ) ) : 'snippets';
		if ( ! in_array( $active_tab, self::VALID_TABS, true ) ) {
			$active_tab = 'snippets';
		}

		$filter_category = isset( $_GET['category'] ) ? sanitize_key( wp_unslash( (string) $_GET['category'] ) ) : '';
		if ( ! in_array( $filter_category, self::VALID_CATEGORIES, true ) ) {
			$filter_category = '';
		}

		$filter_status = isset( $_GET['status'] ) ? sanitize_key( wp_unslash( (string) $_GET['status'] ) ) : '';
		if ( ! in_array( $filter_status, self::VALID_STATUSES, true ) ) {
			$filter_status = '';
		}

		// Determine if ?action=edit or ?action=diff or ?action=rollback is requested.
		$sub_action = isset( $_GET['action'] ) ? sanitize_key( wp_unslash( (string) $_GET['action'] ) ) : '';
		$sub_file   = isset( $_GET['file'] ) ? sanitize_file_name( wp_unslash( (string) $_GET['file'] ) ) : '';
		$sub_to     = isset( $_GET['to'] ) ? (int) $_GET['to'] : 0;
		// phpcs:enable WordPress.Security.NonceVerification.Recommended

		// Transient notices.
		$user_id     = get_current_user_id();
		$notice_key  = 'stonewright_sandbox_lib_notice_' . $user_id;
		$notice_data = get_transient( $notice_key );
		if ( false !== $notice_data ) {
			delete_transient( $notice_key );
		}

		$content = '';
		if ( is_array( $notice_data ) ) {
			$content .= 'error' === ( $notice_data['type'] ?? '' )
				? Notice::render( 'danger', __( 'The change was not made', 'stonewright' ), (string) ( $notice_data['message'] ?? '' ) )
				: Notice::render( 'ok', (string) ( $notice_data['message'] ?? '' ) );
		}

		if ( in_array( $sub_action, [ 'edit', 'diff', 'rollback' ], true ) && '' !== $sub_file ) {
			// Sub-action pages: editor, diff, rollback.
			$content .= self::capture( static fn() => self::render_sub_action( $sub_action, $sub_file, $sub_to ) );
		} else {
			$content .= self::toolbar_html( $active_tab, $filter_category, $filter_status, $embedded );
			$content .= self::capture(
				static function () use ( $active_tab, $filter_category, $filter_status, $embedded ): void {
					switch ( $active_tab ) {
						case 'widgets':
							self::render_widgets_tab( $filter_category, $filter_status, $active_tab, $embedded );
							break;
						case 'plugins':
							self::render_plugins_tab( $filter_category, $filter_status, $active_tab, $embedded );
							break;
						default:
							self::render_snippets_tab( $filter_category, $filter_status, $active_tab, $embedded );
					}
				}
			);
		}

		if ( $embedded ) {
			echo $content; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Markup built by the Ui helpers, which escape every value.
			return;
		}

		AdminShell::open(
			self::SLUG,
			[
				'title'   => __( 'Custom code', 'stonewright' ),
				'lede'    => __( 'Draft, inspect, and activate reviewable PHP files without loading unreviewed code automatically.', 'stonewright' ),
				'current' => SandboxPage::SLUG,
			]
		);
		echo Scope::wrap( Html::element( 'div', [ 'class' => 'sw-code' ], $content ), [ 'page' => true ] ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Markup built by the Ui helpers, which escape every value.
		AdminShell::close();
	}

	/** Print markup that the Ui helpers built, which escape every value they were given. */
	private static function out( string $html ): void {
		echo $html; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Markup built by the Ui helpers, which escape every value.
	}

	/** Run a renderer that prints, and return what it printed. */
	private static function capture( callable $render ): string {
		ob_start();
		$render();

		return (string) ob_get_clean();
	}

	/**
	 * The one toolbar of the Library: which kind of file, a category, a status. The kind used to be a second row
	 * of tabs; it is one of the filters now, and the address it builds is the same.
	 */
	private static function toolbar_html( string $active_tab, string $filter_category, string $filter_status, bool $embedded ): string {
		$hidden = $embedded
			? Html::void( 'input', [ 'type' => 'hidden', 'name' => 'page', 'value' => SandboxPage::SLUG ] ) . Html::void( 'input', [ 'type' => 'hidden', 'name' => 'tab', 'value' => 'library' ] )
			: Html::void( 'input', [ 'type' => 'hidden', 'name' => 'page', 'value' => self::SLUG ] );

		$kinds = '';
		foreach ( self::VALID_TABS as $tab ) {
			$kinds .= Html::element(
				'label',
				[],
				Html::void( 'input', [ 'type' => 'radio', 'name' => $embedded ? 'library_tab' : 'tab', 'value' => $tab, 'checked' => $tab === $active_tab ] )
					. Html::text( self::tab_label( $tab ) )
			);
		}

		$options = static function ( array $choices, string $selected ): string {
			$html = '';
			foreach ( $choices as $value => $label ) {
				$html .= Html::element( 'option', [ 'value' => (string) $value, 'selected' => (string) $value === $selected ], Html::text( $label ) );
			}

			return $html;
		};

		$category_id = Html::unique_id( 'library-category' );
		$status_id   = Html::unique_id( 'library-status' );

		return Html::element(
			'form',
			[ 'method' => 'get', 'class' => 'sw-ui-toolbar sw-code__toolbar' ],
			$hidden
				. Html::element( 'div', [ 'class' => 'sw-ui-segmented', 'role' => 'radiogroup', 'aria-label' => __( 'Kind of file', 'stonewright' ) ], $kinds )
				. Html::element( 'label', [ 'class' => 'sw-ui-visually-hidden', 'for' => $category_id ], Html::text( __( 'Category', 'stonewright' ) ) )
				. Html::element(
					'select',
					[ 'id' => $category_id, 'name' => 'category', 'class' => 'sw-ui-select sw-code__select' ],
					$options(
						[
							''        => __( 'All categories', 'stonewright' ),
							'snippet' => __( 'Snippet', 'stonewright' ),
							'widget'  => __( 'Widget', 'stonewright' ),
							'plugin'  => __( 'Plugin', 'stonewright' ),
						],
						$filter_category
					)
				)
				. Html::element( 'label', [ 'class' => 'sw-ui-visually-hidden', 'for' => $status_id ], Html::text( __( 'Status', 'stonewright' ) ) )
				. Html::element(
					'select',
					[ 'id' => $status_id, 'name' => 'status', 'class' => 'sw-ui-select sw-code__select' ],
					$options(
						[
							''        => __( 'All statuses', 'stonewright' ),
							'pending' => __( 'Pending', 'stonewright' ),
							'active'  => __( 'Active', 'stonewright' ),
						],
						$filter_status
					)
				)
				. Button::render( __( 'Filter', 'stonewright' ), [ 'type' => 'submit' ] )
		);
	}

	// -------------------------------------------------------------------------
	// Tab content renderers
	// -------------------------------------------------------------------------

	private static function tab_label( string $tab ): string {
		return match ( $tab ) {
			'snippets'     => __( 'Snippets', 'stonewright' ),
			'widgets'      => __( 'Elementor widgets', 'stonewright' ),
			'plugins'      => __( 'Generated plugins', 'stonewright' ),
			default        => $tab,
		};
	}

	/**
	 * Render the Snippets tab — the original flat file list, filtered to non-widget/plugin files.
	 *
	 * @param string $filter_category Category filter value ('' = all).
	 * @param string $filter_status   Status filter value ('' = all).
	 */
	private static function render_snippets_tab( string $filter_category, string $filter_status, string $active_tab, bool $embedded ): void {
		$files              = SandboxFiles::list_files();
		$registered_widgets = (array) get_option( 'stonewright_registered_widgets', [] );

		// Snippets tab: files that are NOT classified as widget or plugin.
		$rows = array_filter(
			$files,
			static function ( array $file ) use ( $registered_widgets ): bool {
				$name = $file['name'];
				if ( in_array( $name, array_values( $registered_widgets ), true ) ) {
					return false;
				}
				if ( str_starts_with( $name, 'widget-' ) ) {
					return false;
				}
				if ( str_starts_with( $name, 'plugin-' ) || str_starts_with( $name, 'mu-' ) ) {
					return false;
				}
				return true;
			}
		);

		$rows = self::apply_filters( array_values( $rows ), $filter_category, $filter_status, $registered_widgets, 'snippet' );

		self::render_file_table( $rows, $registered_widgets, $active_tab, $embedded );
	}

	/**
	 * Render the Widgets tab — widget-prefixed sandbox files + registered widgets + installed Elementor widgets.
	 *
	 * @param string $filter_category Category filter value.
	 * @param string $filter_status   Status filter value.
	 */
	private static function render_widgets_tab( string $filter_category, string $filter_status, string $active_tab, bool $embedded ): void {
		$files              = SandboxFiles::list_files();
		$registered_widgets = (array) get_option( 'stonewright_registered_widgets', [] );

		// Widget files: widget- prefix or in registered_widgets option.
		$rows = array_filter(
			$files,
			static function ( array $file ) use ( $registered_widgets ): bool {
				$name = $file['name'];
				if ( in_array( $name, array_values( $registered_widgets ), true ) ) {
					return true;
				}
				if ( str_starts_with( $name, 'widget-' ) ) {
					return true;
				}
				return false;
			}
		);

		$rows = self::apply_filters( array_values( $rows ), $filter_category, $filter_status, $registered_widgets, 'widget' );

		self::render_file_table( $rows, $registered_widgets, $active_tab, $embedded );

		// ---- Installed Elementor Widgets (read-only) ----

		// Check Elementor availability — guarded to never call undefined methods.
		$elementor_widgets = [];
		if (
			class_exists( '\Elementor\Plugin' )
			&& isset( \Elementor\Plugin::$instance )
			&& null !== \Elementor\Plugin::$instance // @phpstan-ignore-line
			&& isset( \Elementor\Plugin::$instance->widgets_manager ) // @phpstan-ignore-line
		) {
			$widget_types = \Elementor\Plugin::$instance->widgets_manager->get_widget_types(); // @phpstan-ignore-line
			if ( is_array( $widget_types ) ) {
				foreach ( $widget_types as $widget ) {
					if ( ! is_object( $widget ) ) {
						continue;
					}
					$elementor_widgets[] = [
						'name'       => method_exists( $widget, 'get_name' ) ? (string) $widget->get_name() : '',
						'title'      => method_exists( $widget, 'get_title' ) ? (string) $widget->get_title() : '',
						'categories' => method_exists( $widget, 'get_categories' ) ? (array) $widget->get_categories() : [],
					];
				}
			}
		}

		$title = __( 'Installed Elementor widgets', 'stonewright' );
		if ( empty( $elementor_widgets ) ) {
			self::out( Card::render(
				$title,
				EmptyState::render(
					__( 'No Elementor widgets detected', 'stonewright' ),
					__( 'Widget projects start from an installed Elementor widget, and Elementor is not active. Activate Elementor, then reload this page.', 'stonewright' )
				)
			) );
			return;
		}

		$rows = [];
		foreach ( $elementor_widgets as $ew ) {
			if ( Permissions::can_manage_sandbox() ) {
				$action = Html::element(
					'form',
					[ 'method' => 'post', 'action' => admin_url( 'admin-post.php' ) ],
					Html::void( 'input', [ 'type' => 'hidden', 'name' => 'action', 'value' => 'stonewright_sandbox_widget_project' ] )
						. Html::void( 'input', [ 'type' => 'hidden', 'name' => 'base', 'value' => $ew['name'] ] )
						. self::return_fields_html( $active_tab, $embedded )
						. Nonce::field( 'stonewright_widget_project', '_stonewright_widget_nonce' )
						. Button::render( __( 'Create widget project', 'stonewright' ), [ 'type' => 'submit', 'size' => 'sm', 'context' => $ew['name'] ] )
				);
			} else {
				$action = Html::text( '—' );
			}
			$rows[] = [
				'name'       => [ 'html' => Html::element( 'code', [], Html::text( $ew['name'] ) ) ],
				'title'      => $ew['title'],
				'categories' => implode( ', ', array_map( 'strval', $ew['categories'] ) ),
				'action'     => [ 'html' => $action ],
			];
		}

		self::out( Card::render(
			$title,
			Table::render(
				[
					[ 'key' => 'name', 'label' => __( 'Widget name', 'stonewright' ), 'primary' => true ],
					[ 'key' => 'title', 'label' => __( 'Title', 'stonewright' ), 'secondary' => true ],
					[ 'key' => 'categories', 'label' => __( 'Categories', 'stonewright' ), 'secondary' => true ],
					[ 'key' => 'action', 'label' => __( 'Actions', 'stonewright' ), 'actions' => true ],
				],
				$rows,
				[ 'caption' => $title ]
			),
			[ 'flush' => true, 'actions_html' => Badge::count( count( $elementor_widgets ) ) ]
		) );
	}

	/**
	 * Render the Generated Plugins tab — plugin- / mu- prefixed files.
	 *
	 * @param string $filter_category Category filter value.
	 * @param string $filter_status   Status filter value.
	 */
	private static function render_plugins_tab( string $filter_category, string $filter_status, string $active_tab, bool $embedded ): void {
		$files              = SandboxFiles::list_files();
		$registered_widgets = (array) get_option( 'stonewright_registered_widgets', [] );

		$rows = array_filter(
			$files,
			static function ( array $file ): bool {
				$name = $file['name'];
				return str_starts_with( $name, 'plugin-' ) || str_starts_with( $name, 'mu-' );
			}
		);

		$rows = self::apply_filters( array_values( $rows ), $filter_category, $filter_status, $registered_widgets, 'plugin' );

		self::render_file_table( $rows, $registered_widgets, $active_tab, $embedded );
	}

	// -------------------------------------------------------------------------
	// Sub-action renderers: edit, diff, rollback
	// -------------------------------------------------------------------------

	/**
	 * Dispatches to the appropriate sub-action renderer (edit, diff, rollback).
	 *
	 * @param string $sub_action 'edit'|'diff'|'rollback'.
	 * @param string $sub_file   Sanitized basename.
	 * @param int    $sub_to     Timestamp for rollback (0 if not applicable).
	 */
	private static function render_sub_action( string $sub_action, string $sub_file, int $sub_to ): void {
		if ( ! Permissions::can_manage_sandbox() ) {
			self::out( Notice::render( 'danger', __( 'Insufficient permissions.', 'stonewright' ), '', [ 'actions_html' => self::back_button() ] ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Markup built by the Ui helpers, which escape every value.
			return;
		}

		$validated = self::resolve_sandbox_basename( $sub_file );
		if ( is_wp_error( $validated ) ) {
			self::out( Notice::render( 'danger', __( 'That file cannot be opened', 'stonewright' ), $validated->get_error_message(), [ 'actions_html' => self::back_button() ] ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Markup built by the Ui helpers, which escape every value.
			return;
		}

		match ( $sub_action ) {
			'edit'     => self::render_editor( $sub_file ),
			'diff'     => self::render_diff( $sub_file ),
			'rollback' => self::render_rollback( $sub_file, $sub_to ),
			default    => null,
		};
	}

	/** The way back to the file list of the tab the view was opened from. */
	private static function back_button(): string {
		return Button::render( __( 'Back to library', 'stonewright' ), [ 'href' => self::library_url( [], self::is_embedded_request() ), 'variant' => 'tertiary', 'size' => 'sm' ] );
	}

	/** The production-safe note: the form carries a token that expires. */
	private static function production_safe_callout(): string {
		return Notice::callout(
			'warn',
			__( 'Production-safe mode is active', 'stonewright' ),
			__( 'A confirmation token is embedded in this form. It expires in 5 minutes.', 'stonewright' )
		);
	}

	/** The facts about one backup or version, as a time element in site time with UTC in its title. */
	private static function time_html( int $timestamp, string $format = 'Y-m-d H:i:s' ): string {
		return Html::element(
			'time',
			[ 'datetime' => gmdate( 'c', $timestamp ), 'title' => gmdate( 'Y-m-d H:i', $timestamp ) . ' UTC' ],
			Html::text( (string) wp_date( $format, $timestamp ) )
		);
	}

	/**
	 * Renders the inline code editor for a sandbox file.
	 *
	 * @param string $file Validated basename.
	 */
	private static function render_editor( string $file ): void {
		$path  = SandboxFiles::stored_path( $file );
		$mode  = get_option( 'stonewright_mode', 'development' );
		$prod  = 'production-safe' === $mode;

		// Size guard: refuse to load oversized files into the editor.
		if ( file_exists( $path ) ) {
			$fsize = filesize( $path );
			if ( false !== $fsize && $fsize > self::MAX_EDIT_BYTES ) {
				self::out( Notice::render(
					'danger',
					__( 'The file is too large to edit here', 'stonewright' ),
					sprintf(
						/* translators: 1: filename 2: max size in KB */
						__( 'File %1$s is too large to edit inline (max %2$d KB). Download it to edit locally.', 'stonewright' ),
						$file,
						self::MAX_EDIT_BYTES / 1024
					),
					[ 'actions_html' => self::back_button() ]
				) );
				return;
			}
		}

		$contents = '';
		if ( file_exists( $path ) ) {
			$raw      = file_get_contents( $path ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
			$contents = false !== $raw ? $raw : '';
		}

		// Compute content hash for optimistic locking + token binding (Suggested 6).
		$content_hash = substr( hash( 'sha256', $contents ), 0, 16 );

		// Generate confirmation token when in production-safe mode.
		// Token is bound to both name and content hash so it cannot be replayed
		// with different content.
		$token = '';
		if ( $prod ) {
			$token = ConfirmationToken::issue(
				'stonewright/sandbox-edit',
				[ 'name' => $file, 'content_hash' => $content_hash ],
				300
			);
		}

		// Backup versions list.
		$versions = SandboxFiles::backup_versions( $file );

		$page_url = self::library_url( [], self::is_embedded_request() );

		$form = Html::element(
			'form',
			[ 'method' => 'post', 'action' => admin_url( 'admin-post.php' ), 'class' => 'sw-code__form' ],
			Html::void( 'input', [ 'type' => 'hidden', 'name' => 'action', 'value' => 'stonewright_sandbox_lib_action' ] )
				. Html::void( 'input', [ 'type' => 'hidden', 'name' => 'stonewright_lib_action', 'value' => 'edit' ] )
				. Html::void( 'input', [ 'type' => 'hidden', 'name' => 'stonewright_filename', 'value' => $file ] )
				. self::return_fields_html( self::request_library_tab(), self::is_embedded_request() )
				// content_hash_at_render is used to detect concurrent edits (optimistic lock).
				. Html::void( 'input', [ 'type' => 'hidden', 'name' => 'content_hash_at_render', 'value' => $content_hash ] )
				. ( $prod && '' !== $token ? Html::void( 'input', [ 'type' => 'hidden', 'name' => 'stonewright_confirmation_token', 'value' => $token ] ) : '' )
				. Nonce::field( self::NONCE_ACTION, '_stonewright_lib_nonce' )
				. Html::element(
					'div',
					[ 'class' => 'sw-ui-field' ],
					Html::element( 'label', [ 'class' => 'sw-ui-field__label', 'for' => 'sw-code-lib-contents' ], Html::text( __( 'Contents', 'stonewright' ) ) )
					. Html::element( 'textarea', [ 'id' => 'sw-code-lib-contents', 'name' => 'stonewright_content', 'class' => 'sw-ui-textarea sw-ui-textarea--code', 'rows' => '30', 'spellcheck' => 'false' ], esc_textarea( $contents ) )
				)
				. Html::element(
					'div',
					[ 'class' => 'sw-ui-actions' ],
					Button::render( __( 'Save changes', 'stonewright' ), [ 'type' => 'submit', 'variant' => 'primary' ] )
					. Button::render( __( 'Cancel', 'stonewright' ), [ 'href' => $page_url ] )
				)
		);

		self::out( ( $prod ? self::production_safe_callout() : '' )
			. Card::render(
				sprintf( /* translators: %s: file name */ __( 'Edit %s', 'stonewright' ), $file ),
				$form,
				[ 'actions_html' => self::back_button() ]
			) );

		// Rollback list.
		if ( ! empty( $versions ) ) {
			$rows = [];
			foreach ( $versions as $v ) {
				$rows[] = [
					'time'   => [ 'html' => self::time_html( (int) $v['timestamp'] ) ],
					'action' => [
						'html' => Button::render(
							__( 'Roll back', 'stonewright' ),
							[
								'href'    => $page_url . ( str_contains( $page_url, '?' ) ? '&' : '?' ) . 'action=rollback&file=' . rawurlencode( $file ) . '&to=' . $v['timestamp'],
								'size'    => 'sm',
								'context' => sprintf( /* translators: %s: time of the backup */ __( 'to %s', 'stonewright' ), (string) wp_date( 'Y-m-d H:i:s', $v['timestamp'] ) ),
							]
						),
					],
				];
			}
			self::out( Card::render(
				__( 'Backup versions', 'stonewright' ),
				Table::render(
					[
						[ 'key' => 'time', 'label' => __( 'Saved', 'stonewright' ), 'primary' => true ],
						[ 'key' => 'action', 'label' => __( 'Action', 'stonewright' ), 'actions' => true ],
					],
					$rows,
					[ 'caption' => sprintf( /* translators: %s: file name */ __( 'Backup versions of %s', 'stonewright' ), $file ) ]
				),
				[ 'flush' => true, 'actions_html' => Badge::count( count( $versions ) ) ]
			) );
		}
	}

	/**
	 * Renders a side-by-side diff between the draft and the .pending variant (if exists).
	 * Falls back to both panes for any two versions.
	 *
	 * @param string $file Validated basename.
	 */
	private static function render_diff( string $file ): void {
		$active_path  = SandboxFiles::stored_path( $file );
		$pending_ext  = preg_replace( '/\.php$/', '.pending.php', $file );
		$pending_path = SandboxFiles::stored_path( (string) $pending_ext );

		// Size guard: refuse to diff oversized files.
		foreach ( [ $active_path, $pending_path ] as $check_path ) {
			if ( file_exists( $check_path ) ) {
				$fsize = filesize( $check_path );
				if ( false !== $fsize && $fsize > self::MAX_EDIT_BYTES ) {
					self::out( Notice::render(
						'danger',
						__( 'The file is too large to compare here', 'stonewright' ),
						sprintf(
							/* translators: 1: filename 2: max size in KB */
							__( 'File %1$s is too large to diff inline (max %2$d KB).', 'stonewright' ),
							basename( $check_path ),
							self::MAX_EDIT_BYTES / 1024
						),
						[ 'actions_html' => self::back_button() ]
					) );
					return;
				}
			}
		}

		$left  = file_exists( $active_path )  ? (string) file_get_contents( $active_path )  : ''; // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
		$right = file_exists( $pending_path ) ? (string) file_get_contents( $pending_path ) : ''; // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents

		$notice = '' === $right
			? Notice::render( 'info', __( 'No pending version found. Only the current draft is shown.', 'stonewright' ) )
			: '';

		// Minimal line-based diff: a line that differs is printed twice, with a minus for the current draft and
		// a plus for the pending version, so the difference does not depend on colour.
		$left_lines  = explode( "\n", $left );
		$right_lines = explode( "\n", $right );
		$max         = max( count( $left_lines ), count( $right_lines ) );
		$text        = [];
		for ( $i = 0; $i < $max; $i++ ) {
			$l = $left_lines[ $i ] ?? null;
			$r = $right_lines[ $i ] ?? null;
			if ( '' === $right || $l === $r ) {
				$text[] = '  ' . (string) $l;
				continue;
			}
			if ( null !== $l ) {
				$text[] = '- ' . $l;
			}
			if ( null !== $r ) {
				$text[] = '+ ' . $r;
			}
		}

		$code = Html::element(
			'div',
			[ 'class' => 'sw-ui-code' ],
			Html::element( 'div', [ 'class' => 'sw-ui-code__head' ], Html::element( 'span', [], Html::text( __( 'Current draft and pending version', 'stonewright' ) ) ) )
				. Html::element(
					'pre',
					[ 'class' => 'sw-ui-code__body', 'tabindex' => '0', 'aria-label' => sprintf( /* translators: %s: file name */ __( 'Differences in %s', 'stonewright' ), $file ) ],
					Html::element( 'code', [], Html::text( implode( "\n", $text ) ) )
				)
		);

		self::out( Card::render(
			sprintf( /* translators: %s: file name */ __( 'Diff %s', 'stonewright' ), $file ),
			$notice
				. Html::element( 'p', [ 'class' => 'sw-ui-hint' ], Html::text( __( 'Current draft: lines marked with a minus. Pending version: lines marked with a plus. Lines that match have neither.', 'stonewright' ) ) )
				. $code,
			[ 'actions_html' => self::back_button() ]
		) );
	}

	/**
	 * Renders the rollback confirmation page.
	 *
	 * @param string $file      Validated basename.
	 * @param int    $timestamp Unix timestamp of the backup to roll back to.
	 */
	private static function render_rollback( string $file, int $timestamp ): void {
		$mode = get_option( 'stonewright_mode', 'development' );
		$prod = 'production-safe' === $mode;

		$token = '';
		if ( $prod ) {
			$token = ConfirmationToken::issue(
				'stonewright/sandbox-rollback',
				[ 'name' => $file, 'to' => $timestamp ],
				300
			);
		}

		$page_url = self::library_url( [], self::is_embedded_request() );
		$title    = sprintf( /* translators: %s: file name */ __( 'Roll back %s', 'stonewright' ), $file );

		if ( 0 === $timestamp ) {
			self::out( Card::render(
				$title,
				Notice::render( 'danger', __( 'Invalid backup timestamp.', 'stonewright' ), __( 'Open the file again and choose one of its backups.', 'stonewright' ) ),
				[ 'actions_html' => self::back_button() ]
			) );
			return;
		}

		$form = Html::element(
			'form',
			[ 'method' => 'post', 'action' => admin_url( 'admin-post.php' ), 'class' => 'sw-code__form' ],
			Html::void( 'input', [ 'type' => 'hidden', 'name' => 'action', 'value' => 'stonewright_sandbox_lib_action' ] )
				. Html::void( 'input', [ 'type' => 'hidden', 'name' => 'stonewright_lib_action', 'value' => 'rollback' ] )
				. Html::void( 'input', [ 'type' => 'hidden', 'name' => 'stonewright_filename', 'value' => $file ] )
				. Html::void( 'input', [ 'type' => 'hidden', 'name' => 'stonewright_rollback_ts', 'value' => (string) $timestamp ] )
				. self::return_fields_html( self::request_library_tab(), self::is_embedded_request() )
				. ( $prod && '' !== $token ? Html::void( 'input', [ 'type' => 'hidden', 'name' => 'stonewright_confirmation_token', 'value' => $token ] ) : '' )
				. Nonce::field( self::NONCE_ACTION, '_stonewright_lib_nonce' )
				. Html::element(
					'p',
					[],
					Html::text( sprintf( /* translators: %s: time of the backup */ __( 'This will replace the current draft with the backup from %s.', 'stonewright' ), (string) wp_date( 'Y-m-d H:i:s', $timestamp ) ) )
				)
				. Html::element(
					'div',
					[ 'class' => 'sw-ui-actions' ],
					Button::render( __( 'Confirm rollback', 'stonewright' ), [ 'type' => 'submit', 'variant' => 'primary' ] )
					. Button::render( __( 'Cancel', 'stonewright' ), [ 'href' => $page_url ] )
				)
		);

		self::out( ( $prod ? self::production_safe_callout() : '' )
			. Card::render( $title, $form ) );
	}

	// -------------------------------------------------------------------------
	// Shared file table renderer
	// -------------------------------------------------------------------------

	/**
	 * Renders the table for a set of sandbox file rows.
	 *
	 * @param array<int, array{name: string, status: string, size: int, modified: int, path: string}> $rows
	 * @param array<mixed, mixed> $registered_widgets
	 */
	private static function render_file_table( array $rows, array $registered_widgets, string $active_tab, bool $embedded ): void {
		$mode = get_option( 'stonewright_mode', 'development' );
		$prod = 'production-safe' === $mode;

		if ( empty( $rows ) ) {
			// phpcs:disable WordPress.Security.NonceVerification.Recommended -- Read-only filter flags.
			$filtered = ( isset( $_GET['category'] ) && '' !== (string) $_GET['category'] ) || ( isset( $_GET['status'] ) && '' !== (string) $_GET['status'] );
			// phpcs:enable WordPress.Security.NonceVerification.Recommended
			self::out( Card::render(
				self::tab_label( $active_tab ),
				$filtered
					? EmptyState::render(
						__( 'No files match', 'stonewright' ),
						__( 'No file of this kind has the category and status you chose. Clear the filters to see them all.', 'stonewright' ),
						[
							'variant'      => 'no-results',
							'actions_html' => Button::render( __( 'Clear filters', 'stonewright' ), [ 'href' => self::library_url( [ 'library_tab' => $active_tab ], $embedded ), 'size' => 'sm' ] ),
						]
					)
					: EmptyState::render(
						__( 'No library files yet', 'stonewright' ),
						__( 'Sandbox files that agents write appear here, grouped by kind, with the title and version from their manifest. Nothing in this list runs until you activate it.', 'stonewright' ),
						[ 'variant' => 'first-run' ]
					)
			) );
			return;
		}

		$table_rows = [];
		$dialogs    = '';
		foreach ( $rows as $file ) {
			$fname    = $file['name'];
			$status   = $file['status'];
			$type     = self::detect_type( $fname, $registered_widgets );
			$manifest = SandboxManifest::read( $fname );
			$title    = (string) ( $manifest['title'] ?? '' );
			$category = (string) ( $manifest['category'] ?? $type );
			$version  = (string) ( $manifest['version'] ?? '' );

			// Per-file confirmation tokens for production-safe.
			$activate_token = '';
			$delete_token   = '';
			if ( $prod ) {
				$activate_token = ConfirmationToken::issue( 'stonewright/sandbox-activate', [ 'name' => $fname ] );
				$delete_token   = ConfirmationToken::issue( 'stonewright/sandbox-delete',   [ 'name' => $fname ] );
			}

			// View: the raw file in a page of its own, so it opens in a new tab.
			$view_nonce = wp_create_nonce( 'stonewright_sandbox_view_' . $fname );
			$buttons    = [
				Button::render(
					__( 'View', 'stonewright' ),
					[
						'href'    => admin_url( 'admin-post.php?action=stonewright_sandbox_view&file=' . rawurlencode( $fname ) . '&_wpnonce=' . $view_nonce ),
						'size'    => 'sm',
						'context' => $fname,
						'new_tab' => true,
					]
				),
			];

			if ( Permissions::can_manage_sandbox() ) {
				$buttons[] = Button::render( __( 'Edit', 'stonewright' ), [ 'href' => self::library_url( [ 'library_tab' => $active_tab, 'action' => 'edit', 'file' => rawurlencode( $fname ) ], $embedded ), 'size' => 'sm', 'context' => $fname ] );
				// Diff (for .pending twins).
				$buttons[] = Button::render( __( 'Diff', 'stonewright' ), [ 'href' => self::library_url( [ 'library_tab' => $active_tab, 'action' => 'diff', 'file' => rawurlencode( $fname ) ], $embedded ), 'size' => 'sm', 'context' => $fname ] );

				if ( 'draft' === $status ) {
					$buttons[] = self::row_form( 'activate', $fname, $active_tab, $embedded, $activate_token, __( 'Activate', 'stonewright' ) );
				}

				$buttons[] = self::row_form( 'delete', $fname, $active_tab, $embedded, $delete_token, __( 'Delete', 'stonewright' ), true );
				$dialogs  .= self::delete_dialog( $fname );
			}

			$table_rows[] = [
				'file'     => [
					'html' => Html::element( 'span', [ 'class' => 'sw-ui-table__primary' ], Html::text( $fname ) )
						. ( '' !== $title ? Html::element( 'span', [ 'class' => 'sw-ui-table__meta' ], Html::text( $title ) ) : '' ),
				],
				'category' => [ 'html' => Badge::tag( ucfirst( $category ) ) ],
				'version'  => '' !== $version ? $version : [ 'html' => Html::element( 'span', [ 'aria-hidden' => 'true' ], '—' ) . Html::element( 'span', [ 'class' => 'sw-ui-visually-hidden' ], Html::text( __( 'None', 'stonewright' ) ) ) ],
				'size'     => size_format( $file['size'] ),
				'modified' => [ 'html' => self::time_html( $file['modified'], 'Y-m-d H:i' ) ],
				'status'   => [ 'html' => SandboxPage::status_badge( $status ) ],
				'actions'  => [ 'html' => Html::element( 'div', [ 'class' => 'sw-ui-actions sw-ui-actions--end sw-code__actions' ], implode( '', $buttons ) ) ],
			];
		}

		self::out( Card::render(
			self::tab_label( $active_tab ),
			Table::render(
				[
					[ 'key' => 'file', 'label' => __( 'File', 'stonewright' ), 'primary' => true ],
					[ 'key' => 'category', 'label' => __( 'Category', 'stonewright' ), 'secondary' => true ],
					[ 'key' => 'version', 'label' => __( 'Version', 'stonewright' ), 'secondary' => true ],
					[ 'key' => 'size', 'label' => __( 'Size', 'stonewright' ), 'secondary' => true, 'numeric' => true ],
					[ 'key' => 'modified', 'label' => __( 'Modified', 'stonewright' ), 'secondary' => true ],
					[ 'key' => 'status', 'label' => __( 'Status', 'stonewright' ) ],
					[ 'key' => 'actions', 'label' => __( 'Actions', 'stonewright' ), 'actions' => true ],
				],
				$table_rows,
				[ 'caption' => sprintf( /* translators: %s: kind of file, for example Snippets */ __( 'Sandbox library: %s', 'stonewright' ), self::tab_label( $active_tab ) ), 'class' => 'sw-code__table' ]
			) . $dialogs,
			[ 'flush' => true, 'actions_html' => Badge::count( count( $rows ) ) ]
		) );
	}

	/**
	 * One action form of a table row. A delete form opens a confirmation dialog when script runs and submits as it
	 * always did when it does not.
	 */
	private static function row_form( string $action, string $fname, string $active_tab, bool $embedded, string $token, string $label, bool $confirm = false ): string {
		$prod = '' !== $token;

		return Html::element(
			'form',
			array_filter( [ 'method' => 'post', 'action' => admin_url( 'admin-post.php' ), 'class' => 'sw-code__inline-form', 'id' => $confirm ? self::delete_form_id( $fname ) : null ] ),
			Html::void( 'input', [ 'type' => 'hidden', 'name' => 'action', 'value' => 'stonewright_sandbox_lib_action' ] )
				. Html::void( 'input', [ 'type' => 'hidden', 'name' => 'stonewright_lib_action', 'value' => $action ] )
				. Html::void( 'input', [ 'type' => 'hidden', 'name' => 'stonewright_filename', 'value' => $fname ] )
				. self::return_fields_html( $active_tab, $embedded )
				. ( $prod ? Html::void( 'input', [ 'type' => 'hidden', 'name' => 'stonewright_confirmation_token', 'value' => $token ] ) : '' )
				. Nonce::field( self::NONCE_ACTION, '_stonewright_lib_nonce' )
				. Button::render(
					$label,
					[
						'type'    => 'submit',
						'size'    => 'sm',
						'variant' => $confirm ? 'danger' : 'secondary',
						'context' => $fname,
						'attrs'   => $confirm ? [ 'data-sw-ui-dialog-open' => '#' . self::delete_dialog_id( $fname ) ] : [],
					]
				)
		);
	}

	private static function delete_form_id( string $fname ): string {
		return 'sw-code-lib-delete-form-' . sanitize_html_class( $fname );
	}

	private static function delete_dialog_id( string $fname ): string {
		return 'sw-code-lib-delete-' . sanitize_html_class( $fname );
	}

	/** The question asked before a file is deleted. Cancel takes focus; the confirming button is not primary. */
	private static function delete_dialog( string $fname ): string {
		$id = self::delete_dialog_id( $fname );

		return Html::element(
			'dialog',
			[ 'class' => 'sw-ui-dialog', 'id' => $id, 'aria-labelledby' => $id . '-title' ],
			Html::element(
				'div',
				[ 'class' => 'sw-ui-dialog__header' ],
				Html::element( 'h2', [ 'class' => 'sw-ui-dialog__title', 'id' => $id . '-title' ], Html::text( sprintf( /* translators: %s: file name */ __( 'Delete %s?', 'stonewright' ), $fname ) ) )
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
				. Button::render( __( 'Delete file', 'stonewright' ), [ 'type' => 'submit', 'variant' => 'danger-solid', 'form' => self::delete_form_id( $fname ) ] )
			)
		);
	}

	// -------------------------------------------------------------------------
	// Action handlers
	// -------------------------------------------------------------------------

	/**
	 * Handles activate, delete, edit, and rollback POST actions.
	 */
	public static function handle_action(): void {
		if ( ! current_user_can( self::CAPABILITY ) ) {
			wp_die(
				esc_html__( 'Insufficient permissions.', 'stonewright' ),
				esc_html__( 'Forbidden', 'stonewright' ),
				[ 'response' => 403 ]
			);
		}

		$nonce = isset( $_POST['_stonewright_lib_nonce'] ) ? wp_unslash( (string) $_POST['_stonewright_lib_nonce'] ) : '';
		if ( ! wp_verify_nonce( $nonce, self::NONCE_ACTION ) ) {
			wp_die( esc_html__( 'Security check failed.', 'stonewright' ) );
		}

		$action = isset( $_POST['stonewright_lib_action'] ) ? sanitize_key( wp_unslash( (string) $_POST['stonewright_lib_action'] ) ) : '';
		$name   = isset( $_POST['stonewright_filename'] ) ? sanitize_file_name( wp_unslash( (string) $_POST['stonewright_filename'] ) ) : '';

		$validated = self::resolve_sandbox_basename( $name );
		if ( is_wp_error( $validated ) ) {
			self::store_notice( 'error', $validated->get_error_message() );
			wp_safe_redirect( self::redirect_url_from_request() );
			exit;
		}

		$mode    = get_option( 'stonewright_mode', 'development' );
		$is_prod = 'production-safe' === $mode;

		// Retrieve caller-supplied confirmation token (may be empty in development mode).
		$raw_token = isset( $_POST['stonewright_confirmation_token'] ) ? wp_unslash( (string) $_POST['stonewright_confirmation_token'] ) : '';

		$result = match ( $action ) {
			'activate' => self::do_activate( $name, $is_prod, $raw_token ),
			'delete'   => self::do_delete( $name, $is_prod, $raw_token ),
			'edit'     => self::do_edit( $name, $is_prod, $raw_token ),
			'rollback' => self::do_rollback( $name, $is_prod, $raw_token ),
			default    => new \WP_Error( 'stonewright_unknown_action', "Unknown action: {$action}" ),
		};

		if ( is_wp_error( $result ) ) {
			self::store_notice( 'error', $result->get_error_message() );
		} else {
			self::store_notice( 'success', __( 'Action completed successfully.', 'stonewright' ) );
		}

		wp_safe_redirect( self::redirect_url_from_request() );
		exit;
	}

	/**
	 * Handles file view GET request via admin-post.php.
	 * Displays raw file contents inside a <pre> block. No execution.
	 */
	public static function handle_view(): void {
		if ( ! current_user_can( self::CAPABILITY ) ) {
			wp_die(
				esc_html__( 'Insufficient permissions.', 'stonewright' ),
				esc_html__( 'Forbidden', 'stonewright' ),
				[ 'response' => 403 ]
			);
		}

		$file  = isset( $_GET['file'] ) ? sanitize_file_name( wp_unslash( (string) $_GET['file'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$nonce = isset( $_GET['_wpnonce'] ) ? wp_unslash( (string) $_GET['_wpnonce'] ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended

		if ( ! wp_verify_nonce( $nonce, 'stonewright_sandbox_view_' . $file ) ) {
			wp_die( esc_html__( 'Security check failed.', 'stonewright' ) );
		}

		$validated = self::resolve_sandbox_basename( $file );
		if ( is_wp_error( $validated ) ) {
			wp_die( esc_html( $validated->get_error_message() ) );
		}

		$full_path = SandboxFiles::stored_path( $file );
		$max_bytes = 262144;
		$size      = @filesize( $full_path ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged

		// phpcs:disable WordPress.Security.EscapeOutput.OutputNotEscaped
		echo '<!DOCTYPE html><html><head><meta charset="UTF-8"/>';
		echo '<title>' . esc_html( sprintf( __( 'View: %s', 'stonewright' ), $file ) ) . '</title>';
		echo '<style>body{font-family:monospace;margin:20px;background:#1e1e1e;color:#d4d4d4;}pre{white-space:pre-wrap;word-wrap:break-word;}h1{font-family:sans-serif;color:#fff;font-size:14px;}</style>';
		echo '</head><body>';
		echo '<h1>' . esc_html( $file ) . '</h1>';

		if ( false !== $size && $size > $max_bytes ) {
			echo '<p>' . esc_html__( 'File too large to display (max 256 KB).', 'stonewright' ) . '</p>';
		} elseif ( ! file_exists( $full_path ) ) {
			echo '<p>' . esc_html__( 'File not found.', 'stonewright' ) . '</p>';
		} else {
			$raw = file_get_contents( $full_path ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
			if ( false === $raw ) {
				$raw = '';
			}
			echo '<pre>' . esc_html( $raw ) . '</pre>';
		}

		echo '</body></html>';
		// phpcs:enable WordPress.Security.EscapeOutput.OutputNotEscaped

		exit;
	}

	/**
	 * Handles the "Create Widget Project" admin-post action.
	 * Creates a starter widget PHP file in the sandbox based on an installed Elementor widget.
	 */
	public static function handle_widget_project(): void {
		if ( ! current_user_can( self::CAPABILITY ) ) {
			wp_die(
				esc_html__( 'Insufficient permissions.', 'stonewright' ),
				esc_html__( 'Forbidden', 'stonewright' ),
				[ 'response' => 403 ]
			);
		}

		$nonce = isset( $_POST['_stonewright_widget_nonce'] ) ? wp_unslash( (string) $_POST['_stonewright_widget_nonce'] ) : '';
		if ( ! wp_verify_nonce( $nonce, 'stonewright_widget_project' ) ) {
			wp_die( esc_html__( 'Security check failed.', 'stonewright' ) );
		}

		if ( ! Permissions::can_manage_sandbox() ) {
			self::store_notice( 'error', __( 'Insufficient permissions to create widget project.', 'stonewright' ) );
			wp_safe_redirect( self::redirect_url_from_request( 'widgets' ) );
			exit;
		}

		$base = isset( $_POST['base'] ) ? sanitize_key( wp_unslash( (string) $_POST['base'] ) ) : '';
		if ( '' === $base ) {
			self::store_notice( 'error', __( 'Widget name is required.', 'stonewright' ) );
			wp_safe_redirect( self::redirect_url_from_request( 'widgets' ) );
			exit;
		}

		// Verify the widget exists in Elementor (if available).
		$widget_found  = false;
		$widget_exists = (
			class_exists( '\Elementor\Plugin' )
			&& isset( \Elementor\Plugin::$instance ) // @phpstan-ignore-line
			&& null !== \Elementor\Plugin::$instance // @phpstan-ignore-line
			&& isset( \Elementor\Plugin::$instance->widgets_manager ) // @phpstan-ignore-line
		);

		if ( $widget_exists ) {
			$widget_types = \Elementor\Plugin::$instance->widgets_manager->get_widget_types(); // @phpstan-ignore-line
			if ( is_array( $widget_types ) && isset( $widget_types[ $base ] ) ) {
				$widget_found = true;
			}
		}

		// When Elementor is not active, we still allow creation (for testing).
		// When it IS active, the widget must be found.
		if ( $widget_exists && ! $widget_found ) {
			self::store_notice( 'error', sprintf( __( 'Widget "%s" not found in Elementor.', 'stonewright' ), $base ) );
			wp_safe_redirect( self::redirect_url_from_request( 'widgets' ) );
			exit;
		}

		// Build a safe filename.
		$file_name = 'widget-' . preg_replace( '/[^a-z0-9_-]/', '-', strtolower( $base ) ) . '.php';

		// Validate name pattern.
		if ( ! SandboxFiles::valid_name( $file_name ) ) {
			self::store_notice( 'error', __( 'Generated filename is invalid.', 'stonewright' ) );
			wp_safe_redirect( self::redirect_url_from_request( 'widgets' ) );
			exit;
		}

		$stub = sprintf(
			"<?php\n// Stonewright widget project based on Elementor widget: %s\n// Generated: %s\n// Edit this file to implement your custom widget logic.\n",
			esc_html( $base ),
			gmdate( 'Y-m-d H:i:s' )
		);

		$result = SandboxFiles::write( $file_name, $stub );
		if ( is_wp_error( $result ) ) {
			self::store_notice( 'error', $result->get_error_message() );
		} else {
			AuditLog::record(
				'sandbox.widget_project_created',
				[
					'name'        => $file_name,
					'base_widget' => $base,
				]
			);
			self::store_notice( 'success', sprintf( __( 'Widget project "%s" created.', 'stonewright' ), $file_name ) );
		}

		wp_safe_redirect( self::redirect_url_from_request( 'widgets' ) );
		exit;
	}

	// -------------------------------------------------------------------------
	// Destructive action helpers
	// -------------------------------------------------------------------------

	/**
	 * @return bool|\WP_Error
	 */
	private static function do_activate( string $name, bool $is_prod, string $raw_token ): bool|\WP_Error {
		if ( ! Permissions::can_manage_sandbox() ) {
			return new \WP_Error( 'stonewright_insufficient_permissions', __( 'Insufficient permissions for sandbox activation.', 'stonewright' ) );
		}

		if ( $is_prod ) {
			$token_result = ConfirmationToken::verify_or_error( $raw_token, 'stonewright/sandbox-activate', [ 'name' => $name ] );
			if ( is_wp_error( $token_result ) ) {
				return $token_result;
			}
		}

		return SandboxFiles::activate( $name );
	}

	/**
	 * @return bool|\WP_Error
	 */
	private static function do_delete( string $name, bool $is_prod, string $raw_token ): bool|\WP_Error {
		if ( ! Permissions::can_manage_sandbox() ) {
			return new \WP_Error( 'stonewright_insufficient_permissions', __( 'Insufficient permissions for sandbox delete.', 'stonewright' ) );
		}

		if ( $is_prod ) {
			$token_result = ConfirmationToken::verify_or_error( $raw_token, 'stonewright/sandbox-delete', [ 'name' => $name ] );
			if ( is_wp_error( $token_result ) ) {
				return $token_result;
			}
		}

		return SandboxFiles::delete( $name );
	}

	/**
	 * Handles saving a file from the inline editor.
	 *
	 * @return bool|\WP_Error
	 */
	private static function do_edit( string $name, bool $is_prod, string $raw_token ): bool|\WP_Error {
		if ( ! Permissions::can_manage_sandbox() ) {
			return new \WP_Error( 'stonewright_insufficient_permissions', __( 'Insufficient permissions for sandbox edit.', 'stonewright' ) );
		}

		// phpcs:disable WordPress.Security.NonceVerification.Missing -- nonce already verified in handle_action() before do_edit() is called.
		$content              = isset( $_POST['stonewright_content'] ) ? wp_unslash( (string) $_POST['stonewright_content'] ) : '';
		$hash_at_render       = isset( $_POST['content_hash_at_render'] ) ? sanitize_text_field( wp_unslash( (string) $_POST['content_hash_at_render'] ) ) : '';
		// phpcs:enable WordPress.Security.NonceVerification.Missing

		// Enforce incoming POST content size before any further processing.
		if ( strlen( $content ) > self::MAX_EDIT_BYTES ) {
			return new \WP_Error(
				'stonewright_sandbox_too_large',
				sprintf( 'Submitted content exceeds maximum edit size of %d bytes.', self::MAX_EDIT_BYTES )
			);
		}

		// Optimistic-lock / conflict detection: re-hash the current disk content
		// and compare against the hash the form captured at render time.
		$path         = SandboxFiles::stored_path( $name );
		$file_existed = file_exists( $path );
		$disk_content = $file_existed ? (string) file_get_contents( $path ) : ''; // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
		$disk_hash    = $file_existed ? substr( hash( 'sha256', $disk_content ), 0, 16 ) : '';

		if ( $file_existed ) {
			// Existing file: hash check is mandatory.
			// An empty or stale hash is treated as a mismatch to prevent overwrites
			// from a form that never had the current disk content (e.g. attacker
			// sending an empty content_hash_at_render to bypass the check).
			if ( '' === $hash_at_render || $hash_at_render !== $disk_hash ) {
				return new \WP_Error(
					'stonewright_sandbox_conflict',
					__( 'Content changed on disk since edit started, or stale form. Please reload and re-apply your changes.', 'stonewright' ),
					[ 'status' => 409 ]
				);
			}
		}
		// New file: no hash check needed (genuine create).

		if ( $is_prod ) {
			// Token is bound to both name and the content hash captured at render.
			$token_result = ConfirmationToken::verify_or_error(
				$raw_token,
				'stonewright/sandbox-edit',
				[ 'name' => $name, 'content_hash' => $hash_at_render ]
			);
			if ( is_wp_error( $token_result ) ) {
				return $token_result;
			}
		}

		// StaticGuard must pass before writing.
		$errors = StaticGuard::scan( $content );
		if ( ! empty( $errors ) ) {
			return new \WP_Error(
				'stonewright_sandbox_static_guard',
				'Static guard blocked save: ' . implode( '; ', $errors ),
				[ 'violations' => $errors ]
			);
		}

		// Write (backup happens inside SandboxFiles::write).
		// SandboxFiles::write emits the 'sandbox.write' audit entry including
		// content_sha8 and user — no separate audit call needed here.
		$result = SandboxFiles::write( $name, $content );
		if ( is_wp_error( $result ) ) {
			return $result;
		}

		return true;
	}

	/**
	 * Handles rolling back to a prior backup version.
	 *
	 * @return bool|\WP_Error
	 */
	private static function do_rollback( string $name, bool $is_prod, string $raw_token ): bool|\WP_Error {
		if ( ! Permissions::can_manage_sandbox() ) {
			return new \WP_Error( 'stonewright_insufficient_permissions', __( 'Insufficient permissions for sandbox rollback.', 'stonewright' ) );
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- nonce already verified in handle_action() before do_rollback() is called.
		$ts = isset( $_POST['stonewright_rollback_ts'] ) ? (int) $_POST['stonewright_rollback_ts'] : 0;
		if ( 0 === $ts ) {
			return new \WP_Error( 'stonewright_rollback_invalid_ts', __( 'Invalid rollback timestamp.', 'stonewright' ) );
		}

		if ( $is_prod ) {
			$token_result = ConfirmationToken::verify_or_error( $raw_token, 'stonewright/sandbox-rollback', [ 'name' => $name, 'to' => $ts ] );
			if ( is_wp_error( $token_result ) ) {
				return $token_result;
			}
		}

		// Find the backup.
		$versions = SandboxFiles::backup_versions( $name );
		$target   = null;
		foreach ( $versions as $v ) {
			if ( $v['timestamp'] === $ts ) {
				$target = $v;
				break;
			}
		}

		if ( null === $target ) {
			return new \WP_Error( 'stonewright_rollback_not_found', __( 'Backup version not found.', 'stonewright' ) );
		}

		// Read the backup content.
		$content = file_get_contents( $target['path'] ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
		if ( false === $content ) {
			return new \WP_Error( 'stonewright_rollback_read_error', __( 'Could not read backup file.', 'stonewright' ) );
		}

		// Re-run StaticGuard on backup content.
		$errors = StaticGuard::scan( $content );
		if ( ! empty( $errors ) ) {
			return new \WP_Error(
				'stonewright_sandbox_static_guard',
				'Static guard blocked rollback: ' . implode( '; ', $errors ),
				[ 'violations' => $errors ]
			);
		}

		// Write — backup happens inside SandboxFiles::write.
		$result = SandboxFiles::write( $name, $content );
		if ( is_wp_error( $result ) ) {
			return $result;
		}

		AuditLog::record(
			'sandbox.rollback',
			[
				'name'              => $name,
				'rolled_back_to_ts' => $ts,
			]
		);

		return true;
	}

	// -------------------------------------------------------------------------
	// Private helpers
	// -------------------------------------------------------------------------

	private static function is_embedded_request(): bool {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended
		$page = isset( $_GET['page'] ) ? sanitize_key( wp_unslash( (string) $_GET['page'] ) ) : '';
		$tab  = isset( $_GET['tab'] ) ? sanitize_key( wp_unslash( (string) $_GET['tab'] ) ) : '';
		// phpcs:enable WordPress.Security.NonceVerification.Recommended

		return SandboxPage::SLUG === $page && 'library' === $tab;
	}

	private static function request_embedded_return(): bool {
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- value only controls redirect target after nonce verification.
		$return_tab = isset( $_POST['stonewright_return_tab'] ) ? sanitize_key( wp_unslash( (string) $_POST['stonewright_return_tab'] ) ) : '';
		return 'library' === $return_tab;
	}

	private static function request_library_tab( string $default = 'snippets' ): string {
		$tab = $default;

		// phpcs:disable WordPress.Security.NonceVerification.Missing, WordPress.Security.NonceVerification.Recommended
		if ( isset( $_POST['stonewright_library_tab'] ) ) {
			$tab = sanitize_key( wp_unslash( (string) $_POST['stonewright_library_tab'] ) );
		} elseif ( isset( $_GET['library_tab'] ) ) {
			$tab = sanitize_key( wp_unslash( (string) $_GET['library_tab'] ) );
		} elseif ( isset( $_GET['tab'] ) && ! self::is_embedded_request() ) {
			$tab = sanitize_key( wp_unslash( (string) $_GET['tab'] ) );
		}
		// phpcs:enable WordPress.Security.NonceVerification.Missing, WordPress.Security.NonceVerification.Recommended

		return in_array( $tab, self::VALID_TABS, true ) ? $tab : $default;
	}

	private static function redirect_url_from_request( string $default_tab = 'snippets' ): string {
		$embedded = self::request_embedded_return();
		$tab      = self::request_library_tab( $default_tab );

		return self::library_url( [ 'library_tab' => $tab ], $embedded );
	}

	/** The hidden fields that bring the person back to the same tab after a post. */
	private static function return_fields_html( string $active_tab, bool $embedded ): string {
		return ( $embedded ? Html::void( 'input', [ 'type' => 'hidden', 'name' => 'stonewright_return_tab', 'value' => 'library' ] ) : '' )
			. Html::void( 'input', [ 'type' => 'hidden', 'name' => 'stonewright_library_tab', 'value' => $active_tab ] );
	}

	/**
	 * Applies category and status filters to a list of file rows.
	 *
	 * @param array<int, array{name: string, status: string, size: int, modified: int, path: string}> $rows
	 * @param string              $filter_category '' = all.
	 * @param string              $filter_status   '' = all.
	 * @param array<mixed, mixed> $registered_widgets
	 * @param string              $default_category Default category when manifest has none.
	 * @return array<int, array{name: string, status: string, size: int, modified: int, path: string}>
	 */
	private static function apply_filters(
		array $rows,
		string $filter_category,
		string $filter_status,
		array $registered_widgets,
		string $default_category
	): array {
		if ( '' === $filter_category && '' === $filter_status ) {
			return $rows;
		}

		return array_values(
			array_filter(
				$rows,
				static function ( array $file ) use ( $filter_category, $filter_status, $default_category ): bool {
					// Category filter.
					if ( '' !== $filter_category ) {
						$manifest = SandboxManifest::read( $file['name'] );
						$cat      = $manifest['category'] ?? $default_category;
						if ( $cat !== $filter_category ) {
							return false;
						}
					}

					// Status filter: 'pending' maps to 'draft' in SandboxFiles.
					if ( '' !== $filter_status ) {
						$file_status = $file['status'];
						$match       = match ( $filter_status ) {
							'pending' => 'draft' === $file_status,
							'active'  => 'active' === $file_status,
							default   => true,
						};
						if ( ! $match ) {
							return false;
						}
					}

					return true;
				}
			)
		);
	}

	/**
	 * Detects a sandbox file type based on name and registered widgets.
	 *
	 * @param string              $fname             Basename.
	 * @param array<mixed, mixed> $registered_widgets stonewright_registered_widgets option.
	 */
	private static function detect_type( string $fname, array $registered_widgets ): string {
		if ( in_array( $fname, array_values( $registered_widgets ), true ) ) {
			return 'widget';
		}
		if ( str_starts_with( $fname, 'widget-' ) ) {
			return 'widget';
		}
		if ( str_starts_with( $fname, 'plugin-' ) || str_starts_with( $fname, 'mu-' ) ) {
			return 'plugin';
		}
		if ( str_starts_with( $fname, 'draft-' ) ) {
			return 'draft';
		}
		return 'snippet';
	}

	/**
	 * Validates that a caller-supplied basename resolves inside the sandbox dir.
	 *
	 * @param string $name Raw caller-supplied basename.
	 * @return bool|\WP_Error
	 */
	public static function resolve_sandbox_basename( string $name ): bool|\WP_Error {
		if ( $name !== basename( $name ) ) {
			return new \WP_Error(
				'stonewright_sandbox_path_traversal',
				__( 'Path traversal detected in filename.', 'stonewright' )
			);
		}

		if ( ! SandboxFiles::valid_name( $name ) ) {
			return new \WP_Error(
				'stonewright_sandbox_invalid_name',
				sprintf(
					/* translators: %s: filename */
					__( 'Invalid sandbox filename: %s', 'stonewright' ),
					$name
				)
			);
		}

		if ( in_array( $name, SandboxFiles::RESERVED_NAMES, true ) ) {
			return new \WP_Error(
				'stonewright_sandbox_reserved_name',
				sprintf(
					/* translators: %s: filename */
					__( 'Reserved sandbox filename: %s', 'stonewright' ),
					$name
				)
			);
		}

		$sandbox_dir = SandboxFiles::draft_dir();
		$candidate   = SandboxFiles::stored_path( $name );
		$real_dir    = realpath( $sandbox_dir );

		if ( false === $real_dir ) {
			return new \WP_Error(
				'stonewright_sandbox_dir_missing',
				__( 'Sandbox directory could not be resolved.', 'stonewright' )
			);
		}

		if ( file_exists( $candidate ) ) {
			$real_path = realpath( $candidate );
			if ( false === $real_path ) {
				return new \WP_Error(
					'stonewright_sandbox_path_error',
					__( 'Could not resolve sandbox file path.', 'stonewright' )
				);
			}

			if ( 0 !== strpos( $real_path, $real_dir . DIRECTORY_SEPARATOR ) ) {
				return new \WP_Error(
					'stonewright_sandbox_path_escape',
					__( 'File path escapes the sandbox directory.', 'stonewright' )
				);
			}
		}

		return true;
	}

	/**
	 * Stores a notice in a user-scoped transient so it survives the redirect.
	 *
	 * @param string $type    'success' | 'error'.
	 * @param string $message Human-readable message.
	 */
	private static function store_notice( string $type, string $message ): void {
		$user_id    = get_current_user_id();
		$notice_key = 'stonewright_sandbox_lib_notice_' . $user_id;
		set_transient( $notice_key, [ 'type' => $type, 'message' => $message ], 60 );
	}
}
