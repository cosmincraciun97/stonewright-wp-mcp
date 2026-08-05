<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\Elementor\V4;

use Stonewright\WpMcp\Elementor\Write\TreeHasher;
use Stonewright\WpMcp\Support\ElementorData;

/** Pure architecture digest and routing decision; never mutates a document. */
final class ArchitectureRouter {

	/** @param array<int, array<string, mixed>> $tree @param list<string> $target_ids @return array<string, mixed> */
	public static function digest( array $tree, array $target_ids = [] ): array {
		$inspection = AtomicTreeInspector::inspect( $tree );
		$architecture = (string) ( $inspection['architecture'] ?? 'empty' );
		$targets = [];
		foreach ( $target_ids as $target_id ) {
			$target_id = sanitize_text_field( (string) $target_id );
			if ( '' === $target_id ) {
				continue;
			}
			$targets[ $target_id ] = AtomicTreeInspector::subtree_architecture( $tree, $target_id );
		}
		$roots = [];
		foreach ( $tree as $root ) {
			if ( ! is_array( $root ) ) {
				continue;
			}
			$root_id = sanitize_text_field( (string) ( $root['id'] ?? '' ) );
			if ( '' === $root_id ) {
				continue;
			}
			$root_architecture = (string) ( AtomicTreeInspector::inspect( [ $root ] )['architecture'] ?? 'empty' );
			$roots[] = [
				'element_id'   => $root_id,
				'architecture' => $root_architecture,
				'mutable_by'   => 'v3' === $root_architecture ? 'stonewright/elementor-v3-batch-mutate' : ( 'v4' === $root_architecture ? 'stonewright/elementor-v4-update-node' : 'blocked_mixed_root' ),
			];
		}
		$route = self::route( [ 'architecture' => $architecture, 'targets' => $targets ] );
		$recommended = match ( $route['route'] ) {
			'v4' => 'stonewright/elementor-v4-update-node',
			'v3', 'v3_safe_root' => 'stonewright/elementor-v3-batch-mutate',
			default => 'stonewright/elementor-document-health',
		};
		return [
			'architecture' => $architecture,
			'tree_hash'    => TreeHasher::hash( $tree ),
			'node_count'   => count( ElementorData::flatten( $tree ) ),
			'targets'      => $targets,
			'roots'        => $roots,
			'v3_safe_roots'=> AtomicTreeInspector::v3_safe_roots( $tree, 100 ),
			'schema_major' => match ( $architecture ) {
				'v3' => '3', 'v4' => '4', 'mixed' => 'mixed', default => '',
			},
			'recommended_ability' => $recommended,
			'route_reason' => $route['reason'],
		];
	}

	/** @param array<string, mixed> $digest @return array{route:string,reason:string} */
	public static function route( array $digest, string $operation_family = 'update' ): array {
		$architecture = (string) ( $digest['architecture'] ?? 'empty' );
		if ( 'v4' === $architecture ) {
			return [ 'route' => 'v4', 'reason' => 'document_is_v4_atomic' ];
		}
		if ( 'mixed' === $architecture ) {
			$targets = is_array( $digest['targets'] ?? null ) ? $digest['targets'] : [];
			foreach ( $targets as $target ) {
				if ( in_array( $target, [ 'v4', 'mixed' ], true ) ) {
					return [ 'route' => 'blocked_mixed', 'reason' => 'target_contains_atomic_nodes' ];
				}
			}
			return [ 'route' => 'v3_safe_root', 'reason' => 'target_is_v3_only' ];
		}
		return [ 'route' => 'v3', 'reason' => '' !== $operation_family ? sanitize_key( $operation_family ) : 'v3_document' ];
	}
}
