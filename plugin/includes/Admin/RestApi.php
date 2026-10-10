<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\Admin;

use Stonewright\WpMcp\Admin\Diagnostics\AbilitiesState;
use Stonewright\WpMcp\Admin\Diagnostics\ApplicationPasswordsState;
use Stonewright\WpMcp\Core\LiveAbilities;
use Stonewright\WpMcp\Sandbox\SandboxFiles;
use Stonewright\WpMcp\Security\DomainLock;
use Stonewright\WpMcp\Security\Permissions;

/**
 * REST API extensions for admin features.
 *
 * Route: GET /wp-json/stonewright/v1/sandbox/files
 *   — listed in RestRoutes.php (which owns the primary /sandbox/* routes).
 *
 * This class registers an additional endpoint that returns sandbox file data
 * in a React-UI-friendly JSON envelope, permission-gated by
 * Permissions::can_view_sandbox().
 *
 * Note: The core /sandbox/files routes (GET/POST/PUT/DELETE) live in
 * Core/RestRoutes.php and are gated by manage_options. This endpoint is
 * separate, gated by can_view_sandbox(), and intended as the future React
 * UI data source.
 */
final class RestApi {

	private static bool $registered = false;

	public static function register(): void {
		if ( self::$registered ) {
			return;
		}
		self::$registered = true;

		register_rest_route(
			'stonewright/v1',
			'/admin/sandbox/library',
			[
				'methods'             => \WP_REST_Server::READABLE,
				'permission_callback' => [ Permissions::class, 'can_view_sandbox' ],
				'callback'            => [ self::class, 'handle_sandbox_library' ],
				'args'                => [],
			]
		);

		register_rest_route(
			'stonewright/v1',
			'/admin/connection-test',
			[
				'methods'             => \WP_REST_Server::READABLE,
				'permission_callback' => [ Permissions::class, 'manage_options' ],
				'callback'            => [ self::class, 'handle_connection_test' ],
				'args'                => [],
			]
		);

		register_rest_route(
			'stonewright/v1',
			'/admin/connection-verify',
			[
				'methods'             => \WP_REST_Server::CREATABLE,
				'permission_callback' => [ Permissions::class, 'manage_options' ],
				'callback'            => [ self::class, 'handle_connection_verify' ],
				'args'                => [],
			]
		);

		register_rest_route(
			'stonewright/v1',
			'/admin/companion-update-status',
			[
				'methods'             => \WP_REST_Server::READABLE,
				'permission_callback' => [ Permissions::class, 'manage_options' ],
				'callback'            => [ self::class, 'handle_companion_update_status' ],
				'args'                => [],
			]
		);
	}

	/**
	 * Checks the latest release and the optional configured HTTP bridge.
	 *
	 * @param \WP_REST_Request $request REST request (unused).
	 */
	public static function handle_companion_update_status( \WP_REST_Request $request ): \WP_REST_Response {
		unset( $request );

		return rest_ensure_response( CompanionUpdateStatus::report( null, true ) );
	}

	/**
	 * Authenticated MCP loopback self-test for the Setup wizard.
	 *
	 * Mints a short-lived Application Password, exercises initialize → tools/list
	 * → stonewright-task-start, then revokes the credential. Distinct from the
	 * local preflight checklist on /admin/connection-test.
	 *
	 * @param \WP_REST_Request $request REST request (unused).
	 * @return \WP_REST_Response
	 */
	public static function handle_connection_verify( \WP_REST_Request $request ): \WP_REST_Response {
		unset( $request );

		return rest_ensure_response( McpLoopbackSelfTest::run() );
	}

