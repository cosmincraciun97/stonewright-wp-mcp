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
final class MemorySave extends AbilityKernel {

	public function name(): string {
		return 'stonewright/memory-save';
	}

	public function label(): string {
		return __( 'Save memory entry', 'stonewright' );
	}

	public function description(): string {
		return __( 'Inserts or updates a typed memory entry.', 'stonewright' );
	}

	public function category(): string {
		return 'memory';
	}

	public function input_schema(): array {
		return [
			'type'                 => 'object',
			'additionalProperties' => false,
			'required'             => [ 'type', 'scope', 'key', 'name' ],
			'properties'           => [
				'type'       => [
					'type' => 'string',
					'enum' => Memory::valid_types(),
				],
				'scope'      => [ 'type' => 'string' ],
				'key'        => [ 'type' => 'string' ],
				'name'       => [ 'type' => 'string' ],
				'value'      => [],
				'confidence' => [
					'type'    => 'number',
					'default' => 1.0,
					'minimum' => 0,
					'maximum' => 1,
				],
			],
		];
	}

	public function output_schema(): array {
		return [
			'type'       => 'object',
			'properties' => [
				'ok' => [ 'type' => 'boolean' ],
				'id' => [ 'type' => 'integer' ],
			],
			'required'   => [ 'ok', 'id' ],
		];
	}

	public function permission_callback( array $args ): bool|\WP_Error {
		return Permissions::manage_options();
	}

	public function execute( array $args ): array|\WP_Error {
		return $this->audit(
			$args,
			function ( array $a ): array|\WP_Error {
				if ( ! get_option( 'stonewright_memory_enabled', true ) ) {
					return $this->error( 'memory_disabled', __( 'Memory is disabled on this site.', 'stonewright' ) );
				}

				$id = Memory::put_typed(
					(string) $a['type'],
					(string) $a['scope'],
					(string) $a['key'],
					(string) $a['name'],
					$a['value'] ?? null,
					(float) ( $a['confidence'] ?? 1.0 )
				);

				return [
					'ok' => true,
					'id' => $id,
				];
			}
		);
	}
}
