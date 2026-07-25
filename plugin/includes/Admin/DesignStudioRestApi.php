<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\Admin;

use Stonewright\WpMcp\Abilities\AbilityKernel;
use Stonewright\WpMcp\Abilities\Design\DirectionActivate;
use Stonewright\WpMcp\Abilities\Design\DirectionGet;
use Stonewright\WpMcp\Abilities\Design\DirectionList;
use Stonewright\WpMcp\Abilities\Design\DirectionRestore;
use Stonewright\WpMcp\Abilities\Design\DirectionSave;
use Stonewright\WpMcp\Abilities\Design\DirectionSyncApply;
use Stonewright\WpMcp\Abilities\Design\DirectionSyncPlan;
use Stonewright\WpMcp\Design\Direction\DesignDirectionService;
use Stonewright\WpMcp\Design\Quality\QualityReportStore;
use Stonewright\WpMcp\Security\Permissions;

/**
 * REST surface for the Design Studio admin page.
 *
 * This controller deliberately holds no design rules. It is a routing table, a
 * capability and nonce gate, and one generic dispatcher that hands the request
 * to the typed design ability that already owns the rule. Every guarantee the
 * MCP surface makes — validation, backup, confirmation tokens, audit, readback
 * — therefore applies unchanged when the admin UI is the caller.
 *
 * Route namespace: `stonewright/v1`, path prefix `/design-studio`.
 */
final class DesignStudioRestApi {

	public const REST_NAMESPACE = 'stonewright/v1';

	public const ROUTE_PREFIX = '/design-studio';

	public const NONCE_ACTION = 'wp_rest';

	public const INVALID_ID_CODE = 'stonewright_design_studio_invalid_id';

	public const INVALID_NONCE_CODE = 'stonewright_design_studio_invalid_nonce';

	private static bool $registered = false;

	private static ?DesignDirectionService $service = null;

	/**
	 * The complete routing table.
	 *
	 * `ability` names the class that owns the behaviour; `id_param` names the
	 * positive integer the route cannot run without. A null ability marks the
	 * one route backed by a read-only store rather than an ability.
	 *
	 * @return array<string, array{path:string, methods:string, ability:class-string<AbilityKernel>|null, id_param:string|null}>
	 */
	public static function routes(): array {
		return [
			'directions.list'       => [
				'path'     => self::ROUTE_PREFIX . '/directions',
				'methods'  => 'GET',
				'ability'  => DirectionList::class,
				'id_param' => null,
			],
			'directions.get'        => [
				'path'     => self::ROUTE_PREFIX . '/directions/(?P<id>\d+)',
				'methods'  => 'GET',
				'ability'  => DirectionGet::class,
				'id_param' => 'id',
			],
			'directions.save'       => [
				'path'     => self::ROUTE_PREFIX . '/directions',
				'methods'  => 'POST',
				'ability'  => DirectionSave::class,
				'id_param' => null,
			],
			'directions.activate'   => [
				'path'     => self::ROUTE_PREFIX . '/directions/(?P<id>\d+)/activate',
				'methods'  => 'POST',
				'ability'  => DirectionActivate::class,
				'id_param' => 'id',
			],
			'directions.restore'    => [
				'path'     => self::ROUTE_PREFIX . '/directions/(?P<id>\d+)/restore',
				'methods'  => 'POST',
				'ability'  => DirectionRestore::class,
				'id_param' => 'id',
			],
			'directions.sync_plan'  => [
				'path'     => self::ROUTE_PREFIX . '/directions/(?P<id>\d+)/sync-plan',
				'methods'  => 'POST',
				'ability'  => DirectionSyncPlan::class,
				'id_param' => 'id',
			],
			'directions.sync_apply' => [
				'path'     => self::ROUTE_PREFIX . '/directions/(?P<id>\d+)/sync-apply',
				'methods'  => 'POST',
				'ability'  => DirectionSyncApply::class,
				'id_param' => 'id',
			],
			'quality.list'          => [
				'path'     => self::ROUTE_PREFIX . '/quality',
				'methods'  => 'GET',
				'ability'  => null,
				'id_param' => 'post_id',
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
				'stonewright_design_studio_unknown_route',
				__( 'Unknown Design Studio route.', 'stonewright' ),
				[ 'status' => 404 ]
			);
		}

		if ( ! Permissions::manage_options() ) {
			return new \WP_Error(
				'rest_forbidden',
				__( 'You do not have permission to use the Design Studio.', 'stonewright' ),
				[ 'status' => 403 ]
			);
		}

		if ( 'GET' !== $route['methods'] && ! self::nonce_is_valid( $request ) ) {
			return new \WP_Error(
				self::INVALID_NONCE_CODE,
				__( 'This request needs a current REST nonce. Reload the Design Studio and try again.', 'stonewright' ),
				[ 'status' => 403 ]
			);
		}

		return true;
	}