	/**
	 * Local preflight checklist for the Setup wizard.
	 *
	 * Reports site readiness only. Does not prove a live MCP client connection.
	 *
	 * @param \WP_REST_Request $request REST request (unused).
	 * @return \WP_REST_Response
	 */
	public static function handle_connection_test( \WP_REST_Request $request ): \WP_REST_Response {
		unset( $request );

		$ability_state = AbilitiesState::current();
		$enabled       = $ability_state['effective'];
		$lock          = DomainLock::status();
		$locked        = (string) $lock['locked'];
		$endpoint      = ConnectClientConfig::mcp_endpoint_url();
		$tool_count    = LiveAbilities::exposed_count();
		$app_state     = ApplicationPasswordsState::inspect();
		$app_available = $app_state['available'];
		$app_count     = self::application_password_count();
		$elementor     = defined( 'ELEMENTOR_VERSION' ) || class_exists( '\\Elementor\\Plugin' );
		$elementor_ver = defined( 'ELEMENTOR_VERSION' ) ? (string) constant( 'ELEMENTOR_VERSION' ) : '';

		$checks = [
			[
				'id'     => 'abilities_enabled',
				'status' => $enabled ? 'ok' : 'error',
				'label'  => __( 'Stonewright abilities', 'stonewright' ),
				'detail' => $enabled
					? __( 'Enabled for this site.', 'stonewright' )
					: $ability_state['summary'],
				'fix'    => $ability_state['remedy'],
			],
			[
				'id'     => 'domain_lock',
				'status' => $lock['matches'] ? 'ok' : 'error',
				'label'  => __( 'Domain lock', 'stonewright' ),
				'detail' => ! $lock['matches']
					? AbilitiesState::lock_summary()
					: ( '' !== $locked
						? sprintf( /* translators: %s: the address the site is locked to */ __( 'Locked to %s, which is the current address of this site.', 'stonewright' ), $locked )
						: __( 'No domain lock is set yet. Stonewright records the address of this site when AI abilities are turned on.', 'stonewright' ) ),
				'fix'    => $lock['matches'] ? '' : AbilitiesState::lock_remedy(),
			],
			[
				'id'     => 'mcp_endpoint',
				'status' => '' !== $endpoint ? 'ok' : 'error',
				'label'  => __( 'MCP endpoint', 'stonewright' ),
				'detail' => '' !== $endpoint ? $endpoint : __( 'Endpoint URL could not be resolved.', 'stonewright' ),
				'fix'    => '' !== $endpoint ? '' : __( 'Check that WordPress REST is reachable.', 'stonewright' ),
			],
			[
				'id'     => 'application_passwords',
				'status' => $app_available && $app_count > 0 ? 'ok' : ( $app_available ? 'warn' : 'error' ),
				'label'  => __( 'Application Passwords', 'stonewright' ),
				'detail' => ! $app_available
					? $app_state['summary']
					: (
						$app_count > 0
							? sprintf(
								/* translators: %d: application password count. */
								__( '%d password(s) on this user — ready for MCP auth.', 'stonewright' ),
								$app_count
							)
							: __( 'Available, but none created yet.', 'stonewright' )
					),
				'fix'    => ! $app_available
					? $app_state['remedy']
					: ( $app_count > 0 ? '' : __( 'Generate an Application Password in step 2.', 'stonewright' ) ),
			],
			[
				'id'     => 'tool_surface',
				'status' => $enabled && $tool_count > 0 ? 'ok' : 'error',
				'label'  => __( 'Tool surface', 'stonewright' ),
				'detail' => $enabled
					? sprintf(
						/* translators: %d: enabled tool count. */
						__( '%d tools exposed in the current profile.', 'stonewright' ),
						$tool_count
					)
					: __( 'No tools are exposed while Stonewright is blocked or turned off.', 'stonewright' ),
				'fix'    => $enabled && $tool_count > 0
					? ''
					: ( $enabled ? __( 'Confirm abilities are registered.', 'stonewright' ) : $ability_state['remedy'] ),
			],
			[
				'id'     => 'elementor',
				'status' => $elementor ? 'ok' : 'warn',
				'label'  => __( 'Elementor', 'stonewright' ),
				'detail' => $elementor
					? (
						'' !== $elementor_ver
							? sprintf(
								/* translators: %s: Elementor version. */
								__( 'Detected (v%s).', 'stonewright' ),
								$elementor_ver
							)
							: __( 'Detected.', 'stonewright' )
					)
					: __( 'Not detected — Elementor abilities stay inactive until installed.', 'stonewright' ),
				'fix'    => $elementor ? '' : __( 'Install and activate Elementor for design tools.', 'stonewright' ),
			],
		];

		$has_error = in_array( 'error', array_column( $checks, 'status' ), true );
		$ready     = ! $has_error;

		if ( $ready ) {
			update_option( 'stonewright_setup_verified_at', time(), false );
		}

		return rest_ensure_response(
			[
				'ready'  => $ready,
				'checks' => $checks,
			]
		);
	}

	private static function application_password_count(): int {
		if ( ! ApplicationPasswordsState::inspect()['available'] || ! method_exists( '\\WP_Application_Passwords', 'get_user_application_passwords' ) ) {
			return 0;
		}

		$user_id = get_current_user_id();
		if ( $user_id <= 0 ) {
			return 0;
		}

		$passwords = \WP_Application_Passwords::get_user_application_passwords( $user_id );
		return is_array( $passwords ) ? count( $passwords ) : 0;
	}

	/**
	 * Returns the sandbox file list in JSON format for the React-ready admin UI.
	 *
	 * @param \WP_REST_Request $request REST request (unused — no query params yet).
	 * @return \WP_REST_Response|\WP_Error
	 */
	public static function handle_sandbox_library( \WP_REST_Request $request ): \WP_REST_Response|\WP_Error {
		$files              = SandboxFiles::list_files();
		$registered_widgets = (array) get_option( 'stonewright_registered_widgets', [] );

		// Enrich each file entry with a type field, and strip absolute paths.
		$enriched = array_map(
			static function ( array $file ) use ( $registered_widgets ): array {
				$name = (string) $file['name'];

				if ( in_array( $name, array_values( $registered_widgets ), true ) || str_starts_with( $name, 'widget-' ) ) {
					$type = 'widget';
				} elseif ( str_starts_with( $name, 'draft-' ) ) {
					$type = 'draft';
				} else {
					$type = 'snippet';
				}

				return [
					'name'     => $name,
					'type'     => $type,
					'size'     => $file['size'],
					'modified' => $file['modified'],
					'status'   => $file['status'],
				];
			},
			$files
		);

		return rest_ensure_response(
			[
				'files'   => $enriched,
				'count'   => count( $enriched ),
				'widgets' => array_keys( $registered_widgets ),
			]
		);
	}

	/**
	 * Resets registration state — for use in tests only.
	 *
	 * @internal
	 */
	public static function reset_for_tests(): void {
		self::$registered = false;
	}
}
