<?php
/**
 * Turns a portable Elementor section into elements ready to insert into a document.
 * SPDX-License-Identifier: GPL-2.0-or-later
 *
 * @package Stonewright
 */

declare( strict_types=1 );

namespace Stonewright\WpMcp\SectionReuse;

use Stonewright\WpMcp\Elementor\Provider\ProviderRouter;
use Stonewright\WpMcp\Elementor\Schema\PatchValidator;
use Stonewright\WpMcp\Elementor\V4\AtomicSchemaRepository;
use Stonewright\WpMcp\Elementor\WidgetAvailability;
use Stonewright\WpMcp\Support\ElementorData;

/**
 * The step between a validated {@see PortableSection} and the write engines of Elementor V3 and V4. It
 *
 * - gives every element a fresh id that is unique in the target document and in the section;
 * - for V4, gives every local style a fresh id that names its new element and rewrites each class list to match,
 *   so a style is never shared between the copy and its source or between two copies;
 * - keeps global colors, fonts, classes and variables that exist on this site, and fails with the exact
 *   reference when one does not;
 * - keeps dynamic tags and reports them;
 * - gives an id attribute (`_element_id` in V3, `_cssid` in V4) that the document already uses, or that the copy
 *   uses twice, the next free `name-2`, `name-3`, and points the links of the copy at the renamed one;
 * - fails with the exact widget when the section holds a placeholder that Elementor registers for a plugin that is
 *   not active, or a widget that is not registered at all;
 * - fails with the exact missing feature when a V4 element type is not available on this site;
 * - never changes a widget type and never removes a setting it does not know.
 *
 * It touches no database record and writes nothing; the caller inserts the result with its own write closure.
 */
final class ElementorSectionInserter {

	/** @var array<string, list<string>> Reference types that must resolve before a copy is inserted, by builder. */
	private const REQUIRED = [
		Builder::ELEMENTOR_V3 => [ 'global_color', 'global_font' ],
		Builder::ELEMENTOR_V4 => [ 'global_class', 'variable' ],
	];

	/** Most missing references listed in one error. */
	private const MAX_MISSING = 20;

