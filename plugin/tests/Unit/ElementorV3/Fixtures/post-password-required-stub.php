<?php
/**
 * Stub of post_password_required() for the post-write verification tests.
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare( strict_types=1 );

if ( ! function_exists( 'post_password_required' ) ) {
	function post_password_required( int|object|null $post = null ): bool {
		$id = is_object( $post ) ? (int) $post->ID : (int) $post;
		return '' !== (string) ( $GLOBALS['stonewright_test_posts'][ $id ]->post_password ?? '' );
	}
}
