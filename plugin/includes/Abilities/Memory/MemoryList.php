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
final class MemoryList extends AbilityKernel {

	public function name(): string {
		return 'stonewright/memory-list';
	}

	public function label(): string {
		return __( 'List memory entries', 'stonewright' );
	}

	public function description(): string {
		return __( 'Returns memory entries, optionally filtered by type.', 'stonewright' );
	}

	public function category(): string {
		return 'memory';
	}

	public function input_schema(): array {
		return [
			'type'                 => 'object',
			'additionalProperties' => false,
			'properties'           => [
				'type'   => [
					'type' => 'string',
					'enum' => Memory::valid_types(),
				],
				'limit'  => [
					'type'    => 'integer',
					'default' => 100,
					'maximum' => 500,
				],
				'offset' => [
					'type'    => 'integer',
					'default' => 0,
				],
			],
		];
	}

	public function output_schema(): array {
		return [
			'type'       => 'object',
			'properties' => [
				'entries' => [
					'type'  => 'array',
					'items' => [ 'type' => 'object' ],
				],
				'count'   => [ 'type' => 'integer' ],
			],
			'required'   => [ 'entries', 'count' ],
		];
	}

	public function permission_callback( array $args ): bool|\WP_Error {
		return Permissions::read();
	}

	public function execute( array $args ): array|\WP_Error {
		if ( ! get_option( 'stonewright_memory_enabled', true ) ) {
			return $this->error( 'memory_disabled', __( 'Memory is disabled on this site.', 'stonewright' ) );
		}

		$limit  = (int) ( $args['limit'] ?? 100 );
		$offset = (int) ( $args['offset'] ?? 0 );

		if ( isset( $args['type'] ) ) {
			$rows = Memory::list_by_type( (string) $args['type'], $limit, $offset );
		} else {
			$rows = Memory::list_all( $limit, $offset );
		}

		return [
			'entries' => $rows,
			'count'   => count( $rows ),
		];
	}
}
