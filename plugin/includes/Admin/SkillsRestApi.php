<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\Admin;

use Stonewright\WpMcp\Security\AuditLog;
use Stonewright\WpMcp\Security\Permissions;
use Stonewright\WpMcp\Skills\SkillExporter;
use Stonewright\WpMcp\Skills\SkillImporter;
use Stonewright\WpMcp\Skills\Skills;

/**
 * REST surface for the skills admin page.
 *
 * Like the Design Studio controller, this is a routing table, a capability and
 * nonce gate, and a dispatcher. The lifecycle rules live in `Skills`,
 * `SkillImporter`, and `SkillExporter`, so the admin UI reaches exactly the
 * same refusals — protected sources, import re-derivation, and the
 * production-safe confirmation gate on hard deletion — that any other caller
 * would hit.
 *
 * Route namespace: `stonewright/v1`, path prefix `/skills-studio`. The prefix
 * keeps these routes clear of the public `/skills` endpoints.
 */
final class SkillsRestApi {

	public const REST_NAMESPACE = 'stonewright/v1';

	public const ROUTE_PREFIX = '/skills-studio';

	public const NONCE_ACTION = 'wp_rest';

	public const INVALID_ID_CODE = 'stonewright_skills_invalid_id';

	public const INVALID_NONCE_CODE = 'stonewright_skills_invalid_nonce';

	private static bool $registered = false;

	/**
	 * The complete routing table.
	 *
	 * `id_param` names the positive integer a route cannot run without.
	 *
	 * @return array<string, array{path: string, methods: string, id_param: string|null}>
	 */
	public static function routes(): array {
		return [
			'skills.catalog' => [
				'path'     => self::ROUTE_PREFIX . '/catalog',
				'methods'  => 'GET',
				'id_param' => null,
			],
			'skills.inspect' => [
				'path'     => self::ROUTE_PREFIX . '/import/inspect',
				'methods'  => 'POST',
				'id_param' => null,
			],
			'skills.import'  => [
				'path'     => self::ROUTE_PREFIX . '/import',
				'methods'  => 'POST',
				'id_param' => null,
			],
			'skills.export'  => [
				'path'     => self::ROUTE_PREFIX . '/skills/(?P<id>\d+)/export',
				'methods'  => 'GET',
				'id_param' => 'id',
			],
			'skills.trash'   => [
				'path'     => self::ROUTE_PREFIX . '/skills/(?P<id>\d+)/trash',
				'methods'  => 'POST',
				'id_param' => 'id',
			],
			'skills.restore' => [
				'path'     => self::ROUTE_PREFIX . '/skills/(?P<id>\d+)/restore',
				'methods'  => 'POST',
				'id_param' => 'id',
			],
			'skills.destroy' => [
				'path'     => self::ROUTE_PREFIX . '/skills/(?P<id>\d+)',
				'methods'  => 'DELETE',
				'id_param' => 'id',
			],
		];
	}

	public static function register(): void {
		if ( self::$registered ) {
			return;
		}
		self::$registered = true;

		foreach ( self::routes() as $route_id => $route ) {
			register_rest_route(
				self::REST_NAMESPACE,
				$route['path'],
				[
					'methods'             => $route['methods'],
					'permission_callback' => static fn( \WP_REST_Request $request ): bool|\WP_Error => self::check_permission( $route_id, $request ),
					'callback'            => static fn( \WP_REST_Request $request ): \WP_REST_Response|\WP_Error => self::handle( $route_id, $request ),
					'args'                => [],
				]
			);
		}
	}

