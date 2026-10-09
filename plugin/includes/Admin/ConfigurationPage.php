<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\Admin;

use Stonewright\WpMcp\Admin\Connect\ConnectedClients;
use Stonewright\WpMcp\Admin\Diagnostics\SupportReport;
use Stonewright\WpMcp\Admin\Setup\ApplicationPasswords;
use Stonewright\WpMcp\Admin\Setup\DomainLockCard;
use Stonewright\WpMcp\Admin\Setup\SetupPage;
use Stonewright\WpMcp\Admin\Setup\SetupTabs;
use Stonewright\WpMcp\Companion\CompanionContract;
use Stonewright\WpMcp\Security\DomainLock;
use Stonewright\WpMcp\Security\PluginEffectiveState;

/**
 * Stonewright Configuration admin page.
 */
final class ConfigurationPage {

	public const SLUG          = 'stonewright';
	private const CAPABILITY   = 'manage_options';
	public const OPTION_GROUP  = 'stonewright_settings';

	/** A submitted secret field equal to this text means "unchanged". */
	public const SECRET_MASK = '********';

	public static function register(): void {
		add_action( 'admin_menu', [ self::class, 'add_menu' ] );
		add_action( 'admin_init', [ self::class, 'register_settings' ] );
		add_action( 'admin_post_stonewright_reset_domain_lock', [ self::class, 'handle_reset_domain_lock' ] );
		add_action( 'admin_post_stonewright_rebind_domain_lock', [ self::class, 'handle_rebind_domain_lock' ] );
		add_action( 'admin_post_stonewright_rollback_domain_lock', [ self::class, 'handle_rollback_domain_lock' ] );
		add_action(
			'admin_post_stonewright_generate_application_password',
			[ self::class, 'handle_generate_application_password' ]
		);
		add_action(
			'admin_post_stonewright_revoke_application_password',
			[ self::class, 'handle_revoke_application_password' ]
		);
		add_action( 'admin_post_stonewright_run_diagnostics', [ self::class, 'handle_run_diagnostics' ] );
		add_action( 'admin_post_' . ConnectedClients::ACTION, [ ConnectedClients::class, 'handle' ] );
		add_action( 'wp_ajax_stonewright_set_setup_client', [ self::class, 'handle_set_setup_client' ] );
		add_action( 'wp_ajax_stonewright_apply_mcp_surface', [ self::class, 'handle_apply_mcp_surface' ] );
		add_action( 'wp_ajax_stonewright_run_diagnostics', [ self::class, 'handle_ajax_run_diagnostics' ] );
	}

	/**
	 * Footer copy for Setup diagnostics. Plugin SemVer and the companion HTTP
	 * contract are different numbers; only a contract major mismatch blocks calls.
	 */
	public static function diagnostics_version_copy( string $plugin, string $contract ): string {
		if ( is_wp_error( CompanionContract::validate_version( $contract ) ) ) {
			return sprintf(
				/* translators: 1: plugin SemVer, 2: companion HTTP contract version. */
				__( 'Plugin %1$s. Companion HTTP contract %2$s. A major contract mismatch is blocked before companion calls.', 'stonewright' ),
				$plugin,
				$contract
			);
		}

		return sprintf(
			/* translators: 1: plugin SemVer, 2: companion HTTP contract version. */
			__( 'Plugin %1$s. Companion HTTP contract %2$s.', 'stonewright' ),
			$plugin,
			$contract
		);
	}

	public static function surface_saved_notice(): string {
		return __(
			'Surface saved. Connected MCP clients refresh on their next task-start or tools/list call — restart the client if the tool count does not change.',
			'stonewright'
		);
	}

