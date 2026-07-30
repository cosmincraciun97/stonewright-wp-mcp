<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\Abilities\ElementorV4;

use Stonewright\WpMcp\Abilities\AbilityKernel;
use Stonewright\WpMcp\Elementor\V4\AtomicWidgetMap;
use Stonewright\WpMcp\Security\Permissions;

/**
 * Lists every DesignSpec node type the V4 atomic renderer knows how to draw.
 *
 * Pure introspection — reads {@see AtomicWidgetMap::known_node_types()} and
 * pairs each type with its target atomic widget identifier and the container
 * flag. Useful so the model can discover what's available without
 * cross-referencing the renderer source.
 *
 * @stonewright-status stable
 */
final class ListAtomicNodeTypes extends AbilityKernel {

	public function name(): string {
		return 'stonewright/elementor-v4-list-atomic-node-types';
	}

	public function label(): string {
		return __( 'List Elementor V4 atomic node types', 'stonewright' );
	}

	public function description(): string {
		return __( 'Returns every DesignSpec node type the V4 atomic renderer can build, paired with its target atomic widget identifier.', 'stonewright' );
	}

	public function category(): string {
		return 'elementor';
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
				'node_types' => [
					'type'  => 'array',
					'items' => [
						'type'       => 'object',
						'properties' => [
							'name'         => [ 'type' => 'string' ],
							'widget'       => [ 'type' => 'string' ],
							'is_container' => [ 'type' => 'boolean' ],
						],
						'required'   => [ 'name', 'widget', 'is_container' ],
					],
				],
			],
			'required'   => [ 'node_types' ],
		];
	}

	public function permission_callback( array $args ): bool|\WP_Error {
		// Read-only introspection — gate at the manage_options level so we don't
		// leak the atomic widget surface to subscriber-level accounts.
		return Permissions::manage_options();
	}

	public function execute( array $args ): array|\WP_Error {
		return $this->audit(
			$args,
			function ( array $args ): array {
				$rows = [];
				foreach ( AtomicWidgetMap::known_node_types() as $type ) {
					$rows[] = [
						'name'         => $type,
						'widget'       => (string) AtomicWidgetMap::widget_type( $type ),
						'is_container' => AtomicWidgetMap::is_container( $type ),
					];
				}
				return [ 'node_types' => $rows ];
			}
		);
	}
}
