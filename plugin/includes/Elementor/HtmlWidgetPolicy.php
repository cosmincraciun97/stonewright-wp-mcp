<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\Elementor;

/**
 * Site-level hard block for Elementor HTML widgets.
 *
 * When stonewright_allow_html_widgets is false (default), per-call flags such as
 * allow_html_widget=true are ignored.
 */
final class HtmlWidgetPolicy {

	public const OPTION = 'stonewright_allow_html_widgets';

	/**
	 * Whether HTML widgets may be planned or written.
	 *
	 * @param array<string, mixed> $args Ability or operation args (flag ignored when site option off).
	 * @return true|\WP_Error
	 */
	public static function allowed( array $args = [] ): bool|\WP_Error {
		if ( ! self::site_allows() ) {
			return new \WP_Error(
				'stonewright_html_widget_disabled',
				__( 'HTML widgets are disabled on this site. Use native Elementor widgets. A site administrator can enable them in Stonewright → Settings.', 'stonewright' ),
				[ 'status' => 400 ]
			);
		}

		// Site allows HTML widgets; still require explicit per-call approval for write paths.
		if ( array_key_exists( 'allow_html_widget', $args ) && empty( $args['allow_html_widget'] ) ) {
			return new \WP_Error(
				'html_widget_requires_explicit_approval',
				__( 'Elementor HTML widgets require allow_html_widget=true when enabled for this site.', 'stonewright' ),
				[ 'status' => 400 ]
			);
		}

		return true;
	}

	public static function site_allows(): bool {
		return (bool) get_option( self::OPTION, false );
	}

	public static function is_html_type( string $widget_type ): bool {
		$t = strtolower( trim( $widget_type ) );
		return in_array( $t, [ 'html', 'raw-html', 'raw_html', 'text-path' ], true )
			|| str_contains( $t, 'html' );
	}
}
