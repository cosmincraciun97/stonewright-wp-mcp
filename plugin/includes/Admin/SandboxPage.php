<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\Admin;

use Stonewright\WpMcp\Sandbox\SandboxFiles;

/**
 * Stonewright Sandbox admin page.
 *
 * Provides a file manager UI for wp-content/stonewright-sandbox/ drafts.
 * Activating a draft copies it to mu-plugins/ after StaticGuard passes.
 * Crashed files are auto-disabled by CrashRecovery.
 */
final class SandboxPage {

	private const SLUG       = 'stonewright-sandbox';
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
		add_submenu_page(
			'stonewright',
			__( 'Sandbox', 'stonewright' ),
			__( 'Sandbox', 'stonewright' ),
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
		<div class="wrap">
			<h1><?php esc_html_e( 'Sandbox', 'stonewright' ); ?></h1>
			<p><?php esc_html_e( "Drafts in wp-content/stonewright-sandbox/. Activating copies the file to mu-plugins/ after static analysis passes. Crashed files are auto-disabled.", 'stonewright' ); ?></p>

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

			<?php /* New File toggle form */ ?>
			<div style="margin-bottom: 1em;">
				<button type="button" class="button" onclick="document.getElementById('stonewright-new-file-form').style.display='block';this.style.display='none';">
					<?php esc_html_e( '+ New File', 'stonewright' ); ?>
				</button>
			</div>

			<div id="stonewright-new-file-form" style="display:none; background:#fff; border:1px solid #ccd0d4; padding:16px; margin-bottom:1.5em; max-width:640px;">
				<h2 style="margin-top:0;"><?php esc_html_e( 'Create New Sandbox File', 'stonewright' ); ?></h2>
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
									<textarea id="stonewright_new_contents" name="stonewright_contents" rows="12" cols="80" style="font-family:monospace;"></textarea>
								</td>
							</tr>
						</tbody>
					</table>
					<?php submit_button( __( 'Create File', 'stonewright' ), 'primary', 'submit', false ); ?>
					&nbsp;
					<button type="button" class="button" onclick="document.getElementById('stonewright-new-file-form').style.display='none';document.querySelector('.button[onclick]').style.display='';">
						<?php esc_html_e( 'Cancel', 'stonewright' ); ?>
					</button>
				</form>
			</div>

			<?php /* File table */ ?>
			<?php if ( empty( $files ) ) : ?>
				<p><?php esc_html_e( 'No sandbox files yet. Create one above.', 'stonewright' ); ?></p>
			<?php else : ?>
				<table class="wp-list-table widefat fixed striped">
					<thead>
						<tr>
							<th scope="col"><?php esc_html_e( 'Filename', 'stonewright' ); ?></th>
							<th scope="col" style="width:90px;"><?php esc_html_e( 'Status', 'stonewright' ); ?></th>
							<th scope="col" style="width:80px;"><?php esc_html_e( 'Size', 'stonewright' ); ?></th>
							<th scope="col" style="width:160px;"><?php esc_html_e( 'Modified', 'stonewright' ); ?></th>
							<th scope="col"><?php esc_html_e( 'Actions', 'stonewright' ); ?></th>
						</tr>
					</thead>
					<tbody>
						<?php foreach ( $files as $file ) : ?>
							<?php
							$fname   = $file['name'];
							$status  = $file['status'];
							$size    = size_format( $file['size'] );
							$mtime   = wp_date( 'Y-m-d H:i', $file['modified'] );

							// Badge styling per status.
							$badge_style = match ( $status ) {
								'active'   => 'background:#00a32a;color:#fff;padding:1px 6px;border-radius:3px;font-size:11px;',
								'disabled' => 'background:#dba617;color:#fff;padding:1px 6px;border-radius:3px;font-size:11px;',
								'crashed'  => 'background:#d63638;color:#fff;padding:1px 6px;border-radius:3px;font-size:11px;',
								default    => 'background:#78716c;color:#fff;padding:1px 6px;border-radius:3px;font-size:11px;',
							};
							?>
							<tr>
								<td><?php echo esc_html( $fname ); ?></td>
								<td><span style="<?php echo esc_attr( $badge_style ); ?>"><?php echo esc_html( ucfirst( $status ) ); ?></span></td>
								<td><?php echo esc_html( $size ); ?></td>
								<td><?php echo esc_html( (string) $mtime ); ?></td>
								<td>
									<?php /* Edit link (no form — just a GET) */ ?>
									<a href="<?php echo esc_url( add_query_arg( [ 'page' => self::SLUG, 'edit' => rawurlencode( $fname ) ], admin_url( 'admin.php' ) ) ); ?>" class="button button-small"><?php esc_html_e( 'Edit', 'stonewright' ); ?></a>

									<?php /* Activate */ ?>
									<?php if ( 'draft' === $status ) : ?>
										<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="display:inline;">
											<input type="hidden" name="action" value="stonewright_sandbox_action"/>
											<input type="hidden" name="stonewright_file_action" value="activate"/>
											<input type="hidden" name="stonewright_filename" value="<?php echo esc_attr( $fname ); ?>"/>
											<?php wp_nonce_field( self::NONCE_ACTION, '_stonewright_nonce' ); ?>
											<button type="submit" class="button button-small"><?php esc_html_e( 'Activate', 'stonewright' ); ?></button>
										</form>
									<?php endif; ?>

									<?php /* Deactivate */ ?>
									<?php if ( 'active' === $status ) : ?>
										<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="display:inline;">
											<input type="hidden" name="action" value="stonewright_sandbox_action"/>
											<input type="hidden" name="stonewright_file_action" value="deactivate"/>
											<input type="hidden" name="stonewright_filename" value="<?php echo esc_attr( $fname ); ?>"/>
											<?php wp_nonce_field( self::NONCE_ACTION, '_stonewright_nonce' ); ?>
											<button type="submit" class="button button-small"><?php esc_html_e( 'Deactivate', 'stonewright' ); ?></button>
										</form>
									<?php endif; ?>

									<?php /* Disable */ ?>
									<?php if ( 'active' === $status ) : ?>
										<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="display:inline;">
											<input type="hidden" name="action" value="stonewright_sandbox_action"/>
											<input type="hidden" name="stonewright_file_action" value="disable"/>
											<input type="hidden" name="stonewright_filename" value="<?php echo esc_attr( $fname ); ?>"/>
											<?php wp_nonce_field( self::NONCE_ACTION, '_stonewright_nonce' ); ?>
											<button type="submit" class="button button-small"><?php esc_html_e( 'Disable', 'stonewright' ); ?></button>
										</form>
									<?php endif; ?>

									<?php /* Enable */ ?>
									<?php if ( 'disabled' === $status ) : ?>
										<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="display:inline;">
											<input type="hidden" name="action" value="stonewright_sandbox_action"/>
											<input type="hidden" name="stonewright_file_action" value="enable"/>
											<input type="hidden" name="stonewright_filename" value="<?php echo esc_attr( $fname ); ?>"/>
											<?php wp_nonce_field( self::NONCE_ACTION, '_stonewright_nonce' ); ?>
											<button type="submit" class="button button-small"><?php esc_html_e( 'Enable', 'stonewright' ); ?></button>
										</form>
									<?php endif; ?>

									<?php /* Delete */ ?>
									<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="display:inline;" onsubmit="return confirm('<?php echo esc_js( __( 'Delete this file? This cannot be undone.', 'stonewright' ) ); ?>');">
										<input type="hidden" name="action" value="stonewright_sandbox_action"/>
										<input type="hidden" name="stonewright_file_action" value="delete"/>
										<input type="hidden" name="stonewright_filename" value="<?php echo esc_attr( $fname ); ?>"/>
										<?php wp_nonce_field( self::NONCE_ACTION, '_stonewright_nonce' ); ?>
										<button type="submit" class="button button-small button-link-delete"><?php esc_html_e( 'Delete', 'stonewright' ); ?></button>
									</form>
								</td>
							</tr>
						<?php endforeach; ?>
					</tbody>
				</table>
			<?php endif; ?>

			<?php /* Inline editor */ ?>
			<?php if ( '' !== $edit_name ) : ?>
				<div style="margin-top:2em;">
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
											<textarea id="stonewright_edit_contents" name="stonewright_contents" rows="25" cols="100" style="font-family:monospace;width:100%;max-width:900px;"><?php echo esc_textarea( $edit_contents ); ?></textarea>
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
	 * @param true|\WP_Error $result     Operation result.
	 * @param string         $edit_name  If set, redirect to editor for that file on success.
	 */
	private static function redirect_with_result( true|\WP_Error $result, string $edit_name = '' ): void {
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
