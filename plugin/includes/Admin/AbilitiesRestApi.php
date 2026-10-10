<?php
/**
 * REST routes of the AI Abilities page: the switch, the bulk action and the parameter list.
 * SPDX-License-Identifier: GPL-2.0-or-later
 *
 * @package Stonewright
 */

declare( strict_types=1 );

namespace Stonewright\WpMcp\Admin;

use Stonewright\WpMcp\Security\Permissions;

/**
 * Lets the page switch an ability without a reload.
 *
 * The routes are the form handlers of AbilitiesPage (`stonewright_toggle_ability` and `stonewright_bulk_abilities`)
 * reached another way, and they keep the same gates: the `manage_options` capability, the nonce of that form, the
 * same option and the same writer (AbilityToggles). A write also needs the REST nonce that WordPress requires of a
 * signed-in request. The routes are for the page's own script; agents use the confirmation-token route.
 *
 * Route namespace `stonewright/v1`, path prefix `/admin/abilities`.
 */
final class AbilitiesRestApi {

	public const REST_NAMESPACE = 'stonewright/v1';

	/** The nonce WordPress asks of a cookie-authenticated REST request. */
	public const REST_NONCE_ACTION = 'wp_rest';

	public const INVALID_NONCE_CODE = 'stonewright_abilities_invalid_nonce';

	private static bool $registered = false;

	/** @return array<string, array{path: string, methods: string, nonce: string}> */
	private static function routes(): array {
		return [
			'abilities.toggle'     => [
				'path'    => '/admin/abilities/toggle',
				'methods' => 'POST',
				'nonce'   => AbilitiesPage::NONCE_ACTION,
			],
			'abilities.bulk'       => [
				'path'    => '/admin/abilities/bulk',
				'methods' => 'POST',
				'nonce'   => AbilitiesPage::BULK_NONCE_ACTION,
			],
			'abilities.parameters' => [
				'path'    => '/admin/abilities/parameters',
				'methods' => 'GET',
				'nonce'   => '',
			],
		];
	}

	public static function register(): void {
		if ( self::$registered ) {
			return;
		}
		self::$registered = true;

		$handlers = [
			'abilities.toggle'     => [ self::class, 'toggle' ],
			'abilities.bulk'       => [ self::class, 'bulk' ],
			'abilities.parameters' => [ self::class, 'parameters' ],
		];
		$args     = [
			'abilities.toggle'     => [
				'name'         => [ 'type' => 'string', 'required' => true ],
				'enabled'      => [ 'type' => 'boolean', 'required' => true ],
				'action_nonce' => [ 'type' => 'string' ],
			],
			'abilities.bulk'       => [
				'action'       => [ 'type' => 'string' ],
				'category'     => [ 'type' => 'string' ],
				'abilities'    => [ 'type' => 'array', 'items' => [ 'type' => 'string' ] ],
				'action_nonce' => [ 'type' => 'string' ],
			],
			'abilities.parameters' => [
				'name' => [ 'type' => 'string', 'required' => true ],
			],
		];

		foreach ( self::routes() as $route_id => $route ) {
			register_rest_route(
				self::REST_NAMESPACE,
				$route['path'],
				[
					'methods'             => $route['methods'],
					'permission_callback' => static fn ( \WP_REST_Request $request ): bool|\WP_Error => self::check_permission( $route_id, $request ),
					'callback'            => $handlers[ $route_id ],
					'args'                => $args[ $route_id ],
				]
			);
		}
	}

	/**
	 * The capability of the form handlers, plus the nonces a write needs.
	 *
	 * @return bool|\WP_Error
	 */
	public static function check_permission( string $route_id, \WP_REST_Request $request ) {
		$route = self::routes()[ $route_id ] ?? null;
		if ( null === $route ) {
			return new \WP_Error( 'stonewright_abilities_unknown_route', __( 'Unknown route.', 'stonewright' ), [ 'status' => 404 ] );
		}

		if ( ! Permissions::manage_options() ) {
			return new \WP_Error( 'rest_forbidden', __( 'You do not have permission to change abilities.', 'stonewright' ), [ 'status' => 403 ] );
		}

		if ( 'GET' === $route['methods'] ) {
			return true;
		}

		if ( ! self::nonce_is_valid( (string) $request->get_header( 'X-WP-Nonce' ), self::REST_NONCE_ACTION )
			|| ! self::nonce_is_valid( self::text( $request->get_param( 'action_nonce' ) ), $route['nonce'] )
		) {
			return new \WP_Error(
				self::INVALID_NONCE_CODE,
				__( 'This page has expired. Reload it and try again.', 'stonewright' ),
				[ 'status' => 403 ]
			);
		}

		return true;
	}

