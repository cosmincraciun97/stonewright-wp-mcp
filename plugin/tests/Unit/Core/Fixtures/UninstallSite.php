<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\Tests\Unit\Core\Fixtures {

	/**
	 * A WordPress site, or a multisite network of them, for the uninstall handler: every site
	 * holds the plugin's tables, options, transients and scheduled events next to tables,
	 * options and events that belong to WordPress, to other plugins, or only look similar.
	 *
	 * The current site lives in the globals of the test bootstrap (options, scheduled events)
	 * and in UninstallWpdb (tables). switch_to_blog() swaps them for the other site's, the way
	 * WordPress swaps the tables, and restore_current_blog() swaps them back.
	 */
	final class UninstallSite {

		/** Every table the plugin creates, without the site prefix. */
		public const PLUGIN_TABLES = [
			'stonewright_audit_log',
			'stonewright_design_direction_versions',
			'stonewright_design_directions',
			'stonewright_expertise_packs',
			'stonewright_expertise_scorecards',
			'stonewright_incidents',
			'stonewright_knowledge_candidates',
			'stonewright_memory',
			'stonewright_oauth_access_tokens',
			'stonewright_oauth_auth_codes',
			'stonewright_oauth_clients',
			'stonewright_oauth_consents',
			'stonewright_oauth_families',
			'stonewright_oauth_rate_limits',
			'stonewright_oauth_rate_metrics',
			'stonewright_oauth_refresh_tokens',
			'stonewright_skill_versions',
			'stonewright_skills',
		];

		/** Tables of WordPress and of other plugins, and one that only carries the plugin's name. */
		public const FOREIGN_TABLES = [ 'options', 'postmeta', 'posts', 'stonewright_not_in_the_list', 'users' ];

		/** Options and transients the plugin owns, with the names WordPress gives transients. */
		public const PLUGIN_OPTIONS = [
			'stonewright_mode',
			'stonewright_version',
			'stonewright_settings',
			'stonewright_oauth_private_key',
			'stonewright_oauth_encryption_key',
			'stonewright_oauth_key_error',
			'stonewright_oauth_schema_version',
			'stonewright_oauth_rate_limit_schema',
			'stonewright_audit_retention_days',
			'stonewright_skills_db_version',
			'stonewright_otl_rate_7',
			'stonewright_elementor_editor_baseline_42',
			'_transient_stonewright_github_release_stable',
			'_transient_timeout_stonewright_github_release_stable',
			'_transient_stonewright_oauth_schema_backoff',
			'_transient_timeout_stonewright_oauth_schema_backoff',
			'_transient_stonewright_oauth_selftest_0123',
			'_transient_timeout_stonewright_oauth_selftest_0123',
			'_transient_sw_cc_grant_0123',
			'_transient_timeout_sw_cc_grant_0123',
			'_site_transient_stonewright_example',
			'_site_transient_timeout_stonewright_example',
			'_site_transient_sw_cc_example',
			'_site_transient_timeout_sw_cc_example',
		];

		/** Options that must survive: WordPress, other plugins, and names that only look like ours. */
		public const FOREIGN_OPTIONS = [
			'blogname',
			'siteurl',
			'cron',
			'active_plugins',
			'page_on_front',
			'generate_settings',
			'cptui_post_types',
			'elementor_pro_theme_builder_conditions',
			'_transient_update_plugins',
			'_transient_timeout_update_plugins',
			'_site_transient_update_core',
			'stonewrightx_look_alike',
			'my_stonewright_option',
			'_transient_other_stonewright_value',
			'_transient_stonewrightx_look_alike',
			'Xtransient_stonewright_look_alike',
			'_transient_swXcc_look_alike',
			'sw_cc_not_a_transient',
		];

		/** Events the plugin schedules. */
		public const PLUGIN_HOOKS = [ 'stonewright_oauth_gc', 'stonewright_audit_retention' ];

		/** Events of WordPress and of other plugins. */
		public const FOREIGN_HOOKS = [ 'wp_version_check', 'woocommerce_cleanup_sessions' ];

		/** @var list<string> What the handler did to the network, in order: "switch:ID", "restore", "flush". */
		public static array $log = [];

		/** @var list<array{number: int, offset: int}> The get_sites() calls. */
		public static array $site_queries = [];

		/** @var list<int> */
		public static array $sites = [ 1 ];

		public static int $current = 1;

		/** @var array<int, array{options: array<string, mixed>, hooks: array<string, int>}> The sites that are not current. */
		private static array $stores = [];

		/** @var list<array{0: int, 1: array<string, mixed>, 2: array<string, int>}|null> What each switch_to_blog() replaced; null when it stayed on the current site. */
		private static array $stack = [];

		private static ?UninstallWpdb $wpdb = null;

		/** Build the network (one site by default); the first site is the current one. */
		public static function reset( int ...$sites ): UninstallWpdb {
			self::$log = [];
			self::$site_queries = [];
			self::$stack = [];
			self::$stores = [];
			self::$sites = [] === $sites ? [ 1 ] : array_values( $sites );
			self::$current = self::$sites[0];
			self::$wpdb = new UninstallWpdb( self::prefix( self::$current ) );
			foreach ( self::$sites as $id ) {
				foreach ( array_merge( self::PLUGIN_TABLES, self::FOREIGN_TABLES ) as $table ) {
					self::$wpdb->tables[ self::prefix( $id ) . $table ] = true;
				}
				$store = [
					'options' => array_fill_keys( array_merge( self::PLUGIN_OPTIONS, self::FOREIGN_OPTIONS ), 'stored' ),
					'hooks'   => array_fill_keys( array_merge( self::PLUGIN_HOOKS, self::FOREIGN_HOOKS ), 1900000000 ),
				];
				if ( $id === self::$current ) {
					$GLOBALS['stonewright_test_options'] = $store['options'];
					$GLOBALS['stonewright_test_scheduled_hooks'] = $store['hooks'];
				} else {
					self::$stores[ $id ] = $store;
				}
			}
			return self::$wpdb;
		}

		public static function prefix( int $site ): string {
			return 1 === $site ? 'wptests_' : 'wptests_' . $site . '_';
		}

		/** @return list<string> The names, without the site prefix, of the listed plugin and foreign tables that still exist. */
		public static function remaining_tables( int $site ): array {
			$left = [];
			foreach ( array_merge( self::PLUGIN_TABLES, self::FOREIGN_TABLES ) as $table ) {
				if ( isset( self::wpdb()->tables[ self::prefix( $site ) . $table ] ) ) {
					$left[] = $table;
				}
			}
			sort( $left );
			return $left;
		}

		/** @return array<string, mixed> */
		public static function options( int $site ): array {
			return $site === self::$current ? $GLOBALS['stonewright_test_options'] : self::$stores[ $site ]['options'];
		}

		/** @return array<string, int> */
		public static function hooks( int $site ): array {
			return $site === self::$current ? $GLOBALS['stonewright_test_scheduled_hooks'] : self::$stores[ $site ]['hooks'];
		}

		/**
		 * @param array<string, mixed> $args
		 * @return list<int>
		 */
		public static function sites( array $args ): array {
			$number = (int) ( $args['number'] ?? 100 );
			$offset = (int) ( $args['offset'] ?? 0 );
			self::$site_queries[] = [ 'number' => $number, 'offset' => $offset ];
			return array_slice( self::$sites, $offset, 0 === $number ? null : $number );
		}

		public static function switch_to( int $site ): bool {
			self::$log[] = 'switch:' . $site;
			if ( $site === self::$current ) {
				self::$stack[] = null;
				return true;
			}
			self::$stack[] = [ self::$current, $GLOBALS['stonewright_test_options'], $GLOBALS['stonewright_test_scheduled_hooks'] ];
			$store = self::$stores[ $site ] ?? [ 'options' => [], 'hooks' => [] ];
			unset( self::$stores[ $site ] );
			self::$current = $site;
			$GLOBALS['stonewright_test_options'] = $store['options'];
			$GLOBALS['stonewright_test_scheduled_hooks'] = $store['hooks'];
			self::wpdb()->use_prefix( self::prefix( $site ) );
			return true;
		}

		public static function restore(): bool {
			self::$log[] = 'restore';
			if ( [] === self::$stack ) {
				return false;
			}
			$previous = array_pop( self::$stack );
			if ( null === $previous ) {
				return true;
			}
			self::$stores[ self::$current ] = [ 'options' => $GLOBALS['stonewright_test_options'], 'hooks' => $GLOBALS['stonewright_test_scheduled_hooks'] ];
			[ $site, $options, $hooks ] = $previous;
			self::$current = $site;
			$GLOBALS['stonewright_test_options'] = $options;
			$GLOBALS['stonewright_test_scheduled_hooks'] = $hooks;
			self::wpdb()->use_prefix( self::prefix( $site ) );
			return true;
		}

		private static function wpdb(): UninstallWpdb {
			if ( null === self::$wpdb ) {
				throw new \LogicException( 'Call UninstallSite::reset() first.' );
			}
			return self::$wpdb;
		}
	}
}

namespace {

	use Stonewright\WpMcp\Tests\Unit\Core\Fixtures\UninstallSite;

	if ( ! function_exists( 'get_sites' ) ) {
		/**
		 * @param array<string, mixed>|string $args
		 * @return list<int>
		 */
		function get_sites( $args = [] ): array {
			return UninstallSite::sites( (array) $args );
		}
	}

	if ( ! function_exists( 'switch_to_blog' ) ) {
		function switch_to_blog( $new_blog_id, $deprecated = null ): bool {
			return UninstallSite::switch_to( (int) $new_blog_id );
		}
	}

	if ( ! function_exists( 'restore_current_blog' ) ) {
		function restore_current_blog(): bool {
			return UninstallSite::restore();
		}
	}

	if ( ! function_exists( 'wp_cache_flush' ) ) {
		function wp_cache_flush(): bool {
			UninstallSite::$log[] = 'flush';
			return true;
		}
	}

}