	/**
	 * Generic dispatcher: validate the identifier, then hand over to the owner.
	 *
	 * @return \WP_REST_Response|\WP_Error
	 */
	public static function handle( string $route_id, \WP_REST_Request $request ) {
		$route = self::routes()[ $route_id ] ?? null;

		if ( null === $route ) {
			return new \WP_Error(
				'stonewright_design_studio_unknown_route',
				__( 'Unknown Design Studio route.', 'stonewright' ),
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

		if ( null === $route['ability'] ) {
			return self::quality_reports( $identifier, $request );
		}

		return self::run_ability( $route['ability'], $request );
	}

	/**
	 * Injects the direction service the abilities read through. Tests only.
	 */
	public static function set_service_for_tests( ?DesignDirectionService $service ): void {
		self::$service = $service;
	}

	public static function reset_for_tests(): void {
		self::$registered = false;
		self::$service    = null;
	}

	/**
	 * Reads the recent quality reports stored on a post.
	 *
	 * @return \WP_REST_Response|\WP_Error
	 */
	private static function quality_reports( int $post_id, \WP_REST_Request $request ) {
		if ( ! Permissions::can_view_design() ) {
			return new \WP_Error(
				'rest_forbidden',
				__( 'You do not have permission to read design quality reports.', 'stonewright' ),
				[ 'status' => 403 ]
			);
		}

		$limit   = self::positive_int( $request->get_param( 'limit' ) ) ?? QualityReportStore::DEFAULT_LIMIT;
		$reports = QualityReportStore::latest( $post_id, $limit );

		return rest_ensure_response(
			[
				'ok'      => true,
				'post_id' => $post_id,
				'count'   => count( $reports ),
				'reports' => $reports,
			]
		);
	}

	/**
	 * Runs one typed ability with its own permission callback and schema.
	 *
	 * @param class-string<AbilityKernel> $ability_class Ability that owns the rule.
	 * @return \WP_REST_Response|\WP_Error
	 */
	private static function run_ability( string $ability_class, \WP_REST_Request $request ) {
		$ability = new $ability_class( self::service() );
		$args    = self::args_for( $ability, $request );

		$allowed = $ability->permission_callback( $args );
		if ( $allowed instanceof \WP_Error ) {
			return $allowed;
		}

		if ( true !== $allowed ) {
			return new \WP_Error(
				'rest_forbidden',
				__( 'You do not have permission to run this design operation.', 'stonewright' ),
				[ 'status' => 403 ]
			);
		}

		return rest_ensure_response( $ability->execute( $args ) );
	}

	/**
	 * Builds ability arguments from the request using the ability's own schema.
	 *
	 * Anything the schema does not declare never reaches the ability, so a new
	 * query parameter cannot smuggle a value past `additionalProperties: false`.
	 *
	 * @return array<string, mixed>
	 */
	private static function args_for( AbilityKernel $ability, \WP_REST_Request $request ): array {
		$schema     = $ability->input_schema();
		$properties = is_array( $schema['properties'] ?? null ) ? $schema['properties'] : [];
		$args       = [];

		foreach ( $properties as $key => $definition ) {
			$value = $request->get_param( (string) $key );

			if ( null === $value ) {
				continue;
			}

			$type = is_array( $definition ) && isset( $definition['type'] ) ? (string) $definition['type'] : 'string';

			$args[ (string) $key ] = self::coerce( $value, $type );
		}

		return $args;
	}

	/**
	 * Narrows a transport value to the type the ability schema declares.
	 *
	 * @param mixed $value Raw request value.
	 * @return mixed
	 */
	private static function coerce( mixed $value, string $type ) {
		return match ( $type ) {
			'integer' => is_numeric( $value ) ? (int) $value : $value,
			'number'  => is_numeric( $value ) ? (float) $value : $value,
			'boolean' => self::to_bool( $value ),
			'string'  => is_scalar( $value ) ? (string) $value : $value,
			'object'  => is_array( $value ) ? $value : $value,
			default   => $value,
		};
	}

	/**
	 * @param mixed $value Raw request value.
	 * @return mixed True/false when the value is a recognised boolean literal.
	 */
	private static function to_bool( mixed $value ) {
		if ( is_bool( $value ) ) {
			return $value;
		}

		if ( is_string( $value ) ) {
			$normalized = strtolower( trim( $value ) );

			if ( in_array( $normalized, [ '1', 'true', 'yes' ], true ) ) {
				return true;
			}

			if ( in_array( $normalized, [ '0', 'false', 'no', '' ], true ) ) {
				return false;
			}
		}

		if ( is_int( $value ) ) {
			return 1 === $value;
		}

		return $value;
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

	private static function service(): DesignDirectionService {
		return self::$service ?? new DesignDirectionService();
	}
}
