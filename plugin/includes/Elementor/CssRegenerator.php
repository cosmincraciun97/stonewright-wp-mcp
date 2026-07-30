<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\Elementor;

/**
 * Targeted Elementor CSS regeneration for one post (not global clear_cache).
 */
final class CssRegenerator {

	/**
	 * @return array{ok:bool,post_id:int,method:string,detail:string}
	 */
	public static function regenerate_post( int $post_id ): array {
		if ( $post_id <= 0 ) {
			return [
				'ok'      => false,
				'post_id' => $post_id,
				'method'  => 'none',
				'detail'  => 'invalid_post_id',
			];
		}

		if ( ! class_exists( '\\Elementor\\Plugin' ) ) {
			// Test / non-Elementor runtime: mark as skipped, not failure.
			return [
				'ok'      => true,
				'post_id' => $post_id,
				'method'  => 'skipped',
				'detail'  => 'elementor_not_loaded',
			];
		}

		try {
			$plugin = \Elementor\Plugin::$instance;
			if ( isset( $plugin->files_manager ) && is_object( $plugin->files_manager ) ) {
				$fm = $plugin->files_manager;
				// Post-scoped invalidation only. A single document write must never
				// clear every Elementor CSS file on the site.
				if ( method_exists( $fm, 'on_delete_post' ) ) {
					$fm->on_delete_post( $post_id );
				}
				delete_post_meta( $post_id, '_elementor_css' );
				if ( method_exists( $fm, 'get_css_file' ) ) {
					// @phpstan-ignore-next-line Elementor dynamic API
					$css_file = $fm->get_css_file( $post_id );
					if ( is_object( $css_file ) && method_exists( $css_file, 'update' ) ) {
						$css_file->update();
						return [
							'ok'      => true,
							'post_id' => $post_id,
							'method'  => 'css_file_update',
							'detail'  => 'regenerated',
						];
					}
				}
			}
		} catch ( \Throwable $e ) {
			return [
				'ok'      => false,
				'post_id' => $post_id,
				'method'  => 'exception',
				'detail'  => $e->getMessage(),
			];
		}

		delete_post_meta( $post_id, '_elementor_css' );
		return [
			'ok'      => true,
			'post_id' => $post_id,
			'method'  => 'meta_delete',
			'detail'  => 'deleted_elementor_css_meta',
		];
	}
}
