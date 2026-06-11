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

		$enabled         = (bool) get_option( 'stonewright_enabled', false );
		$mode            = (string) get_option( 'stonewright_mode', 'development' );
		$companion_url   = (string) get_option( 'stonewright_companion_url', 'http://127.0.0.1:8765' );
		$companion_token = (string) get_option( 'stonewright_companion_token', '' );
		$atomic_enabled  = (bool) get_option( 'stonewright_elementor_v4_atomic', false );
		$server_url      = get_rest_url( null, 'mcp/stonewright' );
		$status_class    = $enabled ? 'stonewright-badge--ok' : 'stonewright-badge--neutral';
		$status_label    = $enabled ? __( 'Enabled', 'stonewright' ) : __( 'Disabled', 'stonewright' );
		$risk_class      = 'production-safe' === $mode ? 'stonewright-risk-notice--ok' : 'stonewright-risk-notice--warning';
		$bootstrap_prompt = sprintf(
			/* translators: %s: MCP tool name. */
			__( 'At the start of every Stonewright task, call MCP tool %s and follow the returned instructions, skills, memory, followups, and context token requirements.', 'stonewright' ),
			'stonewright-context-bootstrap'
		);
		$onboarding_prompt = implode( "\n", [
			__( 'Describe the WordPress task, desired page or template, visual reference, allowed plugins, safety mode, and acceptance checks.', 'stonewright' ),
			__( 'Ask the agent to start with stonewright-context-bootstrap, use native WordPress or Elementor abilities first, validate design specs before rendering, snapshot before writes, and verify desktop, tablet, and mobile breakpoints before completion.', 'stonewright' ),
			__( 'For visual work, provide the design URL or screenshot, list required assets, say whether global styles may be changed, and require screenshots plus no-horizontal-overflow checks.', 'stonewright' ),
		] );
		?>
		<div class="wrap stonewright-admin-shell stonewright-config-page">
			<header class="stonewright-page-header">
				<div>
					<h1><?php esc_html_e( 'Stonewright', 'stonewright' ); ?></h1>
					<p><?php esc_html_e( 'Connect clients, set safety mode, and verify the task bootstrap flow.', 'stonewright' ); ?></p>
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

			<form method="post" action="options.php" class="stonewright-settings-form">
				<?php settings_fields( self::OPTION_GROUP ); ?>

				<section class="stonewright-setup-grid" aria-label="<?php esc_attr_e( 'Stonewright setup steps', 'stonewright' ); ?>">
					<div class="stonewright-setup-step">
						<div class="stonewright-step-index">1</div>
						<div class="stonewright-step-body">
							<h2><?php esc_html_e( 'Enable and choose mode', 'stonewright' ); ?></h2>
							<label class="stonewright-switch">
								<input
									type="checkbox"
									name="stonewright_enabled"
									id="stonewright_enabled"
									value="1"
									<?php checked( $enabled ); ?>
								/>
								<span><?php esc_html_e( 'Enable Stonewright abilities', 'stonewright' ); ?></span>
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
									name="stonewright_elementor_v4_atomic"
									id="stonewright_elementor_v4_atomic"
									value="1"
									<?php checked( $atomic_enabled ); ?>
								/>
								<span><?php esc_html_e( 'Enable Elementor V4 atomic abilities', 'stonewright' ); ?></span>
							</label>
						</div>
					</div>

					<div class="stonewright-setup-step">
						<div class="stonewright-step-index">2</div>
						<div class="stonewright-step-body">
							<h2><?php esc_html_e( 'Credentials', 'stonewright' ); ?></h2>
							<div class="stonewright-field-row">
								<label for="stonewright_companion_url"><?php esc_html_e( 'Companion bridge URL', 'stonewright' ); ?></label>
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
								<label for="stonewright_companion_token"><?php esc_html_e( 'Companion bearer token', 'stonewright' ); ?></label>
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
								</div>
							</div>
							<a
								href="<?php echo esc_url( admin_url( 'profile.php#application-passwords-section' ) ); ?>"
								class="button button-secondary"
								target="_blank"
								rel="noopener noreferrer"
							><?php esc_html_e( 'Manage application passwords', 'stonewright' ); ?></a>
						</div>
					</div>

					<div class="stonewright-setup-step stonewright-setup-step--wide">
						<div class="stonewright-step-index">3</div>
						<div class="stonewright-step-body">
							<h2><?php esc_html_e( 'Client snippets', 'stonewright' ); ?></h2>
							<div class="stonewright-endpoint-row">
								<span><?php esc_html_e( 'MCP endpoint', 'stonewright' ); ?></span>
								<code id="stonewright-mcp-endpoint"><?php echo esc_html( $server_url ); ?></code>
								<button type="button" class="button button-small" data-stonewright-copy="stonewright-mcp-endpoint">
									<?php esc_html_e( 'Copy', 'stonewright' ); ?>
								</button>
							</div>
							<?php self::render_client_tabs(); ?>
						</div>
					</div>

					<div class="stonewright-setup-step stonewright-setup-step--wide">
						<div class="stonewright-step-index">4</div>
						<div class="stonewright-step-body">
							<h2><?php esc_html_e( 'Bootstrap prompt', 'stonewright' ); ?></h2>
							<pre id="stonewright-bootstrap-prompt" class="stonewright-bootstrap-prompt"><?php echo esc_html( $bootstrap_prompt ); ?></pre>
							<button type="button" class="button" data-stonewright-copy="stonewright-bootstrap-prompt">
								<?php esc_html_e( 'Copy bootstrap prompt', 'stonewright' ); ?>
							</button>
						</div>
					</div>

					<div class="stonewright-setup-step stonewright-setup-step--wide">
						<div class="stonewright-step-index">5</div>
						<div class="stonewright-step-body">
							<h2><?php esc_html_e( 'Prompting guide', 'stonewright' ); ?></h2>
							<pre id="stonewright-onboarding-prompt" class="stonewright-bootstrap-prompt"><?php echo esc_html( $onboarding_prompt ); ?></pre>
							<button type="button" class="button" data-stonewright-copy="stonewright-onboarding-prompt">
								<?php esc_html_e( 'Copy prompting guide', 'stonewright' ); ?>
							</button>
						</div>
					</div>
				</section>

				<?php self::render_domain_lock_status(); ?>

				<p class="submit stonewright-submit-row">
					<?php submit_button( __( 'Save changes', 'stonewright' ), 'primary', 'submit', false ); ?>
				</p>
			</form>
		</div>
		<?php
	}

	private static function render_client_tabs(): void {
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
				'your-wp-username',
				'your-application-password'
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