	/**
	 * Capability gate, plus a REST nonce for anything that is not a plain read.
	 *
	 * @return bool|\WP_Error
	 */
	public static function check_permission( string $route_id, \WP_REST_Request $request ) {
		$route = self::routes()[ $route_id ] ?? null;

		if ( null === $route ) {
			return new \WP_Error(
				'stonewright_skills_unknown_route',
				__( 'Unknown skills route.', 'stonewright' ),
				[ 'status' => 404 ]
			);
		}

		if ( ! Permissions::manage_options() ) {
			return new \WP_Error(
				'rest_forbidden',
				__( 'You do not have permission to manage skills.', 'stonewright' ),
				[ 'status' => 403 ]
			);
		}

		if ( 'GET' !== $route['methods'] && ! self::nonce_is_valid( $request ) ) {
			return new \WP_Error(
				self::INVALID_NONCE_CODE,
				__( 'This request needs a current REST nonce. Reload the skills page and try again.', 'stonewright' ),
				[ 'status' => 403 ]
			);
		}

		return true;
	}

	/**
	 * Validate the identifier, then hand the request to the lifecycle helper.
	 *
	 * @return \WP_REST_Response|\WP_Error
	 */
	public static function handle( string $route_id, \WP_REST_Request $request ) {
		$route = self::routes()[ $route_id ] ?? null;

		if ( null === $route ) {
			return new \WP_Error(
				'stonewright_skills_unknown_route',
				__( 'Unknown skills route.', 'stonewright' ),
				[ 'status' => 404 ]
			);
		}

		$identifier = 0;
		if ( null !== $route['id_param'] ) {
			$identifier = self::positive_int( $request->get_param( $route['id_param'] ) );

			if ( null === $identifier ) {
				return new \WP_Error(
					self::INVALID_ID_CODE,
					__( 'This request needs a positive integer identifier.', 'stonewright' ),
					[
						'status' => 400,
						'param'  => $route['id_param'],
					]
				);
			}
		}

		return match ( $route_id ) {
			'skills.catalog' => self::catalog(),
			'skills.inspect' => self::inspect( $request ),
			'skills.import'  => self::import( $request ),
			'skills.export'  => self::export( $identifier ),
			'skills.trash'   => self::lifecycle( 'trash', $identifier ),
			'skills.restore' => self::lifecycle( 'restore', $identifier ),
			'skills.destroy' => self::destroy( $identifier, $request ),
			default          => new \WP_Error(
				'stonewright_skills_unknown_route',
				__( 'Unknown skills route.', 'stonewright' ),
				[ 'status' => 404 ]
			),
		};
	}

	public static function reset_for_tests(): void {
		self::$registered = false;
	}

	/**
	 * Everything the page renders in one read: live skills, trash, and sources.
	 */
	private static function catalog(): \WP_REST_Response {
		$catalog = Skills::catalog();

		return rest_ensure_response(
			[
				'ok'        => true,
				'skills'    => $catalog['skills'],
				'conflicts' => $catalog['conflicts'],
				'sources'   => Skills::sources(),
				'trashed'   => Skills::list_trashed(),
			]
		);
	}

	/**
	 * Step one of an import: review the file, write nothing.
	 *
	 * @return \WP_REST_Response|\WP_Error
	 */
	private static function inspect( \WP_REST_Request $request ) {
		$filename = (string) ( $request->get_param( 'filename' ) ?? '' );
		$content  = $request->get_param( 'content' );

		if ( ! is_string( $content ) ) {
			return new \WP_Error(
				'stonewright_skill_import_invalid',
				__( 'Upload the file contents as text.', 'stonewright' ),
				[ 'status' => 400 ]
			);
		}

		$inspection = SkillImporter::inspect( $filename, $content );

		if ( is_wp_error( $inspection ) ) {
			return $inspection;
		}

		return rest_ensure_response(
			[
				'ok'         => true,
				'inspection' => $inspection,
			]
		);
	}

