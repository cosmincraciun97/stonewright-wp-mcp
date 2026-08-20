<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\Core;

use Stonewright\WpMcp\Admin\AbilitiesPage;
use Stonewright\WpMcp\Admin\AdminBarIndicator;
use Stonewright\WpMcp\Admin\AdminBootstrap;
use Stonewright\WpMcp\Admin\AuditLogPage;
use Stonewright\WpMcp\Admin\ConfigurationPage;
use Stonewright\WpMcp\Admin\CustomCodeApprovalPage;
use Stonewright\WpMcp\Admin\McpbBundle;
use Stonewright\WpMcp\Admin\MemoryInstructionsPage;
use Stonewright\WpMcp\Admin\SandboxPage;
use Stonewright\WpMcp\Admin\SkillsPage;
use Stonewright\WpMcp\Design\Direction\DesignDirectionsTable;
use Stonewright\WpMcp\Design\Direction\DesignDirectionVersionsTable;
use Stonewright\WpMcp\Gutenberg\Finalizer\FinalizerPage;
use Stonewright\WpMcp\Skills\SkillsSeeder;
use Stonewright\WpMcp\Skills\SkillsTable;
use Stonewright\WpMcp\Skills\SkillVersionsTable;
use Stonewright\WpMcp\Knowledge\Lifecycle\CandidateTable;
use Stonewright\WpMcp\Knowledge\Lifecycle\CandidateRepository;
use Stonewright\WpMcp\Expertise\ExpertiseTable;
use Stonewright\WpMcp\Elementor\WidgetBuilder\Loader as WidgetLoader;
use Stonewright\WpMcp\Elementor\Schema\WidgetSchemaRepository;
use Stonewright\WpMcp\Memory\Memory;
use Stonewright\WpMcp\OAuth\Bootstrap as OAuthBootstrap;
use Stonewright\WpMcp\OAuth\Keys as OAuthKeys;
use Stonewright\WpMcp\OAuth\Schema as OAuthSchema;
use Stonewright\WpMcp\Sandbox\CrashRecovery;
use Stonewright\WpMcp\Security\AuditLog;
use Stonewright\WpMcp\Security\ErrorPatterns;
use Stonewright\WpMcp\Security\IncidentStore;
use Stonewright\WpMcp\Security\DomainLock;
use Stonewright\WpMcp\Security\PluginEffectiveState;
use Stonewright\WpMcp\Security\OneTimeLink;
use Stonewright\WpMcp\Security\StaticAnalysis;
use Stonewright\WpMcp\Support\Logger;

/**
 * Boots the Stonewright plugin and wires WordPress hooks.
 */
final class PluginRegistration {

	private static ?self $instance = null;

	private string $plugin_file;

	private Container $container;

	private function __construct( string $plugin_file ) {
		$this->plugin_file = $plugin_file;
		$this->container   = new Container();
	}

	public static function boot( string $plugin_file ): self {
		if ( null === self::$instance ) {
			self::$instance = new self( $plugin_file );
			self::$instance->register_hooks();
		}
		return self::$instance;
	}

	public function container(): Container {
		return $this->container;
	}