	/**
	 * Switch one ability on or off.
	 *
	 * @return \WP_REST_Response|\WP_Error
	 */
	public static function toggle( \WP_REST_Request $request ) {
		$name    = sanitize_text_field( self::text( $request->get_param( 'name' ) ) );
		$enabled = self::truthy( $request->get_param( 'enabled' ) );

		$result = AbilityToggles::set_enabled( $name, $enabled );
		if ( ! $result['ok'] ) {
			return new \WP_Error( 'stonewright_ability_name_missing', AbilityToggles::message( $result['code'] ), [ 'status' => 400 ] );
		}

		return rest_ensure_response(
			[
				'ok'      => true,
				'code'    => $result['code'],
				'name'    => $name,
				'enabled' => $result['enabled'],
				'changed' => $result['changed'],
				'message' => AbilityToggles::message( $result['code'], $result['changed'] ),
				'stats'   => AbilityToggles::stats(),
			]
		);
	}

	/**
	 * Apply a bulk action to selected abilities or to one category.
	 *
	 * @return \WP_REST_Response|\WP_Error
	 */
	public static function bulk( \WP_REST_Request $request ) {
		$action    = sanitize_key( self::text( $request->get_param( 'action' ) ) );
		$category  = sanitize_key( self::text( $request->get_param( 'category' ) ) );
		$abilities = $request->get_param( 'abilities' );
		$selected  = is_array( $abilities ) ? array_map( 'sanitize_text_field', array_map( [ self::class, 'text' ], $abilities ) ) : [];

		$result = AbilityToggles::bulk( $action, $category, $selected );
		if ( ! $result['ok'] ) {
			return new \WP_Error(
				'stonewright_' . str_replace( '-', '_', $result['code'] ),
				AbilityToggles::message( $result['code'] ),
				[ 'status' => 400 ]
			);
		}

		return rest_ensure_response(
			[
				'ok'      => true,
				'code'    => $result['code'],
				'enabled' => $result['enabled'],
				'names'   => $result['names'],
				'changed' => $result['changed'],
				'message' => AbilityToggles::message( $result['code'], $result['changed'] ),
				'stats'   => AbilityToggles::stats(),
			]
		);
	}

	/**
	 * The input parameters of one ability, for the row's parameter list. Read only.
	 *
	 * @return \WP_REST_Response|\WP_Error
	 */
	public static function parameters( \WP_REST_Request $request ) {
		$name = sanitize_text_field( self::text( $request->get_param( 'name' ) ) );

		foreach ( AbilityHubCatalog::collect() as $ability ) {
			if ( (string) $ability['name'] !== $name ) {
				continue;
			}

			return rest_ensure_response(
				[
					'name'       => $name,
					'parameters' => self::parameter_list( $ability ),
				]
			);
		}

		return new \WP_Error( 'stonewright_ability_not_found', __( 'Ability not found.', 'stonewright' ), [ 'status' => 404 ] );
	}

	/**
	 * @param array<string, mixed> $ability
	 * @return list<array{name: string, type: string, required: bool, description: string}>
	 */
	public static function parameter_list( array $ability ): array {
		$schema = is_array( $ability['input_schema'] ?? null ) ? $ability['input_schema'] : [];
		$props  = is_array( $schema['properties'] ?? null ) ? $schema['properties'] : [];
		$req    = is_array( $schema['required'] ?? null ) ? $schema['required'] : [];
		$list   = [];

		foreach ( $props as $param => $def ) {
			$def    = is_object( $def ) ? get_object_vars( $def ) : ( is_array( $def ) ? $def : [] );
			$type   = is_array( $def['type'] ?? null ) ? implode( '|', array_map( 'strval', $def['type'] ) ) : (string) ( $def['type'] ?? '?' );
			$list[] = [
				'name'        => (string) $param,
				'type'        => $type,
				'required'    => in_array( $param, $req, true ),
				'description' => (string) ( $def['description'] ?? '' ),
			];
		}

		return $list;
	}

	/** Forget that the routes were registered. For tests. */
	public static function reset_for_tests(): void {
		self::$registered = false;
	}

	private static function nonce_is_valid( string $nonce, string $action ): bool {
		return '' !== $nonce && false !== wp_verify_nonce( $nonce, $action );
	}

	private static function text( mixed $value ): string {
		return is_scalar( $value ) ? (string) $value : '';
	}

	private static function truthy( mixed $value ): bool {
		if ( is_bool( $value ) ) {
			return $value;
		}

		return in_array( is_string( $value ) ? strtolower( $value ) : $value, [ 1, '1', 'true', 'on', 'yes' ], true );
	}
}
