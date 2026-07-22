<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\Abilities\ElementorV4;

use Stonewright\WpMcp\Abilities\AbilityKernel;
use Stonewright\WpMcp\Elementor\V4\AtomicTreeInspector;
use Stonewright\WpMcp\Elementor\V4\V4FeatureGate;
use Stonewright\WpMcp\Security\Permissions;
use Stonewright\WpMcp\Support\ElementorData;

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
				$count     = count( ElementorData::flatten( $tree ) );
				$outline   = self::outline( $tree, $max_nodes );

				unset( $inspect['atomic_tree'] );

				return array_merge(
					$inspect,
					[
						'post_id'        => $post_id,
						'response_mode'  => 'summary',
						'count'          => $count,
						'returned_count' => count( $outline ),
						'truncated'      => $count > count( $outline ),
						'tree_omitted'   => true,
						'outline'        => $outline,
						'full_mode_hint' => 'Call with responseMode=full only when raw atomic Elementor JSON is required for the next edit.',
					]
				);
			}
		);
	}

	/**
	 * @param array<int, array<string, mixed>> $tree
	 * @return list<array<string, mixed>>
	 */
	private static function outline( array $tree, int $max_elements ): array {
		$out = [];
		self::walk_outline( $tree, [], null, $out, $max_elements );
		return $out;
	}

	/**
	 * @param array<int, array<string, mixed>> $elements
	 * @param list<int>                       $path
	 * @param list<array<string, mixed>>      $out
	 */
	private static function walk_outline( array $elements, array $path, ?string $parent_id, array &$out, int $max_elements ): void {
		foreach ( $elements as $index => $element ) {
			if ( count( $out ) >= $max_elements ) {
				return;
			}
			if ( ! is_array( $element ) ) {
				continue;
			}

			$current_path = array_merge( $path, [ (int) $index ] );
			$id           = (string) ( $element['id'] ?? '' );
			$settings     = isset( $element['settings'] ) && is_array( $element['settings'] ) ? $element['settings'] : [];
			$children     = isset( $element['elements'] ) && is_array( $element['elements'] ) ? $element['elements'] : [];

			$out[] = [
				'id'            => $id,
				'parent_id'     => $parent_id,
				'path'          => implode( '.', array_map( 'strval', $current_path ) ),
				'depth'         => count( $current_path ) - 1,
				'elType'        => (string) ( $element['elType'] ?? '' ),
				'widgetType'    => (string) ( $element['widgetType'] ?? '' ),
				'label'         => self::label_from_settings( $settings ),
				'settings_keys' => array_values( array_slice( array_map( 'strval', array_keys( $settings ) ), 0, 30 ) ),
				'child_count'   => count( $children ),
			];

			if ( [] !== $children ) {
				self::walk_outline( $children, $current_path, '' !== $id ? $id : null, $out, $max_elements );
			}
		}
	}

	/**
	 * @param array<string, mixed> $settings
	 */
	private static function label_from_settings( array $settings ): string {
		foreach ( [ '_title', 'title', 'header_title', 'text', 'editor' ] as $key ) {
			if ( ! isset( $settings[ $key ] ) || ! is_scalar( $settings[ $key ] ) ) {
				continue;
			}
			$raw_label = (string) $settings[ $key ];
			$label     = function_exists( 'wp_strip_all_tags' )
				? trim( wp_strip_all_tags( $raw_label ) )
				: trim( strip_tags( $raw_label ) );
			if ( '' === $label ) {
				continue;
			}
			return strlen( $label ) > 80 ? substr( $label, 0, 77 ) . '...' : $label;
		}
		return '';
	}
}
