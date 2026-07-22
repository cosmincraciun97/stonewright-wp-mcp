<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\Admin;

use Stonewright\WpMcp\Security\DomainLock;

/**
 * Stonewright Configuration admin page.
 */
final class ConfigurationPage {

	public const SLUG          = 'stonewright';
	private const CAPABILITY   = 'manage_options';
	private const OPTION_GROUP = 'stonewright_settings';

	public static function register(): void {
		add_action( 'admin_menu', [ self::class, 'add_menu' ] );
		add_action( 'admin_init', [ self::class, 'register_settings' ] );
		add_action( 'admin_post_stonewright_reset_domain_lock', [ self::class, 'handle_reset_domain_lock' ] );
		add_action(
			'admin_post_stonewright_generate_application_password',
			[ self::class, 'handle_generate_application_password' ]
		);
		add_action(
			'admin_post_stonewright_revoke_application_password',
			[ self::class, 'handle_revoke_application_password' ]
		);
		add_action( 'wp_ajax_stonewright_set_setup_client', [ self::class, 'handle_set_setup_client' ] );
		add_action( 'wp_ajax_stonewright_apply_mcp_surface', [ self::class, 'handle_apply_mcp_surface' ] );
	}

	/**
	 * Apply MCP surface immediately without a full settings form save.
	 */
	public static function handle_apply_mcp_surface(): void {
		if ( ! current_user_can( self::CAPABILITY ) ) {
			wp_send_json_error( [ 'message' => 'forbidden' ], 403 );
		}

		check_ajax_referer( 'stonewright_setup_client', 'nonce' );

		$surface = isset( $_POST['surface'] ) ? sanitize_key( (string) wp_unslash( $_POST['surface'] ) ) : '';
		if ( ! in_array( $surface, [ 'bootstrap', 'essential', 'full' ], true ) ) {
			wp_send_json_error( [ 'message' => 'invalid_surface' ], 400 );
		}

		$saved = \Stonewright\WpMcp\Core\AbilityRegistry::set_mcp_surface( $surface );
		if ( $saved !== $surface ) {
			wp_send_json_error(
				[
					'message' => __( 'The MCP surface could not be persisted. The previous value is still active.', 'stonewright' ),
					'surface' => $saved,
				],
				500
			);
		}
		wp_send_json_success(
			[
				'surface'         => $saved,
				'mcp_surface'     => $saved,
				'surface_revision' => \Stonewright\WpMcp\Core\AbilityRegistry::surface_revision(),
				'message'         => __( 'MCP surface applied and verified.', 'stonewright' ),
				'transport_truth' => __( 'Surface revision bumped. HTTP clients pick this up on their next tools/list; companion sessions re-list automatically on the next task-start or tool-profile response when they see the new surface_revision. Older companions need a client restart.', 'stonewright' ),
			]
		);
	}

	/**
	 * Persist last selected setup client and/or transport method for the current user.
	 */
	public static function handle_set_setup_client(): void {
		if ( ! current_user_can( self::CAPABILITY ) ) {
			wp_send_json_error( [ 'message' => 'forbidden' ], 403 );
		}

		check_ajax_referer( 'stonewright_setup_client', 'nonce' );

		$slug   = isset( $_POST['client'] ) ? sanitize_key( (string) wp_unslash( $_POST['client'] ) ) : '';
		$method = isset( $_POST['method'] ) ? sanitize_key( (string) wp_unslash( $_POST['method'] ) ) : '';
		$known  = ClientCatalog::slugs();

		if ( '' !== $slug && ! in_array( $slug, $known, true ) ) {
			wp_send_json_error( [ 'message' => 'invalid_client' ], 400 );
		}
		if ( '' !== $method && ! in_array( $method, [ 'stdio', 'http' ], true ) ) {
			wp_send_json_error( [ 'message' => 'invalid_method' ], 400 );
		}
		if ( '' === $slug && '' === $method ) {
			wp_send_json_error( [ 'message' => 'missing_selection' ], 400 );
		}

		$user_id = get_current_user_id();
		if ( $user_id <= 0 ) {
			wp_send_json_error( [ 'message' => 'no_user' ], 400 );
		}

		if ( '' !== $slug ) {
			update_user_meta( $user_id, 'stonewright_setup_client', $slug );
		}
		if ( '' !== $method ) {
			update_user_meta( $user_id, 'stonewright_setup_method', $method );
		}

		$saved_client = self::selected_setup_client( $user_id );
		$saved_method = self::selected_setup_method( $user_id );
		wp_send_json_success(
			[
				'client' => $saved_client,
				'method' => $saved_method,
			]
		);
	}

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

