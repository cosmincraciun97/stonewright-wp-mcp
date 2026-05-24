<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\Core;

use Stonewright\WpMcp\Admin\AbilitiesPage;
use Stonewright\WpMcp\Admin\AdminBarIndicator;
use Stonewright\WpMcp\Admin\AdminBootstrap;
use Stonewright\WpMcp\Admin\AuditLogPage;
use Stonewright\WpMcp\Admin\ConfigurationPage;
use Stonewright\WpMcp\Admin\MemoryInstructionsPage;
use Stonewright\WpMcp\Admin\SandboxPage;
use Stonewright\WpMcp\Admin\SkillsPage;
use Stonewright\WpMcp\Skills\SkillsSeeder;
use Stonewright\WpMcp\Skills\SkillsTable;
use Stonewright\WpMcp\Elementor\WidgetBuilder\Loader as WidgetLoader;
use Stonewright\WpMcp\Memory\Memory;
use Stonewright\WpMcp\QA\QaArtifactStore;
use Stonewright\WpMcp\Sandbox\CrashRecovery;
use Stonewright\WpMcp\Security\AuditLog;
use Stonewright\WpMcp\Security\DomainLock;
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
		add_action( 'init', [ OneTimeLink::class, 'maybe_handle_request' ], 1 );
		add_action( 'init', [ SkillsTable::class, 'create_table' ] );
		add_action( 'init', [ ResourceRegistry::class, 'register' ], 30 );
		add_action( 'init', [ BlockRegistry::class, 'register' ], 40 );
		add_action( 'init', [ QaArtifactStore::class, 'schedule_purge' ] );
		add_action( 'rest_api_init', [ RestRoutes::class, 'register' ] );

		CrashRecovery::register();
		WidgetLoader::register();

		ConfigurationPage::register();
		AbilitiesPage::register();
		SandboxPage::register();
		SkillsPage::register();
		MemoryInstructionsPage::register();
		AuditLogPage::register();
		AdminBarIndicator::register();
		AdminBootstrap::register();

		StaticAnalysis::assert_environment();
	}

	public function on_activate(): void {
		Memory::maybe_install_table();
		AuditLog::maybe_install_table();
		SkillsTable::force_create_table();
		SkillsSeeder::seed();
		// Record domain on first activation so subsequent boots can detect clones.
		if ( (bool) get_option( 'stonewright_enabled', false ) ) {
			DomainLock::lock();
		}
		update_option( 'stonewright_version', STONEWRIGHT_VERSION );
		if ( ! get_option( 'stonewright_mode' ) ) {
			update_option( 'stonewright_mode', 'development' );
		}
		Logger::info( 'activate', [ 'version' => STONEWRIGHT_VERSION ] );
	}

	/**
	 * On every boot: if abilities are enabled, record the domain (first time)
	 * and verify it still matches. Auto-disables on mismatch.
	 */
	public function check_domain_lock(): void {
		if ( ! (bool) get_option( 'stonewright_enabled', false ) ) {
			return;
		}
		DomainLock::lock();
		if ( ! DomainLock::check() ) {
			update_option( 'stonewright_enabled', false );
			add_action(
				'admin_notices',
				static function (): void {
					echo '<div class="notice notice-error"><p><strong>Stonewright:</strong> ' .
						esc_html__(
							'AI abilities have been automatically disabled because the site domain has changed. This is a security measure to prevent unauthorized access after a site migration or clone. Re-enable from the Configuration page after verifying the new domain.',
							'stonewright'
						) .
						'</p></div>';
				}
			);
		}
	}

	public function on_deactivate(): void {
		$timestamp = wp_next_scheduled( 'stonewright_qa_artifact_purge' );
		if ( $timestamp ) {
			wp_unschedule_event( $timestamp, 'stonewright_qa_artifact_purge' );
		}
		Logger::info( 'deactivate', [ 'version' => STONEWRIGHT_VERSION ] );
	}

	public function load_textdomain(): void {
		load_plugin_textdomain( 'stonewright', false, dirname( plugin_basename( $this->plugin_file ) ) . '/languages' );
	}
}