	private function register_hooks(): void {
		register_activation_hook( $this->plugin_file, [ $this, 'on_activate' ] );
		register_deactivation_hook( $this->plugin_file, [ $this, 'on_deactivate' ] );

		add_action( 'plugins_loaded', [ $this, 'load_textdomain' ], 5 );
		add_action( 'plugins_loaded', [ $this, 'check_domain_lock' ], 10 );
		add_action( 'plugins_loaded', [ OAuthBootstrap::class, 'boot' ], 20 );
		// Two flavours of the Abilities API exist in the wild and we must
		// support both:
		//
		// • WordPress core 6.9+ ships its own copy in `wp-includes/abilities-api/`,
		// which fires `wp_abilities_api_categories_init` (for categories) and
		// `wp_abilities_api_init` (for abilities). The core copy ALWAYS wins
		// over the vendor copy below because the vendor bootstrap has
		// `class_exists( 'WP_Ability' )` guards.
		//
		// • The standalone `wordpress/abilities-api` package (≤ 0.1.0) used
		// when running on pre-6.9 cores fires only `abilities_api_init`
		// (no `wp_` prefix) and has no separate categories init.
		//
		// We register on every action that any supported flavour might fire.
		// `register_all` is idempotent (it guards against running twice via
		// AbilityRegistry::$registered_once), so listening on multiple hooks is
		// safe even if both fire in the same request.
		add_action( 'wp_abilities_api_categories_init', [ AbilityRegistry::class, 'register_categories' ], 10 );
		add_action( 'wp_abilities_api_init', [ AbilityRegistry::class, 'register_all' ], 20 );
		add_action( 'abilities_api_init', [ AbilityRegistry::class, 'register_all' ], 20 );
		add_action( 'mcp_adapter_init', [ ServerRegistration::class, 'register_server' ], 20 );

		// Rescue themes that forgot to declare `add_theme_support( 'elementor-pro' )`
		// so Stonewright-created header/footer templates actually inject under
		// ProElements / Elementor Pro. Safe no-op when the theme already opts in
		// or when neither Pro is present.
		\Stonewright\WpMcp\Compat\ProElementsThemeSupport::register();

		// Boot the MCP adapter if it is vendored into Stonewright (i.e. not active
		// as a standalone plugin).  McpAdapter::instance() is idempotent — calling
		// it again when the adapter plugin is already running is a no-op because the
		// static $instance guard prevents re-initialisation.
		if ( class_exists( \WP\MCP\Core\McpAdapter::class ) ) {
			\WP\MCP\Core\McpAdapter::instance();
		}
		add_action( 'init', [ Memory::class, 'maybe_install_table' ] );
		add_action( 'init', [ AuditLog::class, 'maybe_install_table' ] );
		add_action( 'init', [ IncidentStore::class, 'maybe_install_table' ] );
		// Idempotent: supersede legacy unresolved audit lessons into incident history.
		// Void wrapper — WP action callbacks must not return values (PHPStan).
		add_action(
			'init',
			static function (): void {
				ErrorPatterns::migrate_legacy_audit_lessons();
			},
			20
		);
		add_action( 'init', [ OneTimeLink::class, 'maybe_handle_request' ], 1 );
		add_action( 'init', [ SkillsTable::class, 'create_table' ] );
		add_action( 'init', [ SkillVersionsTable::class, 'create_table' ] );
		add_action( 'init', [ self::class, 'maybe_upgrade' ], 15 );
		add_action( 'init', [ DesignDirectionsTable::class, 'install' ] );
		add_action( 'init', [ DesignDirectionVersionsTable::class, 'install' ] );
		add_action( 'init', [ CandidateTable::class, 'create_table' ] );
		add_action( 'init', [ ExpertiseTable::class, 'create_tables' ] );
		add_action( 'init', [ ResourceRegistry::class, 'register' ], 30 );
		add_action( 'init', [ BlockRegistry::class, 'register' ], 40 );
		add_action( 'rest_api_init', [ RestRoutes::class, 'register' ] );
		add_action( 'activated_plugin', [ WidgetSchemaRepository::class, 'invalidate' ], 10, 2 );
		add_action( 'activated_plugin', [ CandidateRepository::class, 'invalidate_on_elementor_change' ], 20, 2 );
		add_action( 'deactivated_plugin', [ WidgetSchemaRepository::class, 'invalidate' ], 10, 2 );
		add_action( 'deactivated_plugin', [ CandidateRepository::class, 'invalidate_on_elementor_change' ], 20, 2 );
		add_action( 'upgrader_process_complete', [ WidgetSchemaRepository::class, 'invalidate' ], 10, 2 );
		add_action( 'upgrader_process_complete', [ CandidateRepository::class, 'invalidate_on_elementor_change' ], 20, 2 );
		add_action( 'update_option_active_plugins', [ WidgetSchemaRepository::class, 'invalidate' ], 10, 3 );
		add_action( 'update_option_elementor_experiment-container', [ WidgetSchemaRepository::class, 'invalidate' ], 10, 3 );
		add_action( 'update_option_elementor_experiment-e_atomic_elements', [ WidgetSchemaRepository::class, 'invalidate' ], 10, 3 );
		add_action( 'update_option_elementor_experiment-nested-elements', [ WidgetSchemaRepository::class, 'invalidate' ], 10, 3 );

		CrashRecovery::register();
		WidgetLoader::register();
		GitHubUpdater::register();
		VendorGuard::register();

		ConfigurationPage::register();
		CustomCodeApprovalPage::register();
		FinalizerPage::register();
		AbilitiesPage::register();
		SandboxPage::register();
		SkillsPage::register();
		MemoryInstructionsPage::register();
		AuditLogPage::register();
		AdminBarIndicator::register();
		McpbBundle::register();
		AdminBootstrap::register();

		StaticAnalysis::assert_environment();
	}

