<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\Core;

use Stonewright\WpMcp\Admin\AbilitiesPage;
use Stonewright\WpMcp\Admin\AdminBarIndicator;
use Stonewright\WpMcp\Admin\ActivationRedirect;
use Stonewright\WpMcp\Admin\AdminBootstrap;
use Stonewright\WpMcp\Admin\AuditLogPage;
use Stonewright\WpMcp\Admin\DuplicateCopies;
use Stonewright\WpMcp\Admin\ChangesPage;
use Stonewright\WpMcp\Admin\ConfigurationPage;
use Stonewright\WpMcp\Admin\CustomCodeApprovalPage;
use Stonewright\WpMcp\Admin\McpbBundle;
use Stonewright\WpMcp\Admin\MemoryInstructionsPage;
use Stonewright\WpMcp\Admin\SandboxPage;
use Stonewright\WpMcp\Admin\Setup\DomainLockCard;
use Stonewright\WpMcp\Admin\Setup\SetupTabs;
use Stonewright\WpMcp\Admin\SkillsPage;
use Stonewright\WpMcp\Admin\WriteFreezeSettings;
use Stonewright\WpMcp\Authorization\WordPress\AuthorizationLifecycle;
use Stonewright\WpMcp\Authorization\WordPress\HttpSurface;
use Stonewright\WpMcp\Design\Direction\DesignDirectionsTable;
use Stonewright\WpMcp\Design\Direction\DesignDirectionVersionsTable;
use Stonewright\WpMcp\Design\Motion\MotionAssetLoader;
use Stonewright\WpMcp\Elementor\EditorSaveGuard;
use Stonewright\WpMcp\Elementor\Provider\NativeObserver;
use Stonewright\WpMcp\Elementor\Schema\WidgetSchemaRepository;
use Stonewright\WpMcp\Elementor\WidgetBuilder\Loader as WidgetLoader;
use Stonewright\WpMcp\Expertise\ExpertiseTable;
use Stonewright\WpMcp\Gutenberg\BrowserQueue\QueueConsole;
use Stonewright\WpMcp\SkillLibrary\Site\SkillLibraryService;
use Stonewright\WpMcp\SkillLibrary\Site\SkillTables;
use Stonewright\WpMcp\SkillLibrary\Site\WordPressBoundary;
use Stonewright\WpMcp\Knowledge\Lifecycle\CandidateTable;
use Stonewright\WpMcp\Knowledge\Lifecycle\CandidateRepository;
use Stonewright\WpMcp\Memory\Memory;
use Stonewright\WpMcp\Sandbox\CrashRecovery;
use Stonewright\WpMcp\SectionReuse\SectionReuseSetting;
use Stonewright\WpMcp\Security\AuditLog;
use Stonewright\WpMcp\Security\BasicAuthCredentials;
use Stonewright\WpMcp\Security\ChangeLedger;
use Stonewright\WpMcp\Security\ChangeLedgerRetention;
use Stonewright\WpMcp\Security\ErrorPatterns;
use Stonewright\WpMcp\Security\IncidentStore;
use Stonewright\WpMcp\Security\LeashGuard;
use Stonewright\WpMcp\Security\DomainLock;
use Stonewright\WpMcp\Security\PluginEffectiveState;
use Stonewright\WpMcp\Security\OneTimeLink;
use Stonewright\WpMcp\Security\RescueHooks;
use Stonewright\WpMcp\Security\SitePressure;
use Stonewright\WpMcp\Security\StaticAnalysis;
use Stonewright\WpMcp\Support\AgentNotices;
use Stonewright\WpMcp\Support\LegacyMirrorCleanup;
use Stonewright\WpMcp\Support\Logger;
use Stonewright\WpMcp\Support\OptionLock;

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
		MotionAssetLoader::register();

		add_action( 'plugins_loaded', [ $this, 'load_textdomain' ], 5 );
		add_action( 'plugins_loaded', [ $this, 'check_domain_lock' ], 10 );
		// OAuth: discovery, REST routes, authorization pages and bearer protection.
		add_action( 'plugins_loaded', [ HttpSurface::class, 'register' ], 20, 0 );
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
		// Categories register after every plugin that uses the default priority, so a category another plugin
		// registers on this hook (Elementor registers `elementor`) is left as that plugin defined it.
		add_action( 'wp_abilities_api_categories_init', [ AbilityRegistry::class, 'register_categories' ], 99 );
		add_action( 'wp_abilities_api_init', [ AbilityRegistry::class, 'register_all' ], 20 );
		add_action( 'abilities_api_init', [ AbilityRegistry::class, 'register_all' ], 20 );
		add_action( 'mcp_adapter_init', [ ServerRegistration::class, 'register_server' ], 20 );
		McpSchemaWire::register();
		add_action( 'plugins_loaded', [ self::class, 'maybe_boot_mcp_adapter' ], 99 );

		// Rescue themes that forgot to declare `add_theme_support( 'elementor-pro' )`
		// so Stonewright-created header/footer templates actually inject under
		// ProElements / Elementor Pro. Safe no-op when the theme already opts in
		// or when neither Pro is present.
		\Stonewright\WpMcp\Compat\ProElementsThemeSupport::register();

		add_action( 'init', [ Memory::class, 'maybe_install_table' ] );
		add_action( 'init', [ AuditLog::class, 'maybe_install_table' ] );
		add_action( 'init', [ AuditLog::class, 'sync_retention_schedule' ], 25, 0 );
		add_action( AuditLog::RETENTION_HOOK, [ AuditLog::class, 'run_scheduled_retention' ] );
		add_action( 'init', [ ChangeLedger::class, 'maybe_install_table' ] );
		add_action( 'init', [ ChangeLedgerRetention::class, 'sync_schedule' ], 25, 0 );
		add_action( ChangeLedgerRetention::HOOK, [ ChangeLedgerRetention::class, 'run_scheduled' ] );
		add_action( 'init', [ IncidentStore::class, 'maybe_install_table' ] );
		add_action( 'init', [ OneTimeLink::class, 'maybe_handle_request' ], 1 );
		add_action( 'init', [ SkillTables::class, 'ensure' ] );
		add_action( 'init', [ self::class, 'maybe_upgrade' ], 15 );
		// A site created after a network activation gets its defaults when it is created.
		add_action( 'wp_initialize_site', [ $this, 'seed_new_site' ], 20, 1 );
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
		// A REST request that is not a person in wp-admin cannot loosen the mode or switch an ability back on.
		add_filter( 'pre_update_option_stonewright_mode', [ LeashGuard::class, 'guard_mode_write' ], 10, 2 );
		add_filter( 'pre_update_option_stonewright_disabled_abilities', [ LeashGuard::class, 'guard_disabled_abilities_write' ], 10, 2 );
		add_filter( 'pre_update_option_stonewright_write_freeze', [ LeashGuard::class, 'guard_write_freeze_write' ], 10, 2 );
		WriteFreezeSettings::register();

		CrashRecovery::register();
		WidgetLoader::register();
		GitHubUpdater::register();
		VendorGuard::register();
		// No usable MCP adapter loaded: the owner and version in an admin notice, and an explicit answer on the MCP routes.
		McpRuntimeNotice::register();
		// The stored compatibility report belongs to one plugin set: a plugin or theme change discards it.
		McpAbilitiesCompatibilityPreflight::register();
		// MCP routes: refuse a request whose Origin is present and is neither the site's own nor an allowed one.
		McpOriginGuard::register();
		// tools/list answers over a byte budget are paged with MCP cursors, so a large surface stays under the companion's read cap.
		ToolsListPagination::register();
		EditorSaveGuard::register();
		// Agents learn from every ability response when the site is under load.
		AgentNotices::register_field( 'site_pressure', [ SitePressure::class, 'notice' ] );
		// Elementor's own write abilities that run outside stonewright-elementor-native-execute are snapshotted and recorded, never blocked.
		NativeObserver::register();
		BasicAuthCredentials::register();
		// OAuth storage: clean-up handler, schema upgrade on init, key notice and retry.
		AuthorizationLifecycle::register();

		ConfigurationPage::register();
		CustomCodeApprovalPage::register();
		QueueConsole::attach_hooks();
		AbilitiesPage::register();
		SandboxPage::register();
		SkillsPage::register();
		MemoryInstructionsPage::register();
		AuditLogPage::register();
		ChangesPage::register();
		AdminBarIndicator::register();
		DuplicateCopies::register();
		McpbBundle::register();
		AdminBootstrap::register();
		RescueHooks::register();
		SectionReuseSetting::register();
		if ( class_exists( RescueBootstrap::class ) ) {
			RescueBootstrap::register();
		}

		StaticAnalysis::assert_environment();
	}

	/**
	 * Instantiate the shared MCP adapter after other plugins have registered autoloaders.
	 */
	public static function maybe_boot_mcp_adapter(): void {
		// A compatible report is kept for the plugin set, so a request does not inspect the runtime again.
		$compatibility = McpAbilitiesCompatibilityPreflight::cached();
		if ( $compatibility['compatible'] && class_exists( \WP\MCP\Core\McpAdapter::class ) ) {
			McpDefaultServerGuard::register();
			\WP\MCP\Core\McpAdapter::instance();
		} elseif ( ! $compatibility['compatible'] ) {
			Logger::warning( 'mcp_adapter_ownership_conflict', [ 'preflight' => $compatibility ] );
		}
	}

	public function on_activate(): void {
		// Not the version-gated install: activation re-checks every table and adds what is missing.
		// A store that fails must not stop the activation: its table is repaired again by the daily job.
		self::attempt( 'memory_table', static fn () => Memory::repair_table() );
		self::attempt( 'audit_table', static fn () => AuditLog::repair_table() );
		self::attempt( 'changes_table', static fn () => ChangeLedger::repair_table() );
		self::attempt( 'incident_table', static fn () => IncidentStore::repair_table() );
		// OAuth tables, signing and encryption keys (never throws), daily clean-up schedule.
		AuthorizationLifecycle::activate();
		SkillTables::install();
		DesignDirectionsTable::install();
		DesignDirectionVersionsTable::install();
		CandidateTable::force_create_table();
		ExpertiseTable::force_create_tables();
		SkillLibraryService::open( WordPressBoundary::SYSTEM )->refresh_bundled_pack();
		// Record domain on first activation so subsequent boots can detect clones.
		// Uses operator intent only — never writes enablement as a side effect.
		if ( PluginEffectiveState::enabled_requested() ) {
			DomainLock::lock();
		}
		// Deactivating and activating again is an update that maybe_upgrade() never sees: the version stored below ends its upgrade run.
		BootOptions::autoload_all();
		$is_first_activate = ! get_option( 'stonewright_version' );
		update_option( 'stonewright_version', STONEWRIGHT_VERSION );
		// Mode from the environment type; a first activation also gets the essential surface.
		// Upgrades leave stonewright_mcp_surface unset so mcp_surface() keeps mapping
		// from the existing stonewright_essential_tools_mode choice.
		SiteDefaults::seed( $is_first_activate );
		// A first activation lands on the Overview; a site that already chose on or off is left where it is.
		ActivationRedirect::arm();
		Logger::info( 'activate', [ 'version' => STONEWRIGHT_VERSION ] );
	}

	/**
	 * On wp_initialize_site, after WordPress has installed the new site: give it its defaults.
	 */
	public function seed_new_site( object $site ): void {
		SiteDefaults::seed_new_site( $site, plugin_basename( $this->plugin_file ) );
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
		// One request runs the chain: on a busy site every request that boots before the first one stores
		// the version would otherwise run all of it, and count each run as a migration attempt. A request
		// that does not get the lock goes on; the version stays unstored and a later request tries again.
		if ( ! OptionLock::acquire( 'upgrade', 300 ) ) {
			return;
		}
		try {
			// The holder before us may have finished between our version read and our lock.
			wp_cache_delete( 'alloptions', 'options' );
			wp_cache_delete( 'stonewright_version', 'options' );
			if ( (string) get_option( 'stonewright_version', '' ) === STONEWRIGHT_VERSION ) {
				return;
			}
			// WordPress runs the activation hook once for a network activation, so a sub-site arrives here
			// on its first request without a mode; an update can find one missing too.
			SiteDefaults::seed( '' === $stored );
			// An update can bring a column a table lacks; init no longer looks, so repair all four now.
			// A store that fails must not keep the version from being stored: the daily job repairs it again.
			self::attempt( 'memory_table', static fn () => Memory::repair_table() );
			self::attempt( 'audit_table', static fn () => AuditLog::repair_table() );
			self::attempt( 'changes_table', static fn () => ChangeLedger::repair_table() );
			self::attempt( 'incident_table', static fn () => IncidentStore::repair_table() );
			SkillTables::ensure();
			SkillLibraryService::open( WordPressBoundary::SYSTEM )->refresh_bundled_pack();
			// Guards uploads/stonewright-mirror and removes page exports that earlier versions left there.
			LegacyMirrorCleanup::run();
			// A schema captured by an earlier version may lack controls the new one reads.
			WidgetSchemaRepository::invalidate();
			// Options an earlier version stored without autoload cost a query each per request; move them into the autoloaded set.
			BootOptions::autoload_all();
			// Supersede legacy unresolved audit lessons and return unapproved draft lessons; the daily job retries a failed run.
			ErrorPatterns::run_pending_migrations();
			update_option( 'stonewright_version', STONEWRIGHT_VERSION );
		} finally {
			OptionLock::release( 'upgrade' );
		}
	}

	/**
	 * Runs one step whose failure must not stop the ones after it, and logs the failure.
	 */
	private static function attempt( string $step, callable $run ): void {
		try {
			$run();
		} catch ( \Throwable $failure ) {
			Logger::warning(
				'upgrade_step_failed',
				[
					'step'        => $step,
					'error_class' => get_class( $failure ),
				]
			);
		}
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
		$review   = SetupTabs::url( 'settings', [], DomainLockCard::ID );

		echo '<div class="notice notice-error stonewright-notice"><p><strong>Stonewright:</strong> ';
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
		AuthorizationLifecycle::deactivate();
		AuditLog::unschedule_retention();
		ChangeLedgerRetention::unschedule();
		Logger::info( 'deactivate', [ 'version' => STONEWRIGHT_VERSION ] );
	}

	public function load_textdomain(): void {
		load_plugin_textdomain( 'stonewright', false, dirname( plugin_basename( $this->plugin_file ) ) . '/languages' );
	}
}
