<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\Abilities\System;

use Stonewright\WpMcp\Abilities\AbilityKernel;
use Stonewright\WpMcp\Core\AbilityRegistry;
use Stonewright\WpMcp\Security\Permissions;

/**
 * Contract decision: keep output_schema aligned to the handler response shape.
 *
 * @stonewright-status stable
 */
final class AbilitiesList extends AbilityKernel {

	public function name(): string {
		return 'stonewright/system-abilities-list';
	}

	public function label(): string {
		return __( 'List Stonewright abilities', 'stonewright' );
	}

	public function description(): string {
		return __( 'Returns all registered Stonewright abilities with category and enabled status.', 'stonewright' );
	}

	public function category(): string {
		return 'system';
	}

	public function input_schema(): array {
		return [
			'type'                 => 'object',
			'additionalProperties' => false,
			'properties'           => [],
		];
	}

	public function output_schema(): array {
		return [
			'type'       => 'object',
			'properties' => [
				'abilities' => [
					'type'  => 'array',
					'items' => [ 'type' => 'object' ],
				],
				'count'     => [ 'type' => 'integer' ],
			],
			'required'   => [ 'abilities', 'count' ],
		];
	}

	public function permission_callback( array $args ): bool|\WP_Error {
		return Permissions::read();
	}

	public function execute( array $args ): array {
		$rows = AbilityRegistry::enabled_abilities();

		return [
			'abilities' => $rows,
			'count'     => count( $rows ),
		];
	}
}
