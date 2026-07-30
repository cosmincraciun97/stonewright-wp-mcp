<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\Elementor\Write;

use Stonewright\WpMcp\Elementor\Schema\SettingsValidator;
use Stonewright\WpMcp\Elementor\V4\AtomicTreeInspector;
use Stonewright\WpMcp\Support\ElementorData;

/**
 * Pure in-memory compiler for the V3 operations reused by composite flows.
 */
final class V3MutationCompiler {

	/**
	 * @param array<int, array<string, mixed>> $tree
	 * @param array<int, array<string, mixed>> $operations
	 * @return array<string, mixed>|\WP_Error
	 */
	public function compile(
		array $tree,
		array $operations,
		bool $require_evidence = false,
		bool $stop_on_error = true
	): array|\WP_Error {
		unset( $require_evidence, $stop_on_error );

		$items = [];
		$refs  = [];
		foreach ( $operations as $operation ) {
			if ( 'add_widget' !== (string) ( $operation['action'] ?? '' ) ) {
				return self::error(
					'unsupported_operation',
					__( 'The shared V3 compiler currently accepts add_widget operations.', 'stonewright' ),
					[ 'action' => (string) ( $operation['action'] ?? '' ) ]
				);
			}

			$result = self::add_widget( $tree, $operation );
			if ( $result instanceof \WP_Error ) {
				return $result;
			}
			$tree = $result['tree'];
			if ( '' !== $result['op_id'] ) {
				$refs[ $result['op_id'] ] = $result['element_id'];
			}
			$items[] = [
				'action'     => 'add_widget',
				'element_id' => $result['element_id'],
				'ok'         => true,
			];
		}

		return [
			'tree'         => $tree,
			'items'        => $items,
			'refs'         => $refs,
			'applied'      => count( $items ),
			'failed'       => 0,
			'targeted_ids' => array_values(
				array_unique(
					array_filter(
						array_map(
							static fn( array $operation ): string => trim( (string) ( $operation['parent_id'] ?? '' ) ),
							$operations
						)
					)
				)
			),
		];
	}

	/**
	 * @param array<int, array<string, mixed>> $tree
	 * @param array<string, mixed>             $operation
	 * @return array{tree:array<int,array<string,mixed>>,element_id:string,op_id:string}|\WP_Error
	 */
	private static function add_widget( array $tree, array $operation ): array|\WP_Error {
		$widget_type = sanitize_key( (string) ( $operation['widget_type'] ?? '' ) );
		if ( '' === $widget_type ) {
			return self::error( 'missing_widget_type', __( 'add_widget requires widget_type.', 'stonewright' ) );
		}
		if ( str_starts_with( $widget_type, 'e-' ) ) {
			return self::error(
				'atomic_widget_in_v3_batch',
				__( 'Atomic e-* widgets cannot be added to an Elementor V3 tree.', 'stonewright' ),
				[ 'status' => 409, 'widget_type' => $widget_type ]
			);
		}

		$parent_id = trim( (string) ( $operation['parent_id'] ?? '' ) );
		$architecture = (string) ( AtomicTreeInspector::inspect( $tree )['architecture'] ?? 'empty' );
		if ( '' === $parent_id && 'mixed' === $architecture ) {
			return self::error(
				'mixed_root_add_blocked',
				__( 'Mixed Elementor documents require an existing V3-only parent.', 'stonewright' ),
				[ 'status' => 409, 'architecture' => $architecture ]
			);
		}

		$parent_path = [];
		if ( '' !== $parent_id ) {
			$parent_path = ElementorData::find_path( $tree, $parent_id );
			if ( null === $parent_path ) {
				return self::error(
					'parent_not_found',
					__( 'Parent element not found.', 'stonewright' ),
					[ 'parent_id' => $parent_id ]
				);
			}
			$subtree = AtomicTreeInspector::subtree_architecture( $tree, $parent_id );
			if ( in_array( $subtree, [ 'v4', 'mixed' ], true ) ) {
				return self::error(
					'v3_architecture_mismatch',
					__( 'The requested parent is or contains V4 Atomic nodes.', 'stonewright' ),
					[ 'status' => 409, 'parent_id' => $parent_id, 'architecture' => $subtree ]
				);
			}
		}

		$settings  = is_array( $operation['settings'] ?? null ) ? $operation['settings'] : [];
		$validated = SettingsValidator::validate( $widget_type, $settings );
		if ( $validated instanceof \WP_Error ) {
			return $validated;
		}

		$element_id = isset( $operation['element_id'] )
			? sanitize_key( (string) $operation['element_id'] )
			: ElementorData::generate_id();
		$element = [
			'id'         => $element_id,
			'elType'     => 'widget',
			'widgetType' => $widget_type,
			'settings'   => $validated['settings'],
			'elements'   => [],
		];
		$position = isset( $operation['position'] ) ? (int) $operation['position'] : PHP_INT_MAX;

		return [
			'tree'       => ElementorData::insert( $tree, $parent_path, $position, $element ),
			'element_id' => $element_id,
			'op_id'      => sanitize_key( (string) ( $operation['op_id'] ?? '' ) ),
		];
	}

	/** @param array<string, mixed> $data */
	private static function error( string $code, string $message, array $data = [] ): \WP_Error {
		return new \WP_Error( 'stonewright_' . $code, $message, $data );
	}
}
