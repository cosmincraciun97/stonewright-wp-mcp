<?php
/**
 * Warnings that come with a reuse candidate.
 * SPDX-License-Identifier: GPL-2.0-or-later
 *
 * @package Stonewright
 */

declare( strict_types=1 );

namespace Stonewright\WpMcp\SectionReuse;

/**
 * Turns a section's references into the short warnings an agent shows the user before copying it: what the
 * section carries that may not belong on another page (dynamic tags, forms, global widgets, synced patterns,
 * third-party widgets) and what it needs that is not here (a missing global class, variable, color, font,
 * media file, template or pattern).
 */
final class ReuseWarnings {

	private const MAX_ITEMS = 5;

	/** @var array<string, array{type:string,exists:?bool}> Warning code to the references that raise it. */
	private const RULES = [
		'dynamic_tags'          => [ 'type' => 'dynamic_tag', 'exists' => null ],
		'forms'                 => [ 'type' => 'form', 'exists' => null ],
		'global_widgets'        => [ 'type' => 'global_widget', 'exists' => null ],
		'synced_patterns'       => [ 'type' => 'synced_pattern', 'exists' => null ],
		'third_party_widgets'   => [ 'type' => 'third_party_widget', 'exists' => null ],
		'missing_global_classes' => [ 'type' => 'global_class', 'exists' => false ],
		'missing_variables'     => [ 'type' => 'variable', 'exists' => false ],
		'missing_global_colors' => [ 'type' => 'global_color', 'exists' => false ],
		'missing_global_fonts'  => [ 'type' => 'global_font', 'exists' => false ],
		'missing_media'         => [ 'type' => 'media', 'exists' => false ],
		'missing_templates'     => [ 'type' => 'nested_template', 'exists' => false ],
		'missing_patterns'      => [ 'type' => 'synced_pattern', 'exists' => false ],
	];

	/**
	 * @param list<array<string, mixed>> $references References with their `exists` answer.
	 * @return list<array{code:string,count:int,items:list<string>}>
	 */
	public static function from( array $references ): array {
		$warnings = [];
		foreach ( self::RULES as $code => $rule ) {
			$items = [];
			foreach ( $references as $reference ) {
				if ( (string) $reference['type'] !== $rule['type'] ) {
					continue;
				}
				if ( null !== $rule['exists'] && ( $reference['exists'] ?? null ) !== $rule['exists'] ) {
					continue;
				}
				$items[] = (string) $reference['id'];
			}
			if ( 'missing_templates' === $code ) {
				foreach ( $references as $reference ) {
					if ( 'global_widget' === $reference['type'] && false === ( $reference['exists'] ?? null ) ) {
						$items[] = (string) $reference['id'];
					}
				}
			}
			if ( [] !== $items ) {
				$warnings[] = [ 'code' => $code, 'count' => count( $items ), 'items' => array_slice( $items, 0, self::MAX_ITEMS ) ];
			}
		}

		return $warnings;
	}
}
