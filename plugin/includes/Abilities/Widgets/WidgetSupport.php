<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\Abilities\Widgets;

/**
 * Shared checks of the sidebar and widget ids that widget-save and widget-delete are given.
 */
final class WidgetSupport {

	/**
	 * The sidebar ids the site knows: the registered sidebars, the ones that hold a widget list, and the inactive list.
	 *
	 * @return list<string>
	 */
	public static function sidebar_ids(): array {
		$known = [];
		foreach ( (array) ( $GLOBALS['wp_registered_sidebars'] ?? [] ) as $id => $_sidebar ) {
			$known[] = (string) $id;
		}
		$assigned = wp_get_sidebars_widgets();
		foreach ( is_array( $assigned ) ? array_keys( $assigned ) : [] as $id ) {
			if ( 'array_version' !== $id ) {
				$known[] = (string) $id;
			}
		}
		$known[] = 'wp_inactive_widgets';
		return array_values( array_unique( $known ) );
	}

	/**
	 * The refusal for a sidebar id the site does not know, with the ids it could have been.
	 */
	public static function sidebar_error( string $sidebar_id ): \WP_Error {
		return new \WP_Error(
			'stonewright_sidebar_not_found',
			__( 'That sidebar does not exist. Pick one of available_sidebars.', 'stonewright' ),
			[
				'status'             => 404,
				'sidebar_id'         => $sidebar_id,
				'available_sidebars' => self::sidebar_ids(),
			]
		);
	}

	/**
	 * Whether a widget id (such as text-3) is well formed and names a widget the site has: a registered
	 * widget, one that a sidebar already holds, or a saved instance of its widget type.
	 */
	public static function widget_exists( string $widget_id ): bool {
		if ( 1 !== preg_match( '/^([a-z0-9_-]+)-(\d+)$/i', $widget_id, $parts ) ) {
			return false;
		}
		if ( isset( $GLOBALS['wp_registered_widgets'][ $widget_id ] ) ) {
			return true;
		}
		foreach ( (array) wp_get_sidebars_widgets() as $list ) {
			if ( is_array( $list ) && in_array( $widget_id, array_map( 'strval', $list ), true ) ) {
				return true;
			}
		}
		$instances = get_option( 'widget_' . $parts[1], [] );
		return is_array( $instances ) && array_key_exists( (int) $parts[2], $instances );
	}
}
