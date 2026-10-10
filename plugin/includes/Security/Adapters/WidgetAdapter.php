<?php
/**
 * The widget family in the change ledger: the widgets of a sidebar and their settings, and the restore.
 * SPDX-License-Identifier: GPL-2.0-or-later
 *
 * @package Stonewright
 */

declare( strict_types=1 );

namespace Stonewright\WpMcp\Security\Adapters;

use Stonewright\WpMcp\Security\ChangeLedger;

/**
 * Which widgets a sidebar holds is the "sidebars_widgets" option; the settings of each widget are an entry
 * of the option of its widget type ("widget_text", numbered by the widget). This adapter describes one
 * sidebar and the settings of the widgets in it as one image:
 *
 * - v, kind "widget".
 * - sidebar: the sidebar id.
 * - widgets: the widget ids in the sidebar, in order, or null when the sidebar does not exist.
 * - instances: widget id => { exists, value }, the settings of the widgets the call may touch.
 * - vetoed: the options whose settings were left out because their name is a secret name.
 *
 * The settings of a widget type whose option has a secret name are never read, so never stored. Other
 * credentials inside settings are masked by the ledger, which marks the row not restorable.
 *
 * restore() writes the sidebar list with wp_set_sidebars_widgets(), the function the abilities use, and the
 * settings with update_option(); it leaves other sidebars and other widgets alone, reads both back, and
 * reports what still differs. It checks no permission, token or newer change: the code that calls it does.
 */
final class WidgetAdapter {

	public const IMAGE_VERSION = 1;

	private const SIDEBAR_PATTERN = '/^[A-Za-z0-9][A-Za-z0-9_\-.]{0,99}$/D';

	private const WIDGET_PATTERN = '/^([A-Za-z0-9][A-Za-z0-9_\-]{0,60})-([0-9]{1,9})$/D';

	/**
	 * The scope of a widget ability call: the sidebar it names, and the widgets whose settings the image holds.
	 *
	 * @param array<string, mixed> $args
	 * @return array{sidebar:string,widgets:list<string>}|null Null when the ability is not a widget ability or names no valid sidebar.
	 */
	public static function scope( string $ability, array $args ): ?array {
		if ( ! in_array( $ability, [ 'stonewright/widget-save', 'stonewright/widget-delete' ], true ) ) {
			return null;
		}
		$sidebar = (string) ( $args['sidebar_id'] ?? '' );
		if ( 1 !== preg_match( self::SIDEBAR_PATTERN, $sidebar ) ) {
			return null;
		}
		$ids = self::list_of( $sidebar ) ?? [];
		if ( 'stonewright/widget-save' === $ability ) {
			foreach ( (array) ( $args['widgets'] ?? [] ) as $id ) {
				$ids[] = (string) $id;
			}
		} else {
			$ids[] = (string) ( $args['widget_id'] ?? '' );
		}
		return [ 'sidebar' => $sidebar, 'widgets' => array_values( array_unique( array_filter( $ids, static fn ( string $id ): bool => '' !== $id ) ) ) ];
	}

	/**
	 * The image of a sidebar as it is now.
	 *
	 * @param list<string> $widget_ids Widgets whose settings are part of the image.
	 * @return array<string, mixed>
	 */
	public static function image( string $sidebar, array $widget_ids ): array {
		$instances = [];
		$vetoed    = [];
		foreach ( array_unique( $widget_ids ) as $id ) {
			$parsed = self::parse( $id );
			if ( null === $parsed ) {
				continue;
			}
			$option = 'widget_' . $parsed['base'];
			if ( OptionsAdapter::is_vetoed( $option ) ) {
				$vetoed[] = $option;
				continue;
			}
			$sentinel = new \stdClass();
			$stored   = get_option( $option, $sentinel );
			$has      = is_array( $stored ) && array_key_exists( $parsed['number'], $stored );
			$instances[ $id ] = [ 'exists' => $has, 'value' => $has ? $stored[ $parsed['number'] ] : null ];
		}
		ksort( $instances, SORT_STRING );
		$vetoed = array_values( array_unique( $vetoed ) );
		sort( $vetoed, SORT_STRING );
		return [
			'v'         => self::IMAGE_VERSION,
			'kind'      => 'widget',
			'sidebar'   => $sidebar,
			'widgets'   => self::list_of( $sidebar ),
			'instances' => $instances,
			'vetoed'    => $vetoed,
		];
	}

	/**
	 * A short plain summary of a sidebar change, without any setting.
	 *
	 * @param array<string, mixed> $image
	 */
	public static function summary( array $image ): string {
		$count = is_array( $image['widgets'] ?? null ) ? count( $image['widgets'] ) : 0;
		return 'Sidebar ' . (string) ( $image['sidebar'] ?? '' ) . ' (' . $count . ' widget' . ( 1 === $count ? '' : 's' ) . ')';
	}

