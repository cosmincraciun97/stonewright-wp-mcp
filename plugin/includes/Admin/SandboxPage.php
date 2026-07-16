<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\Admin;

use Stonewright\WpMcp\Admin\AuditLogPage;
use Stonewright\WpMcp\Admin\Pages\SandboxLibraryPage;
use Stonewright\WpMcp\Sandbox\SandboxFiles;

/**
 * Stonewright Sandbox admin page.
 *
 * Provides a file manager UI for wp-content/stonewright-sandbox/ drafts.
 * Activating a draft copies it to mu-plugins/ after StaticGuard passes.
 * Crashed files are auto-disabled by CrashRecovery.
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
		// IA group: Workflows — slug stonewright-sandbox unchanged.
		add_submenu_page(
			'stonewright',
			__( 'Sandbox', 'stonewright' ),
			__( 'Workflows', 'stonewright' ),
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
		$tabs = [
			'drafts'         => __( 'Drafts', 'stonewright' ),
			'library'        => __( 'Library', 'stonewright' ),
			'mu-plugins'     => __( 'Active MU Plugins', 'stonewright' ),
			'crash-recovery' => __( 'Crash Recovery', 'stonewright' ),
			'audit'          => __( 'Audit Log', 'stonewright' ),
		];
		?>
		<?php AdminShell::open( self::SLUG ); ?>
		<div class="stonewright-sandbox-page">
			<div class="stonewright-page-header">
				<div>
					<h1><?php esc_html_e( 'Sandbox', 'stonewright' ); ?></h1>
					<p><?php esc_html_e( 'Draft, inspect, and activate reviewable PHP files without loading unreviewed code automatically.', 'stonewright' ); ?></p>
				</div>
			</div>

			<nav class="sw-tabs" aria-label="<?php esc_attr_e( 'Sandbox sections', 'stonewright' ); ?>">
				<?php foreach ( $tabs as $slug => $label ) : ?>
					<a
						href="<?php echo esc_url( add_query_arg( [ 'page' => self::SLUG, 'tab' => $slug ], admin_url( 'admin.php' ) ) ); ?>"
						class="sw-tabs__link<?php echo $current_tab === $slug ? ' is-active' : ''; ?>"
						<?php echo $current_tab === $slug ? ' aria-current="page"' : ''; ?>
					><?php echo esc_html( $label ); ?></a>
				<?php endforeach; ?>
			</nav>

			<div class="stonewright-tab-content">
				<?php
				match ( $current_tab ) {
					'library'        => SandboxLibraryPage::render(),
					'mu-plugins'     => self::render_mu_plugins_tab(),
					'crash-recovery' => self::render_crash_recovery_tab(),
					'audit'          => self::render_audit_tab(),
					default          => self::render_drafts_tab(), // 'drafts' + any unknown tab
				};
				?>
			</div>
		</div>
		<?php AdminShell::close(); ?>
		<?php
	}

	/**
	 * Renders the Drafts tab — the original sandbox file list + inline editor.
	 */
	private static function render_drafts_tab(): void {
		$page_url  = admin_url( 'admin.php?page=' . self::SLUG );
		$edit_name = isset( $_GET['edit'] ) ? sanitize_file_name( wp_unslash( (string) $_GET['edit'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$files     = SandboxFiles::list_files();

		// Error/success transient notices.
		$user_id       = get_current_user_id();
		$transient_key = 'stonewright_sandbox_error_' . $user_id;
		$error_msg     = '';

		if ( isset( $_GET['error'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			$stored = get_transient( $transient_key );
			if ( false !== $stored ) {
				$error_msg = (string) $stored;
				delete_transient( $transient_key );
			}
		}

		// File contents for inline editor.
		$edit_contents = '';
		$edit_error    = '';
		if ( '' !== $edit_name && SandboxFiles::valid_name( $edit_name ) ) {
			$result = SandboxFiles::read( $edit_name );
			if ( is_wp_error( $result ) ) {
				$edit_error = $result->get_error_message();
			} else {
				$edit_contents = $result;
			}
		}
		?>
		<div class="stonewright-sandbox-drafts">
			<p class="description"><?php esc_html_e( 'Drafts in wp-content/stonewright-sandbox/. Activating copies the file to mu-plugins/ after static analysis passes. Crashed files are auto-disabled.', 'stonewright' ); ?></p>

			<?php if ( isset( $_GET['updated'] ) ) : // phpcs:ignore WordPress.Security.NonceVerification.Recommended ?>
				<div class="notice notice-success is-dismissible">
					<p><?php esc_html_e( 'Action completed successfully.', 'stonewright' ); ?></p>
				</div>
			<?php endif; ?>

			<?php if ( '' !== $error_msg ) : ?>
				<div class="notice notice-error is-dismissible">
					<p><?php echo esc_html( $error_msg ); ?></p>
				</div>
			<?php endif; ?>

			<div class="stonewright-toolbar">
				<button
					type="button"
					class="button"
					data-stonewright-toggle-target="stonewright-new-file-form"
					data-stonewright-focus-target="stonewright_new_filename"
				>
					<?php esc_html_e( 'New File', 'stonewright' ); ?>
				</button>
			</div>

			<div id="stonewright-new-file-form" class="stonewright-panel stonewright-sandbox-new-file" hidden>
				<h2><?php esc_html_e( 'Create New Sandbox File', 'stonewright' ); ?></h2>
				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
					<input type="hidden" name="action" value="stonewright_sandbox_create"/>
					<?php wp_nonce_field( self::NONCE_ACTION, '_stonewright_nonce' ); ?>
					<table class="form-table" role="presentation">
						<tbody>
							<tr>
								<th scope="row">
									<label for="stonewright_new_filename"><?php esc_html_e( 'Filename', 'stonewright' ); ?></label>
								</th>
								<td>
									<input type="text" id="stonewright_new_filename" name="stonewright_filename" class="regular-text" placeholder="my-snippet.php" required pattern="[a-z0-9_-]+\.php"/>
									<p class="description"><?php esc_html_e( 'Lowercase letters, digits, hyphens, underscores. Must end in .php', 'stonewright' ); ?></p>
								</td>
							</tr>
							<tr>
								<th scope="row">
									<label for="stonewright_new_contents"><?php esc_html_e( 'Contents', 'stonewright' ); ?></label>
								</th>
								<td>
									<textarea id="stonewright_new_contents" name="stonewright_contents" rows="12" cols="80" class="large-text code"></textarea>
								</td>
							</tr>
						</tbody>
					</table>
					<?php submit_button( __( 'Create File', 'stonewright' ), 'primary', 'submit', false ); ?>
					&nbsp;
					<button
						type="button"
						class="button"
						data-stonewright-hide-target="stonewright-new-file-form"
					>
						<?php esc_html_e( 'Cancel', 'stonewright' ); ?>
					</button>
				</form>
			</div>
			<?php if ( empty( $files ) ) : ?>
				<div class="stonewright-empty-state">
					<p><?php esc_html_e( 'No sandbox files yet. Create one above.', 'stonewright' ); ?></p>
				</div>
			<?php else : ?>
				<div class="stonewright-card-grid stonewright-sandbox-grid">
					<?php foreach ( $files as $file ) : ?>
					<?php
					$fname  = $file['name'];
					$status = $file['status'];
					$size   = size_format( $file['size'] );
					$mtime  = wp_date( 'Y-m-d H:i', $file['modified'] );

					$badge_class = match ( $status ) {
						'active'   => 'sw-badge sw-badge--active',
						'disabled' => 'sw-badge sw-badge--disabled',
						'crashed'  => 'sw-badge sw-badge--crashed',
						default    => 'sw-badge sw-badge--draft',
					};
					?>
					<div class="stonewright-card">
						<div class="stonewright-card__header">
							<div class="stonewright-card__title-row">
								<span class="stonewright-card__title"><?php echo esc_html( $fname ); ?></span>
								<span class="<?php echo esc_attr( $badge_class ); ?>"><?php echo esc_html( ucfirst( $status ) ); ?></span>
								<span class="sw-badge sw-badge--category">PHP</span>
							</div>
						</div>
						<div class="stonewright-card__body">
							<p class="stonewright-card__description">
								<?php echo esc_html( $size ); ?> - <?php echo esc_html( (string) $mtime ); ?>
							</p>
						</div>
						<div class="stonewright-card__footer">
							<?php /* Edit link (GET) */ ?>
							<a href="<?php echo esc_url( add_query_arg( [ 'page' => self::SLUG, 'edit' => rawurlencode( $fname ) ], admin_url( 'admin.php' ) ) ); ?>" class="button button-small"><?php esc_html_e( 'Edit', 'stonewright' ); ?></a>

							<?php /* Activate */ ?>
							<?php if ( 'draft' === $status ) : ?>
							<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="stonewright-inline-form">
								<input type="hidden" name="action" value="stonewright_sandbox_action"/>
								<input type="hidden" name="stonewright_file_action" value="activate"/>
								<input type="hidden" name="stonewright_filename" value="<?php echo esc_attr( $fname ); ?>"/>
								<?php wp_nonce_field( self::NONCE_ACTION, '_stonewright_nonce' ); ?>
								<button type="submit" class="button button-small button-primary"><?php esc_html_e( 'Activate', 'stonewright' ); ?></button>
							</form>
							<?php endif; ?>

							<?php /* Deactivate */ ?>
							<?php if ( 'active' === $status ) : ?>
							<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="stonewright-inline-form">
								<input type="hidden" name="action" value="stonewright_sandbox_action"/>
								<input type="hidden" name="stonewright_file_action" value="deactivate"/>
								<input type="hidden" name="stonewright_filename" value="<?php echo esc_attr( $fname ); ?>"/>
								<?php wp_nonce_field( self::NONCE_ACTION, '_stonewright_nonce' ); ?>
								<button type="submit" class="button button-small"><?php esc_html_e( 'Deactivate', 'stonewright' ); ?></button>
							</form>
							<?php endif; ?>

							<?php /* Disable */ ?>
							<?php if ( 'active' === $status ) : ?>
							<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="stonewright-inline-form">
								<input type="hidden" name="action" value="stonewright_sandbox_action"/>
								<input type="hidden" name="stonewright_file_action" value="disable"/>
								<input type="hidden" name="stonewright_filename" value="<?php echo esc_attr( $fname ); ?>"/>
								<?php wp_nonce_field( self::NONCE_ACTION, '_stonewright_nonce' ); ?>
								<button type="submit" class="button button-small"><?php esc_html_e( 'Disable', 'stonewright' ); ?></button>
							</form>
							<?php endif; ?>

							<?php /* Enable */ ?>
							<?php if ( 'disabled' === $status ) : ?>
							<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="stonewright-inline-form">
								<input type="hidden" name="action" value="stonewright_sandbox_action"/>
								<input type="hidden" name="stonewright_file_action" value="enable"/>
								<input type="hidden" name="stonewright_filename" value="<?php echo esc_attr( $fname ); ?>"/>
								<?php wp_nonce_field( self::NONCE_ACTION, '_stonewright_nonce' ); ?>
								<button type="submit" class="button button-small"><?php esc_html_e( 'Enable', 'stonewright' ); ?></button>
							</form>
							<?php endif; ?>

							<?php /* Delete */ ?>
							<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="stonewright-inline-form">
								<input type="hidden" name="action" value="stonewright_sandbox_action"/>
								<input type="hidden" name="stonewright_file_action" value="delete"/>
								<input type="hidden" name="stonewright_filename" value="<?php echo esc_attr( $fname ); ?>"/>
								<?php wp_nonce_field( self::NONCE_ACTION, '_stonewright_nonce' ); ?>
								<button
									type="submit"
									class="button button-small button-link-delete"
									data-confirm="<?php esc_attr_e( 'Delete this file? This cannot be undone.', 'stonewright' ); ?>"
								><?php esc_html_e( 'Delete', 'stonewright' ); ?></button>
							</form>
						</div>
					</div>
					<?php endforeach; ?>
				</div>
			<?php endif; ?>

			<?php /* Inline editor */ ?>
			<?php if ( '' !== $edit_name ) : ?>
				<div class="stonewright-panel stonewright-editor-panel">
					<h2><?php echo esc_html( __( 'Editing: ', 'stonewright' ) . $edit_name ); ?></h2>

					<?php if ( '' !== $edit_error ) : ?>
						<div class="notice notice-error inline">
							<p><?php echo esc_html( $edit_error ); ?></p>
						</div>
					<?php else : ?>
						<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
							<input type="hidden" name="action" value="stonewright_sandbox_save"/>
							<input type="hidden" name="stonewright_filename" value="<?php echo esc_attr( $edit_name ); ?>"/>
							<?php wp_nonce_field( self::NONCE_ACTION, '_stonewright_nonce' ); ?>

							<table class="form-table" role="presentation">
								<tbody>
									<tr>
										<th scope="row"><?php esc_html_e( 'Filename', 'stonewright' ); ?></th>
										<td><code><?php echo esc_html( $edit_name ); ?></code></td>
									</tr>
									<tr>
										<th scope="row">
											<label for="stonewright_edit_contents"><?php esc_html_e( 'Contents', 'stonewright' ); ?></label>
										</th>
										<td>
											<textarea id="stonewright_edit_contents" name="stonewright_contents" rows="25" cols="100" class="large-text code"><?php echo esc_textarea( $edit_contents ); ?></textarea>
										</td>
									</tr>
								</tbody>
							</table>

							<?php submit_button( __( 'Save File', 'stonewright' ), 'primary', 'submit', false ); ?>
							&nbsp;
							<a href="<?php echo esc_url( $page_url ); ?>" class="button"><?php esc_html_e( 'Cancel', 'stonewright' ); ?></a>
						</form>
					<?php endif; ?>
				</div>
			<?php endif; ?>
		</div>
		<?php
	}

	/**
	 * Renders Active MU Plugins tab — shows sandbox files currently active in mu-plugins/.
	 */
	private static function render_mu_plugins_tab(): void {
		$sandbox_dir = defined( 'WP_CONTENT_DIR' ) ? WP_CONTENT_DIR . '/stonewright-sandbox/' : '';
		$mu_dir      = SandboxFiles::mu_dir() . '/';
		if ( '' === $sandbox_dir ) {
			echo '<div class="stonewright-empty-state"><p>' . esc_html__( 'MU plugin directories not defined.', 'stonewright' ) . '</p></div>';
			return;
		}
		if ( ! is_dir( $mu_dir ) ) {
			echo '<div class="stonewright-empty-state"><p>' . esc_html__( 'No sandbox files are currently active as MU plugins.', 'stonewright' ) . '</p></div>';
			return;
		}
		$files  = glob( $sandbox_dir . '*.php' ) ?: [];
		$active = array_filter( $files, static fn( string $f ) => file_exists( $mu_dir . SandboxFiles::active_prefix() . basename( $f ) ) );
		if ( empty( $active ) ) {
			echo '<div class="stonewright-empty-state"><p>' . esc_html__( 'No sandbox files are currently active as MU plugins.', 'stonewright' ) . '</p></div>';
			return;
		}
		echo '<table class="wp-list-table widefat fixed striped"><thead><tr>';
		echo '<th scope="col">' . esc_html__( 'File', 'stonewright' ) . '</th>';
		echo '<th scope="col">' . esc_html__( 'Size', 'stonewright' ) . '</th>';
		echo '</tr></thead><tbody>';
		foreach ( $active as $f ) {
			$name = basename( $f );
			$size = size_format( (int) @filesize( $mu_dir . SandboxFiles::active_prefix() . $name ) ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
			echo '<tr><td>' . esc_html( $name ) . '</td><td>' . esc_html( $size ) . '</td></tr>';
		}
		echo '</tbody></table>';
	}

	/**
	 * Renders Crash Recovery tab — lists entries from stonewright_crash_log option.
	 */
	private static function render_crash_recovery_tab(): void {
		/** @var mixed[] $log */
		$log = (array) get_option( 'stonewright_crash_log', [] );
		if ( empty( $log ) ) {
			echo '<div class="stonewright-empty-state"><p>' . esc_html__( 'No crashes recorded.', 'stonewright' ) . '</p></div>';
			return;
		}
		echo '<table class="wp-list-table widefat fixed striped"><thead><tr>';
		echo '<th scope="col">' . esc_html__( 'File', 'stonewright' ) . '</th>';
		echo '<th scope="col">' . esc_html__( 'Time', 'stonewright' ) . '</th>';
		echo '<th scope="col">' . esc_html__( 'Error', 'stonewright' ) . '</th>';
		echo '</tr></thead><tbody>';
		foreach ( $log as $entry ) {
			$entry = (array) $entry;
			echo '<tr>';
			echo '<td>' . esc_html( (string) ( $entry['file'] ?? '' ) ) . '</td>';
			echo '<td>' . esc_html( (string) wp_date( 'Y-m-d H:i', (int) ( $entry['time'] ?? 0 ) ) ) . '</td>';
			echo '<td>' . esc_html( (string) ( $entry['error'] ?? '' ) ) . '</td>';
			echo '</tr>';
		}
		echo '</tbody></table>';
	}

	/**
	 * Renders Audit Log tab — delegates to AuditLogPage::render_inline().
	 */
	private static function render_audit_tab(): void {
		AuditLogPage::render_inline();
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