		// IA group: Connect — slug unchanged for deep links and ConfigurationPage.
		add_submenu_page(
			self::SLUG,
			__( 'Setup', 'stonewright' ),
			__( 'Connect', 'stonewright' ),
			self::CAPABILITY,
			self::SLUG,
			[ self::class, 'render' ]
		);
	}

	public static function register_settings(): void {
		register_setting( self::OPTION_GROUP, 'stonewright_enabled', [
			'type'              => 'boolean',
			'default'           => false,
			'sanitize_callback' => static fn( mixed $value ): bool => (bool) $value,
		] );

		register_setting( self::OPTION_GROUP, 'stonewright_custom_instructions_enabled', [
			'type'              => 'boolean',
			'default'           => true,
			'sanitize_callback' => static fn( mixed $value ): bool => (bool) $value,
		] );

		register_setting( self::OPTION_GROUP, 'stonewright_essential_tools_mode', [
			'type'              => 'boolean',
			'default'           => true,
			'sanitize_callback' => static fn( mixed $value ): bool => (bool) $value,
		] );

		register_setting( self::OPTION_GROUP, 'stonewright_mcp_surface', [
			'type'              => 'string',
			'default'           => 'bootstrap',
			'sanitize_callback' => static function ( mixed $value ): string {
				$value = is_string( $value ) ? strtolower( trim( $value ) ) : '';
				if ( ! in_array( $value, [ 'bootstrap', 'essential', 'full' ], true ) ) {
					$value = 'essential';
				}
				// Keep legacy essential_tools_mode aligned with the surface choice.
				update_option( 'stonewright_essential_tools_mode', 'full' !== $value, false );
				return $value;
			},
		] );

		register_setting( self::OPTION_GROUP, 'stonewright_mode', [
			'type'              => 'string',
			'default'           => 'development',
			'sanitize_callback' => static function ( mixed $value ): string {
				$value = is_string( $value ) ? strtolower( trim( $value ) ) : '';
				return in_array( $value, [ 'development', 'staging', 'production-safe' ], true )
					? $value
					: 'development';
			},
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
			'sanitize_callback' => static fn( mixed $value ): bool => (bool) $value,
		] );

		register_setting( self::OPTION_GROUP, 'stonewright_unsplash_access_key', [
			'type'              => 'string',
			'default'           => '',
			'sanitize_callback' => 'sanitize_text_field',
		] );

		register_setting( self::OPTION_GROUP, 'stonewright_pexels_api_key', [
			'type'              => 'string',
			'default'           => '',
			'sanitize_callback' => 'sanitize_text_field',
		] );
	}

	public static function render(): void {
		if ( ! current_user_can( self::CAPABILITY ) ) {
			return;
		}

		$enabled             = (bool) get_option( 'stonewright_enabled', false );
		$mode                = (string) get_option( 'stonewright_mode', 'development' );
		$companion_url       = (string) get_option( 'stonewright_companion_url', 'http://127.0.0.1:8765' );
		$companion_token     = (string) get_option( 'stonewright_companion_token', '' );
		$bridge_token        = '' !== $companion_token ? $companion_token : '<choose-a-long-random-token>';
		$bridge_launch_env   = implode(
			"\n",
			[
				'STONEWRIGHT_HTTP_ENABLE=1',
				'PORT=8765',
				'COMPANION_BEARER_TOKEN=' . $bridge_token,
				'COMPANION_ALLOWED_ORIGINS=http://localhost,http://127.0.0.1',
			]
		);
		$atomic_enabled      = (bool) get_option( 'stonewright_elementor_v4_atomic', false );
		$essential_mode      = (bool) get_option( 'stonewright_essential_tools_mode', true );
		$mcp_surface         = \Stonewright\WpMcp\Core\AbilityRegistry::mcp_surface();
		$unsplash_key        = (string) get_option( 'stonewright_unsplash_access_key', '' );
		$pexels_key          = (string) get_option( 'stonewright_pexels_api_key', '' );
		$current_user        = wp_get_current_user();
		$current_user_id     = get_current_user_id();
		$username            = isset( $current_user->user_login ) ? (string) $current_user->user_login : '';
		$username            = '' !== $username ? $username : 'your-wp-username';
		$app_password_flash  = self::consume_application_password_flash( $current_user_id );
		$generated_password  = $app_password_flash['generated'];
		$app_password_error  = $app_password_flash['error'];
		$app_password_value  = is_array( $generated_password )
			? (string) ( $generated_password['password'] ?? '' )
			: '';
		$prompt_password     = '' !== $app_password_value ? $app_password_value : '<application-password-from-step-2>';
		$connect_prompt      = ConnectClientConfig::paste_to_agent_prompt( $username, $prompt_password );
		$prompt_preview      = self::prompt_preview( $connect_prompt );
		$app_passwords       = self::application_passwords_for_current_user();
		$risk_class          = 'production-safe' === $mode
			? 'stonewright-risk-notice--ok'
			: 'stonewright-risk-notice--warning';
		$setup_diagnostics   = SetupDiagnostics::report();
		$has_app_password    = is_array( $generated_password ) || [] !== $app_passwords;
		$step_states         = self::step_states( $enabled, $has_app_password );
		$selected_client     = self::selected_setup_client( $current_user_id );
		$selected_method     = self::selected_setup_method( $current_user_id );
		?>
		<?php AdminShell::open( self::SLUG ); ?>
		<div class="stonewright-config-page sw-setup-page">
			<header class="sw-setup-header">
				<div>
					<h1><?php esc_html_e( 'Setup', 'stonewright' ); ?></h1>
					<p><?php esc_html_e( 'Enable Stonewright, generate an Application Password, then connect your AI client.', 'stonewright' ); ?></p>
				</div>
			</header>

			<?php if ( wp_get_environment_type() === 'production' && $enabled ) : ?>
				<div class="notice notice-warning sw-notice">
					<p>
						<strong><?php esc_html_e( 'Production site detected.', 'stonewright' ); ?></strong>
						<?php esc_html_e( 'Use production-safe mode for confirmation-token checks on destructive operations.', 'stonewright' ); ?>
					</p>
				</div>
			<?php endif; ?>

			<section class="sw-setup-diagnostics" aria-label="<?php esc_attr_e( 'Setup diagnostics', 'stonewright' ); ?>">
				<h2><?php esc_html_e( 'Setup diagnostics', 'stonewright' ); ?></h2>
				<ul class="sw-checklist">
					<?php foreach ( $setup_diagnostics['checks'] as $check ) : ?>
						<?php
						$status = (string) ( $check['status'] ?? 'error' );
						$icon   = match ( $status ) {
							'ok'   => '✓',
							'warn' => '!',
							'info' => 'ⓘ',
							default => '✗',
						};
						?>
						<li class="sw-checklist__item sw-checklist__item--<?php echo esc_attr( $status ); ?>" data-status="<?php echo esc_attr( $status ); ?>">
							<span class="sw-checklist__icon" aria-hidden="true"><?php echo esc_html( $icon ); ?></span>
							<span class="sw-checklist__body">
								<strong class="sw-checklist__label"><?php echo esc_html( $check['label'] ); ?></strong>
								<span class="sw-checklist__detail"><?php echo esc_html( $check['detail'] ); ?></span>
							</span>
						</li>
					<?php endforeach; ?>
				</ul>
				<p class="description">
					<?php
					echo esc_html(
						sprintf(
							/* translators: 1: plugin version, 2: companion contract version. */
							__( 'Plugin %1$s; companion contract %2$s. A major contract mismatch is blocked before companion calls.', 'stonewright' ),
							(string) $setup_diagnostics['versions']['plugin'],
							(string) $setup_diagnostics['versions']['companion_contract']
						)
					);
					?>
				</p>
			</section>

			<nav class="sw-stepper" aria-label="<?php esc_attr_e( 'Setup progress', 'stonewright' ); ?>">
				<?php
				$step_labels = [
					1 => __( 'Enable', 'stonewright' ),
					2 => __( 'App Password', 'stonewright' ),
					3 => __( 'Connect client', 'stonewright' ),
				];
				foreach ( $step_labels as $num => $label ) :
					$state = $step_states[ $num ];
					?>
					<div class="sw-stepper__step sw-stepper__step--<?php echo esc_attr( $state ); ?>" data-step="<?php echo esc_attr( (string) $num ); ?>" data-state="<?php echo esc_attr( $state ); ?>">
						<span class="sw-stepper__index" aria-hidden="true"><?php echo esc_html( (string) $num ); ?></span>
						<span class="sw-stepper__label"><?php echo esc_html( $label ); ?></span>
					</div>
				<?php endforeach; ?>
			</nav>

			<section class="stonewright-setup-grid sw-setup-steps" aria-label="<?php esc_attr_e( 'Stonewright setup steps', 'stonewright' ); ?>">
				<form method="post" action="options.php" class="stonewright-setup-step stonewright-settings-form sw-setup-card sw-setup-card--<?php echo esc_attr( $step_states[1] ); ?>">
					<?php settings_fields( self::OPTION_GROUP ); ?>
					<div class="stonewright-step-index" aria-hidden="true">1</div>
					<div class="stonewright-step-body">
						<h2><?php esc_html_e( 'Enable AI Abilities', 'stonewright' ); ?></h2>
						<div class="sw-field">
							<label class="stonewright-switch">
								<input
									type="checkbox"
									name="stonewright_enabled"
									id="stonewright_enabled"
									value="1"
									<?php checked( $enabled ); ?>
								/>
								<span><?php esc_html_e( 'Turn on Stonewright abilities for this site', 'stonewright' ); ?></span>
							</label>
						</div>
						<div class="sw-field stonewright-field-row">
							<label for="stonewright_mode"><?php esc_html_e( 'Mode', 'stonewright' ); ?></label>
							<select name="stonewright_mode" id="stonewright_mode">
								<option value="development" <?php selected( $mode, 'development' ); ?>>
									<?php esc_html_e( 'Development', 'stonewright' ); ?>
								</option>
								<option value="staging" <?php selected( $mode, 'staging' ); ?>>
									<?php esc_html_e( 'Staging', 'stonewright' ); ?>
								</option>
								<option value="production-safe" <?php selected( $mode, 'production-safe' ); ?>>
									<?php esc_html_e( 'Production-safe', 'stonewright' ); ?>
								</option>
							</select>
						</div>
						<div class="stonewright-risk-notice <?php echo esc_attr( $risk_class ); ?>">
							<?php esc_html_e( 'Production-safe mode requires confirmation tokens for destructive operations.', 'stonewright' ); ?>
						</div>
						<div class="sw-field" data-sw-mcp-surface-field>
							<label for="stonewright_mcp_surface"><?php esc_html_e( 'MCP tool surface', 'stonewright' ); ?></label>
							<div class="sw-field-inline" style="display:flex;flex-wrap:wrap;gap:0.5rem;align-items:center;">
								<select
									name="stonewright_mcp_surface"
									id="stonewright_mcp_surface"
									data-sw-tooltip="<?php echo esc_attr( __( 'HTTP clients pick this up on their next tools/list. Current stdio companion sessions sync on the next task-start or tool-profile response; older companions need a client restart.', 'stonewright' ) ); ?>"
								>
									<option value="bootstrap" <?php selected( $mcp_surface, 'bootstrap' ); ?>>
										<?php esc_html_e( 'Bootstrap (≤8 tools — progressive discovery)', 'stonewright' ); ?>
									</option>
									<option value="essential" <?php selected( $mcp_surface, 'essential' ); ?>>
										<?php esc_html_e( 'Essential (compact fast path)', 'stonewright' ); ?>
									</option>
									<option value="full" <?php selected( $mcp_surface, 'full' ); ?>>
										<?php esc_html_e( 'Full (slow / high-context — all registered tools)', 'stonewright' ); ?>
									</option>
								</select>
								<button
									type="button"
									class="sw-btn sw-btn--secondary sw-btn--sm"
									id="stonewright-apply-mcp-surface"
									data-sw-apply-mcp-surface
									data-sw-tooltip="<?php echo esc_attr( __( 'Saves and verifies the MCP surface immediately. HTTP clients pick this up on their next tools/list; current stdio sessions sync on the next task-start or tool-profile response.', 'stonewright' ) ); ?>"
								>
									<?php esc_html_e( 'Apply now', 'stonewright' ); ?>
								</button>
							</div>
							<p class="description" id="stonewright-mcp-surface-status" data-sw-mcp-surface-status role="status" aria-live="polite">
								<?php esc_html_e( 'Bootstrap is the recommended default for new clients. Full mode loads the entire ability surface and is slow and high-context — use only for specialist sessions. Use Apply now to save the surface without a full form submit, or Save settings for all fields.', 'stonewright' ); ?>
							</p>
						</div>
						<div class="sw-field">
							<label class="stonewright-switch">
								<input
									type="checkbox"
									name="stonewright_elementor_v4_atomic"
									id="stonewright_elementor_v4_atomic"
									value="1"
									<?php checked( $atomic_enabled ); ?>
								/>
								<span><?php esc_html_e( 'Enable Elementor V4 atomic abilities', 'stonewright' ); ?></span>
							</label>
						</div>
						<div class="sw-field">
							<label for="stonewright_unsplash_access_key"><?php esc_html_e( 'Unsplash access key (optional)', 'stonewright' ); ?></label>
							<input
								type="password"
								class="regular-text"
								name="stonewright_unsplash_access_key"
								id="stonewright_unsplash_access_key"
								value="<?php echo esc_attr( $unsplash_key ); ?>"
								autocomplete="off"
							/>
							<p class="description"><?php esc_html_e( 'Leave empty to keep Unsplash off. Openverse stock search works without any key.', 'stonewright' ); ?></p>
						</div>
						<div class="sw-field">
							<label for="stonewright_pexels_api_key"><?php esc_html_e( 'Pexels API key (optional)', 'stonewright' ); ?></label>
							<input
								type="password"
								class="regular-text"
								name="stonewright_pexels_api_key"
								id="stonewright_pexels_api_key"
								value="<?php echo esc_attr( $pexels_key ); ?>"
								autocomplete="off"
							/>
							<p class="description"><?php esc_html_e( 'Leave empty to keep Pexels off. Only used by stock-image abilities when set.', 'stonewright' ); ?></p>
						</div>

						<div class="stonewright-advanced-bridge">
							<button
								type="button"
								class="button-link"
								data-stonewright-toggle-target="stonewright-companion-bridge-settings"
								aria-expanded="false"
							><?php esc_html_e( 'Local WP-CLI bridge (advanced)', 'stonewright' ); ?></button>
							<div id="stonewright-companion-bridge-settings" hidden>
								<div class="stonewright-risk-notice stonewright-risk-notice--info">
									<strong><?php esc_html_e( 'Most users can skip this.', 'stonewright' ); ?></strong>
									<?php esc_html_e( 'Step 3 already runs Stonewright through npx. Use this only if you want WordPress-side WP-CLI abilities to call a local bridge you run yourself.', 'stonewright' ); ?>
								</div>
								<ol class="stonewright-bridge-steps">
									<li><?php esc_html_e( 'Click Generate token.', 'stonewright' ); ?></li>
									<li><?php esc_html_e( 'Save Settings.', 'stonewright' ); ?></li>
									<li><?php esc_html_e( 'Copy the developer launch values into the local bridge process.', 'stonewright' ); ?></li>
								</ol>
								<div class="sw-field stonewright-field-row">
									<label for="stonewright_companion_url"><?php esc_html_e( 'Bridge URL (usually keep default)', 'stonewright' ); ?></label>
									<input
										type="url"
										class="regular-text"
										name="stonewright_companion_url"
										id="stonewright_companion_url"
										value="<?php echo esc_attr( $companion_url ); ?>"
										autocomplete="off"
									/>
								</div>
								<div class="sw-field stonewright-field-row stonewright-secret-field">
									<label for="stonewright_companion_token"><?php esc_html_e( 'Bridge token', 'stonewright' ); ?></label>
									<div class="stonewright-inline-controls sw-actions">
										<input
											type="password"
											class="regular-text"
											name="stonewright_companion_token"
											id="stonewright_companion_token"
											value="<?php echo esc_attr( $companion_token ); ?>"
											autocomplete="off"
										/>
										<button type="button" class="button" data-stonewright-secret-toggle="stonewright_companion_token">
											<?php esc_html_e( 'Reveal', 'stonewright' ); ?>
										</button>
										<button type="button" class="button" data-stonewright-copy="stonewright_companion_token">
											<?php esc_html_e( 'Copy', 'stonewright' ); ?>
										</button>
										<button type="button" class="button" data-stonewright-generate-token="stonewright_companion_token">
											<?php esc_html_e( 'Generate token', 'stonewright' ); ?>
										</button>
									</div>
								</div>
								<details class="stonewright-bridge-env-panel">
									<summary><?php esc_html_e( 'Developer launch values', 'stonewright' ); ?></summary>
									<p class="description"><?php esc_html_e( 'Start the optional bridge with these env vars. The token must match the saved bridge token above.', 'stonewright' ); ?></p>
									<pre
										id="stonewright-companion-bridge-env"
										class="stonewright-bridge-env"
										data-stonewright-bridge-token-source="stonewright_companion_token"
									><?php echo esc_html( $bridge_launch_env ); ?></pre>
									<button type="button" class="button button-small" data-stonewright-copy="stonewright-companion-bridge-env">
										<?php esc_html_e( 'Copy bridge launch env', 'stonewright' ); ?>
									</button>
								</details>
							</div>
						</div>

						<div class="sw-actions stonewright-submit-row">
							<?php submit_button( __( 'Save Settings', 'stonewright' ), 'primary', 'submit', false ); ?>
						</div>
					</div>
				</form>

				<div class="stonewright-setup-step sw-setup-card sw-setup-card--<?php echo esc_attr( $step_states[2] ); ?>" id="stonewright-application-password">
					<div class="stonewright-step-index" aria-hidden="true">2</div>
					<div class="stonewright-step-body">
						<h2><?php esc_html_e( 'Application Password', 'stonewright' ); ?></h2>
						<p class="description"><?php esc_html_e( 'Generate one WordPress Application Password for your AI client. It is embedded into the connection prompt in step 3 and shown only once.', 'stonewright' ); ?></p>

						<?php if ( '' !== $app_password_error ) : ?>
							<div class="notice notice-error inline sw-notice">
								<p><?php echo esc_html( $app_password_error ); ?></p>
							</div>
						<?php endif; ?>

						<?php if ( is_array( $generated_password ) ) : ?>
							<div class="stonewright-app-password-success">
								<strong><?php esc_html_e( 'Application password generated.', 'stonewright' ); ?></strong>
								<span><?php esc_html_e( 'It is now embedded in the connection prompt in step 3. Save it somewhere safe; it will not be shown in full again.', 'stonewright' ); ?></span>
								<div class="stonewright-inline-controls sw-actions">
									<input
										type="text"
										readonly
										class="regular-text"
										id="stonewright-generated-app-password"
										value="<?php echo esc_attr( $app_password_value ); ?>"
									/>
									<button type="button" class="button button-small" data-stonewright-copy="stonewright-generated-app-password">
										<?php esc_html_e( 'Copy password only', 'stonewright' ); ?>
									</button>
								</div>
							</div>
						<?php endif; ?>

						<?php if ( self::application_passwords_available() ) : ?>
							<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="stonewright-app-password-form">
								<input type="hidden" name="action" value="stonewright_generate_application_password"/>
								<?php wp_nonce_field( 'stonewright_generate_application_password' ); ?>
								<div class="sw-field stonewright-field-row">
									<label for="stonewright_app_password_name"><?php esc_html_e( 'Name', 'stonewright' ); ?></label>
									<input
										type="text"
										class="regular-text"
										name="stonewright_app_password_name"
										id="stonewright_app_password_name"
										placeholder="<?php esc_attr_e( 'e.g. Cursor on laptop, Claude Desktop', 'stonewright' ); ?>"
										autocomplete="off"
										required
										aria-required="true"
									/>
									<p class="description"><?php esc_html_e( 'Required. Use a clear client label so you can revoke it later.', 'stonewright' ); ?></p>
								</div>
								<div class="sw-actions">
									<?php
									submit_button(
										is_array( $generated_password )
											? __( 'Generate another application password', 'stonewright' )
											: __( 'Generate application password', 'stonewright' ),
										'primary',
										'submit',
										false
									);
									?>
								</div>
							</form>
							<p>
								<a href="<?php echo esc_url( admin_url( 'profile.php#application-passwords-section' ) ); ?>">
									<?php esc_html_e( 'I already have an application password', 'stonewright' ); ?>
								</a>
							</p>
							<?php self::render_application_password_table( $app_passwords ); ?>
						<?php else : ?>
							<div class="notice notice-warning inline sw-notice">
								<p><?php esc_html_e( 'Application Passwords are not available for this user or site. Enable HTTPS or create one from your WordPress profile.', 'stonewright' ); ?></p>
							</div>
							<a
								href="<?php echo esc_url( admin_url( 'profile.php#application-passwords-section' ) ); ?>"
								class="button button-secondary"
								target="_blank"
								rel="noopener noreferrer"
							><?php esc_html_e( 'Open WordPress profile', 'stonewright' ); ?></a>
						<?php endif; ?>
					</div>
				</div>

				<div class="stonewright-setup-step stonewright-setup-step--wide sw-setup-card sw-setup-card--<?php echo esc_attr( $step_states[3] ); ?>">
					<div class="stonewright-step-index" aria-hidden="true">3</div>
					<div class="stonewright-step-body">
						<h2><?php esc_html_e( 'Connect MCP Client', 'stonewright' ); ?></h2>
						<p><?php esc_html_e( 'Pick your AI client and connection method. Snippets use this site URL, your username, and the Application Password from step 2.', 'stonewright' ); ?></p>
						<div class="stonewright-share-warning">
							<strong><?php esc_html_e( 'Heads up:', 'stonewright' ); ?></strong>
							<?php if ( '' !== $app_password_value ) : ?>
								<?php esc_html_e( 'This prompt includes the generated Application Password. Prefer manual configuration if you do not want to paste credentials into chat.', 'stonewright' ); ?>
							<?php else : ?>
								<?php esc_html_e( 'Generate an Application Password in step 2 to embed it automatically, or use the paste-to-agent prompt below with a placeholder password.', 'stonewright' ); ?>
							<?php endif; ?>
						</div>

						<?php self::render_client_method_picker( $username, $prompt_password, $selected_client, $selected_method ); ?>

						<details class="sw-setup-details">
							<summary><?php esc_html_e( 'Paste-to-agent prompt', 'stonewright' ); ?></summary>
							<pre
								id="stonewright-connect-prompt-full"
								class="stonewright-connect-prompt"
								data-stonewright-text-preview="<?php echo esc_attr( $prompt_preview ); ?>"
								data-stonewright-text-full="<?php echo esc_attr( $connect_prompt ); ?>"
								data-stonewright-expanded="false"
							><?php echo esc_html( $prompt_preview ); ?></pre>
							<div class="stonewright-inline-controls stonewright-connect-actions sw-actions">
								<button
									type="button"
									class="button button-small"
									data-stonewright-text-toggle="stonewright-connect-prompt-full"
									aria-expanded="false"
								><?php esc_html_e( 'Show full text', 'stonewright' ); ?></button>
								<button type="button" class="button button-primary" data-stonewright-copy="stonewright-connect-prompt-full">
									<?php esc_html_e( 'Copy prompt', 'stonewright' ); ?>
								</button>
								<button
									type="button"
									class="button-link"
									data-stonewright-text-collapse="stonewright-connect-prompt-full"
									hidden
								><?php esc_html_e( 'Show less', 'stonewright' ); ?></button>
							</div>
						</details>

						<p class="description">
							<a href="<?php echo esc_url( admin_url( 'admin.php?page=stonewright-prompts' ) ); ?>">
								<?php esc_html_e( 'Open the Prompt Library tab', 'stonewright' ); ?>
							</a>
							<?php esc_html_e( ' for searchable, outcome-grouped starters (20+ prompts).', 'stonewright' ); ?>
						</p>
					</div>
				</div>

				<section class="sw-setup-card sw-setup-verify" aria-label="<?php esc_attr_e( 'Verify connection', 'stonewright' ); ?>">
					<div class="stonewright-step-index" aria-hidden="true">✓</div>
					<div class="stonewright-step-body">
						<h2><?php esc_html_e( 'Verify setup', 'stonewright' ); ?></h2>
						<p class="description"><?php esc_html_e( 'Run preflight for local readiness, then Verify connection for a live authenticated MCP loopback (initialize → tools/list → task-start). Preflight alone does not prove a client is connected.', 'stonewright' ); ?></p>
						<div class="sw-actions">
							<button
								type="button"
								class="button"
								data-stonewright-connection-test
								data-rest-url="<?php echo esc_url( rest_url( 'stonewright/v1/admin/connection-test' ) ); ?>"
								data-rest-nonce="<?php echo esc_attr( wp_create_nonce( 'wp_rest' ) ); ?>"
							><?php esc_html_e( 'Run preflight', 'stonewright' ); ?></button>
							<button
								type="button"
								class="button button-primary"
								data-stonewright-connection-verify
								data-rest-url="<?php echo esc_url( rest_url( 'stonewright/v1/admin/connection-verify' ) ); ?>"
								data-rest-nonce="<?php echo esc_attr( wp_create_nonce( 'wp_rest' ) ); ?>"
							><?php esc_html_e( 'Verify connection', 'stonewright' ); ?></button>
						</div>
						<ul class="sw-checklist sw-connection-test-results" data-stonewright-connection-results hidden aria-live="polite"></ul>
						<ul class="sw-checklist sw-connection-verify-results" data-stonewright-connection-verify-results hidden aria-live="polite"></ul>
					</div>
				</section>
			</section>

			<?php self::render_domain_lock_status(); ?>
		</div>
		<?php AdminShell::close(); ?>
		<?php
	}

	/**
	 * @return array{1: string, 2: string, 3: string} done|current|todo per step.
	 */
	private static function step_states( bool $enabled, bool $has_app_password ): array {
		if ( ! $enabled ) {
			return [ 1 => 'current', 2 => 'todo', 3 => 'todo' ];
		}
		if ( ! $has_app_password ) {
			return [ 1 => 'done', 2 => 'current', 3 => 'todo' ];
		}
		return [ 1 => 'done', 2 => 'done', 3 => 'current' ];
	}

	private static function selected_setup_client( int $user_id ): string {
		$default = 'claude-desktop';
		$known   = ClientCatalog::slugs();
		if ( [] === $known ) {
			return $default;
		}
		if ( ! in_array( $default, $known, true ) ) {
			$default = (string) $known[0];
		}
		if ( $user_id <= 0 ) {
			return $default;
		}
		$saved = get_user_meta( $user_id, 'stonewright_setup_client', true );
		return is_string( $saved ) && in_array( $saved, $known, true ) ? $saved : $default;
	}

	private static function selected_setup_method( int $user_id ): string {
		$default = 'stdio';
		if ( $user_id <= 0 ) {
			return $default;
		}
		$saved = get_user_meta( $user_id, 'stonewright_setup_method', true );
		return is_string( $saved ) && in_array( $saved, [ 'stdio', 'http' ], true ) ? $saved : $default;
	}

	/**
	 * Short card blurb derived from catalog snippet kind.
	 *
	 * @param array<string, mixed> $client Catalog client row.
	 */
	private static function client_card_blurb( array $client ): string {
		return match ( (string) ( $client['snippet_kind'] ?? 'json' ) ) {
			'cli'   => __( 'Terminal / CLI command', 'stonewright' ),
			'toml'  => __( 'TOML config block', 'stonewright' ),
			'mixed' => __( 'Client-specific config', 'stonewright' ),
			default => __( 'JSON MCP config', 'stonewright' ),
		};
	}

	/**
	 * @param array<string, mixed> $snippet Snippet payload from ConnectClientConfig.
	 */
	private static function format_snippet_display( array $snippet ): string {
		if ( isset( $snippet['command'] ) && is_string( $snippet['command'] ) ) {
			return $snippet['command'];
		}
		if ( isset( $snippet['toml'] ) && is_string( $snippet['toml'] ) ) {
			return $snippet['toml'];
		}
		return (string) wp_json_encode( $snippet, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES );
	}

	/**
	 * Single client picker + transport method picker for setup step 3.
	 */
	private static function render_client_method_picker(
		string $username,
		string $app_password,
		string $selected_slug,
		string $selected_method
	): void {
		$clients = ClientCatalog::all();
		$slugs   = array_map(
			static fn( array $client ): string => (string) $client['slug'],
			$clients
		);
		if ( ! in_array( $selected_slug, $slugs, true ) ) {
			$selected_slug = self::selected_setup_client( 0 );
		}
		if ( ! in_array( $selected_method, [ 'stdio', 'http' ], true ) ) {
			$selected_method = 'stdio';
		}
		?>
		<div class="sw-client-picker" data-stonewright-client-picker>
			<div class="sw-client-cards" role="tablist" aria-label="<?php esc_attr_e( 'MCP clients', 'stonewright' ); ?>">
				<?php foreach ( $clients as $client ) : ?>
					<?php
					$slug      = (string) $client['slug'];
					$is_active = $slug === $selected_slug;
					?>
					<button
						type="button"
						role="tab"
						class="sw-client-card<?php echo $is_active ? ' is-active' : ''; ?>"
						data-stonewright-client-card="<?php echo esc_attr( $slug ); ?>"
						aria-selected="<?php echo $is_active ? 'true' : 'false'; ?>"
						aria-controls="sw-client-panel-<?php echo esc_attr( $slug ); ?>"
					>
						<span class="sw-client-card__label"><?php echo esc_html( (string) $client['label'] ); ?></span>
						<span class="sw-client-card__blurb"><?php echo esc_html( self::client_card_blurb( $client ) ); ?></span>
					</button>
				<?php endforeach; ?>
			</div>
		</div>

		<div
			class="sw-method-picker"
			data-stonewright-method-picker
			role="radiogroup"
			aria-label="<?php esc_attr_e( 'Connection method', 'stonewright' ); ?>"
		>
			<p class="description sw-method-picker__note">
				<?php esc_html_e( 'Transports only. The Application Password from step 2 authenticates both methods.', 'stonewright' ); ?>
			</p>
			<div class="sw-method-picker__options">
				<button
					type="button"
					role="radio"
					class="sw-method-option<?php echo 'stdio' === $selected_method ? ' is-active' : ''; ?>"
					data-stonewright-method="stdio"
					aria-checked="<?php echo 'stdio' === $selected_method ? 'true' : 'false'; ?>"
					data-sw-tooltip="<?php echo esc_attr( __( 'Runs the Stonewright companion on the same machine as your AI client. Best for local development: fastest, no public endpoint needed. Requires Node.js on your machine.', 'stonewright' ) ); ?>"
				>
					<span class="sw-method-option__label"><?php esc_html_e( 'Local companion (stdio)', 'stonewright' ); ?></span>
					<span class="sw-method-option__blurb"><?php esc_html_e( 'npx runs the Stonewright companion on your machine.', 'stonewright' ); ?></span>
				</button>
				<button
					type="button"
					role="radio"
					class="sw-method-option<?php echo 'http' === $selected_method ? ' is-active' : ''; ?>"
					data-stonewright-method="http"
					aria-checked="<?php echo 'http' === $selected_method ? 'true' : 'false'; ?>"
					data-sw-tooltip="<?php echo esc_attr( __( 'Your AI client connects directly to this WordPress site over HTTPS. Best for remote/production sites: nothing to install locally. Requires the application password from this page.', 'stonewright' ) ); ?>"
				>
					<span class="sw-method-option__label"><?php esc_html_e( 'Remote Streamable HTTP', 'stonewright' ); ?></span>
					<span class="sw-method-option__blurb"><?php esc_html_e( 'No Node or companion required.', 'stonewright' ); ?></span>
				</button>
			</div>
		</div>

		<div class="sw-snippet-panels" data-stonewright-snippet-panels>
			<?php foreach ( $clients as $client ) : ?>
				<?php
				$slug      = (string) $client['slug'];
				$is_active = $slug === $selected_slug;
				$is_cli    = 'cli' === (string) ( $client['snippet_kind'] ?? '' );
				$panel_id  = 'sw-client-panel-' . $slug;
				$path      = (string) ( $client['config_path'] ?? '' );
				$notes     = (string) ( $client['notes'] ?? '' );
				?>
				<div
					id="<?php echo esc_attr( $panel_id ); ?>"
					class="sw-client-panel<?php echo $is_active ? ' is-active' : ''; ?>"
					role="tabpanel"
					data-stonewright-client-panel="<?php echo esc_attr( $slug ); ?>"
					<?php echo $is_active ? '' : 'hidden'; ?>
				>
					<?php if ( '' !== $path ) : ?>
						<p class="stonewright-client-config-path">
							<strong><?php echo $is_cli ? esc_html__( 'Run command:', 'stonewright' ) : esc_html__( 'Config file:', 'stonewright' ); ?></strong>
							<code><?php echo esc_html( $path ); ?></code>
						</p>
					<?php endif; ?>
					<?php if ( '' !== $notes ) : ?>
						<p class="description"><?php echo esc_html( $notes ); ?></p>
					<?php endif; ?>

					<?php foreach ( [ 'stdio', 'http' ] as $method ) : ?>
						<?php
						$snippet = ConnectClientConfig::snippet_for( $slug, $username, $app_password, $method );
						if ( is_wp_error( $snippet ) ) {
							continue;
						}
						$code_id     = 'sw-client-snippet-' . $slug . '-' . $method;
						$display     = self::format_snippet_display( $snippet );
						$method_show = $method === $selected_method;
						?>
						<div
							class="sw-method-snippet"
							data-stonewright-method-snippet="<?php echo esc_attr( $method ); ?>"
							<?php echo $method_show ? '' : 'hidden'; ?>
						>
							<?php if ( 'http' === $method ) : ?>
								<p class="description">
									<?php esc_html_e( 'Streamable HTTP against the WordPress MCP endpoint. Keep the companion only when you need local WP-CLI workflows.', 'stonewright' ); ?>
								</p>
							<?php endif; ?>
							<pre id="<?php echo esc_attr( $code_id ); ?>"><code><?php echo esc_html( $display ); ?></code></pre>
							<div class="sw-actions">
								<button type="button" class="button button-primary" data-stonewright-copy="<?php echo esc_attr( $code_id ); ?>">
									<?php esc_html_e( 'Copy snippet', 'stonewright' ); ?>
								</button>
							</div>
						</div>
					<?php endforeach; ?>
				</div>
			<?php endforeach; ?>
		</div>
		<?php
	}

	/**
	 * @param array<int, array<string, mixed>> $app_passwords Application password records.
	 */
	private static function render_application_password_table( array $app_passwords ): void {
		$count = count( $app_passwords );
		?>
		<details class="stonewright-app-passwords-list">
			<summary>
				<?php
				echo esc_html(
					sprintf(
						/* translators: %d: Application Password count. */
						__( 'Manage existing application passwords (%d)', 'stonewright' ),
						$count
					)
				);
				?>
			</summary>
			<?php if ( [] === $app_passwords ) : ?>
				<p class="description"><?php esc_html_e( 'No existing application passwords found for this user.', 'stonewright' ); ?></p>
			<?php else : ?>
				<table class="widefat striped stonewright-app-password-table">
					<thead>
						<tr>
							<th><?php esc_html_e( 'Name', 'stonewright' ); ?></th>
							<th><?php esc_html_e( 'Created', 'stonewright' ); ?></th>
							<th><?php esc_html_e( 'Actions', 'stonewright' ); ?></th>
						</tr>
					</thead>
					<tbody>
						<?php foreach ( $app_passwords as $item ) : ?>
							<?php
							$name    = (string) ( $item['name'] ?? __( 'Unnamed', 'stonewright' ) );
							$uuid    = (string) ( $item['uuid'] ?? '' );
							$created = isset( $item['created'] ) ? (int) $item['created'] : 0;
							$date    = $created > 0
								? (string) wp_date( 'M j, Y g:i a', $created )
								: __( 'Unknown', 'stonewright' );
							?>
							<tr>
								<td><?php echo esc_html( $name ); ?></td>
								<td><?php echo esc_html( $date ); ?></td>
								<td>
									<?php if ( '' !== $uuid ) : ?>
										<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="stonewright-inline-form">
											<input type="hidden" name="action" value="stonewright_revoke_application_password"/>
											<input type="hidden" name="stonewright_app_password_uuid" value="<?php echo esc_attr( $uuid ); ?>"/>
											<?php wp_nonce_field( 'stonewright_revoke_application_password_' . $uuid ); ?>
											<button
												type="submit"
												class="button button-small button-link-delete"
												data-confirm="<?php esc_attr_e( 'Revoke this Application Password? The connected client will lose access immediately.', 'stonewright' ); ?>"
											>
												<?php esc_html_e( 'Revoke', 'stonewright' ); ?>
											</button>
										</form>
									<?php else : ?>
										<span class="description"><?php esc_html_e( 'Open profile to manage', 'stonewright' ); ?></span>
									<?php endif; ?>
								</td>
							</tr>
						<?php endforeach; ?>
					</tbody>
				</table>
			<?php endif; ?>
		</details>
		<?php
	}

	/**
	 * @param array<int, array{title: string, prompt: string}> $examples Example prompts.
	 */
	/**
	 * @param list<array<string, mixed>> $examples
	 */
	private static function render_prompt_library( array $examples ): void {
		$outcomes = [];
		foreach ( $examples as $example ) {
			$outcome = (string) ( $example['outcome'] ?? 'general' );
			$outcomes[ $outcome ] = true;
		}
		$outcome_keys = array_keys( $outcomes );
		sort( $outcome_keys );
		?>
		<div class="stonewright-prompt-library__controls">
			<label class="screen-reader-text" for="stonewright-prompt-search"><?php esc_html_e( 'Search prompts', 'stonewright' ); ?></label>
			<input
				type="search"
				id="stonewright-prompt-search"
				class="regular-text"
				data-stonewright-prompt-search
				placeholder="<?php esc_attr_e( 'Search by outcome, tool, or keyword…', 'stonewright' ); ?>"
			/>
			<label class="screen-reader-text" for="stonewright-prompt-outcome"><?php esc_html_e( 'Filter by outcome', 'stonewright' ); ?></label>
			<select id="stonewright-prompt-outcome" data-stonewright-prompt-outcome>
				<option value=""><?php esc_html_e( 'All outcomes', 'stonewright' ); ?></option>
				<?php foreach ( $outcome_keys as $outcome ) : ?>
					<option value="<?php echo esc_attr( $outcome ); ?>"><?php echo esc_html( $outcome ); ?></option>
				<?php endforeach; ?>
			</select>
		</div>
		<p class="description stonewright-prompt-library__hint">
			<?php esc_html_e( 'Catalog lives in plugin/data/prompts. Agents should call stonewright-task-start (skill refs only) — do not inline full library prompts into task-start.', 'stonewright' ); ?>
		</p>
		<div class="stonewright-example-grid" data-stonewright-prompt-grid>
			<?php foreach ( $examples as $index => $example ) : ?>
				<?php
				$code_id = 'stonewright-example-prompt-' . (string) $index;
				$outcome = (string) ( $example['outcome'] ?? 'general' );
				$tools   = isset( $example['tools'] ) && is_array( $example['tools'] ) ? $example['tools'] : [];
				$search  = strtolower(
					implode(
						' ',
						[
							(string) ( $example['id'] ?? '' ),
							(string) ( $example['title'] ?? '' ),
							$outcome,
							(string) ( $example['summary'] ?? '' ),
							(string) ( $example['prompt'] ?? '' ),
							implode( ' ', $tools ),
						]
					)
				);
				?>
				<div
					class="stonewright-example-card"
					data-stonewright-prompt-card
					data-outcome="<?php echo esc_attr( $outcome ); ?>"
					data-search="<?php echo esc_attr( $search ); ?>"
				>
					<p class="stonewright-prompt-library__outcome"><span class="sw-pill"><?php echo esc_html( $outcome ); ?></span></p>
					<h3><?php echo esc_html( (string) ( $example['title'] ?? '' ) ); ?></h3>
					<?php if ( ! empty( $example['summary'] ) ) : ?>
						<p class="description"><?php echo esc_html( (string) $example['summary'] ); ?></p>
					<?php endif; ?>
					<?php if ( [] !== $tools ) : ?>
						<p class="stonewright-prompt-library__tools"><code><?php echo esc_html( implode( ', ', $tools ) ); ?></code></p>
					<?php endif; ?>
					<pre id="<?php echo esc_attr( $code_id ); ?>"><?php echo esc_html( (string) ( $example['prompt'] ?? '' ) ); ?></pre>
					<?php if ( ! empty( $example['verification'] ) ) : ?>
						<p class="description"><strong><?php esc_html_e( 'Verify:', 'stonewright' ); ?></strong> <?php echo esc_html( (string) $example['verification'] ); ?></p>
					<?php endif; ?>
					<button type="button" class="button button-small" data-stonewright-copy="<?php echo esc_attr( $code_id ); ?>">
						<?php esc_html_e( 'Copy prompt', 'stonewright' ); ?>
					</button>
				</div>
			<?php endforeach; ?>
		</div>
		<?php
	}

	/**
	 * @param list<array{title: string, prompt: string}> $examples
	 * @deprecated Use render_prompt_library().
	 */
	private static function render_example_prompts( array $examples ): void {
		self::render_prompt_library( $examples );
	}

	private static function prompt_preview( string $prompt ): string {
		$lines = explode( "\n", $prompt );
		$slice = array_slice( $lines, 0, 8 );
		return implode( "\n", $slice ) . "\n\n" . __( '-- full setup rules hidden; expand below --', 'stonewright' );
	}

	/**
	 * Prompt library catalog (plugin/data/prompts) with hardcoded fallback.
	 *
	 * @return list<array<string, mixed>>
	 */
	private static function example_prompts(): array {
		$catalog = \Stonewright\WpMcp\Support\PromptCatalog::all();
		if ( count( $catalog ) >= 1 ) {
			return $catalog;
		}

		// Minimal fallback if catalog file is missing in a broken install.
		return [
			[
				'id'            => 'fallback-elementor-landing',
				'title'         => __( 'Elementor landing page from screenshot', 'stonewright' ),
				'outcome'       => 'elementor',
				'summary'       => '',
				'prerequisites' => [],
				'tools'         => [ 'stonewright-task-start', 'stonewright-elementor-v3-build-page-from-spec' ],
				'prompt'        => __( 'Use Stonewright on this WordPress site. Call stonewright-task-start. Build a new Elementor landing page from the attached screenshot with native widgets first via stonewright-elementor-v3-build-page-from-spec, then stonewright-elementor-v3-batch-mutate. Verify desktop, tablet, and mobile.', 'stonewright' ),
				'verification'  => '',
			],
		];
	}

	/**
	 * @return array{generated: array<string, mixed>|null, error: string}
	 */
	private static function consume_application_password_flash( int $user_id ): array {
		if ( $user_id <= 0 ) {
			return [ 'generated' => null, 'error' => '' ];
		}

		$key   = self::application_password_flash_key( $user_id );
		$flash = get_transient( $key );
		delete_transient( $key );

		if ( ! is_array( $flash ) ) {
			return [ 'generated' => null, 'error' => '' ];
		}

		$generated = isset( $flash['generated'] ) && is_array( $flash['generated'] )
			? $flash['generated']
			: null;
		$error     = isset( $flash['error'] ) && is_string( $flash['error'] )
			? $flash['error']
			: '';

		return [ 'generated' => $generated, 'error' => $error ];
	}

	private static function application_password_flash_key( int $user_id ): string {
		return 'stonewright_app_password_flash_' . (string) $user_id;
	}

	private static function application_passwords_available(): bool {
		if ( ! class_exists( '\WP_Application_Passwords' ) ) {
			return false;
		}

		if ( function_exists( 'wp_is_application_passwords_available' ) && ! wp_is_application_passwords_available() ) {
			return false;
		}

		if ( function_exists( 'wp_is_application_passwords_available_for_user' ) ) {
			$user = wp_get_current_user();
			return (bool) wp_is_application_passwords_available_for_user( $user );
		}

		return true;
	}

	/**
	 * @return array<int, array<string, mixed>>
	 */
	private static function application_passwords_for_current_user(): array {
		if ( ! self::application_passwords_available() ) {
			return [];
		}

		$user_id = get_current_user_id();
		if ( $user_id <= 0 || ! method_exists( '\WP_Application_Passwords', 'get_user_application_passwords' ) ) {
			return [];
		}

		$passwords = \WP_Application_Passwords::get_user_application_passwords( $user_id );
		return is_array( $passwords ) ? array_values( $passwords ) : [];
	}

	public static function handle_generate_application_password(): void {
		if ( ! current_user_can( self::CAPABILITY ) ) {
			wp_die( esc_html__( 'Insufficient permissions.', 'stonewright' ) );
		}

		check_admin_referer( 'stonewright_generate_application_password' );

		$user_id = get_current_user_id();
		if ( $user_id <= 0 ) {
			self::store_application_password_error( 0, __( 'No current user is available.', 'stonewright' ) );
			self::redirect_to_configuration( 'app_password_error' );
		}

		if ( ! self::application_passwords_available() || ! method_exists( '\WP_Application_Passwords', 'create_new_application_password' ) ) {
			self::store_application_password_error( $user_id, __( 'Application Passwords are not available on this site.', 'stonewright' ) );
			self::redirect_to_configuration( 'app_password_error' );
		}

		$name = isset( $_POST['stonewright_app_password_name'] )
			? sanitize_text_field( wp_unslash( $_POST['stonewright_app_password_name'] ) )
			: '';
		if ( '' === $name ) {
			self::store_application_password_error( $user_id, __( 'Enter a name before generating an Application Password.', 'stonewright' ) );
			self::redirect_to_configuration( 'app_password_error' );
		}

		$created = \WP_Application_Passwords::create_new_application_password( $user_id, [ 'name' => $name ] );
		if ( is_wp_error( $created ) ) {
			self::store_application_password_error( $user_id, $created->get_error_message() );
			self::redirect_to_configuration( 'app_password_error' );
		}

		$password = isset( $created[0] ) && is_string( $created[0] ) ? $created[0] : '';
		$item     = isset( $created[1] ) && is_array( $created[1] ) ? $created[1] : [];
		if ( '' === $password ) {
			self::store_application_password_error( $user_id, __( 'WordPress did not return an Application Password.', 'stonewright' ) );
			self::redirect_to_configuration( 'app_password_error' );
		}

		set_transient(
			self::application_password_flash_key( $user_id ),
			[
				'generated' => [
					'password' => $password,
					'name'     => $name,
					'uuid'     => (string) ( $item['uuid'] ?? '' ),
					'created'  => (int) ( $item['created'] ?? time() ),
				],
			],
			5 * MINUTE_IN_SECONDS
		);

		self::redirect_to_configuration( 'app_password_created' );
	}

	public static function handle_revoke_application_password(): void {
		if ( ! current_user_can( self::CAPABILITY ) ) {
			wp_die( esc_html__( 'Insufficient permissions.', 'stonewright' ) );
		}

		$uuid = isset( $_POST['stonewright_app_password_uuid'] )
			? sanitize_text_field( wp_unslash( $_POST['stonewright_app_password_uuid'] ) )
			: '';

		check_admin_referer( 'stonewright_revoke_application_password_' . $uuid );

		$user_id = get_current_user_id();
		if ( $user_id <= 0 || '' === $uuid ) {
			self::store_application_password_error( $user_id, __( 'Choose an Application Password to revoke.', 'stonewright' ) );
			self::redirect_to_configuration( 'app_password_error' );
		}

		if ( ! method_exists( '\WP_Application_Passwords', 'delete_application_password' ) ) {
			self::store_application_password_error( $user_id, __( 'Application Password revocation is not available on this site.', 'stonewright' ) );
			self::redirect_to_configuration( 'app_password_error' );
		}

		$deleted = \WP_Application_Passwords::delete_application_password( $user_id, $uuid );
		if ( is_wp_error( $deleted ) ) {
			self::store_application_password_error( $user_id, $deleted->get_error_message() );
			self::redirect_to_configuration( 'app_password_error' );
		}

		self::redirect_to_configuration( 'app_password_revoked' );
	}

	private static function store_application_password_error( int $user_id, string $message ): void {
		if ( $user_id <= 0 ) {
			return;
		}

		set_transient(
			self::application_password_flash_key( $user_id ),
			[ 'error' => $message ],
			5 * MINUTE_IN_SECONDS
		);
	}

	private static function redirect_to_configuration( string $status ): void {
		wp_safe_redirect(
			add_query_arg(
				[
					'page' => self::SLUG,
					'stonewright_app_password' => $status,
				],
				admin_url( 'admin.php' )
			) . '#stonewright-application-password'
		);
		exit;
	}

	private static function render_domain_lock_status(): void {
		$locked_domain = DomainLock::locked_domain();
		if ( '' === $locked_domain ) {
			return;
		}
		?>
		<div class="stonewright-domain-lock-status">
			<span><?php esc_html_e( 'Domain lock:', 'stonewright' ); ?></span>
			<code><?php echo esc_html( $locked_domain ); ?></code>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="stonewright-inline-form">
				<input type="hidden" name="action" value="stonewright_reset_domain_lock"/>
				<?php wp_nonce_field( 'stonewright_reset_domain_lock' ); ?>
				<button type="submit" class="button button-small"><?php esc_html_e( 'Reset lock', 'stonewright' ); ?></button>
			</form>
		</div>
		<?php
	}

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
