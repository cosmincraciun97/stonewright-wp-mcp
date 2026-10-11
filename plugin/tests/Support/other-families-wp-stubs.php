<?php
/**
 * WordPress and WooCommerce functions the user, comment, media, catalog and site adapters call and the
 * shared test bootstrap does not define.
 * SPDX-License-Identifier: GPL-2.0-or-later
 *
 * @package Stonewright
 */

declare( strict_types=1 );

if ( ! function_exists( 'wp_trash_comment' ) ) {
	/** Moves a fake comment to the trash. */
	function wp_trash_comment( $comment_id ): bool {
		$id = (int) $comment_id;
		if ( ! isset( $GLOBALS['stonewright_test_comments'][ $id ] ) ) {
			return false;
		}
		$GLOBALS['stonewright_test_comments'][ $id ]['comment_approved'] = 'trash';
		return true;
	}
}

if ( ! function_exists( 'wp_delete_attachment' ) ) {
	/** Removes a fake attachment and remembers the call. */
	function wp_delete_attachment( int $post_id, bool $force_delete = false ): mixed {
		$post = $GLOBALS['stonewright_test_posts'][ $post_id ] ?? null;
		if ( null === $post ) {
			return false;
		}
		$GLOBALS['stonewright_test_deleted_attachments'][] = [ $post_id, $force_delete ];
		unset( $GLOBALS['stonewright_test_posts'][ $post_id ] );
		return $post;
	}
}

if ( ! function_exists( 'count_user_posts' ) ) {
	/** Posts a fake user has written, from $GLOBALS['stonewright_test_user_post_counts']. */
	function count_user_posts( int $userid, $post_type = 'post', bool $public_only = false ): string {
		return (string) (int) ( $GLOBALS['stonewright_test_user_post_counts'][ $userid ] ?? 0 );
	}
}

if ( ! function_exists( 'get_term' ) ) {
	/** A fake term by id, from the terms the shared bootstrap keeps per taxonomy. */
	function get_term( $term, string $taxonomy = '', string $output = 'OBJECT', string $filter = 'raw' ) {
		foreach ( $GLOBALS['stonewright_test_terms'][ $taxonomy ] ?? [] as $known ) {
			if ( (int) ( $known->term_id ?? 0 ) === (int) $term ) {
				$known->taxonomy    = $taxonomy;
				$known->description = (string) ( $known->description ?? '' );
				$known->parent      = (int) ( $known->parent ?? 0 );
				$known->count       = (int) ( $known->count ?? 0 );
				return $known;
			}
		}
		return null;
	}
}

if ( ! function_exists( 'wp_update_term' ) ) {
	/** Updates a fake term. */
	function wp_update_term( int $term_id, string $taxonomy, array $args = [] ) {
		foreach ( $GLOBALS['stonewright_test_terms'][ $taxonomy ] ?? [] as $slug => $known ) {
			if ( (int) $known->term_id === $term_id ) {
				foreach ( $args as $key => $value ) {
					$known->{$key} = $value;
				}
				return [ 'term_id' => $term_id, 'term_taxonomy_id' => $term_id ];
			}
		}
		return new \WP_Error( 'invalid_term', 'Empty Term.' );
	}
}

if ( ! function_exists( 'wp_delete_term' ) ) {
	/** Removes a fake term. */
	function wp_delete_term( int $term_id, string $taxonomy, array $args = [] ) {
		foreach ( $GLOBALS['stonewright_test_terms'][ $taxonomy ] ?? [] as $slug => $known ) {
			if ( (int) $known->term_id === $term_id ) {
				unset( $GLOBALS['stonewright_test_terms'][ $taxonomy ][ $slug ] );
				return true;
			}
		}
		return false;
	}
}

if ( ! function_exists( 'wc_create_attribute' ) ) {
	/** A fake global attribute, kept in $GLOBALS['stonewright_test_wc_attributes']. */
	function wc_create_attribute( array $args ) {
		$id = (int) ( $GLOBALS['stonewright_test_wc_next_attribute'] ?? 40 );
		$GLOBALS['stonewright_test_wc_next_attribute'] = $id + 1;
		$GLOBALS['stonewright_test_wc_attributes'][ $id ] = (object) [
			'attribute_id'      => $id,
			'attribute_label'   => (string) ( $args['name'] ?? '' ),
			'attribute_name'    => (string) ( $args['slug'] ?? sanitize_title( (string) ( $args['name'] ?? '' ) ) ),
			'attribute_type'    => (string) ( $args['type'] ?? 'select' ),
			'attribute_orderby' => (string) ( $args['order_by'] ?? 'menu_order' ),
			'attribute_public'  => ! empty( $args['has_archives'] ) ? 1 : 0,
		];
		return $id;
	}
}

if ( ! function_exists( 'wc_update_attribute' ) ) {
	function wc_update_attribute( int $attribute_id, array $args ) {
		$row = $GLOBALS['stonewright_test_wc_attributes'][ $attribute_id ] ?? null;
		if ( null === $row ) {
			return new \WP_Error( 'invalid_product_attribute', 'Unknown attribute.' );
		}
		$map = [ 'name' => 'attribute_label', 'slug' => 'attribute_name', 'type' => 'attribute_type', 'order_by' => 'attribute_orderby' ];
		foreach ( $map as $key => $column ) {
			if ( array_key_exists( $key, $args ) ) {
				$row->{$column} = (string) $args[ $key ];
			}
		}
		if ( array_key_exists( 'has_archives', $args ) ) {
			$row->attribute_public = $args['has_archives'] ? 1 : 0;
		}
		return $attribute_id;
	}
}

if ( ! function_exists( 'wc_delete_attribute' ) ) {
	function wc_delete_attribute( int $attribute_id ): bool {
		if ( ! isset( $GLOBALS['stonewright_test_wc_attributes'][ $attribute_id ] ) ) {
			return false;
		}
		unset( $GLOBALS['stonewright_test_wc_attributes'][ $attribute_id ] );
		return true;
	}
}