	/**
	 * Write an image back, then read the sidebar to confirm.
	 *
	 * @param array<string, mixed> $image
	 * @return array{ok:bool,skipped:list<string>,differences:list<string>}|\WP_Error ok is true only when the sidebar and the settings now equal the image.
	 */
	public static function restore( array $image ): array|\WP_Error {
		$sidebar = (string) ( $image['sidebar'] ?? '' );
		$widgets = $image['widgets'] ?? null;
		if ( self::IMAGE_VERSION !== ( $image['v'] ?? null ) || 'widget' !== ( $image['kind'] ?? '' ) || 1 !== preg_match( self::SIDEBAR_PATTERN, $sidebar ) || ( null !== $widgets && ! is_array( $widgets ) ) || ! is_array( $image['instances'] ?? null ) ) {
			return new \WP_Error( 'stonewright_image_invalid', __( 'The image is not a widget image this version can restore.', 'stonewright' ) );
		}
		if ( AdapterSupport::has_mask( [ $widgets, $image['instances'] ] ) ) {
			return new \WP_Error( 'stonewright_image_masked', __( 'The image had credentials masked out of it, so it cannot be written back.', 'stonewright' ) );
		}

		$skipped = [];
		foreach ( (array) ( $image['vetoed'] ?? [] ) as $name ) {
			if ( is_string( $name ) ) {
				$skipped[] = 'vetoed.' . $name;
			}
		}

		$sidebars = wp_get_sidebars_widgets();
		$sidebars = is_array( $sidebars ) ? $sidebars : [];
		if ( null === $widgets ) {
			unset( $sidebars[ $sidebar ] );
		} else {
			$sidebars[ $sidebar ] = array_values( array_map( 'strval', $widgets ) );
		}
		wp_set_sidebars_widgets( $sidebars );

		$written = [];
		foreach ( $image['instances'] as $id => $payload ) {
			$id     = (string) $id;
			$parsed = self::parse( $id );
			if ( null === $parsed || ! is_array( $payload ) ) {
				$skipped[] = 'widget.' . $id;
				continue;
			}
			$option = 'widget_' . $parsed['base'];
			if ( OptionsAdapter::is_vetoed( $option ) ) {
				$skipped[] = 'vetoed.' . $option;
				continue;
			}
			$stored = get_option( $option, [] );
			$stored = is_array( $stored ) ? $stored : [];
			if ( ! empty( $payload['exists'] ) ) {
				$stored[ $parsed['number'] ] = $payload['value'] ?? null;
			} else {
				unset( $stored[ $parsed['number'] ] );
			}
			update_option( $option, $stored );
			$written[] = $id;
		}

		$live        = self::image( $sidebar, $written );
		$differences = [];
		if ( ! AdapterSupport::same( $live['widgets'], $widgets ) ) {
			$differences[] = 'sidebar.' . $sidebar;
		}
		foreach ( $written as $id ) {
			if ( ! AdapterSupport::same( $live['instances'][ $id ] ?? null, $image['instances'][ $id ] ) ) {
				$differences[] = 'widget.' . $id;
			}
		}
		return [
			'ok'          => [] === $differences,
			'skipped'     => $skipped,
			'differences' => $differences,
		];
	}

	/**
	 * Undo one ledger row of this family: write its before image back.
	 *
	 * @return array{ok:bool,skipped:list<string>,differences:list<string>}|\WP_Error
	 */
	public static function undo( string $change_id ): array|\WP_Error {
		$row = ChangeLedger::get( $change_id );
		if ( null === $row ) {
			return new \WP_Error( 'stonewright_change_not_found', __( 'The change is not recorded.', 'stonewright' ) );
		}
		if ( 'widget' !== $row['family'] ) {
			return new \WP_Error( 'stonewright_change_not_a_widget', __( 'The change is not a change of a widget sidebar.', 'stonewright' ) );
		}
		if ( ! $row['restorable'] ) {
			return new \WP_Error( 'stonewright_change_not_restorable', __( 'The change cannot be undone from the ledger.', 'stonewright' ), [ 'reason' => $row['restorable_reason'] ] );
		}
		$image = ChangeLedger::read_image( $change_id, 'before' );
		if ( $image instanceof \WP_Error ) {
			return $image;
		}
		if ( ! is_array( $image ) ) {
			return new \WP_Error( 'stonewright_image_invalid', __( 'The stored image is not a widget image.', 'stonewright' ) );
		}
		return self::restore( $image );
	}

	/**
	 * The widget ids of a sidebar, or null when the sidebar does not exist.
	 *
	 * @return list<string>|null
	 */
	private static function list_of( string $sidebar ): ?array {
		$all = wp_get_sidebars_widgets();
		if ( ! is_array( $all ) || ! array_key_exists( $sidebar, $all ) ) {
			return null;
		}
		return array_values( array_map( 'strval', (array) $all[ $sidebar ] ) );
	}

	/**
	 * @return array{base:string,number:int}|null
	 */
	private static function parse( string $id ): ?array {
		if ( 1 !== preg_match( self::WIDGET_PATTERN, $id, $match ) ) {
			return null;
		}
		return [ 'base' => $match[1], 'number' => (int) $match[2] ];
	}
}
