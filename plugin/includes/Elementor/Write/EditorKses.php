<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\Elementor\Write;

/**
 * The filtering Elementor applies to what a user without `unfiltered_html` saves.
 *
 * Its editor runs every string of the saved document through kses for such a user (a role without the capability, or a
 * site with DISALLOW_UNFILTERED_HTML), and its sanitize callbacks of `_elementor_data` and `_elementor_page_settings`
 * do it again when the meta is stored. A writer that stored the value as given would read back something else, so it
 * filters first, the way the editor does, and then stores, reads back and verifies what is really stored.
 */
final class EditorKses {

	/** Whether the current user's saves are filtered. */
	public static function applies(): bool {
		return function_exists( 'current_user_can' ) && ! current_user_can( 'unfiltered_html' );
	}

	/**
	 * The value as Elementor stores it for the current user: every string run through kses when the user lacks the
	 * capability, unchanged otherwise.
	 *
	 * @param array<mixed> $value A document tree or a settings array.
	 * @return array<mixed>
	 */
	public static function filter( array $value ): array {
		if ( ! self::applies() ) {
			return $value;
		}
		$filtered = self::walk( $value );
		return is_array( $filtered ) ? $filtered : $value;
	}

	private static function walk( mixed $value ): mixed {
		if ( is_string( $value ) ) {
			return wp_kses_post( $value );
		}
		if ( is_array( $value ) ) {
			foreach ( $value as $key => $item ) {
				$value[ $key ] = self::walk( $item );
			}
		}
		return $value;
	}
}
