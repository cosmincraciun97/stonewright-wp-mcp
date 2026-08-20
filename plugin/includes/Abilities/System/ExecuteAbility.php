<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\Abilities\System;

use Stonewright\WpMcp\Abilities\AbilityKernel;
use Stonewright\WpMcp\Core\AbilityRegistry;
use Stonewright\WpMcp\Security\Permissions;
use Stonewright\WpMcp\Security\PluginEffectiveState;
use Stonewright\WpMcp\Support\Utf8;

/**
 * Run any enabled registered ability through the same MCP execute path.
 *
 * @stonewright-status stable
 */
final class ExecuteAbility extends AbilityKernel {

	public function name(): string {
		return 'stonewright/execute-ability';
	}

	public function label(): string {
		return __( 'Execute a Stonewright ability', 'stonewright' );
	}

	public function description(): string {
		return __( 'Runs one named Stonewright ability with an arguments object through the same permission, mode, confirmation, backup, audit, and context gates as MCP. Disabled abilities stay disabled. Does not bypass php-execute read-only guards.', 'stonewright' );
	}

	public function category(): string {
		return 'system';
	}

	public function input_schema(): array {
		return [
			'type'                 => 'object',
			'additionalProperties' => false,
			'required'             => [ 'name' ],
			'properties'           => [
				'name'      => [
					'type'        => 'string',
					'description' => 'Ability name (stonewright/ping) or hyphenated MCP tool name (stonewright-ping).',
				],
				'arguments' => [
					'type'                 => 'object',
					'additionalProperties' => true,
					'default'              => [],
					'description'          => 'Arguments passed to the target ability, including confirmation_token or stonewright_context_token when that ability requires them.',
				],
			],
		];
	}

	public function output_schema(): array {
		return [
			'type'       => 'object',
			'properties' => [
				'ok'            => [ 'type' => 'boolean' ],
				'ability'       => [ 'type' => 'string' ],
				'mcp_tool_name' => [ 'type' => 'string' ],
				'result'        => [ 'type' => 'object' ],
			],
			'required'   => [ 'ok', 'ability', 'mcp_tool_name', 'result' ],
		];
	}

	public function permission_callback( array $args ): bool|\WP_Error {
		return Permissions::read();
	}

	public function execute( array $args ): array|\WP_Error {
		$name = self::normalize_ability_name( (string) ( $args['name'] ?? '' ) );
		if ( '' === $name ) {
			return $this->error(
				'ability_not_found',
				__( 'Ability not found.', 'stonewright' ),
				[ 'status' => 404 ]
			);
		}
		if ( $this->name() === $name ) {
			return $this->error(
				'ability_invalid',
				__( 'execute-ability cannot invoke itself.', 'stonewright' ),
				[ 'status' => 400 ]
			);
		}

		$ability = AbilityRegistry::ability_by_name( $name );
		if ( null === $ability ) {
			return $this->error(
				'ability_not_found',
				__( 'Ability not found.', 'stonewright' ),
				[ 'status' => 404 ]
			);
		}

		$master_enabled = PluginEffectiveState::is_effectively_enabled();
		if ( ! $master_enabled && 'stonewright/ping' !== $name ) {
			return $this->error(
				'disabled',
				__( 'Master toggle is OFF or blocked (domain lock / dependency).', 'stonewright' ),
				[ 'status' => 403 ]
			);
		}

		$disabled = (array) get_option( 'stonewright_disabled_abilities', [] );
		if ( in_array( $name, $disabled, true ) ) {
			return $this->error(
				'ability_disabled',
				__( 'Ability is disabled.', 'stonewright' ),
				[ 'status' => 403 ]
			);
		}

		$input = $args['arguments'] ?? [];
		$input = is_array( $input ) ? Utf8::deep_sanitize( $input ) : [];
		if ( ! is_array( $input ) ) {
			$input = [];
		}

		$permission = $ability->permission_callback( $input );
		if ( $permission instanceof \WP_Error ) {
			return $permission;
		}
		if ( true !== $permission ) {
			return $this->error(
				'ability_forbidden',
				__( 'You do not have permission to run this ability.', 'stonewright' ),
				[ 'status' => 403 ]
			);
		}

		$result = AbilityRegistry::execute_with_context_guard( $ability, $input );
		if ( $result instanceof \WP_Error ) {
			return $result;
		}

		return [
			'ok'            => true,
			'ability'       => $ability->name(),
			'mcp_tool_name' => AbilityRegistry::mcp_tool_name( $ability->name() ),
			'result'        => is_array( $result ) ? $result : [ 'value' => $result ],
		];
	}

	public static function normalize_ability_name( string $raw ): string {
		$name = strtolower( trim( $raw ) );
		if ( str_starts_with( $name, 'stonewright-' ) && ! str_contains( $name, '/' ) ) {
			return 'stonewright/' . substr( $name, strlen( 'stonewright-' ) );
		}
		return $name;
	}
}
