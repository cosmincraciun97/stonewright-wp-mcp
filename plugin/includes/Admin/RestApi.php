<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\Admin;

use Stonewright\WpMcp\Sandbox\SandboxFiles;
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
