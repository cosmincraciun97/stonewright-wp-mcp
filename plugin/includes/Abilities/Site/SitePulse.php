<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\Abilities\Site;

use Stonewright\WpMcp\Abilities\AbilityKernel;
use Stonewright\WpMcp\Security\Permissions;

/**
 * Read-only site hardening + performance pulse (score 0–100 + top fixes).
 *
 * @stonewright-status stable
 */
final class SitePulse extends AbilityKernel {

	public function name(): string {
		return 'stonewright/site-pulse';
	}

	public function label(): string {
		return __( 'Site pulse', 'stonewright' );
	}

	public function description(): string {
		return __( 'Compact performance and hardening snapshot with a 0–100 score and top fixes.', 'stonewright' );
	}

	public function category(): string {
		return 'site';
	}

	public function output_schema(): array {
		return [
			'type'       => 'object',
			'properties' => [
				'score'   => [ 'type' => 'integer', 'minimum' => 0, 'maximum' => 100 ],
				'grade'   => [ 'type' => 'string' ],
				'summary' => [
					'type'                 => 'object',
					'additionalProperties' => true,
				],
				'checks'  => [
					'type'  => 'array',
					'items' => [
						'type'       => 'object',
						'properties' => [
							'id'       => [ 'type' => 'string' ],
							'label'    => [ 'type' => 'string' ],
							'ok'       => [ 'type' => 'boolean' ],
							'severity' => [ 'type' => 'string' ],
							'points'   => [ 'type' => 'integer' ],
							'detail'   => [ 'type' => 'string' ],
						],
					],
				],
				'fixes'   => [
					'type'  => 'array',
					'items' => [
						'type'       => 'object',
						'properties' => [
							'id'       => [ 'type' => 'string' ],
							'label'    => [ 'type' => 'string' ],
							'severity' => [ 'type' => 'string' ],
							'points'   => [ 'type' => 'integer' ],
							'fix'      => [ 'type' => 'string' ],
						],
					],
				],
			],
			'required'   => [ 'score', 'grade', 'summary', 'checks', 'fixes' ],
		];
	}

	public function permission_callback( array $args ): bool|\WP_Error {
		return Permissions::manage_options();
	}

	public function execute( array $args ): array {
		$checks = $this->run_checks();
		$score  = 100;
		$fixes  = [];

		foreach ( $checks as $check ) {
			if ( $check['ok'] ) {
				continue;
			}
			$score  -= (int) $check['points'];
			$fixes[] = [
				'id'       => $check['id'],
				'label'    => $check['label'],
				'severity' => $check['severity'],
				'points'   => $check['points'],
				'fix'      => $check['fix'],
			];
		}

		$score = max( 0, min( 100, $score ) );
		usort(
			$fixes,
			static function ( array $a, array $b ): int {
				$sev = [ 'critical' => 0, 'high' => 1, 'medium' => 2, 'low' => 3 ];
				$sa  = $sev[ $a['severity'] ] ?? 9;
				$sb  = $sev[ $b['severity'] ] ?? 9;
				if ( $sa !== $sb ) {
					return $sa <=> $sb;
				}
				return (int) $b['points'] <=> (int) $a['points'];
			}
		);
		$fixes = array_slice( $fixes, 0, 5 );

		return [
			'score'   => $score,
			'grade'   => $this->grade( $score ),
			'summary' => $this->summary_from_checks( $checks ),
			'checks'  => array_map(
				static fn( array $c ): array => [
					'id'       => $c['id'],
					'label'    => $c['label'],
					'ok'       => $c['ok'],
					'severity' => $c['severity'],
					'points'   => $c['points'],
					'detail'   => $c['detail'],
				],
				$checks
			),
			'fixes'   => $fixes,
		];
	}