	/**
	 * @param array<string, mixed>                $payload     A payload from {@see PortableSection::validate()}.
	 * @param array<int, array<string, mixed>>    $tree        The document the section goes into, for id uniqueness.
	 * @param list<int>                           $parent_path Where it goes; an empty path is the document root.
	 * @param array{id_generator?:callable():string} $options  `id_generator` replaces the random id source in tests.
	 * @return array{element:array<string,mixed>,id_map:array<string,string>,warnings:list<array<string,mixed>>}|\WP_Error
	 */
	public static function instantiate( array $payload, string $builder, array $tree, array $parent_path, array $options = [] ): array|\WP_Error {
		$element = $payload['element'] ?? null;
		if ( ! is_array( $element ) ) {
			return new \WP_Error( 'stonewright_section_invalid', 'The section has no element.', [ 'status' => 400 ] );
		}
		$root_type = (string) ( $element['elType'] ?? '' );
		if ( Builder::ELEMENTOR_V3 === $builder && ! in_array( $root_type, [ 'container', 'section' ], true ) ) {
			return new \WP_Error( 'stonewright_section_parent_invalid', 'A V3 section must start with a container or a section element.', [ 'status' => 400, 'element_type' => $root_type ] );
		}
		if ( Builder::ELEMENTOR_V3 === $builder && 'section' === $root_type && [] !== $parent_path ) {
			return new \WP_Error( 'stonewright_section_parent_invalid', 'A legacy section can only sit at the top level of a document.', [ 'status' => 400 ] );
		}

		$inspection = SectionInspector::inspect( $builder, $element );
		$unavailable = self::unavailable_types( $builder, $element );
		if ( null !== $unavailable ) {
			return new \WP_Error(
				'stonewright_atomic_type_unavailable',
				'The section uses an Atomic type this site does not have: ' . $unavailable . '.',
				[ 'status' => 409, 'missing_types' => [ $unavailable ], 'missing_feature' => 'atomic_type:' . $unavailable ]
			);
		}
		if ( Builder::ELEMENTOR_V3 === $builder ) {
			$widget_error = self::unavailable_widget( $element );
			if ( null !== $widget_error ) {
				return $widget_error;
			}
		}
		$missing = self::missing_references( $builder, $inspection['references'] );
		if ( [] !== $missing ) {
			return new \WP_Error(
				'stonewright_section_reference_missing',
				sprintf( 'The section refers to %1$s "%2$s", which does not exist on this site.', str_replace( '_', ' ', $missing[0]['type'] ), $missing[0]['id'] ),
				[ 'status' => 409, 'missing' => $missing, 'reference' => $missing[0], 'repair' => 'Create the missing reference, or choose another section. Nothing was written.' ]
			);
		}

		$used      = array_fill_keys( array_keys( ElementorData::flatten( $tree ) ), true );
		$generator = $options['id_generator'] ?? static fn(): string => ElementorData::generate_id();
		$id_map    = [];
		$copy      = self::copy( $element, $builder, $used, $generator, $id_map );
		$copy['isInner'] = [] !== $parent_path;
		$renamed         = [];
		$copy            = self::rename_dom_ids( $copy, self::dom_ids( $tree ), $renamed );
		if ( Builder::ELEMENTOR_V3 === $builder ) {
			$unwritable = self::unwritable_settings( $copy, array_flip( $id_map ) );
			if ( null !== $unwritable ) {
				return $unwritable;
			}
		}

		$warnings = [];
		if ( [] !== $renamed ) {
			$warnings[] = [ 'code' => 'anchors_renamed', 'count' => count( $renamed ), 'items' => array_slice( array_map( static fn( array $pair ): string => $pair[0] . ' -> ' . $pair[1], $renamed ), 0, 5 ) ];
		}
		$tags     = array_values( array_map( static fn( array $reference ): string => (string) $reference['id'], array_filter( $inspection['references'], static fn( array $reference ): bool => 'dynamic_tag' === $reference['type'] ) ) );
		if ( [] !== $tags ) {
			$warnings[] = [ 'code' => 'dynamic_tags_kept', 'count' => count( $tags ), 'items' => array_slice( $tags, 0, 5 ) ];
		}

		return [ 'element' => $copy, 'id_map' => $id_map, 'warnings' => $warnings ];
	}

	/**
	 * The error for the first element whose settings the document write would refuse, or null.
	 *
	 * A write validates the settings of every new element against the live schema and refuses a setting it
	 * does not know; it never drops one. A copy therefore either keeps every setting of its source or is
	 * refused here, in the dry run, naming the exact setting, instead of failing when the page is saved.
	 *
	 * @param array<string, mixed>   $element
	 * @param array<string, string>  $placeholders New id to placeholder.
	 */
	private static function unwritable_settings( array $element, array $placeholders ): ?\WP_Error {
		$type     = (string) ( $element['elType'] ?? '' );
		$widget   = 'widget' === $type ? (string) ( $element['widgetType'] ?? '' ) : '';
		$settings = is_array( $element['settings'] ?? null ) ? $element['settings'] : [];
		$checked  = ! ( 'widget' === $type && ( str_starts_with( $widget, 'e-' ) || 'html' === $widget ) ) && in_array( $type, [ 'widget', 'container', 'section', 'column' ], true ) && [] !== $settings;
		if ( $checked ) {
			$validated = 'widget' === $type
				? PatchValidator::widget( $widget, [], $settings, 'merge' )
				: PatchValidator::container( [], $settings, $type, 'merge' );
			$problem   = $validated instanceof \WP_Error ? $validated : ( $validated['settings'] !== $settings ? new \WP_Error( 'stonewright_elementor_settings_invalid', 'The settings would change when written.', [ 'violations' => [ [ 'path' => 'settings', 'code' => 'delta_result_mismatch' ] ] ] ) : null );
			if ( null !== $problem ) {
				$data = is_array( $problem->get_error_data() ) ? $problem->get_error_data() : [];
				$name = $placeholders[ (string) $element['id'] ] ?? (string) $element['id'];
				return new \WP_Error(
					'stonewright_section_settings_not_reusable',
					sprintf( 'The section holds settings that the live Elementor schema does not accept as they are (element %1$s, %2$s). Stonewright never strips settings, so it did not copy the section.', $name, '' !== $widget ? $widget : $type ),
					[
						'status'       => 409,
						'element'      => $name,
						'element_type' => '' !== $widget ? $widget : $type,
						'code'         => (string) $problem->get_error_code(),
						'violations'   => array_slice( array_values( (array) ( $data['violations'] ?? [] ) ), 0, 10 ),
						'repair'       => 'Choose another section, or activate the plugin that provides these settings. Nothing was written.',
					]
				);
			}
		}
		foreach ( is_array( $element['elements'] ?? null ) ? $element['elements'] : [] as $child ) {
			if ( is_array( $child ) ) {
				$found = self::unwritable_settings( $child, $placeholders );
				if ( null !== $found ) {
					return $found;
				}
			}
		}

		return null;
	}

