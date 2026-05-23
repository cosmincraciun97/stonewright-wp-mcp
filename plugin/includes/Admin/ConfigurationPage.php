<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\Admin;

use Stonewright\WpMcp\Security\DomainLock;

/**
 * Stonewright Configuration admin page (Page 1).
 *
 * Top-level menu entry plus the Configuration sub-page. Registers all
 * stonewright_settings options including the new master toggle and
 * custom-instructions-enabled flag so they persist even before the
 * MemoryInstructionsPage is wired up.
 */
final class ConfigurationPage {

	public const SLUG         = 'stonewright';
	private const CAPABILITY  = 'manage_options';
	private const OPTION_GROUP = 'stonewright_settings';

	/**
	 * Wire WordPress hooks. Safe to call at plugin boot.
	 */
	public static function register(): void {
		add_action( 'admin_menu', [ self::class, 'add_menu' ] );
		add_action( 'admin_init', [ self::class, 'register_settings' ] );
		add_action( 'admin_post_stonewright_reset_domain_lock', [ self::class, 'handle_reset_domain_lock' ] );
	}

	/**
	 * Register top-level menu + first sub-page. Runs on admin_menu only.
	 */
	public static function add_menu(): void {
		add_menu_page(
			__( 'Stonewright', 'stonewright' ),
			__( 'Stonewright', 'stonewright' ),
			self::CAPABILITY,
			self::SLUG,
			[ self::class, 'render' ],
			'dashicons-hammer',
			76
		);

		add_submenu_page(
			self::SLUG,
			__( 'Configuration', 'stonewright' ),
			__( 'Configuration', 'stonewright' ),
			self::CAPABILITY,
			self::SLUG,
			[ self::class, 'render' ]
		);
	}

	/**
	 * Register all stonewright_settings options.
	 */
	public static function register_settings(): void {
		// NEW: master enable toggle.
		register_setting( self::OPTION_GROUP, 'stonewright_enabled', [
			'type'              => 'boolean',
			'default'           => false,
			'sanitize_callback' => static function ( $value ): bool {
				return (bool) $value;
			},
		] );

		// NEW: custom instructions on/off (persisted here so it survives before
		// MemoryInstructionsPage is registered).
		register_setting( self::OPTION_GROUP, 'stonewright_custom_instructions_enabled', [
			'type'              => 'boolean',
			'default'           => true,
			'sanitize_callback' => static function ( $value ): bool {
				return (bool) $value;
			},
		] );

		// EXISTING: copied verbatim from SettingsPage so option group is coherent.
		register_setting( self::OPTION_GROUP, 'stonewright_mode', [
			'type'              => 'string',
			'default'           => 'development',
			'sanitize_callback' => static function ( $value ): string {
				$value = is_string( $value ) ? strtolower( trim( $value ) ) : '';
				return in_array( $value, [ 'development', 'staging', 'production-safe' ], true )
					? $value
					: 'development';
			},
		] );

		register_setting( self::OPTION_GROUP, 'stonewright_figma_token', [
			'type'              => 'string',
			'default'           => '',
			'sanitize_callback' => 'sanitize_text_field',
		] );

		register_setting( self::OPTION_GROUP, 'stonewright_companion_url', [
			'type'              => 'string',
			'default'           => 'http://127.0.0.1:8765',
			'sanitize_callback' => 'esc_url_raw',
		] );

		register_setting( self::OPTION_GROUP, 'stonewright_companion_token', [
			'type'              => 'string',
			'default'           => '',
			'sanitize_callback' => 'sanitize_text_field',
		] );

		register_setting( self::OPTION_GROUP, 'stonewright_elementor_v4_atomic', [
			'type'              => 'boolean',
			'default'           => false,
			'sanitize_callback' => static function ( $value ): bool {
				return (bool) $value;
			},
		] );
	}

