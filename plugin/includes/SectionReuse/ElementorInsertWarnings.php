<?php
/**
 * Warnings about what the insert of an Elementor section will refuse.
 * SPDX-License-Identifier: GPL-2.0-or-later
 *
 * @package Stonewright
 */

declare( strict_types=1 );

namespace Stonewright\WpMcp\SectionReuse;

use Stonewright\WpMcp\Elementor\ElementorCustomCssGate;
use Stonewright\WpMcp\Elementor\HtmlWidgetPolicy;
use Stonewright\WpMcp\Elementor\WidgetAvailability;

/**
 * Extract tells the agent, before anything is chosen, what the write would refuse for this section, so reuse does
 * not dead-end at the dry run:
 *
 * - CSS classes the site has not approved (`css_classes_not_approved`), by name;
 * - custom CSS, which needs a human-issued custom-code grant (`custom_css_needs_approval`);
 * - HTML widgets, which are refused unless the site allows them (`html_widgets`);
 * - widgets that Elementor registers as placeholders because their plugin is not active (`placeholder_widgets`).
 *
 * It reads the section and the live site; it changes nothing. The checks are the ones the write runs, not a second
 * opinion: the CSS check is the custom CSS gate's own.
 */
final class ElementorInsertWarnings {

	/** Most names a warning lists. */
	private const MAX_ITEMS = 10;

	/**
	 * @param string               $builder One of the Builder constants.
	 * @param array<string, mixed> $element The portable element of an Elementor section.
	 * @return list<array{code:string,count:int,items:list<string>,detail:string}>
	 */
	public static function for_section( string $builder, array $element ): array {
		if ( ! Builder::is_elementor( $builder ) || [] === $element ) {
			return [];
		}
		$v3 = Builder::ELEMENTOR_V3 === $builder;
		// An Atomic style variant always has a `custom_css` key, null when it has none; only one that holds something counts.
		$found    = ElementorCustomCssGate::inspect( [ 'element' => $v3 ? $element : ElementorCustomCssGate::without_empty_css_keys( $element ) ] );
		$warnings = [];
		if ( [] !== $found['rejected_classes'] ) {
			sort( $found['rejected_classes'] );
			$warnings[] = self::warning(
				'css_classes_not_approved',
				$found['rejected_classes'],
				'The insert is refused while these CSS classes are not approved on this site. A site approves a class by adding it to its stonewright_approved_css_classes option.'
			);
		}
		$css_keys = $found['css_keys'];
		if ( $found['html_style'] ) {
			$css_keys[] = 'html';
		}
		if ( [] !== $css_keys ) {
			$warnings[] = self::warning(
				'custom_css_needs_approval',
				$css_keys,
				'The insert needs a human-issued custom-code grant for this CSS; without one it is refused.'
			);
		}
		if ( $v3 ) {
			$html = [];
			$stub = [];
			self::walk_widgets( $element, $html, $stub );
			if ( [] !== $html ) {
				$warnings[] = self::warning(
					'html_widgets',
					array_keys( $html ),
					HtmlWidgetPolicy::site_allows()
						? 'The insert is refused unless the operation sets allow_html_widget:true.'
						: 'HTML widgets are disabled on this site, so the insert is refused.',
					array_sum( $html )
				);
			}
			if ( [] !== $stub ) {
				$warnings[] = self::warning(
					'placeholder_widgets',
					array_keys( $stub ),
					'The plugin that provides these widgets is not active on this site, so Elementor shows a placeholder in their place; the insert is refused.'
				);
			}
		}

		return $warnings;
	}

	/**
	 * @param list<string> $items
	 * @return array{code:string,count:int,items:list<string>,detail:string}
	 */
	private static function warning( string $code, array $items, string $detail, ?int $count = null ): array {
		return [ 'code' => $code, 'count' => $count ?? count( $items ), 'items' => array_slice( $items, 0, self::MAX_ITEMS ), 'detail' => $detail ];
	}

	/**
	 * The widget types of a section that are HTML widgets (with how many of each) and the ones that are placeholders.
	 *
	 * @param array<string, mixed> $element
	 * @param array<string, int>   $html
	 * @param array<string, int>   $placeholders
	 */
	private static function walk_widgets( array $element, array &$html, array &$placeholders ): void {
		$type = 'widget' === ( $element['elType'] ?? '' ) && is_string( $element['widgetType'] ?? null ) ? $element['widgetType'] : '';
		if ( '' !== $type && ! str_starts_with( $type, 'e-' ) ) {
			if ( HtmlWidgetPolicy::is_html_type( $type ) ) {
				$html[ $type ] = ( $html[ $type ] ?? 0 ) + 1;
			}
			if ( ! isset( $placeholders[ $type ] ) && 'placeholder' === WidgetAvailability::registration( $type ) ) {
				$placeholders[ $type ] = 1;
			}
		}
		foreach ( is_array( $element['elements'] ?? null ) ? $element['elements'] : [] as $child ) {
			if ( is_array( $child ) ) {
				self::walk_widgets( $child, $html, $placeholders );
			}
		}
	}
}