	/**
	 * Most elements one batch of insert operations may add: the element cap of an Elementor write.
	 */
	public static function max_batch_elements(): int {
		return (int) ProviderRouter::element_limits()['max_elements'];
	}

	/**
	 * Refuses an insert that would bring the elements added by one batch over the element cap. A section is checked
	 * on its own when it is validated; a batch of several may not add up to more than one write may hold.
	 *
	 * @param int                  $already Elements the earlier insert operations of the batch add.
	 * @param array<string, mixed> $payload A payload from {@see PortableSection::validate()}.
	 */
	public static function within_batch_budget( int $already, array $payload ): ?\WP_Error {
		$adding = is_array( $payload['element'] ?? null ) ? self::count_elements( $payload['element'] ) : 0;
		$limit  = self::max_batch_elements();
		if ( $already + $adding <= $limit ) {
			return null;
		}

		return new \WP_Error(
			'stonewright_section_batch_too_large',
			sprintf( 'This batch would add %1$d elements (%2$d already planned and %3$d in this section); one write may add at most %4$d. Split the work into several batches. Nothing was written.', $already + $adding, $already, $adding, $limit ),
			[ 'status' => 413, 'limit' => $limit, 'inserted' => $already, 'adding' => $adding, 'limit_kind' => 'batch_elements', 'retryable' => true, 'write_blocked' => true ]
		);
	}

	/** @param array<string, mixed> $element */
	private static function count_elements( array $element ): int {
		$count = 1;
		foreach ( is_array( $element['elements'] ?? null ) ? $element['elements'] : [] as $child ) {
			if ( is_array( $child ) ) {
				$count += self::count_elements( $child );
			}
		}

		return $count;
	}