	/**
	 * Render the Configuration page HTML.
	 */
	public static function render(): void {
		if ( ! current_user_can( self::CAPABILITY ) ) {
			return;
		}

		$enabled       = (bool) get_option( 'stonewright_enabled', false );
		$is_production = wp_get_environment_type() === 'production';
		$mode          = (string) get_option( 'stonewright_mode', 'development' );
		$server_url    = get_rest_url( null, 'mcp/stonewright' );
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'Stonewright', 'stonewright' ); ?></h1>
			<p><?php esc_html_e( 'Design-accurate WordPress building for Gutenberg and Elementor. Connect any MCP-compatible AI client.', 'stonewright' ); ?></p>

			<?php if ( $is_production && $enabled ) : ?>
				<div class="notice notice-warning">
					<p>
						<strong><?php esc_html_e( 'Production site detected.', 'stonewright' ); ?></strong>
						<?php esc_html_e( ' Disable AI Abilities on production or use \'production-safe\' mode to restrict destructive operations and require confirmation tokens.', 'stonewright' ); ?>
					</p>
				</div>
			<?php endif; ?>

			<form method="post" action="options.php">
				<?php settings_fields( self::OPTION_GROUP ); ?>

				<!-- Card 1: Enable AI Abilities -->
				<div class="stonewright-card">
					<h2>
						<span class="stonewright-step">1</span>
						<?php esc_html_e( 'Enable AI Abilities', 'stonewright' ); ?>
					</h2>
					<p><?php esc_html_e( 'Master switch, mode selector, and connection settings.', 'stonewright' ); ?></p>
					<table class="form-table" role="presentation">
						<tbody>
							<tr>
								<th scope="row">
									<label for="stonewright_enabled"><?php esc_html_e( 'AI Abilities', 'stonewright' ); ?></label>
								</th>
								<td>
									<label>
										<input
											type="checkbox"
											name="stonewright_enabled"
											id="stonewright_enabled"
											value="1"
											<?php checked( $enabled ); ?>
										/>
										<?php esc_html_e( 'Enable AI Abilities (master switch). When off, the MCP server rejects all tool calls except ping.', 'stonewright' ); ?>
									</label>
								</td>
							</tr>

							<tr>
								<th scope="row">
									<label for="stonewright_mode"><?php esc_html_e( 'Mode', 'stonewright' ); ?></label>
								</th>
								<td>
									<select name="stonewright_mode" id="stonewright_mode">
										<option value="development" <?php selected( $mode, 'development' ); ?>><?php esc_html_e( 'Development', 'stonewright' ); ?></option>
										<option value="staging" <?php selected( $mode, 'staging' ); ?>><?php esc_html_e( 'Staging', 'stonewright' ); ?></option>
										<option value="production-safe" <?php selected( $mode, 'production-safe' ); ?>><?php esc_html_e( 'Production (safe)', 'stonewright' ); ?></option>
									</select>
									<p class="description"><?php esc_html_e( 'Production mode disables destructive abilities and requires confirmation tokens.', 'stonewright' ); ?></p>
								</td>
							</tr>

							<tr>
								<th scope="row">
									<label for="stonewright_figma_token"><?php esc_html_e( 'Figma personal access token', 'stonewright' ); ?></label>
								</th>
								<td>
									<input
										type="password"
										class="regular-text"
										name="stonewright_figma_token"
										id="stonewright_figma_token"
										value="<?php echo esc_attr( (string) get_option( 'stonewright_figma_token', '' ) ); ?>"
										autocomplete="off"
									/>
									<p class="description"><?php esc_html_e( 'Used by the Figma importer ability. Stored in wp_options.', 'stonewright' ); ?></p>
								</td>
							</tr>

							<tr>
								<th scope="row">
									<label for="stonewright_companion_url"><?php esc_html_e( 'Companion bridge URL', 'stonewright' ); ?></label>
								</th>
								<td>
									<input
										type="url"
										class="regular-text"
										name="stonewright_companion_url"
										id="stonewright_companion_url"
										value="<?php echo esc_attr( (string) get_option( 'stonewright_companion_url', 'http://127.0.0.1:8765' ) ); ?>"
										autocomplete="off"
									/>
									<p class="description"><?php esc_html_e( 'The Node companion endpoint used for screenshots, pixel diff, and Lighthouse.', 'stonewright' ); ?></p>
								</td>
							</tr>

