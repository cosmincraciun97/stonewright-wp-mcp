<?php
/**
 * Rollback handler for posts and everything that is stored as a post.
 * SPDX-License-Identifier: GPL-2.0-or-later
 *
 * @package Stonewright
 */

declare( strict_types=1 );

namespace Stonewright\WpMcp\Security\Rollback;

use Stonewright\WpMcp\Security\Adapters\PostAdapter;

/**
 * Pages, posts, Elementor documents, Gutenberg content, FSE templates, global styles and patterns. The live image is
 * the post as PostAdapter images it, over the meta keys the change covered. A restore writes an image back with
 * PostAdapter::restore(); the undo of a creation moves the post to the trash, and a redo of that writes the post back.
 */
final class PostRollbackFamily implements RollbackFamilyHandler {

	public function families(): array {
		return [ 'post', 'elementor', 'gutenberg', 'fse', 'global_styles' ];
	}

	public function live_image( array $row ): string|array|\WP_Error|null {
		$stored = FamilySupport::scope_image( $row ) ?? [];
		$keys   = array_merge( array_keys( (array) ( $stored['meta'] ?? [] ) ), (array) ( $stored['meta_absent'] ?? [] ) );
		return PostAdapter::image( (int) $row['resource_id'], array_values( array_filter( array_map( 'strval', $keys ), 'is_string' ) ) );
	}

	public function restore( array $row, string|array|null $image, array $options ): array {
		$post_id = (int) $row['resource_id'];
		if ( null === $image ) {
			$result = PostAdapter::trash_created( $post_id );
			if ( $result instanceof \WP_Error ) {
				return FamilySupport::answer( $result );
			}
			if ( empty( $result['ok'] ) ) {
				return [ 'status' => 'failed', 'detail' => 'not_trashed' ];
			}
			return 'already_trashed' === $result['action'] ? [ 'status' => 'noop', 'detail' => 'already_trashed' ] : [ 'status' => 'succeeded', 'detail' => '' ];
		}
		if ( ! is_array( $image ) ) {
			return [ 'status' => 'failed', 'detail' => 'image_invalid' ];
		}
		return FamilySupport::answer( PostAdapter::restore( $post_id, $image ) );
	}

	public function describe( array $row, string|array|null $image ): string {
		return null === $image
			? __( 'Moves the post to the trash. It does not delete it.', 'stonewright' )
			: __( 'Writes the post back as it was: its fields, terms, featured image and the meta Stonewright tracks.', 'stonewright' );
	}

	public function records_own_row( array $row ): bool {
		return false;
	}

	public function requires_human( array $row ): bool {
		return false;
	}
}
