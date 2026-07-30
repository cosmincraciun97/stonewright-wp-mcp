<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\Abilities\ElementorV4;

use Stonewright\WpMcp\Abilities\AbilityKernel;
use Stonewright\WpMcp\Elementor\V4\AtomicTreeInspector;
use Stonewright\WpMcp\Elementor\V4\V4FeatureGate;
use Stonewright\WpMcp\Security\Permissions;
use Stonewright\WpMcp\Support\ElementorData;
use Stonewright\WpMcp\Support\TreeSummary;

/**
 * Reads _elementor_data for a post and returns only atomic-aware elements.
 * An element is considered atomic if its elType is 'e-element' or 'e-flexbox',
 * or its widgetType begins with 'e-' (the V4 atomic widget prefix convention).
 * Contract decision: keep output_schema aligned to the handler response shape.
 *
 * Default responseMode=summary returns a capped outline (no atomic_tree payload).
 *
 * @stonewright-status experimental
 */
final class ReadAtomicTree extends AbilityKernel {

	public function name(): string {
		return 'stonewright/elementor-v4-read-atomic-tree';
	}

	public function label(): string {
		return __( 'Read Elementor V4 atomic tree', 'stonewright' );
	}

	public function description(): string {
		return __( 'Returns a compact outline of atomic Elementor elements by default, or the full atomic_tree when responseMode=full. Also reports non-atomic elements filtered out.', 'stonewright' );
	}

	public function category(): string {
		return 'elementor';
	}

	public function meta(): array {
		return [ 'experimental' => true ];
	}

	public function input_schema(): array {
		return [
			'type'                 => 'object',
			'additionalProperties' => false,
			'properties'           => [
				'post_id'      => [ 'type' => 'integer', 'minimum' => 1 ],
				'responseMode' => [
					'type'        => 'string',
					'enum'        => [ 'summary', 'full' ],
					'default'     => 'summary',
					'description' => 'Use summary for compact atomic IDs, types, labels, and settings keys; use full only when raw atomic JSON is required.',
				],
				'max_nodes'    => [
					'type'        => 'integer',
					'minimum'     => 1,
					'maximum'     => 500,
					'default'     => 200,
					'description' => 'Maximum outline rows returned in summary mode.',
				],
			],
			'required'             => [ 'post_id' ],
		];
	}

	public function output_schema(): array {
		return [
			'type'       => 'object',
			'properties' => [
				'post_id'             => [ 'type' => 'integer' ],
				'atomic_tree'         => [ 'type' => 'array' ],
				'atomic_count'        => [ 'type' => 'integer' ],
				'non_atomic_count'    => [ 'type' => 'integer' ],
				'unknown_atomic'      => [ 'type' => 'array' ],
				'architecture'        => [ 'type' => 'string', 'enum' => [ 'empty', 'v3', 'v4', 'mixed' ] ],
				'schema_fingerprint'  => [ 'type' => 'string' ],
				'implicit_conversion' => [ 'type' => 'boolean' ],
				'response_mode'       => [ 'type' => 'string', 'enum' => [ 'summary', 'full' ] ],
				'outline'             => [ 'type' => 'array' ],
				'count'               => [ 'type' => 'integer' ],
				'returned_count'      => [ 'type' => 'integer' ],
				'truncated'           => [ 'type' => 'boolean' ],
				'tree_omitted'        => [ 'type' => 'boolean' ],
				'full_mode_hint'      => [ 'type' => 'string' ],
			],
		];
	}

	public function permission_callback( array $args ): bool|\WP_Error {
		$gate = V4FeatureGate::check();
		if ( is_wp_error( $gate ) ) {
			return $gate;
		}
		$post_id = (int) ( $args['post_id'] ?? 0 );
		return Permissions::edit_post( $post_id );
	}

	public function execute( array $args ): array|\WP_Error {
		return $this->audit(
			$args,
			function ( array $args ) {
				$post_id = (int) $args['post_id'];
				if ( ! get_post( $post_id ) ) {
					return $this->error( 'not_found', __( 'Post not found.', 'stonewright' ) );
				}

				$inspect = AtomicTreeInspector::inspect( ElementorData::read( $post_id ) );
				$mode    = (string) ( $args['responseMode'] ?? 'summary' );

				if ( 'full' === $mode ) {
					return array_merge(
						$inspect,
						[
							'post_id'       => $post_id,
							'response_mode' => 'full',
						]
					);
				}

				$max_nodes = min( 500, max( 1, (int) ( $args['max_nodes'] ?? 200 ) ) );
				$tree      = isset( $inspect['atomic_tree'] ) && is_array( $inspect['atomic_tree'] )
					? $inspect['atomic_tree']
					: [];
				$summary   = TreeSummary::outline(
					$tree,
					$max_nodes,
					static fn( array $element, array $ctx ): array => TreeSummary::default_row( $element, $ctx )
				);

				unset( $inspect['atomic_tree'] );

				return array_merge(
					$inspect,
					[
						'post_id'        => $post_id,
						'response_mode'  => 'summary',
						'count'          => $summary['count'],
						'returned_count' => $summary['returned_count'],
						'truncated'      => $summary['truncated'],
						'tree_omitted'   => true,
						'outline'        => $summary['outline'],
						'full_mode_hint' => 'Call with responseMode=full only when raw atomic Elementor JSON is required for the next edit.',
					]
				);
			}
		);
	}
}