							<tr>
								<th scope="row">
									<label for="stonewright_companion_token"><?php esc_html_e( 'Companion bearer token', 'stonewright' ); ?></label>
								</th>
								<td>
									<input
										type="password"
										class="regular-text"
										name="stonewright_companion_token"
										id="stonewright_companion_token"
										value="<?php echo esc_attr( (string) get_option( 'stonewright_companion_token', '' ) ); ?>"
										autocomplete="off"
									/>
								</td>
							</tr>

							<tr>
								<th scope="row">
									<label for="stonewright_elementor_v4_atomic"><?php esc_html_e( 'Elementor V4 atomic (experimental)', 'stonewright' ); ?></label>
								</th>
								<td>
									<label>
										<input
											type="checkbox"
											name="stonewright_elementor_v4_atomic"
											id="stonewright_elementor_v4_atomic"
											value="1"
											<?php checked( (bool) get_option( 'stonewright_elementor_v4_atomic', false ) ); ?>
										/>
										<?php esc_html_e( 'Enable the experimental V4 atomic renderer and related abilities.', 'stonewright' ); ?>
									</label>
								</td>
							</tr>
						</tbody>
					</table>
				</div>

				<!-- Card 2: Application Password -->
				<div class="stonewright-card">
					<h2>
						<span class="stonewright-step">2</span>
						<?php esc_html_e( 'Application Password', 'stonewright' ); ?>
					</h2>
					<p><?php esc_html_e( 'Generate the password your AI client uses to authenticate with WordPress.', 'stonewright' ); ?></p>
					<a
						href="<?php echo esc_url( admin_url( 'profile.php#application-passwords-section' ) ); ?>"
						class="button button-primary"
						target="_blank"
					>
						<?php esc_html_e( 'Manage application passwords', 'stonewright' ); ?>
					</a>
					<p class="description"><?php esc_html_e( 'Generates new passwords in your WP profile under "Application Passwords".', 'stonewright' ); ?></p>
				</div>

				<!-- Card 3: Connect Your AI Client -->
				<div class="stonewright-card">
					<h2>
						<span class="stonewright-step">3</span>
						<?php esc_html_e( 'Connect Your AI Client', 'stonewright' ); ?>
					</h2>
					<p>
						<?php esc_html_e( 'Server URL:', 'stonewright' ); ?>
						<code><?php echo esc_html( $server_url ); ?></code>
					</p>
					<p><?php esc_html_e( 'Pick your AI client to see the exact snippet to paste into its config file. Replace your-wp-username with your WP username and your-application-password with the password generated in step 2.', 'stonewright' ); ?></p>

					<?php
					$clients      = ConnectClientConfig::clients();
					$default_slug = 'claude-desktop';
					?>
					<div class="stonewright-client-tabs" role="tablist" aria-label="<?php esc_attr_e( 'AI client snippets', 'stonewright' ); ?>">
						<?php
                        foreach ( $clients as $client ) :
							$is_active = $client['slug'] === $default_slug;
							?>
							<button
								type="button"
								role="tab"
								class="stonewright-client-tab<?php echo $is_active ? ' is-active' : ''; ?>"
								data-target="stonewright-client-panel-<?php echo esc_attr( $client['slug'] ); ?>"
								aria-selected="<?php echo $is_active ? 'true' : 'false'; ?>"
								aria-controls="stonewright-client-panel-<?php echo esc_attr( $client['slug'] ); ?>"
							><?php echo esc_html( $client['label'] ); ?></button>
						<?php endforeach; ?>
					</div>

