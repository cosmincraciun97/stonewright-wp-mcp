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
final class MemoryGet extends AbilityKernel {

	public function name(): string {
		return 'stonewright/memory-get';
	}

	public function label(): string {
		return __( 'Get memory entry', 'stonewright' );
	}

	public function description(): string {
		return __( 'Returns a single memory entry by its numeric id.', 'stonewright' );
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
				'entry' => [
					'oneOf' => [
						[ 'type' => 'object' ],
						[ 'type' => 'null' ],
					],
				],
			],
			'required'   => [ 'entry' ],
		];
	}

	public function permission_callback( array $args ): bool|\WP_Error {
		return Permissions::read();
	}

	public function execute( array $args ): array|\WP_Error {
		if ( ! get_option( 'stonewright_memory_enabled', true ) ) {
			return $this->error( 'memory_disabled', __( 'Memory is disabled on this site.', 'stonewright' ) );
		}

		return [
			'entry' => Memory::get_by_id( (int) $args['id'] ),
		];
	}
}