	/**
	 * @return list<array{id:string,label:string,ok:bool,severity:string,points:int,detail:string,fix:string}>
	 */
	private function run_checks(): array {
		$wp_version  = (string) get_bloginfo( 'version' );
		$php_version = PHP_VERSION;
		$elementor   = defined( 'ELEMENTOR_VERSION' ) ? (string) constant( 'ELEMENTOR_VERSION' ) : '';
		$object_cache = function_exists( 'wp_using_ext_object_cache' )
			? (bool) wp_using_ext_object_cache()
			: false;
		$plugins      = $this->plugin_counts();
		$autoload     = $this->autoload_size_bytes();
		$debug_on     = defined( 'WP_DEBUG' ) && constant( 'WP_DEBUG' );
		$debug_display = defined( 'WP_DEBUG_DISPLAY' ) && constant( 'WP_DEBUG_DISPLAY' );
		$https        = is_ssl() || $this->home_is_https();
		$file_edit_disabled = defined( 'DISALLOW_FILE_EDIT' ) && true === constant( 'DISALLOW_FILE_EDIT' );
		$admin_count  = $this->admin_user_count();
		$updates      = $this->plugin_update_count();
		$orphans      = $this->orphan_postmeta_count();

		$checks = [];

		$php_ok = version_compare( $php_version, '8.1', '>=' );
		$checks[] = [
			'id'       => 'php_version',
			'label'    => __( 'PHP version', 'stonewright' ),
			'ok'       => $php_ok,
			'severity' => version_compare( $php_version, '8.0', '<' ) ? 'critical' : 'high',
			'points'   => version_compare( $php_version, '8.0', '<' ) ? 20 : 12,
			'detail'   => sprintf(
				/* translators: %s: PHP version */
				__( 'Running PHP %s', 'stonewright' ),
				$php_version
			),
			'fix'      => __( 'Upgrade PHP to 8.1 or newer (8.2+ recommended).', 'stonewright' ),
		];

		$wp_ok = version_compare( $wp_version, '6.7', '>=' );
		$checks[] = [
			'id'       => 'wp_version',
			'label'    => __( 'WordPress version', 'stonewright' ),
			'ok'       => $wp_ok,
			'severity' => 'high',
			'points'   => 10,
			'detail'   => sprintf(
				/* translators: %s: WordPress version */
				__( 'WordPress %s', 'stonewright' ),
				$wp_version
			),
			'fix'      => __( 'Update WordPress core to 6.7 or newer.', 'stonewright' ),
		];

		$checks[] = [
			'id'       => 'object_cache',
			'label'    => __( 'Persistent object cache', 'stonewright' ),
			'ok'       => $object_cache,
			'severity' => 'medium',
			'points'   => 10,
			'detail'   => $object_cache
				? __( 'External object cache active', 'stonewright' )
				: __( 'No external object cache detected', 'stonewright' ),
			'fix'      => __( 'Enable Redis/Memcached via a drop-in or host object cache.', 'stonewright' ),
		];

		$inactive = (int) ( $plugins['inactive'] ?? 0 );
		$checks[] = [
			'id'       => 'inactive_plugins',
			'label'    => __( 'Inactive plugins', 'stonewright' ),
			'ok'       => $inactive <= 10,
			'severity' => 'low',
			'points'   => 5,
			'detail'   => sprintf(
				/* translators: 1: active count, 2: inactive count */
				__( '%1$d active, %2$d inactive', 'stonewright' ),
				(int) ( $plugins['active'] ?? 0 ),
				$inactive
			),
			'fix'      => __( 'Remove unused inactive plugins to shrink attack surface.', 'stonewright' ),
		];

		$autoload_mb = $autoload / 1024 / 1024;
		$autoload_ok = $autoload_mb < 1.0;
		$checks[]    = [
			'id'       => 'autoload_options',
			'label'    => __( 'Autoload options size', 'stonewright' ),
			'ok'       => $autoload_ok,
			'severity' => $autoload_mb >= 2.0 ? 'high' : 'medium',
			'points'   => $autoload_mb >= 2.0 ? 15 : 10,
			'detail'   => sprintf(
				/* translators: %s: megabytes */
				__( '%s MB autoloaded', 'stonewright' ),
				number_format( $autoload_mb, 2 )
			),
			'fix'      => __( 'Audit large autoloaded options and set autoload=no where safe.', 'stonewright' ),
		];

		$checks[] = [
			'id'       => 'debug_flags',
			'label'    => __( 'Debug flags', 'stonewright' ),
			'ok'       => ! $debug_on && ! $debug_display,
			'severity' => 'high',
			'points'   => 12,
			'detail'   => sprintf(
				'WP_DEBUG=%s, WP_DEBUG_DISPLAY=%s',
				$debug_on ? 'true' : 'false',
				$debug_display ? 'true' : 'false'
			),
			'fix'      => __( 'Disable WP_DEBUG and WP_DEBUG_DISPLAY on non-local environments.', 'stonewright' ),
		];

		$checks[] = [
			'id'       => 'https',
			'label'    => __( 'HTTPS', 'stonewright' ),
			'ok'       => $https,
			'severity' => 'critical',
			'points'   => 15,
			'detail'   => $https ? __( 'Site served over HTTPS', 'stonewright' ) : __( 'Site not using HTTPS', 'stonewright' ),
			'fix'      => __( 'Force HTTPS for home/siteurl and enable HSTS at the edge.', 'stonewright' ),
		];

		$checks[] = [
			'id'       => 'file_edit',
			'label'    => __( 'Theme/plugin file editor', 'stonewright' ),
			'ok'       => $file_edit_disabled,
			'severity' => 'medium',
			'points'   => 8,
			'detail'   => $file_edit_disabled
				? __( 'DISALLOW_FILE_EDIT is true', 'stonewright' )
				: __( 'File editor is still enabled', 'stonewright' ),
			'fix'      => __( 'Set DISALLOW_FILE_EDIT to true in wp-config.php.', 'stonewright' ),
		];

		$checks[] = [
			'id'       => 'admin_count',
			'label'    => __( 'Administrator accounts', 'stonewright' ),
			'ok'       => $admin_count <= 3,
			'severity' => $admin_count > 5 ? 'high' : 'medium',
			'points'   => $admin_count > 5 ? 10 : 5,
			'detail'   => sprintf(
				/* translators: %d: admin user count */
				_n( '%d administrator', '%d administrators', $admin_count, 'stonewright' ),
				$admin_count
			),
			'fix'      => __( 'Reduce administrator accounts; prefer least-privilege roles.', 'stonewright' ),
		];

		$checks[] = [
			'id'       => 'plugin_updates',
			'label'    => __( 'Plugin updates', 'stonewright' ),
			'ok'       => 0 === $updates,
			'severity' => $updates >= 5 ? 'high' : 'medium',
			'points'   => min( 15, max( 3, $updates * 3 ) ),
			'detail'   => sprintf(
				/* translators: %d: update count */
				_n( '%d plugin update available', '%d plugin updates available', $updates, 'stonewright' ),
				$updates
			),
			'fix'      => __( 'Apply pending plugin updates after staging validation.', 'stonewright' ),
		];

		if ( '' !== $elementor ) {
			$checks[] = [
				'id'       => 'elementor_version',
				'label'    => __( 'Elementor present', 'stonewright' ),
				'ok'       => true,
				'severity' => 'low',
				'points'   => 0,
				'detail'   => sprintf(
					/* translators: %s: Elementor version */
					__( 'Elementor %s detected', 'stonewright' ),
					$elementor
				),
				'fix'      => '',
			];
		}

		$checks[] = [
			'id'       => 'orphan_postmeta',
			'label'    => __( 'Orphan postmeta', 'stonewright' ),
			'ok'       => $orphans < 100,
			'severity' => 'low',
			'points'   => 5,
			'detail'   => sprintf(
				/* translators: %d: orphan row count */
				__( '%d orphan postmeta rows (sampled)', 'stonewright' ),
				$orphans
			),
			'fix'      => __( 'Clean orphaned postmeta left by deleted posts/plugins.', 'stonewright' ),
		];

		return $checks;
	}

