<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\Elementor;

/**
 * Invalidates generated Elementor state for one edited post.
 */
final class PostCacheInvalidator {

	/**
	 * @return array{ok:bool,post_id:int,method:string}
	 */
	public static function invalidate( int $post_id ): array {
		clean_post_cache( $post_id );

		if ( did_action( 'elementor/loaded' ) && class_exists( '\\Elementor\\Plugin' ) ) {
			try {
				$manager = \Elementor\Plugin::$instance->posts_css_manager ?? null;
				if ( is_object( $manager ) && method_exists( $manager, 'clear_cache_post' ) ) {
					$manager->clear_cache_post( $post_id );
					return [
						'ok'      => true,
						'post_id' => $post_id,
						'method'  => 'posts_css_manager',
					];
				}
			} catch ( \Throwable $error ) {
				unset( $error );
			}
		}

		delete_post_meta( $post_id, '_elementor_css' );
		return [
			'ok'      => true,
			'post_id' => $post_id,
			'method'  => 'meta_delete',
		];
	}
}