					<?php
                    foreach ( $clients as $client ) :
						$snippet = ConnectClientConfig::snippet_for( $client['slug'], 'your-wp-username', 'your-application-password' );
						if ( is_wp_error( $snippet ) ) {
							continue;
						}
						$is_active = $client['slug'] === $default_slug;
						// Claude Code returns a CLI command, not JSON; everything else is a JSON config.
						$is_cli  = isset( $snippet['command'] ) && is_string( $snippet['command'] );
						$display = $is_cli
							? $snippet['command']
							: (string) wp_json_encode( $snippet, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES );
						?>
						<div
							id="stonewright-client-panel-<?php echo esc_attr( $client['slug'] ); ?>"
							class="stonewright-client-panel<?php echo $is_active ? ' is-active' : ''; ?>"
							role="tabpanel"
							<?php
                            if ( ! $is_active ) :
?>
hidden<?php endif; ?>
						>
							<p class="stonewright-client-config-path">
								<strong><?php echo $is_cli ? esc_html__( 'Run this command:', 'stonewright' ) : esc_html__( 'Config file:', 'stonewright' ); ?></strong>
								<code><?php echo esc_html( $client['config_path'] ); ?></code>
							</p>
							<p class="description"><?php echo esc_html( $client['notes'] ); ?></p>
							<pre><code><?php echo esc_html( $display ); ?></code></pre>
						</div>
					<?php endforeach; ?>
				</div>

				<?php if ( $enabled ) : ?>
				<!-- Card 3.5: Paste to AI agent — onboarding block -->
				<div class="stonewright-card stonewright-paste-card">
					<h2>
						<span class="stonewright-step">4</span>
						<?php esc_html_e( 'Paste this to your AI agent', 'stonewright' ); ?>
					</h2>
					<p><?php esc_html_e( 'Copy the message below and paste it directly into your AI chat to have it set up the MCP connection automatically.', 'stonewright' ); ?></p>
					<?php
					$mcp_url   = esc_html( $server_url );
					$wp_user   = esc_html( wp_get_current_user()->user_login );
					$paste_msg = sprintf(
						/* translators: %1$s: MCP server URL, %2$s: WP username */
						__( 'Connect to Stonewright MCP at %1$s using username %2$s and application password. Once connected, run stonewright/ping to verify the connection.', 'stonewright' ),
						$mcp_url,
						$wp_user
					);
					?>
					<div class="stonewright-paste-block">
						<pre id="stonewright-paste-msg" class="stonewright-paste-pre"><?php echo esc_html( $paste_msg ); ?></pre>
						<button
							type="button"
							class="button stonewright-copy-btn"
							id="stonewright-copy-paste"
							data-target="stonewright-paste-msg"
						><?php esc_html_e( '📋 Copy', 'stonewright' ); ?></button>
					</div>
				</div>
				<?php endif; ?>

				<?php
				// Domain-lock status row.
				$locked_domain = DomainLock::locked_domain();
				if ( '' !== $locked_domain ) :
				?>
				<div class="stonewright-domain-lock-status">
					<span class="stonewright-lock-icon">🔒</span>
					<?php
					printf(
						/* translators: %s: locked domain URL */
						esc_html__( 'Abilities locked to: %s', 'stonewright' ),
						'<code>' . esc_html( $locked_domain ) . '</code>'
					);
					?>
					<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="display:inline;margin-left:8px;">
						<input type="hidden" name="action" value="stonewright_reset_domain_lock"/>
						<?php wp_nonce_field( 'stonewright_reset_domain_lock' ); ?>
						<button type="submit" class="button button-small"><?php esc_html_e( 'Reset lock', 'stonewright' ); ?></button>
					</form>
				</div>
				<?php endif; ?>

				<?php submit_button( __( 'Save changes', 'stonewright' ) ); ?>
			</form>