	public function on_activate(): void {
		Memory::maybe_install_table();
		AuditLog::maybe_install_table();
		IncidentStore::maybe_install_table();
		OAuthSchema::maybe_install();
		OAuthKeys::get();
		OAuthSchema::schedule_gc();
		SkillsTable::force_create_table();
		SkillVersionsTable::force_create_table();
		DesignDirectionsTable::install();
		DesignDirectionVersionsTable::install();
		CandidateTable::force_create_table();
		ExpertiseTable::force_create_tables();
		SkillsSeeder::seed();
		// Record domain on first activation so subsequent boots can detect clones.
		// Uses operator intent only — never writes enablement as a side effect.
		if ( PluginEffectiveState::enabled_requested() ) {
			DomainLock::lock();
		}
		$is_first_activate = ! get_option( 'stonewright_version' );
		update_option( 'stonewright_version', STONEWRIGHT_VERSION );
		if ( ! get_option( 'stonewright_mode' ) ) {
			$environment = function_exists( 'wp_get_environment_type' ) ? wp_get_environment_type() : 'development';
			$initial_mode = match ( $environment ) {
				'production' => 'production-safe',
				'staging'    => 'staging',
				default      => 'development',
			};
			update_option( 'stonewright_mode', $initial_mode );
		}
		// New installs start on the useful bounded surface. Bootstrap remains an
		// explicit transport/profile diagnostic, never a permanent install default.
		// Upgrades leave stonewright_mcp_surface unset so mcp_surface() keeps mapping
		// from the existing stonewright_essential_tools_mode choice.
		if ( $is_first_activate ) {
			update_option( 'stonewright_mcp_surface', 'essential', false );
			update_option( 'stonewright_essential_tools_mode', true, false );
		}
		Logger::info( 'activate', [ 'version' => STONEWRIGHT_VERSION ] );
	}

	/**
	 * Reseed packaged skills when the installed plugin version changes.
	 * File copies do not run activation hooks; this keeps the catalog in sync.
	 */
	public static function maybe_upgrade(): void {
		$stored = (string) get_option( 'stonewright_version', '' );
		if ( $stored === STONEWRIGHT_VERSION ) {
			return;
		}
		SkillsSeeder::seed();
		update_option( 'stonewright_version', STONEWRIGHT_VERSION );
	}

	/**
	 * On every boot: if the operator requested enablement, record the domain
	 * (first time) and verify it still matches.
	 *
	 * On mismatch: NEVER rewrite operator intent (`stonewright_enabled`).
	 * Block effective runtime via PluginEffectiveState, record a redacted
	 * mismatch fingerprint, and show a persistent admin notice with rebind.
	 */
	public function check_domain_lock(): void {
		if ( ! PluginEffectiveState::enabled_requested() ) {
			return;
		}
		DomainLock::lock();
		if ( DomainLock::check() ) {
			// Live origin matches — clear any stale mismatch record.
			if ( null !== DomainLock::mismatch() ) {
				DomainLock::clear_mismatch();
			}
			return;
		}

		DomainLock::record_mismatch();
		add_action( 'admin_notices', [ self::class, 'domain_mismatch_admin_notice' ] );
	}

	/**
	 * Persistent admin notice when domain lock blocks effective enablement.
	 */
	public static function domain_mismatch_admin_notice(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		if ( DomainLock::check() ) {
			return;
		}

		$mismatch = DomainLock::mismatch();
		$locked   = is_array( $mismatch ) ? (string) ( $mismatch['locked_redacted'] ?? '' ) : DomainLock::redact_origin( DomainLock::locked_domain() );
		$current  = is_array( $mismatch ) ? (string) ( $mismatch['current_redacted'] ?? '' ) : DomainLock::redact_origin( DomainLock::current_origin() );
		$review   = admin_url( 'admin.php?page=' . ConfigurationPage::SLUG . '#stonewright-domain-lock' );

		echo '<div class="notice notice-error"><p><strong>Stonewright:</strong> ';
		echo esc_html__(
			'AI abilities are BLOCKED because the site domain no longer matches the locked origin. Operator enablement was left unchanged. Review and rebind this site after confirming the new domain is intentional.',
			'stonewright'
		);
		echo '</p>';
		if ( '' !== $locked || '' !== $current ) {
			echo '<p><code>' . esc_html( $locked ) . '</code> → <code>' . esc_html( $current ) . '</code></p>';
		}
		echo '<p><a class="button button-primary" href="' . esc_url( $review ) . '">';
		echo esc_html__( 'Review and rebind this site', 'stonewright' );
		echo '</a></p></div>';
	}

	public function on_deactivate(): void {
		OAuthSchema::unschedule_gc();
		Logger::info( 'deactivate', [ 'version' => STONEWRIGHT_VERSION ] );
	}

	public function load_textdomain(): void {
		load_plugin_textdomain( 'stonewright', false, dirname( plugin_basename( $this->plugin_file ) ) . '/languages' );
	}
}