	/**
	 * The error for the first widget of the section that cannot render here: a placeholder that Elementor registers
	 * for a plugin that is not active, or a widget that is not registered. A V3 write validates only the settings a
	 * widget is given, so a widget without settings would otherwise pass until the page is saved.
	 *
	 * @param array<string, mixed> $element
	 */
	private static function unavailable_widget( array $element ): ?\WP_Error {
		$widget = 'widget' === ( $element['elType'] ?? '' ) ? (string) ( $element['widgetType'] ?? '' ) : '';
		if ( '' !== $widget && ! str_starts_with( $widget, 'e-' ) ) {
			$registration = WidgetAvailability::registration( $widget );
			$name         = (string) ( $element['id'] ?? '' );
			if ( 'placeholder' === $registration ) {
				$requires = WidgetAvailability::placeholder_requirement( $widget );
				return new \WP_Error(
					'stonewright_section_placeholder_widget',
					sprintf( 'The section uses the "%1$s" widget (element %2$s), which is provided by a plugin that is not active on this site (%3$s). Elementor shows a placeholder in its place, so the copy would render empty. Nothing was written.', $widget, $name, 'elementor-pro' === $requires ? 'Elementor Pro' : $requires ),
					[ 'status' => 409, 'widget_type' => $widget, 'element' => $name, 'requires' => $requires, 'repair' => 'Activate the plugin that provides the widget, or choose another section.' ]
				);
			}
			if ( 'unregistered' === $registration ) {
				return new \WP_Error(
					'stonewright_section_widget_unregistered',
					sprintf( 'The section uses the "%1$s" widget (element %2$s), which is not registered on this site. Nothing was written.', $widget, $name ),
					[ 'status' => 409, 'widget_type' => $widget, 'element' => $name, 'repair' => 'Activate the plugin that provides the widget, or choose another section.' ]
				);
			}
		}
		foreach ( is_array( $element['elements'] ?? null ) ? $element['elements'] : [] as $child ) {
			if ( is_array( $child ) ) {
				$found = self::unavailable_widget( $child );
				if ( null !== $found ) {
					return $found;
				}
			}
		}

		return null;
	}

	// ---------------------------------------------------------------- id attributes

	/**
	 * The id attribute an element sets: `_element_id` in V3, `_cssid` (a string prop) in V4.
	 *
	 * @param array<string, mixed> $element
	 * @return array{key:string,value:string}|null
	 */
	private static function dom_id( array $element ): ?array {
		$settings = is_array( $element['settings'] ?? null ) ? $element['settings'] : [];
		$v3       = $settings['_element_id'] ?? null;
		if ( is_string( $v3 ) && '' !== trim( $v3 ) ) {
			return [ 'key' => '_element_id', 'value' => trim( $v3 ) ];
		}
		$v4 = is_array( $settings['_cssid'] ?? null ) ? ( $settings['_cssid']['value'] ?? null ) : null;
		if ( is_string( $v4 ) && '' !== trim( $v4 ) ) {
			return [ 'key' => '_cssid', 'value' => trim( $v4 ) ];
		}

		return null;
	}

	/**
	 * Every id attribute the document uses.
	 *
	 * @param array<int, mixed> $tree
	 * @return array<string, true>
	 */
	private static function dom_ids( array $tree ): array {
		$used = [];
		foreach ( $tree as $element ) {
			if ( ! is_array( $element ) ) {
				continue;
			}
			$dom = self::dom_id( $element );
			if ( null !== $dom ) {
				$used[ $dom['value'] ] = true;
			}
			$used += self::dom_ids( is_array( $element['elements'] ?? null ) ? $element['elements'] : [] );
		}

		return $used;
	}

	/**
	 * Gives every id attribute of the copy that the document already uses, or that an earlier element of the copy
	 * uses, the next free `name-2`, `name-3`, and points the `#name` links of the copy at the renamed id.
	 *
	 * @param array<string, mixed>           $copy
	 * @param array<string, true>            $used    Id attributes taken in the document.
	 * @param list<array{0:string,1:string}> $renamed Old and new id of every rename, filled as it goes.
	 * @return array<string, mixed>
	 */
	private static function rename_dom_ids( array $copy, array $used, array &$renamed ): array {
		$seen  = [];
		$links = [];
		$copy  = self::rename_dom_ids_in( $copy, $used, $seen, $links, $renamed );
		if ( [] !== $links ) {
			$copy = self::rewrite_links( $copy, $links );
		}

		return $copy;
	}

