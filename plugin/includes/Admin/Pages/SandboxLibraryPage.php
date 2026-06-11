<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\Admin\Pages;

use Stonewright\WpMcp\Admin\SandboxPage;
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
	 * Renders the full Sandbox Library page with outer wrap/h1.
	 * Also called by SandboxPage when the 'library' tab is selected.
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
		// phpcs:enable WordPress.Security.NonceVerification.Recommended

		// Determine if ?action=edit or ?action=diff or ?action=rollback is requested.
		// phpcs:disable WordPress.Security.NonceVerification.Recommended
		$sub_action = isset( $_GET['action'] ) ? sanitize_key( wp_unslash( (string) $_GET['action'] ) ) : '';
		$sub_file   = isset( $_GET['file'] ) ? sanitize_file_name( wp_unslash( (string) $_GET['file'] ) ) : '';
		$sub_to     = isset( $_GET['to'] ) ? (int) $_GET['to'] : 0;
		// phpcs:enable WordPress.Security.NonceVerification.Recommended

		$page_url = self::library_url( [], $embedded );

		// Transient notices.
		$user_id     = get_current_user_id();
		$notice_key  = 'stonewright_sandbox_lib_notice_' . $user_id;
		$notice_data = get_transient( $notice_key );
		if ( false !== $notice_data ) {
			delete_transient( $notice_key );
		}

		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'Sandbox Library', 'stonewright' ); ?></h1>

			<?php if ( is_array( $notice_data ) ) : ?>
				<div class="notice notice-<?php echo esc_attr( (string) ( $notice_data['type'] ?? 'info' ) ); ?> is-dismissible">
					<p><?php echo esc_html( (string) ( $notice_data['message'] ?? '' ) ); ?></p>
				</div>
			<?php endif; ?>

			<?php
			// Sub-action pages: editor, diff, rollback.
			if ( in_array( $sub_action, [ 'edit', 'diff', 'rollback' ], true ) && '' !== $sub_file ) {
				self::render_sub_action( $sub_action, $sub_file, $sub_to );
				echo '</div>';
				return;
			}
			?>

			<nav class="nav-tab-wrapper" aria-label="<?php esc_attr_e( 'Sandbox Library tabs', 'stonewright' ); ?>">
				<?php foreach ( self::VALID_TABS as $tab ) : ?>
					<a
						href="<?php echo esc_url( self::library_url( [ 'library_tab' => $tab ], $embedded ) ); ?>"
						class="nav-tab<?php echo $active_tab === $tab ? ' nav-tab-active' : ''; ?>"
					><?php echo esc_html( self::tab_label( $tab ) ); ?></a>
				<?php endforeach; ?>
			</nav>

				<div style="margin-top:10px;">
					<form method="get" action="">
						<?php if ( $embedded ) : ?>
							<input type="hidden" name="page" value="<?php echo esc_attr( SandboxPage::SLUG ); ?>"/>
							<input type="hidden" name="tab" value="library"/>
							<input type="hidden" name="library_tab" value="<?php echo esc_attr( $active_tab ); ?>"/>
						<?php else : ?>
							<input type="hidden" name="page" value="<?php echo esc_attr( self::SLUG ); ?>"/>
							<input type="hidden" name="tab" value="<?php echo esc_attr( $active_tab ); ?>"/>
						<?php endif; ?>
						<select name="category">
							<option value=""><?php esc_html_e( 'All Categories', 'stonewright' ); ?></option>
							<option value="snippet"<?php selected( $filter_category, 'snippet' ); ?>><?php esc_html_e( 'Snippet', 'stonewright' ); ?></option>
							<option value="widget"<?php selected( $filter_category, 'widget' ); ?>><?php esc_html_e( 'Widget', 'stonewright' ); ?></option>
							<option value="plugin"<?php selected( $filter_category, 'plugin' ); ?>><?php esc_html_e( 'Plugin', 'stonewright' ); ?></option>
						</select>
						<select name="status">
							<option value=""><?php esc_html_e( 'All Statuses', 'stonewright' ); ?></option>
							<option value="pending"<?php selected( $filter_status, 'pending' ); ?>><?php esc_html_e( 'Pending', 'stonewright' ); ?></option>
							<option value="active"<?php selected( $filter_status, 'active' ); ?>><?php esc_html_e( 'Active', 'stonewright' ); ?></option>
						</select>
						<?php submit_button( __( 'Filter', 'stonewright' ), 'secondary', 'filter-submit', false ); ?>
					</form>
				</div>

			<?php
			switch ( $active_tab ) {
				case 'snippets':
					self::render_snippets_tab( $filter_category, $filter_status, $active_tab, $embedded );
					break;
				case 'widgets':
					self::render_widgets_tab( $filter_category, $filter_status, $active_tab, $embedded );
					break;
				case 'plugins':
					self::render_plugins_tab( $filter_category, $filter_status, $active_tab, $embedded );
					break;
			}
			?>
		</div>
		<?php
	}

	// -------------------------------------------------------------------------
	// Tab content renderers
	// -------------------------------------------------------------------------

	private static function tab_label( string $tab ): string {
		return match ( $tab ) {
			'snippets'     => __( 'Snippets', 'stonewright' ),
			'widgets'      => __( 'Elementor Widgets', 'stonewright' ),
			'plugins'      => __( 'Generated Plugins', 'stonewright' ),
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
		echo '<h2>' . esc_html__( 'Installed Elementor Widgets', 'stonewright' ) . '</h2>';

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

		if ( empty( $elementor_widgets ) ) {
			echo '<p>' . esc_html__( 'No Elementor widgets detected. Make sure Elementor is active.', 'stonewright' ) . '</p>';
		} else {
			echo '<table class="wp-list-table widefat fixed striped">';
			echo '<thead><tr>';
			echo '<th>' . esc_html__( 'Widget Name', 'stonewright' ) . '</th>';
			echo '<th>' . esc_html__( 'Title', 'stonewright' ) . '</th>';
			echo '<th>' . esc_html__( 'Categories', 'stonewright' ) . '</th>';
			echo '<th>' . esc_html__( 'Actions', 'stonewright' ) . '</th>';
			echo '</tr></thead><tbody>';

			foreach ( $elementor_widgets as $ew ) {
				$widget_name = esc_html( $ew['name'] );
				$cats        = esc_html( implode( ', ', $ew['categories'] ) );
				$create_url  = esc_url(
					admin_url( 'admin-post.php' )
				);

				// phpcs:disable WordPress.Security.EscapeOutput.OutputNotEscaped -- $widget_name and $cats are pre-escaped via esc_html above.
				echo '<tr>';
				echo '<td><code>' . $widget_name . '</code></td>';
				echo '<td>' . esc_html( $ew['title'] ) . '</td>';
				echo '<td>' . $cats . '</td>';
				echo '<td>';
				// phpcs:enable WordPress.Security.EscapeOutput.OutputNotEscaped
				if ( Permissions::can_manage_sandbox() ) {
					echo '<form method="post" action="' . $create_url . '" style="display:inline;">';  // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- pre-escaped via esc_url()
					echo '<input type="hidden" name="action" value="stonewright_sandbox_widget_project"/>';
					echo '<input type="hidden" name="base" value="' . esc_attr( $ew['name'] ) . '"/>';
					self::render_return_fields( $active_tab, $embedded );
					wp_nonce_field( 'stonewright_widget_project', '_stonewright_widget_nonce' );
					echo '<button type="submit" class="button button-small">' . esc_html__( 'Create Widget Project', 'stonewright' ) . '</button>';
					echo '</form>';
				} else {
					echo '&mdash;';
				}
				echo '</td>';
				echo '</tr>';
			}

			echo '</tbody></table>';
		}
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
			echo '<div class="notice notice-error"><p>' . esc_html__( 'Insufficient permissions.', 'stonewright' ) . '</p></div>';
			return;
		}

		$validated = self::resolve_sandbox_basename( $sub_file );
		if ( is_wp_error( $validated ) ) {
			echo '<div class="notice notice-error"><p>' . esc_html( $validated->get_error_message() ) . '</p></div>';
			return;
		}

		match ( $sub_action ) {
			'edit'     => self::render_editor( $sub_file ),
			'diff'     => self::render_diff( $sub_file ),
			'rollback' => self::render_rollback( $sub_file, $sub_to ),
			default    => null,
		};
	}

	/**
	 * Renders the inline code editor for a sandbox file.
	 *
	 * @param string $file Validated basename.
	 */
	private static function render_editor( string $file ): void {
		$path  = SandboxFiles::draft_dir() . '/' . $file;
		$mode  = get_option( 'stonewright_mode', 'development' );
		$prod  = 'production-safe' === $mode;

		// Size guard: refuse to load oversized files into the editor.
		if ( file_exists( $path ) ) {
			$fsize = filesize( $path );
			if ( false !== $fsize && $fsize > self::MAX_EDIT_BYTES ) {
				echo '<div class="notice notice-error"><p>';
				echo esc_html(
					sprintf(
						/* translators: 1: filename 2: max size in KB */
						__( 'File %1$s is too large to edit inline (max %2$d KB). Download it to edit locally.', 'stonewright' ),
						$file,
						self::MAX_EDIT_BYTES / 1024
					)
				);
				echo '</p></div>';
				echo '<p><a href="' . esc_url( self::library_url( [], self::is_embedded_request() ) ) . '" class="button">' . esc_html__( 'Back', 'stonewright' ) . '</a></p>';
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

		echo '<h2>' . esc_html( sprintf( __( 'Edit: %s', 'stonewright' ), $file ) ) . '</h2>';

		if ( $prod ) {
			echo '<div class="notice notice-warning"><p><strong>' . esc_html__( 'Production-safe mode is active.', 'stonewright' ) . '</strong> ' . esc_html__( 'A confirmation token has been embedded. It will expire in 5 minutes.', 'stonewright' ) . '</p></div>';
		}

		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
		echo '<input type="hidden" name="action" value="stonewright_sandbox_lib_action"/>';
		echo '<input type="hidden" name="stonewright_lib_action" value="edit"/>';
		echo '<input type="hidden" name="stonewright_filename" value="' . esc_attr( $file ) . '"/>';
		self::render_return_fields( self::request_library_tab(), self::is_embedded_request() );
		// content_hash_at_render is used to detect concurrent edits (optimistic lock).
		echo '<input type="hidden" name="content_hash_at_render" value="' . esc_attr( $content_hash ) . '"/>';
		if ( $prod && '' !== $token ) {
			echo '<input type="hidden" name="stonewright_confirmation_token" value="' . esc_attr( $token ) . '"/>';
		}
		wp_nonce_field( self::NONCE_ACTION, '_stonewright_lib_nonce' );

		echo '<p>';
		echo '<textarea name="stonewright_content" rows="30" cols="120" style="font-family:monospace;width:100%;">';
		echo esc_textarea( $contents );
		echo '</textarea>';
		echo '</p>';
		echo '<p>';
		echo '<button type="submit" class="button button-primary">' . esc_html__( 'Save', 'stonewright' ) . '</button> ';
		echo '<a href="' . esc_url( $page_url ) . '" class="button">' . esc_html__( 'Cancel', 'stonewright' ) . '</a>';
		echo '</p>';
		echo '</form>';

		// Rollback list.
		if ( ! empty( $versions ) ) {
			echo '<h3>' . esc_html__( 'Backup Versions', 'stonewright' ) . '</h3>';
			echo '<table class="wp-list-table widefat fixed striped"><thead><tr>';
			echo '<th>' . esc_html__( 'Timestamp', 'stonewright' ) . '</th>';
			echo '<th>' . esc_html__( 'Action', 'stonewright' ) . '</th>';
			echo '</tr></thead><tbody>';
			foreach ( $versions as $v ) {
				$ts_str = esc_html( (string) wp_date( 'Y-m-d H:i:s', $v['timestamp'] ) );
				$rb_url = esc_url( $page_url . '&action=rollback&file=' . rawurlencode( $file ) . '&to=' . $v['timestamp'] );
				// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- $ts_str pre-escaped via esc_html, $rb_url pre-escaped via esc_url.
				echo '<tr><td>' . $ts_str . '</td><td><a href="' . $rb_url . '" class="button button-small">' . esc_html__( 'Rollback', 'stonewright' ) . '</a></td></tr>';
			}
			echo '</tbody></table>';
		}
	}

	/**
	 * Renders a side-by-side diff between the draft and the .pending variant (if exists).
	 * Falls back to both panes for any two versions.
	 *
	 * @param string $file Validated basename.
	 */
	private static function render_diff( string $file ): void {
		$draft_dir    = SandboxFiles::draft_dir();
		$active_path  = $draft_dir . '/' . $file;
		$pending_ext  = preg_replace( '/\.php$/', '.pending.php', $file );
		$pending_path = $draft_dir . '/' . $pending_ext;

		$left_label  = __( 'Current Draft', 'stonewright' );
		$right_label = __( 'Pending Version', 'stonewright' );

		// Size guard: refuse to diff oversized files.
		foreach ( [ $active_path, $pending_path ] as $check_path ) {
			if ( file_exists( $check_path ) ) {
				$fsize = filesize( $check_path );
				if ( false !== $fsize && $fsize > self::MAX_EDIT_BYTES ) {
					echo '<div class="notice notice-error"><p>';
					echo esc_html(
						sprintf(
							/* translators: 1: filename 2: max size in KB */
							__( 'File %1$s is too large to diff inline (max %2$d KB).', 'stonewright' ),
							basename( $check_path ),
							self::MAX_EDIT_BYTES / 1024
						)
					);
					echo '</p></div>';
					echo '<p><a href="' . esc_url( self::library_url( [], self::is_embedded_request() ) ) . '" class="button">' . esc_html__( 'Back', 'stonewright' ) . '</a></p>';
					return;
				}
			}
		}

		$left  = file_exists( $active_path )  ? (string) file_get_contents( $active_path )  : ''; // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
		$right = file_exists( $pending_path ) ? (string) file_get_contents( $pending_path ) : ''; // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents

		echo '<h2>' . esc_html( sprintf( __( 'Diff: %s', 'stonewright' ), $file ) ) . '</h2>';

		if ( '' === $right ) {
			echo '<div class="notice notice-info"><p>' . esc_html__( 'No pending version found. Only current draft is shown.', 'stonewright' ) . '</p></div>';
		}

		// Minimal line-based diff: show both panes side-by-side.
		$left_lines  = explode( "\n", $left );
		$right_lines = explode( "\n", $right );
		$max         = max( count( $left_lines ), count( $right_lines ) );

		echo '<table class="widefat" style="font-family:monospace;font-size:12px;table-layout:fixed;">';
		echo '<colgroup><col style="width:5%;"/><col style="width:47%;"/><col style="width:48%;"/></colgroup>';
		echo '<thead><tr>';
		echo '<th>#</th>';
		echo '<th>' . esc_html( $left_label ) . '</th>';
		echo '<th>' . esc_html( $right_label ) . '</th>';
		echo '</tr></thead><tbody>';

		for ( $i = 0; $i < $max; $i++ ) {
			$l   = $left_lines[ $i ] ?? '';
			$r   = $right_lines[ $i ] ?? '';
			$row_style = $l !== $r ? 'background:#fffbcc;' : '';
			echo '<tr style="' . esc_attr( $row_style ) . '">';
			echo '<td style="color:#999;">' . esc_html( (string) ( $i + 1 ) ) . '</td>';
			echo '<td><pre style="margin:0;white-space:pre-wrap;">' . esc_html( $l ) . '</pre></td>';
			echo '<td><pre style="margin:0;white-space:pre-wrap;">' . esc_html( $r ) . '</pre></td>';
			echo '</tr>';
		}

		echo '</tbody></table>';
		echo '<p><a href="' . esc_url( self::library_url( [], self::is_embedded_request() ) ) . '" class="button">' . esc_html__( 'Back', 'stonewright' ) . '</a></p>';
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

		echo '<h2>' . esc_html( sprintf( __( 'Rollback: %s', 'stonewright' ), $file ) ) . '</h2>';

		if ( 0 === $timestamp ) {
			echo '<div class="notice notice-error"><p>' . esc_html__( 'Invalid backup timestamp.', 'stonewright' ) . '</p></div>';
			return;
		}

		$ts_str = esc_html( (string) wp_date( 'Y-m-d H:i:s', $timestamp ) );
		echo '<p>' . esc_html( sprintf( __( 'This will replace the current draft with the backup from %s.', 'stonewright' ), (string) wp_date( 'Y-m-d H:i:s', $timestamp ) ) ) . '</p>';

		if ( $prod ) {
			echo '<div class="notice notice-warning"><p><strong>' . esc_html__( 'Production-safe mode is active.', 'stonewright' ) . '</strong> ' . esc_html__( 'A confirmation token has been embedded. It will expire in 5 minutes.', 'stonewright' ) . '</p></div>';
		}

		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
		echo '<input type="hidden" name="action" value="stonewright_sandbox_lib_action"/>';
		echo '<input type="hidden" name="stonewright_lib_action" value="rollback"/>';
		echo '<input type="hidden" name="stonewright_filename" value="' . esc_attr( $file ) . '"/>';
		echo '<input type="hidden" name="stonewright_rollback_ts" value="' . esc_attr( (string) $timestamp ) . '"/>';
		self::render_return_fields( self::request_library_tab(), self::is_embedded_request() );
		if ( $prod && '' !== $token ) {
			echo '<input type="hidden" name="stonewright_confirmation_token" value="' . esc_attr( $token ) . '"/>';
		}
		wp_nonce_field( self::NONCE_ACTION, '_stonewright_lib_nonce' );
		echo '<button type="submit" class="button button-primary">' . esc_html__( 'Confirm Rollback', 'stonewright' ) . '</button> ';
		echo '<a href="' . esc_url( $page_url ) . '" class="button">' . esc_html__( 'Cancel', 'stonewright' ) . '</a>';
		echo '</form>';
	}

	// -------------------------------------------------------------------------
	// Shared file table renderer
	// -------------------------------------------------------------------------

	/**
	 * Renders the WP-style list table for a set of sandbox file rows.
	 *
	 * @param array<int, array{name: string, status: string, size: int, modified: int, path: string}> $rows
	 * @param array<mixed, mixed> $registered_widgets
	 */
	private static function render_file_table( array $rows, array $registered_widgets, string $active_tab, bool $embedded ): void {
		$page_url = self::library_url( [ 'library_tab' => $active_tab ], $embedded );
		$mode     = get_option( 'stonewright_mode', 'development' );
		$prod     = 'production-safe' === $mode;

		if ( empty( $rows ) ) {
			echo '<p>' . esc_html__( 'No files found for the selected filters.', 'stonewright' ) . '</p>';
			return;
		}

		echo '<table class="wp-list-table widefat fixed striped stonewright-sandbox-library-table">';
		echo '<thead><tr>';
		echo '<th scope="col">' . esc_html__( 'Filename', 'stonewright' ) . '</th>';
		echo '<th scope="col">' . esc_html__( 'Title', 'stonewright' ) . '</th>';
		echo '<th scope="col" style="width:70px;">' . esc_html__( 'Category', 'stonewright' ) . '</th>';
		echo '<th scope="col" style="width:60px;">' . esc_html__( 'Version', 'stonewright' ) . '</th>';
		echo '<th scope="col" style="width:80px;">' . esc_html__( 'Size', 'stonewright' ) . '</th>';
		echo '<th scope="col" style="width:160px;">' . esc_html__( 'Modified', 'stonewright' ) . '</th>';
		echo '<th scope="col" style="width:90px;">' . esc_html__( 'Status', 'stonewright' ) . '</th>';
		echo '<th scope="col">' . esc_html__( 'Actions', 'stonewright' ) . '</th>';
		echo '</tr></thead><tbody>';

		foreach ( $rows as $file ) {
			$fname    = $file['name'];
			$status   = $file['status'];
			$size     = size_format( $file['size'] );
			$mtime    = (string) wp_date( 'Y-m-d H:i', $file['modified'] );
			$type     = self::detect_type( $fname, $registered_widgets );
			$manifest = SandboxManifest::read( $fname );
			$title    = $manifest['title'] ?? '';
			$category = $manifest['category'] ?? $type;
			$version  = $manifest['version'] ?? '';

			$badge_style = match ( $status ) {
				'active'   => 'background:#00a32a;color:#fff;padding:1px 6px;border-radius:3px;font-size:11px;',
				'disabled' => 'background:#dba617;color:#fff;padding:1px 6px;border-radius:3px;font-size:11px;',
				'crashed'  => 'background:#d63638;color:#fff;padding:1px 6px;border-radius:3px;font-size:11px;',
				default    => 'background:#78716c;color:#fff;padding:1px 6px;border-radius:3px;font-size:11px;',
			};

			// Per-file confirmation tokens for production-safe.
			$activate_token = '';
			$delete_token   = '';
			if ( $prod ) {
				$activate_token = ConfirmationToken::issue( 'stonewright/sandbox-activate', [ 'name' => $fname ] );
				$delete_token   = ConfirmationToken::issue( 'stonewright/sandbox-delete',   [ 'name' => $fname ] );
			}

			echo '<tr>';
			echo '<td>' . esc_html( $fname ) . '</td>';
			echo '<td>' . esc_html( $title ) . '</td>';
			echo '<td>' . esc_html( $category ) . '</td>';
			echo '<td>' . esc_html( $version ) . '</td>';
			echo '<td>' . esc_html( $size ) . '</td>';
			echo '<td>' . esc_html( $mtime ) . '</td>';
			echo '<td><span style="' . esc_attr( $badge_style ) . '">' . esc_html( ucfirst( $status ) ) . '</span></td>';
			echo '<td>';

			// View.
			$view_nonce = wp_create_nonce( 'stonewright_sandbox_view_' . $fname );
			$view_url   = esc_url( admin_url( 'admin-post.php?action=stonewright_sandbox_view&file=' . rawurlencode( $fname ) . '&_wpnonce=' . $view_nonce ) );
			// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- $view_url pre-escaped via esc_url.
			echo '<a href="' . $view_url . '" class="button button-small">' . esc_html__( 'View', 'stonewright' ) . '</a> ';

			if ( Permissions::can_manage_sandbox() ) {
				// Edit.
				$edit_url = esc_url( self::library_url( [ 'library_tab' => $active_tab, 'action' => 'edit', 'file' => rawurlencode( $fname ) ], $embedded ) );
				// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- $edit_url pre-escaped via esc_url.
				echo '<a href="' . $edit_url . '" class="button button-small">' . esc_html__( 'Edit', 'stonewright' ) . '</a> ';

				// Diff (for .pending twins).
				$diff_url = esc_url( self::library_url( [ 'library_tab' => $active_tab, 'action' => 'diff', 'file' => rawurlencode( $fname ) ], $embedded ) );
				// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- $diff_url pre-escaped via esc_url.
				echo '<a href="' . $diff_url . '" class="button button-small">' . esc_html__( 'Diff', 'stonewright' ) . '</a> ';

				// Activate.
				if ( 'draft' === $status ) {
					echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" style="display:inline;">';
					echo '<input type="hidden" name="action" value="stonewright_sandbox_lib_action"/>';
					echo '<input type="hidden" name="stonewright_lib_action" value="activate"/>';
					echo '<input type="hidden" name="stonewright_filename" value="' . esc_attr( $fname ) . '"/>';
					self::render_return_fields( $active_tab, $embedded );
					if ( $prod && '' !== $activate_token ) {
						echo '<input type="hidden" name="stonewright_confirmation_token" value="' . esc_attr( $activate_token ) . '"/>';
					}
					wp_nonce_field( self::NONCE_ACTION, '_stonewright_lib_nonce' );
					echo '<button type="submit" class="button button-small">' . esc_html__( 'Activate', 'stonewright' ) . '</button>';
					echo '</form> ';
				}

				// Delete.
				echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" style="display:inline;" class="stonewright-delete-form">';
				echo '<input type="hidden" name="action" value="stonewright_sandbox_lib_action"/>';
				echo '<input type="hidden" name="stonewright_lib_action" value="delete"/>';
				echo '<input type="hidden" name="stonewright_filename" value="' . esc_attr( $fname ) . '"/>';
				self::render_return_fields( $active_tab, $embedded );
				if ( $prod && '' !== $delete_token ) {
					echo '<input type="hidden" name="stonewright_confirmation_token" value="' . esc_attr( $delete_token ) . '"/>';
				}
				wp_nonce_field( self::NONCE_ACTION, '_stonewright_lib_nonce' );
				echo '<button type="submit" class="button button-small button-link-delete" data-confirm="' . esc_attr( sprintf( __( 'Delete %s? This cannot be undone.', 'stonewright' ), $fname ) ) . '">' . esc_html__( 'Delete', 'stonewright' ) . '</button>';
				echo '</form>';
			}

			echo '</td>';
			echo '</tr>';
		}

		echo '</tbody></table>';
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

		$full_path = SandboxFiles::draft_dir() . '/' . $file;
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
		$path         = SandboxFiles::draft_dir() . '/' . $name;
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

	private static function render_return_fields( string $active_tab, bool $embedded ): void {
		if ( $embedded ) {
			echo '<input type="hidden" name="stonewright_return_tab" value="library"/>';
		}
		echo '<input type="hidden" name="stonewright_library_tab" value="' . esc_attr( $active_tab ) . '"/>';
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
		$candidate   = $sandbox_dir . '/' . $name;
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
