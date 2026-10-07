<?php
/**
 * Tabs of the admin UI layer: views inside one page.
 * SPDX-License-Identifier: GPL-2.0-or-later
 *
 * @package Stonewright
 */

declare( strict_types=1 );

namespace Stonewright\WpMcp\Admin\Ui;

/**
 * An ARIA tab list and its panels (sw-ui.js owns the behaviour: roving tabindex, Arrow, Home and End, one visible
 * panel). A tab that has a destination is a link, so without script a click loads the page with that view shown;
 * a tab without one is a button. Only the selected panel is shown by the server.
 *
 * Views of one page are tabs. Links to other pages of the same hub are not: they belong to HubNav.
 */
final class Tabs {

	/**
	 * @param list<array{id: string, label: string, href?: string, count?: int|null, count_label?: string}> $tabs
	 * @param array{label: string, prefix?: string, param?: string, class?: string|list<string>} $args "label" names the
	 *        list. "prefix" makes the element ids, so two tab lists on a page never clash. "param" is the query argument
	 *        the script keeps up to date with the selected tab.
	 */
	public static function list( array $tabs, string $current, array $args ): string {
		$prefix = (string) ( $args['prefix'] ?? 'sw-ui' );
		$ids    = array_column( $tabs, 'id' );
		if ( ! in_array( $current, $ids, true ) ) {
			$current = (string) ( $ids[0] ?? '' );
		}

		$items = '';
		foreach ( $tabs as $tab ) {
			$selected = $tab['id'] === $current;
			$count    = '';
			if ( isset( $tab['count'] ) ) {
				$count = ' ' . Badge::count( $tab['count'] );
				if ( '' !== (string) ( $tab['count_label'] ?? '' ) ) {
					$count .= Html::element( 'span', [ 'class' => 'sw-ui-visually-hidden' ], ' ' . Html::text( (string) $tab['count_label'] ) );
				}
			}

			$attributes = [
				'class'         => 'sw-ui-tabs__tab',
				'role'          => 'tab',
				'id'            => $prefix . '-tab-' . $tab['id'],
				'aria-controls' => $prefix . '-panel-' . $tab['id'],
				'aria-selected' => $selected ? 'true' : 'false',
				'tabindex'      => $selected ? null : '-1',
			];
			if ( '' !== (string) ( $tab['href'] ?? '' ) ) {
				$tag        = 'a';
				$attributes = self::after( $attributes, 'id', [ 'href' => $tab['href'] ] );
			} else {
				$tag        = 'button';
				$attributes = self::after( $attributes, 'id', [ 'type' => 'button' ] );
			}

			$items .= Html::element( $tag, $attributes, Html::text( $tab['label'] ) . $count );
		}

		return Html::element(
			'div',
			[
				'class'                => Html::classes( 'sw-ui-tabs', $args['class'] ?? '' ),
				'role'                 => 'tablist',
				'aria-label'           => $args['label'],
				'data-sw-ui-tabs'      => true,
				'data-sw-ui-tabs-param' => $args['param'] ?? null,
			],
			$items
		);
	}

	/** One panel. $selected decides whether the server shows it. */
	public static function panel( string $prefix, string $id, string $html, bool $selected ): string {
		return Html::element(
			'div',
			[
				'class'           => 'sw-ui-tabs__panel',
				'role'            => 'tabpanel',
				'id'              => $prefix . '-panel-' . $id,
				'aria-labelledby' => $prefix . '-tab-' . $id,
				'hidden'          => $selected ? null : true,
			],
			$html
		);
	}

	/**
	 * Inserts attributes after one key, so the markup keeps a fixed, readable order.
	 *
	 * @param array<string, scalar|null>               $attributes
	 * @param array<string, scalar|null>               $insert
	 * @return array<string, scalar|null>
	 */
	private static function after( array $attributes, string $key, array $insert ): array {
		$out = [];
		foreach ( $attributes as $name => $value ) {
			$out[ $name ] = $value;
			if ( $name === $key ) {
				$out = array_merge( $out, $insert );
			}
		}

		return $out;
	}
}
