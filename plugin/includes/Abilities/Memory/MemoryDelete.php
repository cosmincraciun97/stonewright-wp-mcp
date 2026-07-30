<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\Abilities\Memory;

use Stonewright\WpMcp\Abilities\AbilityKernel;
use Stonewright\WpMcp\Memory\Memory;
use Stonewright\WpMcp\Security\Permissions;

/**
 * Contract decision: keep output_schema aligned to the handler response shape.
 *
 * @stonewright-status stable
 */
final class MemoryDelete extends AbilityKernel {

	public function name(): string {
		return 'stonewright/memory-delete';
	}

	public function label(): string {
		return __( 'Delete memory entry', 'stonewright' );
	}

	public function description(): string {
		return __( 'Permanently deletes a memory entry by its numeric id.', 'stonewright' );
	}

	public function category(): string {
		return 'memory';
	}

	public function input_schema(): array {
		return [
			'type'                 => 'object',
			'additionalProperties' => false,
			'required'             => [ 'id' ],
			'properties'           => [
				'id' => [
					'type'    => 'integer',
					'minimum' => 1,
				],
			],
		];
	}

	public function output_schema(): array {
		return [
			'type'       => 'object',
			'properties' => [
				'ok'      => [ 'type' => 'boolean' ],
				'deleted' => [ 'type' => 'boolean' ],
			],
			'required'   => [ 'ok', 'deleted' ],
		];
	}

	public function permission_callback( array $args ): bool|\WP_Error {
		return Permissions::manage_options();
	}

	public function execute( array $args ): array|\WP_Error {
		return $this->audit(
			$args,
			function ( array $a ): array {
				$result = Memory::delete_by_id( (int) $a['id'] );

				return [
					'ok'      => true,
					'deleted' => $result,
				];
			}
		);
	}
}
