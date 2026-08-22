<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\Abilities\System;

use Stonewright\WpMcp\Abilities\AbilityKernel;
use Stonewright\WpMcp\Core\AbilityRegistry;
use Stonewright\WpMcp\Security\Permissions;

/**
 * Bounded schema + gate notes for one registered ability.
 *
 * @stonewright-status stable
 */
final class GetAbilityInfo extends AbilityKernel {

	private const MAX_DEPTH      = 4;
	private const MAX_PROPERTIES = 40;
	private const MAX_ENUM       = 24;
	private const MAX_STRING     = 200;

	public function name(): string {
		return 'stonewright/get-ability-info';
	}

	public function label(): string {
		return __( 'Get Stonewright ability info', 'stonewright' );
	}

	public function description(): string {
		return __( 'Returns a bounded input/output schema for one ability plus permission and mode notes. Schemas are truncated; call with a single name rather than dumping the catalog.', 'stonewright' );
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
				'name' => [
					'type'        => 'string',
					'description' => 'Ability name (stonewright/ping) or hyphenated MCP tool name (stonewright-ping).',
				],
			],
		];
	}

	public function output_schema(): array {
		return [
			'type'       => 'object',
			'properties' => [
				'name'                   => [ 'type' => 'string' ],
				'mcp_tool_name'          => [ 'type' => 'string' ],
				'label'                  => [ 'type' => 'string' ],
				'description'            => [ 'type' => 'string' ],
				'category'               => [ 'type' => 'string' ],
				'enabled'                => [ 'type' => 'boolean' ],
				'input_schema'           => [ 'type' => 'object' ],
				'output_schema'          => [ 'type' => 'object' ],
				'schema_truncated'       => [ 'type' => 'boolean' ],
				'has_confirmation_token' => [ 'type' => 'boolean' ],
				'permission_notes'       => [ 'type' => 'string' ],
			],
			'required'   => [
				'name',
				'mcp_tool_name',
				'label',
				'description',
				'category',
				'enabled',
				'input_schema',
				'output_schema',
				'schema_truncated',
				'has_confirmation_token',
				'permission_notes',
			],
		];
	}

	public function permission_callback( array $args ): bool|\WP_Error {
		return Permissions::read();
	}

	public function execute( array $args ): array|\WP_Error {
		$name    = ExecuteAbility::normalize_ability_name( (string) ( $args['name'] ?? '' ) );
		$ability = AbilityRegistry::ability_by_name( $name );
		if ( null === $ability || '' === $name ) {
			return $this->error(
				'ability_not_found',
				__( 'Ability not found.', 'stonewright' ),
				[ 'status' => 404 ]
			);
		}

		$disabled = (array) get_option( 'stonewright_disabled_abilities', [] );
		$input    = $ability->input_schema();
		$output   = $ability->output_schema();
		$bounded_input  = $this->bound_schema( $input, 1 );
		$bounded_output = $this->bound_schema( $output, 1 );
		$has_token      = isset( $input['properties'] ) && is_array( $input['properties'] )
			&& array_key_exists( 'confirmation_token', $input['properties'] );

		return [
			'name'                   => $ability->name(),
			'mcp_tool_name'          => AbilityRegistry::mcp_tool_name( $ability->name() ),
			'label'                  => $ability->label(),
			'description'            => mb_substr( $ability->description(), 0, self::MAX_STRING ),
			'category'               => $ability->category(),
			'enabled'                => ! in_array( $ability->name(), $disabled, true ),
			'input_schema'           => $bounded_input['schema'],
			'output_schema'          => $bounded_output['schema'],
			'schema_truncated'       => $bounded_input['truncated'] || $bounded_output['truncated'],
			'has_confirmation_token' => $has_token,
			'permission_notes'       => 'The target ability permission_callback still applies. Destructive operations require a confirmation_token in production-safe mode. Disabled abilities stay disabled. Backup, audit, and php-execute read-only guards are not bypassed.',
		];
	}

	/**
	 * @param mixed $schema
	 * @return array{schema: mixed, truncated: bool}
	 */
	private function bound_schema( mixed $schema, int $depth ): array {
		if ( ! is_array( $schema ) ) {
			if ( is_string( $schema ) && mb_strlen( $schema ) > self::MAX_STRING ) {
				return [
					'schema'    => mb_substr( $schema, 0, self::MAX_STRING ),
					'truncated' => true,
				];
			}
			return [ 'schema' => $schema, 'truncated' => false ];
		}

		if ( $depth >= self::MAX_DEPTH ) {
			return [
				'schema'    => [
					'truncated' => true,
					'type'      => isset( $schema['type'] ) && is_string( $schema['type'] ) ? $schema['type'] : 'object',
				],
				'truncated' => true,
			];
		}

		$truncated = false;
		$out       = [];
		$count     = 0;
		foreach ( $schema as $key => $value ) {
			if ( $count >= self::MAX_PROPERTIES ) {
				$truncated     = true;
				$out['_truncated'] = true;
				break;
			}
			++$count;

			if ( 'enum' === $key && is_array( $value ) && count( $value ) > self::MAX_ENUM ) {
				$out['enum']       = array_slice( array_values( $value ), 0, self::MAX_ENUM );
				$out['enum_truncated'] = true;
				$truncated         = true;
				continue;
			}

			$child           = $this->bound_schema( $value, is_array( $value ) ? $depth + 1 : $depth );
			$out[ $key ]     = $child['schema'];
			$truncated       = $truncated || $child['truncated'];
		}

		return [ 'schema' => $out, 'truncated' => $truncated ];
	}
}
