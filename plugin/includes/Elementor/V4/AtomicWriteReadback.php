<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\Elementor\V4;

use Stonewright\WpMcp\Security\Backup;
use Stonewright\WpMcp\Support\ElementorData;

/**
 * Mandatory nested readback after a V4 document write that Stonewright performs itself.
 *
 * The stored tree is compared recursively with the tree that was written, every node and child
 * included; a dropped child, a changed node or an extra node is an error, never a success. On a
 * mismatch the pre-write snapshot is restored and the rollback state is reported.
 */
final class AtomicWriteReadback {

	/**
	 * @param array<int, mixed> $expected    The tree handed to the writer.
	 * @param string            $snapshot_id Snapshot taken before the write; empty when none exists.
	 * @return array{verified: bool, method: string, checked: int, context: string}|\WP_Error
	 */
	public static function verify_tree( int $post_id, array $expected, string $snapshot_id, string $context ): array|\WP_Error {
		$stored = ElementorData::read( $post_id );
		$report = AtomicReadbackVerifier::verify( $expected, $stored, [ 'exact_children' => true, 'root_parent' => '' ] );
		$wanted = self::root_ids( $expected );
		foreach ( array_diff( self::root_ids( $stored ), $wanted ) as $extra ) {
			$report['ok']         = false;
			$report['problems'][] = [ 'code' => 'unexpected_child', 'id' => (string) $extra, 'path' => '/' . $extra ];
			++$report['problems_count'];
		}
		if ( $report['ok'] ) {
			return [ 'verified' => true, 'method' => 'document_tree', 'checked' => $report['checked'], 'context' => $context ];
		}
		$rolled_back = '' !== $snapshot_id && Backup::restore( $post_id, $snapshot_id );
		$error       = AtomicReadbackVerifier::error( $report, $context );
		$data        = $error->get_error_data();
		$data        = is_array( $data ) ? $data : [];
		$data['rollback_status'] = $rolled_back ? 'succeeded' : 'failed';
		$data['snapshot_id']     = $snapshot_id;
		return new \WP_Error( $error->get_error_code(), $error->get_error_message(), $data );
	}

	/**
	 * @param array<int, mixed> $tree
	 * @return list<string>
	 */
	private static function root_ids( array $tree ): array {
		$ids = [];
		foreach ( $tree as $node ) {
			if ( is_array( $node ) && isset( $node['id'] ) && is_scalar( $node['id'] ) && '' !== (string) $node['id'] ) {
				$ids[] = (string) $node['id'];
			}
		}
		return $ids;
	}
}