	/**
	 * @param list<array<string, mixed>> $checks
	 * @return array<string, mixed>
	 */
	private function summary_from_checks( array $checks ): array {
		$by_id = [];
		foreach ( $checks as $check ) {
			$by_id[ $check['id'] ] = $check;
		}

		$plugins = $this->plugin_counts();

		return [
			'wp_version'        => (string) get_bloginfo( 'version' ),
			'php_version'       => PHP_VERSION,
			'elementor_version' => defined( 'ELEMENTOR_VERSION' ) ? (string) constant( 'ELEMENTOR_VERSION' ) : null,
			'object_cache'      => function_exists( 'wp_using_ext_object_cache' ) ? (bool) wp_using_ext_object_cache() : false,
			'plugins_active'    => (int) ( $plugins['active'] ?? 0 ),
			'plugins_inactive'  => (int) ( $plugins['inactive'] ?? 0 ),
			'autoload_bytes'    => $this->autoload_size_bytes(),
			'debug_on'          => defined( 'WP_DEBUG' ) && constant( 'WP_DEBUG' ),
			'https'             => is_ssl() || $this->home_is_https(),
			'file_edit_disabled'=> defined( 'DISALLOW_FILE_EDIT' ) && true === constant( 'DISALLOW_FILE_EDIT' ),
			'admin_count'       => $this->admin_user_count(),
			'plugin_updates'    => $this->plugin_update_count(),
			'orphan_postmeta'   => $this->orphan_postmeta_count(),
			'failed_checks'     => count(
				array_filter(
					$checks,
					static fn( array $c ): bool => ! $c['ok']
				)
			),
		];
	}