	/**
	 * @param array<string, mixed>           $element
	 * @param array<string, true>            $used
	 * @param array<string, true>            $seen    Id attributes met in the copy so far.
	 * @param array<string, string>          $links   Old to new id, for the first element of the copy that carries an id when that one was renamed.
	 * @param list<array{0:string,1:string}> $renamed
	 * @return array<string, mixed>
	 */
	private static function rename_dom_ids_in( array $element, array &$used, array &$seen, array &$links, array &$renamed ): array {
		$dom = self::dom_id( $element );
		if ( null !== $dom ) {
			$old = $dom['value'];
			$new = $old;
			if ( isset( $used[ $old ] ) ) {
				$new = self::free_dom_id( $old, $used );
				if ( '_element_id' === $dom['key'] ) {
					$element['settings']['_element_id'] = $new;
				} else {
					$element['settings']['_cssid']['value'] = $new;
				}
				if ( ! isset( $seen[ $old ] ) ) {
					$links[ $old ] = $new;
				}
				$renamed[] = [ $old, $new ];
			}
			$used[ $new ] = true;
			$seen[ $old ] = true;
		}
		if ( is_array( $element['elements'] ?? null ) ) {
			$children = [];
			foreach ( $element['elements'] as $child ) {
				$children[] = is_array( $child ) ? self::rename_dom_ids_in( $child, $used, $seen, $links, $renamed ) : $child;
			}
			$element['elements'] = $children;
		}

		return $element;
	}

	/** @param array<string, true> $used */
	private static function free_dom_id( string $id, array $used ): string {
		for ( $n = 2; $n < 10000; ++$n ) {
			if ( ! isset( $used[ $id . '-' . $n ] ) ) {
				return $id . '-' . $n;
			}
		}

		return $id . '-' . bin2hex( random_bytes( 3 ) );
	}

	/**
	 * Points the `#name` links in the settings of the copy at the renamed ids. A link is a string that is exactly
	 * `#name`, or an `href="#name"` inside HTML; styles and the id attributes themselves are left as they are.
	 *
	 * @param array<string, mixed>  $element
	 * @param array<string, string> $links   Old to new id.
	 * @return array<string, mixed>
	 */
	private static function rewrite_links( array $element, array $links ): array {
		if ( is_array( $element['settings'] ?? null ) ) {
			$element['settings'] = self::rewrite_link_values( $element['settings'], $links, 0 );
		}
		if ( is_array( $element['elements'] ?? null ) ) {
			$element['elements'] = array_map( static fn( mixed $child ): mixed => is_array( $child ) ? self::rewrite_links( $child, $links ) : $child, $element['elements'] );
		}

		return $element;
	}

	/**
	 * @param array<mixed>          $value
	 * @param array<string, string> $links
	 * @return array<mixed>
	 */
	private static function rewrite_link_values( array $value, array $links, int $depth ): array {
		if ( $depth > 12 ) {
			return $value;
		}
		foreach ( $value as $key => $member ) {
			if ( in_array( $key, [ '_element_id', '_cssid' ], true ) ) {
				continue;
			}
			if ( is_array( $member ) ) {
				$value[ $key ] = self::rewrite_link_values( $member, $links, $depth + 1 );
			} elseif ( is_string( $member ) ) {
				foreach ( $links as $old => $new ) {
					if ( '#' . $old === $member ) {
						$member = '#' . $new;
						continue;
					}
					$member = str_replace( [ 'href="#' . $old . '"', "href='#" . $old . "'" ], [ 'href="#' . $new . '"', "href='#" . $new . "'" ], $member );
				}
				$value[ $key ] = $member;
			}
		}

		return $value;
	}

	/**
	 * The references of the section that definitely do not exist here. A reference this site cannot check
	 * (unknown, not false) is reported by extract and never blocks.
	 *
	 * @param list<array<string, mixed>> $references
	 * @return list<array{type:string,id:string,at:list<string>}>
	 */
	private static function missing_references( string $builder, array $references ): array {
		$missing = [];
		foreach ( $references as $reference ) {
			if ( ! in_array( (string) $reference['type'], self::REQUIRED[ $builder ] ?? [], true ) ) {
				continue;
			}
			if ( false === ReferenceCatalog::exists( (string) $reference['type'], (string) $reference['id'] ) ) {
				$missing[] = [ 'type' => (string) $reference['type'], 'id' => (string) $reference['id'], 'at' => array_values( array_map( 'strval', (array) $reference['at'] ) ) ];
			}
		}

		return array_slice( $missing, 0, self::MAX_MISSING );
	}

