<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\Support;

/**
 * Sanitize block markup / template content before FSE and pattern writes.
 */
final class BlockMarkup {

	/**
	 * @return string|\WP_Error
	 */
	public static function sanitize( string $content ) {
		$content = wp_kses_post( $content );
		$stripped = preg_replace( '#<style\b[^>]*>.*?</style>#is', '', $content );
		if ( is_string( $stripped ) ) {
			$content = $stripped;
		}
		$stripped = preg_replace( '#<style\b[^>]*/?>#is', '', $content );
		if ( is_string( $stripped ) ) {
			$content = $stripped;
		}
		if ( '' === trim( $content ) ) {
			return new \WP_Error(
				'stonewright_empty_content',
				__( 'Content is empty after sanitization.', 'stonewright' ),
				[ 'status' => 400 ]
			);
		}
		return $content;
	}
}