	/**
	 * Apply every runtime-affecting Step 1 control without a page reload.
	 */
	public static function handle_apply_mcp_surface(): void {
		if ( ! current_user_can( self::CAPABILITY ) ) {
			wp_send_json_error( [ 'message' => 'forbidden' ], 403 );
		}

		check_ajax_referer( 'stonewright_setup_client', 'nonce' );

		$surface = isset( $_POST['surface'] ) ? sanitize_key( (string) wp_unslash( $_POST['surface'] ) ) : '';
		$mode    = isset( $_POST['mode'] ) ? sanitize_key( (string) wp_unslash( $_POST['mode'] ) ) : '';
		if ( ! in_array( $surface, [ 'bootstrap', 'essential', 'full' ], true ) ) {
			wp_send_json_error( [ 'message' => 'invalid_surface' ], 400 );
		}
		if ( '' !== $mode && ! in_array( $mode, [ 'development', 'staging', 'production-safe' ], true ) ) {
			wp_send_json_error( [ 'message' => 'invalid_mode' ], 400 );
		}

		$partial = [ 'mcp_surface' => $surface ];
		if ( '' !== $mode ) {
			$partial['wordpress_mode'] = $mode;
		}
		if ( isset( $_POST['enabled'] ) ) {
			$partial['abilities_requested'] = '1' === (string) wp_unslash( $_POST['enabled'] );
			if ( $partial['abilities_requested'] ) {
				DomainLock::lock();
			}
		}
		if ( isset( $_POST['elementor_v4_atomic'] ) ) {
			$partial['elementor_v4_atomic'] = '1' === (string) wp_unslash( $_POST['elementor_v4_atomic'] );
		}

		$state = SetupState::persist_partial( $partial, get_current_user_id() );
		if ( (string) $state['mcp_surface'] !== $surface ) {
			wp_send_json_error(
				[
					'message' => __( 'The MCP surface could not be persisted. The previous value is still active.', 'stonewright' ),
					'surface' => (string) $state['mcp_surface'],
				],
				500
			);
		}
		wp_send_json_success(
			[
				'surface'          => (string) $state['mcp_surface'],
				'mcp_surface'      => (string) $state['mcp_surface'],
				'surface_revision' => \Stonewright\WpMcp\Core\AbilityRegistry::surface_revision(),
				'setup_state'      => $state,
				'message'          => self::surface_saved_notice(),
				'transport_truth'  => '',
			]
		);
	}

	public static function handle_run_diagnostics(): void {
		if ( ! current_user_can( self::CAPABILITY ) ) {
			wp_die( esc_html__( 'You do not have permission to do this.', 'stonewright' ) );
		}

		check_admin_referer( 'stonewright_run_diagnostics' );

		$method = self::diagnostics_method_from_request();

		$return = isset( $_POST['stonewright_diagnostics_return'] )
			? sanitize_key( (string) wp_unslash( $_POST['stonewright_diagnostics_return'] ) )
			: self::SLUG;
		if ( ! in_array( $return, [ self::SLUG, 'stonewright-troubleshoot' ], true ) ) {
			$return = self::SLUG;
		}

		$report = SetupDiagnostics::report(
			[
				'probe'  => true,
				'method' => $method,
				'mode'   => $method,
			]
		);
		update_option( 'stonewright_diagnostics_last', $report, false );

		wp_safe_redirect(
			add_query_arg(
				[
					'page'                    => $return,
					'stonewright_diagnostics' => '1',
				],
				admin_url( 'admin.php' )
			)
		);
		exit;
	}

	public static function handle_ajax_run_diagnostics(): void {
		if ( ! current_user_can( self::CAPABILITY ) ) {
			wp_send_json_error( [ 'message' => 'forbidden' ], 403 );
			return;
		}

		check_ajax_referer( 'stonewright_setup_client', 'nonce' );

		$method = self::diagnostics_method_from_request();

		$report = SetupDiagnostics::report(
			[
				'probe'  => true,
				'method' => $method,
				'mode'   => $method,
			]
		);
		update_option( 'stonewright_diagnostics_last', $report, false );
		// The support report is built here, with its redaction, so the page copies exactly what the server vetted.
		$report['report_text'] = SupportReport::render( $report );
		wp_send_json_success( $report );
	}