	private function grade( int $score ): string {
		if ( $score >= 90 ) {
			return 'A';
		}
		if ( $score >= 80 ) {
			return 'B';
		}
		if ( $score >= 70 ) {
			return 'C';
		}
		if ( $score >= 60 ) {
			return 'D';
		}
		return 'F';
	}

	/**
	 * @return array{active:int,inactive:int,total:int}
	 */
	private function plugin_counts(): array {
		if ( ! function_exists( 'get_plugins' ) ) {
			$plugin_file = ABSPATH . 'wp-admin/includes/plugin.php';
			if ( is_readable( $plugin_file ) ) {
				require_once $plugin_file;
			}
		}
		if ( ! function_exists( 'get_plugins' ) ) {
			return [ 'active' => 0, 'inactive' => 0, 'total' => 0 ];
		}

		$all      = get_plugins();
		$active   = 0;
		$inactive = 0;
		foreach ( array_keys( $all ) as $file ) {
			if ( function_exists( 'is_plugin_active' ) && is_plugin_active( $file ) ) {
				++$active;
			} else {
				++$inactive;
			}
		}

		return [
			'active'   => $active,
			'inactive' => $inactive,
			'total'    => $active + $inactive,
		];
	}

	private function autoload_size_bytes(): int {
		if ( isset( $GLOBALS['stonewright_test_autoload_bytes'] ) ) {
			return (int) $GLOBALS['stonewright_test_autoload_bytes'];
		}

		global $wpdb;
		if ( ! isset( $wpdb ) || ! is_object( $wpdb ) || ! method_exists( $wpdb, 'get_var' ) ) {
			return 0;
		}

		$table = isset( $wpdb->options ) ? (string) $wpdb->options : '';
		if ( '' === $table ) {
			return 0;
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$value = $wpdb->get_var(
			"SELECT SUM(LENGTH(option_value)) FROM {$table} WHERE autoload IN ('yes','on','auto','auto-on')"
		);

		return max( 0, (int) $value );
	}

	private function admin_user_count(): int {
		if ( isset( $GLOBALS['stonewright_test_admin_count'] ) ) {
			return max( 0, (int) $GLOBALS['stonewright_test_admin_count'] );
		}

		if ( function_exists( 'count_users' ) ) {
			$counts = count_users();
			$roles  = (array) ( $counts['avail_roles'] ?? [] );
			return (int) ( $roles['administrator'] ?? 0 );
		}

		if ( function_exists( 'get_users' ) ) {
			$users = get_users(
				[
					'role'   => 'administrator',
					'fields' => 'ID',
					'number' => 100,
				]
			);
			return is_array( $users ) ? count( $users ) : 0;
		}

		return 1;
	}

	private function plugin_update_count(): int {
		if ( isset( $GLOBALS['stonewright_test_plugin_updates'] ) ) {
			return max( 0, (int) $GLOBALS['stonewright_test_plugin_updates'] );
		}

		$transient = function_exists( 'get_site_transient' )
			? get_site_transient( 'update_plugins' )
			: get_transient( 'update_plugins' );

		if ( ! is_object( $transient ) || empty( $transient->response ) || ! is_array( $transient->response ) ) {
			return 0;
		}

		return count( $transient->response );
	}

	private function orphan_postmeta_count(): int {
		if ( isset( $GLOBALS['stonewright_test_orphan_postmeta'] ) ) {
			return max( 0, (int) $GLOBALS['stonewright_test_orphan_postmeta'] );
		}

		global $wpdb;
		if ( ! isset( $wpdb ) || ! is_object( $wpdb ) || ! method_exists( $wpdb, 'get_var' ) ) {
			return 0;
		}

		$postmeta = isset( $wpdb->postmeta ) ? (string) $wpdb->postmeta : '';
		$posts    = isset( $wpdb->posts ) ? (string) $wpdb->posts : '';
		if ( '' === $postmeta || '' === $posts ) {
			return 0;
		}

		// Targeted sample: count of postmeta rows without a matching post.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$value = $wpdb->get_var(
			"SELECT COUNT(*) FROM {$postmeta} pm
			LEFT JOIN {$posts} p ON p.ID = pm.post_id
			WHERE p.ID IS NULL"
		);

		return max( 0, (int) $value );
	}

	private function home_is_https(): bool {
		$url = home_url( '/' );
		return is_string( $url ) && str_starts_with( strtolower( $url ), 'https://' );
	}
}