	/**
	 * The first V4 element type of the section that this site does not offer, or null.
	 *
	 * @param array<string, mixed> $element
	 */
	private static function unavailable_types( string $builder, array $element ): ?string {
		if ( Builder::ELEMENTOR_V4 !== $builder ) {
			return null;
		}
		$type = Builder::element_type( $element );
		if ( str_starts_with( $type, 'e-' ) && null === AtomicSchemaRepository::for_atomic_type( $type ) ) {
			return $type;
		}
		foreach ( is_array( $element['elements'] ?? null ) ? $element['elements'] : [] as $child ) {
			if ( is_array( $child ) ) {
				$found = self::unavailable_types( $builder, $child );
				if ( null !== $found ) {
					return $found;
				}
			}
		}

		return null;
	}

	/**
	 * Copies an element and its children under fresh ids.
	 *
	 * @param array<string, mixed>   $element
	 * @param array<string, true>    $used      Ids taken in the target document and by this copy so far.
	 * @param callable():string      $generator
	 * @param array<string, string>  $id_map    Placeholder to new id, filled as the copy goes.
	 * @return array<string, mixed>
	 */
	private static function copy( array $element, string $builder, array &$used, callable $generator, array &$id_map ): array {
		$placeholder = (string) $element['id'];
		$new_id      = self::fresh_id( $used, $generator );
		$id_map[ $placeholder ] = $new_id;
		$copy        = $element;
		$copy['id']  = $new_id;

		if ( Builder::ELEMENTOR_V4 === $builder && is_array( $element['styles'] ?? null ) && [] !== $element['styles'] ) {
			$map    = [];
			$styles = [];
			foreach ( $element['styles'] as $old => $style ) {
				$suffix = substr( bin2hex( random_bytes( 4 ) ), 0, 7 );
				$new    = 'e-' . $new_id . '-' . $suffix;
				while ( isset( $styles[ $new ] ) ) {
					$suffix = substr( bin2hex( random_bytes( 4 ) ), 0, 7 );
					$new    = 'e-' . $new_id . '-' . $suffix;
				}
				$map[ (string) $old ] = $new;
				if ( is_array( $style ) && isset( $style['id'] ) && (string) $style['id'] === (string) $old ) {
					$style['id'] = $new;
				}
				$styles[ $new ] = $style;
			}
			$copy['styles']   = $styles;
			$copy['settings'] = PortableSection::remap_classes( is_array( $element['settings'] ?? null ) ? $element['settings'] : [], $map );
		}

		$children = [];
		foreach ( is_array( $element['elements'] ?? null ) ? $element['elements'] : [] as $child ) {
			if ( is_array( $child ) ) {
				$children[] = self::copy( $child, $builder, $used, $generator, $id_map );
			}
		}
		$copy['elements'] = $children;

		return $copy;
	}

	/**
	 * @param array<string, true> $used
	 * @param callable():string   $generator
	 */
	private static function fresh_id( array &$used, callable $generator ): string {
		for ( $attempt = 0; $attempt < 1000; ++$attempt ) {
			$id = (string) $generator();
			if ( '' !== $id && ! isset( $used[ $id ] ) ) {
				$used[ $id ] = true;
				return $id;
			}
		}

		// A generator that keeps repeating itself is bypassed: a random id is almost certainly free.
		do {
			$id = substr( bin2hex( random_bytes( 4 ) ), 0, 7 );
		} while ( isset( $used[ $id ] ) );
		$used[ $id ] = true;

		return $id;
	}
}
