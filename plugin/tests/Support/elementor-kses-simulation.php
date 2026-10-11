<?php
/**
 * Stands in for the kses that Elementor applies when the current user cannot `unfiltered_html`.
 * SPDX-License-Identifier: GPL-2.0-or-later
 *
 * @package Stonewright
 */

declare( strict_types=1 );

namespace Stonewright\WpMcp\Tests\Support;

/**
 * Elementor registers `_elementor_data` and `_elementor_page_settings` with a sanitize callback that runs every string
 * of the value through kses for a user without `unfiltered_html` (a role without it, or DISALLOW_UNFILTERED_HTML).
 * The trait installs the same behaviour in the unit stubs: kses removes a script element, and a write of one of the two
 * meta keys by a user without the capability stores the filtered value.
 */
trait ElementorKsesSimulation {

	/** Removes a script element from every string of `_elementor_data` and `_elementor_page_settings` written by a user who cannot `unfiltered_html`. */
	protected function simulate_elementor_kses(): void {
		$GLOBALS['stonewright_test_filters']              = [];
		$GLOBALS['stonewright_test_wp_kses_post_callback'] = static fn( string $content ): string => (string) preg_replace( '#<script\b[^>]*>.*?</script>#is', '', $content );
		add_filter(
			'update_post_metadata',
			static function ( mixed $check, int $post_id, string $key, mixed $value ): mixed {
				if ( ! in_array( $key, [ '_elementor_data', '_elementor_page_settings' ], true ) || current_user_can( 'unfiltered_html' ) || ! isset( $GLOBALS['stonewright_test_posts'][ $post_id ] ) ) {
					return $check;
				}
				$value = wp_unslash( $value );
				$walk  = static function ( mixed $node ) use ( &$walk ): mixed {
					if ( is_string( $node ) ) {
						return wp_kses_post( $node );
					}
					return is_array( $node ) ? array_map( $walk, $node ) : $node;
				};
				if ( '_elementor_data' === $key && is_string( $value ) ) {
					$value = wp_json_encode( $walk( json_decode( $value, true ) ) );
				} else {
					$value = $walk( $value );
				}
				$GLOBALS['stonewright_test_posts'][ $post_id ]->meta[ $key ] = $value;
				return true;
			},
			10,
			4
		);
	}

	protected function stop_simulating_elementor_kses(): void {
		unset( $GLOBALS['stonewright_test_wp_kses_post_callback'] );
		$GLOBALS['stonewright_test_filters']          = [];
		$GLOBALS['stonewright_test_user_can_callback'] = null;
	}
}
