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

		add_submenu_page(
			self::SLUG,
			__( 'Configuration', 'stonewright' ),
			__( 'Configuration', 'stonewright' ),
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
			'default'           => false,
			'sanitize_callback' => static fn( mixed $value ): bool => (bool) $value,
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
				'PORT=8765',
				'COMPANION_BEARER_TOKEN=' . $bridge_token,
				'COMPANION_ALLOWED_ORIGINS=http://localhost,http://127.0.0.1',
			]
		);
		$atomic_enabled      = (bool) get_option( 'stonewright_elementor_v4_atomic', false );
		$essential_mode      = (bool) get_option( 'stonewright_essential_tools_mode', false );
		$server_url          = get_rest_url( null, 'mcp/stonewright' );
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
		$example_prompts     = self::example_prompts();
		$app_passwords       = self::application_passwords_for_current_user();
		$status_class        = $enabled ? 'stonewright-badge--ok' : 'stonewright-badge--neutral';
		$status_label        = $enabled ? __( 'Enabled', 'stonewright' ) : __( 'Disabled', 'stonewright' );
		$risk_class          = 'production-safe' === $mode
			? 'stonewright-risk-notice--ok'
			: 'stonewright-risk-notice--warning';
		?>
		<div class="wrap stonewright-admin-shell stonewright-config-page">
			<div class="stonewright-brand-banner" aria-label="<?php esc_attr_e( 'Stonewright', 'stonewright' ); ?>">
				<span>Stonewright</span>
			</div>

			<header class="stonewright-page-header">
				<div>
					<h1><?php esc_html_e( 'Configuration', 'stonewright' ); ?></h1>
					<p><?php esc_html_e( 'Enable Stonewright, generate an Application Password, then copy one client prompt.', 'stonewright' ); ?></p>
				</div>
				<span class="stonewright-badge <?php echo esc_attr( $status_class ); ?>">
					<?php echo esc_html( $status_label ); ?>
				</span>
			</header>

			<?php if ( wp_get_environment_type() === 'production' && $enabled ) : ?>
				<div class="notice notice-warning">
					<p>
						<strong><?php esc_html_e( 'Production site detected.', 'stonewright' ); ?></strong>
						<?php esc_html_e( 'Use production-safe mode for confirmation-token checks on destructive operations.', 'stonewright' ); ?>
					</p>
				</div>
			<?php endif; ?>

			<section class="stonewright-setup-grid" aria-label="<?php esc_attr_e( 'Stonewright setup steps', 'stonewright' ); ?>">
				<form method="post" action="options.php" class="stonewright-setup-step stonewright-settings-form">
					<?php settings_fields( self::OPTION_GROUP ); ?>
					<div class="stonewright-step-index">1</div>
					<div class="stonewright-step-body">
						<h2><?php esc_html_e( 'Enable AI Abilities', 'stonewright' ); ?></h2>
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
						<div class="stonewright-field-row">
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
						<label class="stonewright-switch">
							<input
								type="checkbox"
								name="stonewright_essential_tools_mode"
								id="stonewright_essential_tools_mode"
								value="1"
								<?php checked( $essential_mode ); ?>
							/>
							<span><?php esc_html_e( 'Enable essential tools mode for faster MCP startup', 'stonewright' ); ?></span>
						</label>
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
								<div class="stonewright-field-row">
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
								<div class="stonewright-field-row stonewright-secret-field">
									<label for="stonewright_companion_token"><?php esc_html_e( 'Bridge token', 'stonewright' ); ?></label>
									<div class="stonewright-inline-controls">
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

						<p class="submit stonewright-submit-row">
							<?php submit_button( __( 'Save Settings', 'stonewright' ), 'primary', 'submit', false ); ?>
						</p>
					</div>
				</form>

				<div class="stonewright-setup-step" id="stonewright-application-password">
					<div class="stonewright-step-index">2</div>
					<div class="stonewright-step-body">
						<h2><?php esc_html_e( 'Application Password', 'stonewright' ); ?></h2>
						<p class="description"><?php esc_html_e( 'Generate one WordPress Application Password for your AI client. It is embedded into the connection prompt in step 3 and shown only once.', 'stonewright' ); ?></p>

						<?php if ( '' !== $app_password_error ) : ?>
							<div class="notice notice-error inline">
								<p><?php echo esc_html( $app_password_error ); ?></p>
							</div>
						<?php endif; ?>

						<?php if ( is_array( $generated_password ) ) : ?>
							<div class="stonewright-app-password-success">
								<strong><?php esc_html_e( 'Application password generated.', 'stonewright' ); ?></strong>
								<span><?php esc_html_e( 'It is now embedded in the connection prompt in step 3. Save it somewhere safe; it will not be shown in full again.', 'stonewright' ); ?></span>
								<div class="stonewright-inline-controls">
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
								<div class="stonewright-field-row">
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
							</form>
							<p>
								<a href="<?php echo esc_url( admin_url( 'profile.php#application-passwords-section' ) ); ?>">
									<?php esc_html_e( 'I already have an application password', 'stonewright' ); ?>
								</a>
							</p>
							<?php self::render_application_password_table( $app_passwords ); ?>
						<?php else : ?>
							<div class="notice notice-warning inline">
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

				<div class="stonewright-setup-step stonewright-setup-step--wide">
					<div class="stonewright-step-index">3</div>
					<div class="stonewright-step-body">
						<h2><?php esc_html_e( 'Connect MCP Client', 'stonewright' ); ?></h2>
						<p><?php esc_html_e( 'Copy this setup note into your AI client, or expand the JSON snippets for manual configuration.', 'stonewright' ); ?></p>
						<div class="stonewright-share-warning">
							<strong><?php esc_html_e( 'Heads up:', 'stonewright' ); ?></strong>
							<?php if ( '' !== $app_password_value ) : ?>
								<?php esc_html_e( 'This prompt includes the generated Application Password. Prefer manual configuration if you do not want to paste credentials into chat.', 'stonewright' ); ?>
							<?php else : ?>
								<?php esc_html_e( 'Generate an Application Password in step 2 to embed it automatically, or use manual configuration below.', 'stonewright' ); ?>
							<?php endif; ?>
						</div>
						<pre
							id="stonewright-connect-prompt-full"
							class="stonewright-connect-prompt"
							data-stonewright-text-preview="<?php echo esc_attr( $prompt_preview ); ?>"
							data-stonewright-text-full="<?php echo esc_attr( $connect_prompt ); ?>"
							data-stonewright-expanded="false"
						><?php echo esc_html( $prompt_preview ); ?></pre>
						<div class="stonewright-inline-controls stonewright-connect-actions">
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
						<div class="stonewright-link-stack">
							<button
								type="button"
								class="button-link"
								data-stonewright-toggle-target="stonewright-client-configs"
								aria-expanded="false"
							><?php esc_html_e( 'Need the JSON config for a specific client?', 'stonewright' ); ?></button>
							<button
								type="button"
								class="button-link"
								data-stonewright-toggle-target="stonewright-example-prompts"
								aria-expanded="false"
							><?php esc_html_e( 'Examples: real Stonewright prompts', 'stonewright' ); ?></button>
						</div>
						<div id="stonewright-client-configs" hidden>
							<div class="stonewright-endpoint-row">
								<span><?php esc_html_e( 'MCP endpoint', 'stonewright' ); ?></span>
								<code id="stonewright-mcp-endpoint"><?php echo esc_html( $server_url ); ?></code>
								<button type="button" class="button button-small" data-stonewright-copy="stonewright-mcp-endpoint">
									<?php esc_html_e( 'Copy', 'stonewright' ); ?>
								</button>
							</div>
							<?php self::render_client_tabs( $username, $prompt_password ); ?>
						</div>
						<div id="stonewright-example-prompts" class="stonewright-example-prompts" hidden>
							<?php self::render_example_prompts( $example_prompts ); ?>
						</div>
					</div>
				</div>
			</section>

			<?php self::render_domain_lock_status(); ?>
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
	private static function render_example_prompts( array $examples ): void {
		?>
		<div class="stonewright-example-grid">
			<?php foreach ( $examples as $index => $example ) : ?>
				<?php $code_id = 'stonewright-example-prompt-' . (string) $index; ?>
				<div class="stonewright-example-card">
					<h3><?php echo esc_html( $example['title'] ); ?></h3>
					<pre id="<?php echo esc_attr( $code_id ); ?>"><?php echo esc_html( $example['prompt'] ); ?></pre>
					<button type="button" class="button button-small" data-stonewright-copy="<?php echo esc_attr( $code_id ); ?>">
						<?php esc_html_e( 'Copy example', 'stonewright' ); ?>
					</button>
				</div>
			<?php endforeach; ?>
		</div>
		<?php
	}

	private static function render_client_tabs( string $username, string $app_password ): void {
		$clients      = ConnectClientConfig::clients();
		$default_slug = 'claude-desktop';
		?>
		<div class="stonewright-client-tabs" role="tablist" aria-label="<?php esc_attr_e( 'Client snippets', 'stonewright' ); ?>">
			<?php foreach ( $clients as $client ) : ?>
				<?php $is_active = $client['slug'] === $default_slug; ?>
				<button
					type="button"
					role="tab"
					class="stonewright-client-tab<?php echo $is_active ? ' is-active' : ''; ?>"
					data-stonewright-client-tab="stonewright-client-panel-<?php echo esc_attr( $client['slug'] ); ?>"
					aria-selected="<?php echo $is_active ? 'true' : 'false'; ?>"
					aria-controls="stonewright-client-panel-<?php echo esc_attr( $client['slug'] ); ?>"
				><?php echo esc_html( $client['label'] ); ?></button>
			<?php endforeach; ?>
		</div>

		<?php foreach ( $clients as $client ) : ?>
			<?php
			$snippet = ConnectClientConfig::snippet_for(
				$client['slug'],
				$username,
				$app_password
			);
			if ( is_wp_error( $snippet ) ) {
				continue;
			}

			$is_active = $client['slug'] === $default_slug;
			$is_cli    = isset( $snippet['command'] ) && is_string( $snippet['command'] );
			$display   = $is_cli
				? $snippet['command']
				: (string) wp_json_encode( $snippet, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES );
			$panel_id  = 'stonewright-client-panel-' . $client['slug'];
			$code_id   = 'stonewright-client-snippet-' . $client['slug'];
			?>
			<div
				id="<?php echo esc_attr( $panel_id ); ?>"
				class="stonewright-client-panel<?php echo $is_active ? ' is-active' : ''; ?>"
				role="tabpanel"
				<?php echo $is_active ? '' : 'hidden'; ?>
			>
				<p class="stonewright-client-config-path">
					<strong><?php echo $is_cli ? esc_html__( 'Run command:', 'stonewright' ) : esc_html__( 'Config file:', 'stonewright' ); ?></strong>
					<code><?php echo esc_html( $client['config_path'] ); ?></code>
				</p>
				<p class="description"><?php echo esc_html( $client['notes'] ); ?></p>
				<pre id="<?php echo esc_attr( $code_id ); ?>"><code><?php echo esc_html( $display ); ?></code></pre>
				<button type="button" class="button button-small" data-stonewright-copy="<?php echo esc_attr( $code_id ); ?>">
					<?php esc_html_e( 'Copy snippet', 'stonewright' ); ?>
				</button>
			</div>
		<?php endforeach; ?>
		<?php
	}

	private static function prompt_preview( string $prompt ): string {
		$lines = explode( "\n", $prompt );
		$slice = array_slice( $lines, 0, 8 );
		return implode( "\n", $slice ) . "\n\n" . __( '-- full setup rules hidden; expand below --', 'stonewright' );
	}

	/**
	 * @return array<int, array{title: string, prompt: string}>
	 */
	private static function example_prompts(): array {
		return [
			[
				'title'  => __( 'Elementor landing page from screenshot', 'stonewright' ),
				'prompt' => __( 'Use Stonewright on this WordPress site. Start with stonewright-context-bootstrap and stonewright-workflow-preflight. Build a new Elementor landing page from the attached screenshot, use native Elementor widgets first, use stonewright-elementor-v3-build-page-from-spec for the first pass, then stonewright-elementor-v3-batch-mutate for grouped fixes. Verify desktop, tablet, and mobile with no horizontal overflow.', 'stonewright' ),
			],
			[
				'title'  => __( 'CPT, custom fields, and Loop Grid', 'stonewright' ),
				'prompt' => __( 'Use Stonewright to create a Services content model with fields for icon, headline, summary, proof metric, and CTA. Seed six service rows with stonewright-content-bulk-upsert-posts, then create an Elementor Loop Item and Loop Grid that reads those fields dynamically. Keep the result responsive and visually polished.', 'stonewright' ),
			],
			[
				'title'  => __( 'ACF field group for case studies', 'stonewright' ),
				'prompt' => __( 'Use Stonewright to create an ACF field group for Case Studies with client logo, industry, challenge, solution, results metrics, testimonial, gallery, and CTA fields. Attach it to the case-study post type, add three sample entries, and verify fields are available for dynamic Elementor templates.', 'stonewright' ),
			],
			[
				'title'  => __( 'CPT UI content model and archive', 'stonewright' ),
				'prompt' => __( 'Use Stonewright with CPT UI to create a Projects post type and Project Type taxonomy. Add labels, archive support, featured images, REST visibility, and sensible rewrite slugs. Then seed sample projects and build a responsive archive layout that can be filtered by taxonomy.', 'stonewright' ),
			],
			[
				'title'  => __( 'Figma design to Elementor V3', 'stonewright' ),
				'prompt' => __( 'Use Stonewright to implement the attached Figma design in Elementor V3. Start with context bootstrap and workflow preflight, extract layout, spacing, colors, typography, and responsive behavior, create a validated design spec, render with stonewright-elementor-v3-build-page-from-spec, then use batch mutate for polish. Verify desktop, tablet, and mobile screenshots against the design.', 'stonewright' ),
			],
			[
				'title'  => __( 'WooCommerce product cleanup', 'stonewright' ),
				'prompt' => __( 'Use Stonewright to inspect WooCommerce catalog data, normalize product titles, SKUs, prices, categories, and stock for the provided list, then verify the shop and product pages still render correctly. Use bulk or batch tools where possible and report changed products.', 'stonewright' ),
			],
			[
				'title'  => __( 'Gutenberg/FSE site section', 'stonewright' ),
				'prompt' => __( 'Use Stonewright to update the block theme homepage hero and footer. Start with workflow preflight, read current templates and theme.json, snapshot before writes, preserve existing brand tokens, then verify the frontend in desktop and mobile viewports.', 'stonewright' ),
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