	/**
	 * Step two of an import: the reviewed file lands as a disabled draft.
	 *
	 * The report is echoed back by the browser, so the importer re-derives
	 * readiness from the content rather than believing what it is handed.
	 *
	 * @return \WP_REST_Response|\WP_Error
	 */
	private static function import( \WP_REST_Request $request ) {
		$inspection = $request->get_param( 'inspection' );

		if ( ! is_array( $inspection ) ) {
			return new \WP_Error(
				'stonewright_skill_import_invalid',
				__( 'Inspect the file before importing it.', 'stonewright' ),
				[ 'status' => 400 ]
			);
		}

		$skill_id = SkillImporter::import( $inspection, get_current_user_id() );

		if ( is_wp_error( $skill_id ) ) {
			self::audit( 'import', [ 'slug' => (string) ( $inspection['slug'] ?? '' ) ], 'blocked' );
			return $skill_id;
		}

		self::audit(
			'import',
			[
				'skill_id' => $skill_id,
				'slug'     => (string) ( $inspection['slug'] ?? '' ),
			]
		);

		return rest_ensure_response(
			[
				'ok'       => true,
				'skill_id' => $skill_id,
			]
		);
	}

	/**
	 * @return \WP_REST_Response|\WP_Error
	 */
	private static function export( int $skill_id ) {
		$markdown = SkillExporter::markdown( $skill_id );

		if ( is_wp_error( $markdown ) ) {
			return $markdown;
		}

		$skill = Skills::get_by_id( $skill_id );
		$slug  = (string) ( $skill['slug'] ?? 'skill' );

		return rest_ensure_response(
			[
				'ok'       => true,
				'filename' => $slug . '.md',
				'markdown' => $markdown,
			]
		);
	}

	/**
	 * Trash or restore, whichever the route asked for.
	 *
	 * @return \WP_REST_Response|\WP_Error
	 */
	private static function lifecycle( string $action, int $skill_id ) {
		$result = 'trash' === $action ? Skills::trash( $skill_id ) : Skills::restore( $skill_id );

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		self::audit( $action, [ 'skill_id' => $skill_id ] );

		return rest_ensure_response(
			[
				'ok'       => true,
				'skill_id' => $skill_id,
				'action'   => $action,
			]
		);
	}

	/**
	 * Hard deletion. Needs a confirmation token in production-safe mode.
	 *
	 * @return \WP_REST_Response|\WP_Error
	 */
	private static function destroy( int $skill_id, \WP_REST_Request $request ) {
		$token  = $request->get_param( 'confirmation_token' );
		$result = Skills::destroy( $skill_id, is_string( $token ) ? $token : '' );

		if ( is_wp_error( $result ) ) {
			self::audit( 'destroy', [ 'skill_id' => $skill_id ], 'blocked' );
			return $result;
		}

		self::audit( 'destroy', [ 'skill_id' => $skill_id ] );

		return rest_ensure_response(
			[
				'ok'       => true,
				'skill_id' => $skill_id,
				'action'   => 'destroy',
			]
		);
	}

	/**
	 * @param array<string, mixed> $args Sanitised argument summary for the log.
	 */
	private static function audit( string $action, array $args, string $status = 'ok' ): void {
		AuditLog::record( 'stonewright/skills-' . $action, $args, $status );
	}

	/**
	 * @param mixed $value Raw request value.
	 * @return int|null Positive integer, or null when the value is not one.
	 */
	private static function positive_int( mixed $value ): ?int {
		if ( is_bool( $value ) || ! is_scalar( $value ) ) {
			return null;
		}

		$text = trim( (string) $value );

		if ( 1 !== preg_match( '/^\d+$/', $text ) ) {
			return null;
		}

		$number = (int) $text;

		return $number > 0 ? $number : null;
	}

	private static function nonce_is_valid( \WP_REST_Request $request ): bool {
		$nonce = $request->get_header( 'X-WP-Nonce' );

		if ( null === $nonce || '' === $nonce ) {
			$param = $request->get_param( '_wpnonce' );
			$nonce = is_string( $param ) ? $param : '';
		}

		if ( '' === $nonce ) {
			return false;
		}

		return false !== wp_verify_nonce( $nonce, self::NONCE_ACTION );
	}
}
