<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\Elementor;

/**
 * Invalidates generated Elementor state for one edited post.
 */
final class PostCacheInvalidator {

	/**
	 * @return array{
	 *   ok:bool,
	 *   post_id:int,
	 *   method:string,
	 *   element_cache:array{key:string,existed:bool,deleted:bool},
	 *   css_cache:array{method:string,cleared:bool},
	 *   atomic_styles_notified:bool
	 * }
	 */
	public static function invalidate( int $post_id ): array {
		$cache_key = self::element_cache_meta_key();
		$cache_existed = '' !== get_post_meta( $post_id, $cache_key, true );
		$cache_result  = delete_post_meta( $post_id, $cache_key );
		$cache_deleted = ! $cache_existed || $cache_result;
		clean_post_cache( $post_id );

		$css_method  = 'meta_delete';
		$css_cleared = false;
		if ( did_action( 'elementor/loaded' ) && class_exists( '\\Elementor\\Plugin' ) ) {
			try {
				$manager = \Elementor\Plugin::$instance->posts_css_manager ?? null;
				if ( is_object( $manager ) && method_exists( $manager, 'clear_cache_post' ) ) {
					$manager->clear_cache_post( $post_id );
					$css_method  = 'posts_css_manager';
					$css_cleared = true;
				}
			} catch ( \Throwable $error ) {
				unset( $error );
			}
		}

		if ( ! $css_cleared ) {
			$css_existed = '' !== get_post_meta( $post_id, '_elementor_css', true );
			$css_result  = delete_post_meta( $post_id, '_elementor_css' );
			$css_cleared = ! $css_existed || $css_result;
		}

		$atomic_styles_notified = false;
		if ( did_action( 'elementor/loaded' ) ) {
			// phpcs:ignore WordPress.NamingConventions.ValidHookName.UseUnderscores -- Official Elementor hook.
			do_action( 'elementor/atomic-widgets/styles/clear', [ 'global', $post_id ] );
			$atomic_styles_notified = true;
		}

		return [
			'ok'        => $cache_deleted && $css_cleared,
			'post_id'   => $post_id,
			'method'    => $css_method,
			'element_cache' => [
				'key'     => $cache_key,
				'existed' => $cache_existed,
				'deleted' => $cache_deleted,
			],
			'css_cache' => [
				'method'  => $css_method,
				'cleared' => $css_cleared,
			],
			'atomic_styles_notified' => $atomic_styles_notified,
		];
	}

	private static function element_cache_meta_key(): string {
		$document_class = '\\Elementor\\Core\\Base\\Document';
		if ( class_exists( $document_class ) && defined( $document_class . '::CACHE_META_KEY' ) ) {
			$key = constant( $document_class . '::CACHE_META_KEY' );
			if ( is_string( $key ) && '' !== $key ) {
				return $key;
			}
		}

		return '_elementor_element_cache';
	}
}