			<style>
				.stonewright-card { background: #fff; padding: 16px 20px; margin: 16px 0; border-left: 4px solid #2271b1; box-shadow: 0 1px 1px rgba(0,0,0,0.04); }
				.stonewright-card h2 { display: flex; align-items: center; gap: 10px; margin-top: 0; }
				.stonewright-step { display: inline-flex; align-items: center; justify-content: center; width: 28px; height: 28px; background: #1d2327; color: #fff; border-radius: 50%; font-size: 14px; }
				.stonewright-client-tabs { display: flex; flex-wrap: wrap; gap: 4px; border-bottom: 1px solid #c3c4c7; margin: 12px 0 0; padding: 0; }
				.stonewright-client-tab { background: #f6f7f7; border: 1px solid #c3c4c7; border-bottom: none; border-radius: 4px 4px 0 0; padding: 6px 12px; cursor: pointer; font-size: 13px; color: #1d2327; margin-bottom: -1px; }
				.stonewright-client-tab:hover { background: #fff; }
				.stonewright-client-tab.is-active { background: #fff; border-bottom: 1px solid #fff; font-weight: 600; }
				.stonewright-client-tab:focus { outline: 2px solid #2271b1; outline-offset: 1px; }
				.stonewright-client-panel { border: 1px solid #c3c4c7; border-top: none; padding: 12px 16px; background: #fff; }
				.stonewright-client-panel[hidden] { display: none; }
				.stonewright-client-panel pre { background: #f6f7f7; padding: 12px; overflow-x: auto; margin: 8px 0 0; }
				.stonewright-client-config-path code { background: #f0f0f1; padding: 2px 6px; border-radius: 2px; font-size: 12px; }
				.stonewright-paste-block { position: relative; }
				.stonewright-paste-pre { background: #f6f7f7; padding: 12px; white-space: pre-wrap; word-wrap: break-word; border: 1px solid #c3c4c7; border-radius: 4px; margin: 8px 0; font-size: 13px; }
				.stonewright-copy-btn { margin-top: 4px; }
				.stonewright-copy-btn.copied { background: #00a32a; color: #fff; border-color: #00a32a; }
				.stonewright-domain-lock-status { display: flex; align-items: center; gap: 6px; padding: 8px 12px; background: #f6f7f7; border: 1px solid #c3c4c7; border-radius: 4px; margin: 8px 0 12px; font-size: 13px; }
				.stonewright-lock-icon { font-size: 16px; }
			</style>
			<script>
				(function () {
					document.querySelectorAll('.stonewright-client-tab').forEach(function (tab) {
						tab.addEventListener('click', function () {
							var target = tab.getAttribute('data-target');
							document.querySelectorAll('.stonewright-client-tab').forEach(function (t) {
								t.classList.remove('is-active');
								t.setAttribute('aria-selected', 'false');
							});
							document.querySelectorAll('.stonewright-client-panel').forEach(function (p) {
								p.classList.remove('is-active');
								p.setAttribute('hidden', '');
							});
							tab.classList.add('is-active');
							tab.setAttribute('aria-selected', 'true');
							var panel = document.getElementById(target);
							if (panel) {
								panel.classList.add('is-active');
								panel.removeAttribute('hidden');
							}
						});
					});

					// Copy-to-clipboard for paste-to-agent block.
					var copyBtn = document.getElementById('stonewright-copy-paste');
					if (copyBtn) {
						copyBtn.addEventListener('click', function () {
							var target = document.getElementById(copyBtn.getAttribute('data-target'));
							if (!target) return;
							navigator.clipboard.writeText(target.textContent || '').then(function () {
								copyBtn.textContent = '✅ Copied!';
								copyBtn.classList.add('copied');
								setTimeout(function () {
									copyBtn.textContent = '📋 Copy';
									copyBtn.classList.remove('copied');
								}, 2000);
							});
						});
					}
				}());
			</script>
		</div>
		<?php
	}

	/**
	 * Handle the "Reset lock" form POST — clears the stored domain lock.
	 */
	public static function handle_reset_domain_lock(): void {
		if ( ! current_user_can( self::CAPABILITY ) ) {
			wp_die( esc_html__( 'Insufficient permissions.', 'stonewright' ) );
		}
		check_admin_referer( 'stonewright_reset_domain_lock' );
		DomainLock::reset();
		wp_safe_redirect( add_query_arg( [ 'page' => self::SLUG, 'lock_reset' => '1' ], admin_url( 'admin.php' ) ) );
		exit;
	}
}
