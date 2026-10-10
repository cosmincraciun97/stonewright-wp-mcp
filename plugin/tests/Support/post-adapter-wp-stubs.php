<?php
/**
 * WordPress functions the post adapter calls and the shared test bootstrap does not define.
 * SPDX-License-Identifier: GPL-2.0-or-later
 *
 * @package Stonewright
 */

declare( strict_types=1 );

if ( ! function_exists( 'get_object_taxonomies' ) ) {
	/**
	 * Taxonomies of a post type, from $GLOBALS['stonewright_test_object_taxonomies'][ post type ].
	 *
	 * @return list<string>
	 */
	function get_object_taxonomies( $object_type, string $output = 'names' ): array {
		$type = is_object( $object_type ) ? (string) ( $object_type->post_type ?? '' ) : (string) $object_type;
		return array_values( (array) ( $GLOBALS['stonewright_test_object_taxonomies'][ $type ] ?? [] ) );
	}
}

if ( ! function_exists( 'term_exists' ) ) {
	/**
	 * A term id is known when a registered term of the taxonomy has it.
	 *
	 * @return array{term_id:int,term_taxonomy_id:int}|null
	 */
	function term_exists( $term, string $taxonomy = '', int $parent_term = 0 ) {
		foreach ( $GLOBALS['stonewright_test_terms'][ $taxonomy ] ?? [] as $known ) {
			if ( (int) ( $known->term_id ?? 0 ) === (int) $term ) {
				return [ 'term_id' => (int) $term, 'term_taxonomy_id' => (int) $term ];
			}
		}
		return null;
	}
}

if ( ! function_exists( 'wp_trash_post' ) ) {
	/**
	 * Moves a fake post to the trash, and remembers the status it had.
	 */
	function wp_trash_post( int $post_id = 0 ): mixed {
		$post = $GLOBALS['stonewright_test_posts'][ $post_id ] ?? null;
		if ( null === $post ) {
			return false;
		}
		$GLOBALS['stonewright_test_trashed_posts'][] = $post_id;
		$post->post_status                           = 'trash';
		return $post;
	}
}