	/**
	 * Canonical connection method from the diagnostics form.
	 */
	private static function diagnostics_method_from_request(): string {
		// phpcs:disable WordPress.Security.NonceVerification.Missing -- Callers verify the diagnostics nonce first.
		$raw = isset( $_POST['mode'] ) ? sanitize_key( (string) wp_unslash( $_POST['mode'] ) ) : '';
		if ( '' === $raw && isset( $_POST['method'] ) ) {
			$raw = sanitize_key( (string) wp_unslash( $_POST['method'] ) );
		}
		// phpcs:enable WordPress.Security.NonceVerification.Missing
		$allowed = array_merge( SetupDiagnostics::METHODS, [ 'both', 'http', 'stdio' ] );
		if ( ! in_array( $raw, $allowed, true ) ) {
			$raw = 'not-sure';
		}

		return SetupDiagnostics::resolve_method(
			[
				'method' => $raw,
				'mode'   => $raw,
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
		$auth   = isset( $_POST['auth_method'] ) ? sanitize_key( (string) wp_unslash( $_POST['auth_method'] ) ) : '';
		if ( '' !== $slug ) {
			$slug = ClientCatalog::resolve_slug( $slug );
		}
		$known  = ClientCatalog::slugs();

		if ( '' !== $slug && ! in_array( $slug, $known, true ) ) {
			wp_send_json_error( [ 'message' => 'invalid_client' ], 400 );
		}
		if ( '' !== $method && ! in_array( $method, [ 'stdio', 'http' ], true ) ) {
			wp_send_json_error( [ 'message' => 'invalid_method' ], 400 );
		}
		if ( '' !== $auth && ! in_array( $auth, [ 'oauth', 'application-password' ], true ) ) {
			wp_send_json_error( [ 'message' => 'invalid_auth_method' ], 400 );
		}
		if ( '' === $slug && '' === $method && '' === $auth ) {
			wp_send_json_error( [ 'message' => 'missing_selection' ], 400 );
		}

		$user_id = get_current_user_id();
		if ( $user_id <= 0 ) {
			wp_send_json_error( [ 'message' => 'no_user' ], 400 );
		}

		$partial = [];
		if ( '' !== $slug ) {
			$partial['selected_client'] = $slug;
		}
		if ( '' !== $method ) {
			$partial['transport_method'] = $method;
		}
		if ( '' !== $auth ) {
			$partial['auth_method'] = $auth;
		}
		$state = SetupState::persist_partial( $partial, $user_id );

		wp_send_json_success(
			[
				'client'      => (string) $state['selected_client'],
				'method'      => (string) $state['transport_method'],
				'auth_method' => (string) $state['auth_method'],
				'setup_state' => $state,
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
			__( 'Setup', 'stonewright' ),
			self::CAPABILITY,
			self::SLUG,
			[ self::class, 'render' ]
		);

		// Links to the stand-alone connected clients page lead to the list on this screen.
		ConnectedClients::register_page();
	}

	public static function register_settings(): void {
		register_setting( self::OPTION_GROUP, PluginEffectiveState::OPTION_REQUESTED, [
			'type'              => 'boolean',
			'default'           => false,
			'sanitize_callback' => static function ( mixed $value ): bool {
				$enabled = (bool) $value;
				// Operator intent only — domain lock is applied separately on enable.
				if ( $enabled ) {
					DomainLock::lock();
				}
				if ( PluginEffectiveState::enabled_requested() !== $enabled ) {
					\Stonewright\WpMcp\Core\AbilityRegistry::bump_surface_revision();
				}
				return $enabled;
			},
		] );

		register_setting( self::OPTION_GROUP, 'stonewright_install_mode', [
			'type'              => 'string',
			'default'           => 'auto',
			'sanitize_callback' => static function ( mixed $value ): string {
				$value = is_string( $value ) ? strtolower( trim( $value ) ) : '';
				return in_array( $value, [ 'direct-only', 'plugin-only', 'auto' ], true ) ? $value : 'auto';
			},
		] );

		register_setting( self::OPTION_GROUP, 'stonewright_site_alias', [
			'type'              => 'string',
			'default'           => '',
			'sanitize_callback' => static fn( mixed $value ): string => sanitize_text_field( is_string( $value ) ? $value : '' ),
		] );

		register_setting( self::OPTION_GROUP, 'stonewright_site_environment', [
			'type'              => 'string',
			'default'           => '',
			'sanitize_callback' => static function ( mixed $value ): string {
				$value = is_string( $value ) ? strtolower( trim( $value ) ) : '';
				return in_array( $value, [ '', 'local', 'development', 'staging', 'production' ], true ) ? $value : '';
			},
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
			'default'           => 'essential',
			'sanitize_callback' => static function ( mixed $value ): string {
				$value = is_string( $value ) ? strtolower( trim( $value ) ) : '';
				if ( ! in_array( $value, [ 'bootstrap', 'essential', 'full' ], true ) ) {
					$value = 'essential';
				}

				$previous = \Stonewright\WpMcp\Core\AbilityRegistry::mcp_surface();
				update_option( 'stonewright_essential_tools_mode', 'full' !== $value, false );
				if ( $previous !== $value ) {
					\Stonewright\WpMcp\Core\AbilityRegistry::bump_surface_revision();
				}

				return $value;
			},
		] );

		register_setting( self::OPTION_GROUP, 'stonewright_mode', [
			'type'              => 'string',
			'default'           => 'development',
			'sanitize_callback' => static function ( mixed $value ): string {
				$value = is_string( $value ) ? strtolower( trim( $value ) ) : '';
				$value = in_array( $value, [ 'development', 'staging', 'production-safe' ], true )
					? $value
					: 'development';
				if ( (string) get_option( 'stonewright_mode', 'development' ) !== $value ) {
					\Stonewright\WpMcp\Core\AbilityRegistry::bump_surface_revision();
				}
				return $value;
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
			'sanitize_callback' => self::secret_sanitizer( 'stonewright_companion_token' ),
		] );

		register_setting( self::OPTION_GROUP, 'stonewright_elementor_v4_atomic', [
			'type'              => 'boolean',
			'default'           => false,
			'sanitize_callback' => static function ( mixed $value ): bool {
				$enabled = (bool) $value;
				if ( (bool) get_option( 'stonewright_elementor_v4_atomic', false ) !== $enabled ) {
					\Stonewright\WpMcp\Core\AbilityRegistry::bump_surface_revision();
				}
				return $enabled;
			},
		] );

		register_setting( self::OPTION_GROUP, 'stonewright_unsplash_access_key', [
			'type'              => 'string',
			'default'           => '',
			'sanitize_callback' => self::secret_sanitizer( 'stonewright_unsplash_access_key' ),
		] );

		register_setting( self::OPTION_GROUP, 'stonewright_pexels_api_key', [
			'type'              => 'string',
			'default'           => '',
			'sanitize_callback' => self::secret_sanitizer( 'stonewright_pexels_api_key' ),
		] );
	}

	/**
	 * Sanitizer for a secret setting.
	 *
	 * The Setup form never carries the stored value, so on that form an empty
	 * (or unchanged mask) field keeps the stored secret, a new value replaces
	 * it, and only an explicit clear request removes it. Updates made from
	 * code are plain text-field updates.
	 *
	 * @return callable(mixed): string
	 */
	private static function secret_sanitizer( string $option ): callable {
		return static function ( mixed $value ) use ( $option ): string {
			$submitted = is_string( $value ) ? sanitize_text_field( $value ) : '';

			// phpcs:disable WordPress.Security.NonceVerification.Missing -- options.php verified the settings nonce before it calls the sanitizers.
			$option_page = isset( $_POST['option_page'] ) ? sanitize_key( (string) wp_unslash( $_POST['option_page'] ) ) : '';
			if ( self::OPTION_GROUP !== $option_page ) {
				return $submitted;
			}

			$clear = isset( $_POST['stonewright_clear_secrets'] ) && is_array( $_POST['stonewright_clear_secrets'] )
				? array_map( 'strval', (array) wp_unslash( $_POST['stonewright_clear_secrets'] ) )
				: [];
			// phpcs:enable WordPress.Security.NonceVerification.Missing
			if ( in_array( $option, $clear, true ) ) {
				return '';
			}

			if ( '' === $submitted || self::SECRET_MASK === $submitted ) {
				return (string) get_option( $option, '' );
			}

			return $submitted;
		};
	}

	public static function render(): void {
		if ( ! current_user_can( self::CAPABILITY ) ) {
			return;
		}

		SetupPage::render();
	}

	public static function handle_generate_application_password(): void {
		if ( ! current_user_can( self::CAPABILITY ) ) {
			wp_die( esc_html__( 'Insufficient permissions.', 'stonewright' ) );
		}

		check_admin_referer( 'stonewright_generate_application_password' );

		$user_id = get_current_user_id();
		if ( $user_id <= 0 ) {
			self::redirect_to_configuration( 'app_password_error' );
		}

		if ( ! ApplicationPasswords::available() || ! method_exists( '\WP_Application_Passwords', 'create_new_application_password' ) ) {
			self::redirect_to_configuration( 'app_password_error' );
		}

		$name = isset( $_POST['stonewright_app_password_name'] )
			? sanitize_text_field( wp_unslash( $_POST['stonewright_app_password_name'] ) )
			: '';
		if ( '' === $name ) {
			self::redirect_to_configuration( 'app_password_error' );
		}

		$created = \WP_Application_Passwords::create_new_application_password( $user_id, [ 'name' => $name ] );
		if ( is_wp_error( $created ) ) {
			self::redirect_to_configuration( 'app_password_error' );
		}

		$password = isset( $created[0] ) && is_string( $created[0] ) ? $created[0] : '';
		if ( '' === $password ) {
			self::redirect_to_configuration( 'app_password_error' );
		}

		self::render_application_password_once( $name, $password );
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
			self::redirect_to_configuration( 'app_password_error' );
		}

		if ( ! method_exists( '\WP_Application_Passwords', 'delete_application_password' ) ) {
			self::redirect_to_configuration( 'app_password_error' );
		}

		$deleted = \WP_Application_Passwords::delete_application_password( $user_id, $uuid );
		if ( is_wp_error( $deleted ) ) {
			self::redirect_to_configuration( 'app_password_error' );
		}

		self::redirect_to_configuration( 'app_password_revoked' );
	}

	/**
	 * No-JavaScript fallback: return the credential exactly once without any
	 * redirect, transient, option, user meta, log, or external asset request.
	 */
	private static function render_application_password_once( string $name, string $password ): never {
		nocache_headers();
		header( 'Cache-Control: no-store, private, max-age=0', true );
		header( 'Referrer-Policy: no-referrer', true );
		header( 'X-Robots-Tag: noindex, nofollow', true );
		status_header( 200 );
		$back_url = admin_url( 'admin.php?page=' . self::SLUG . '#stonewright-application-password' );
		?>
		<!doctype html>
		<html <?php language_attributes(); ?>>
		<head>
			<meta charset="<?php bloginfo( 'charset' ); ?>">
			<meta name="viewport" content="width=device-width, initial-scale=1">
			<meta name="robots" content="noindex,nofollow,noarchive">
			<title><?php esc_html_e( 'Application Password generated', 'stonewright' ); ?></title>
		</head>
		<body>
			<main>
				<h1><?php esc_html_e( 'Application Password generated', 'stonewright' ); ?></h1>
				<p><?php esc_html_e( 'Copy this password now. It is shown only in this no-store response and cannot be displayed again.', 'stonewright' ); ?></p>
				<p><strong><?php echo esc_html( $name ); ?></strong></p>
				<p><input type="text" readonly autocomplete="off" value="<?php echo esc_attr( $password ); ?>" aria-label="<?php esc_attr_e( 'Generated Application Password', 'stonewright' ); ?>"></p>
				<p><a href="<?php echo esc_url( $back_url ); ?>"><?php esc_html_e( 'Return to Stonewright Setup', 'stonewright' ); ?></a></p>
			</main>
		</body>
		</html>
		<?php
		exit;
	}

	private static function redirect_to_configuration( string $status ): void {
		wp_safe_redirect( SetupTabs::url( 'get-started', [ 'stonewright_app_password' => $status ], 'stonewright-application-password' ) );
		exit;
	}

	public static function handle_reset_domain_lock(): void {
		if ( ! current_user_can( self::CAPABILITY ) ) {
			wp_die( esc_html__( 'Insufficient permissions.', 'stonewright' ) );
		}
		check_admin_referer( 'stonewright_reset_domain_lock' );

		$result = DomainLock::reset();
		if ( is_wp_error( $result ) ) {
			wp_die( esc_html( $result->get_error_message() ) );
		}

		wp_safe_redirect( SetupTabs::url( 'settings', [ 'lock_reset' => '1' ], DomainLockCard::ID ) );
		exit;
	}

	/**
	 * Explicit rebind: snapshot prior binding, write new lock, audit.
	 * Never mutates operator enablement intent.
	 */
	public static function handle_rebind_domain_lock(): void {
		if ( ! current_user_can( self::CAPABILITY ) ) {
			wp_die( esc_html__( 'Insufficient permissions.', 'stonewright' ) );
		}
		check_admin_referer( 'stonewright_rebind_domain_lock' );

		$confirmed = isset( $_POST['stonewright_rebind_confirm'] ) && '1' === (string) wp_unslash( $_POST['stonewright_rebind_confirm'] );
		if ( ! $confirmed ) {
			wp_die( esc_html__( 'Rebind requires explicit confirmation.', 'stonewright' ) );
		}

		$result = DomainLock::rebind();
		if ( is_wp_error( $result ) ) {
			wp_die( esc_html( $result->get_error_message() ) );
		}

		wp_safe_redirect( SetupTabs::url( 'settings', [ 'lock_rebind' => '1' ], DomainLockCard::ID ) );
		exit;
	}

	/**
	 * Restore prior domain binding within the retention window.
	 */
	public static function handle_rollback_domain_lock(): void {
		if ( ! current_user_can( self::CAPABILITY ) ) {
			wp_die( esc_html__( 'Insufficient permissions.', 'stonewright' ) );
		}
		check_admin_referer( 'stonewright_rollback_domain_lock' );

		$result = DomainLock::rollback();
		if ( is_wp_error( $result ) ) {
			wp_die( esc_html( $result->get_error_message() ) );
		}

		wp_safe_redirect( SetupTabs::url( 'settings', [ 'lock_rollback' => '1' ], DomainLockCard::ID ) );
		exit;
	}
}