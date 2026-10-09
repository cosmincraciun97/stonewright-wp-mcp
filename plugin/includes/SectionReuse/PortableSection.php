<?php
/**
 * The portable form of a section: what extract returns and what an insert operation accepts.
 * SPDX-License-Identifier: GPL-2.0-or-later
 *
 * @package Stonewright
 */

declare( strict_types=1 );

namespace Stonewright\WpMcp\SectionReuse;

use Stonewright\WpMcp\Elementor\Provider\ProviderRouter;

/**
 * `SectionPortableV1` is a section in its own builder's format with nothing that ties it to its source:
 *
 * - Elementor V3 and V4: one `element` whose element ids are replaced by placeholders `ph-1`, `ph-2`, ... in
 *   document order, and, for V4, whose local style ids are replaced by `ls-1`, `ls-2`, ...;
 * - Gutenberg: the `blocks` as the block parser returns them, with the anchors listed under `anchors`.
 *
 * Every other value, text and unknown setting included, is carried exactly as it is stored. A placeholder is
 * only a name inside the payload: an insert gives each one a fresh id and lets later operations of the same
 * batch address it as `@<op_id>.<placeholder>`.
 */
final class PortableSection {

	public const SCHEMA = 'SectionPortableV1';

	/** Largest payload, in bytes of JSON. */
	public const MAX_BYTES = 262144;

	/** Most blocks a Gutenberg payload may hold at its top level. */
	private const MAX_TOP_BLOCKS = 50;

	private const ID_PATTERN = '/^[A-Za-z0-9_-]{1,64}$/D';

	// ---------------------------------------------------------------- extract

	/**
	 * Builds the portable form of a section of a source post.
	 *
	 * @param array{builder:string,index:int,locator:array<string,mixed>,node:array<string,mixed>} $section One entry of {@see SectionSource::sections()}.
	 * @return array{section:array<string,mixed>,inspection:array<string,mixed>,layout:array<string,mixed>,stats:array<string,int>,migrated:list<array{block:string,key:string,to:string}>}|\WP_Error
	 */
	public static function extract( object $post, array $section ): array|\WP_Error {
		$limits  = ProviderRouter::element_limits();
		$builder = $section['builder'];
		$payload = [
			'schema'  => self::SCHEMA,
			'builder' => $builder,
			'source'  => [ 'post_id' => (int) $post->ID, 'locator' => self::source_locator( $section['locator'] ) ],
		];
		$placeholders = 0;
		$migrated     = [];

		if ( Builder::GUTENBERG === $builder ) {
			$moved             = LegacyBlockAttributes::migrate( self::gutenberg_blocks( $post, $section ) );
			$blocks            = $moved['blocks'];
			$migrated          = $moved['migrated'];
			$payload['blocks'] = $blocks;
			$inspection        = self::inspect_blocks( $blocks, $limits );
			$payload['anchors'] = $inspection['anchors'];
			$layout            = LayoutSummary::of( $builder, 1 === count( $blocks ) ? $blocks[0] : [ 'blockName' => 'core/group', 'attrs' => [], 'innerBlocks' => $blocks ], $limits );
		} else {
			$state                 = [ 'n' => 0, 'styles' => 0, 'max' => (int) $limits['max_elements'], 'over' => false ];
			$element               = self::placeholder_element( $section['node'], $state, 0, (int) $limits['max_depth'] );
			if ( $state['over'] || null === $element ) {
				return self::too_large( 'elements', $state['n'], (int) $limits['max_elements'] );
			}
			$payload['element']    = $element;
			$placeholders          = $state['n'];
			$inspection            = SectionInspector::inspect( $builder, $element, $limits );
			$layout                = LayoutSummary::of( $builder, $element, $limits );
		}

		$bytes = strlen( (string) wp_json_encode( $payload ) );
		if ( $bytes > self::MAX_BYTES ) {
			return self::too_large( 'bytes', $bytes, self::MAX_BYTES );
		}

		return [
			'section'    => $payload,
			'inspection' => $inspection,
			'layout'     => $layout,
			'stats'      => [ 'elements' => Builder::GUTENBERG === $builder ? 0 : $layout['elements'], 'blocks' => Builder::GUTENBERG === $builder ? $layout['elements'] : 0, 'placeholders' => $placeholders, 'bytes' => $bytes ],
			'migrated'   => $migrated,
		];
	}

	/**
	 * The blocks of a Gutenberg section. A synced pattern stays a reference: the payload is one `core/block`
	 * that points at the pattern, and an insert keeps it a reference unless the operation asks to detach it.
	 *
	 * @param array{builder:string,index:int,locator:array<string,mixed>,node:array<string,mixed>} $section
	 * @return list<array<string, mixed>>
	 */
	private static function gutenberg_blocks( object $post, array $section ): array {
		if ( 'pattern' !== (string) $section['locator']['kind'] ) {
			return [ $section['node'] ];
		}
		if ( SectionSource::is_synced_pattern( $post ) ) {
			return [ [ 'blockName' => 'core/block', 'attrs' => [ 'ref' => (int) $post->ID ], 'innerBlocks' => [], 'innerHTML' => '', 'innerContent' => [] ] ];
		}

		return \Stonewright\WpMcp\Support\BlockTree::parse( (string) $post->post_content );
	}

	/**
	 * The inspection of every top-level block of a payload, merged.
	 *
	 * @param list<array<string, mixed>>            $blocks
	 * @param array{max_elements:int,max_depth:int} $limits
	 * @return array<string, mixed>
	 */
	public static function inspect_blocks( array $blocks, array $limits ): array {
		$merged = [ 'outline' => [ 'heading' => '', 'headings' => 0, 'images' => 0, 'buttons' => 0, 'forms' => 0 ], 'references' => [], 'flags' => [], 'signals' => [], 'anchors' => [], 'capped' => false ];
		$refs   = [];
		foreach ( $blocks as $index => $block ) {
			$one = SectionInspector::inspect( Builder::GUTENBERG, $block, $limits, (int) $index );
			if ( '' === $merged['outline']['heading'] ) {
				$merged['outline']['heading'] = $one['outline']['heading'];
			}
			foreach ( [ 'headings', 'images', 'buttons', 'forms' ] as $key ) {
				$merged['outline'][ $key ] += $one['outline'][ $key ];
			}
			foreach ( $one['references'] as $reference ) {
				$key = $reference['type'] . ':' . $reference['id'];
				if ( ! isset( $refs[ $key ] ) ) {
					$refs[ $key ] = $reference;
					continue;
				}
				$refs[ $key ]['count'] += $reference['count'];
				$refs[ $key ]['at']     = array_slice( array_values( array_unique( array_merge( $refs[ $key ]['at'], $reference['at'] ) ) ), 0, 5 );
			}
			foreach ( $one['signals'] as $key => $count ) {
				$merged['signals'][ $key ] = ( $merged['signals'][ $key ] ?? 0 ) + $count;
			}
			$merged['anchors'] = array_merge( $merged['anchors'], $one['anchors'] );
			$merged['capped']  = $merged['capped'] || $one['capped'];
		}
		ksort( $refs );
		$merged['references'] = array_values( $refs );
		$flags                = [];
		foreach ( $merged['references'] as $reference ) {
			$flags[ self::flag_for( $reference['type'] ) ] = true;
		}
		unset( $flags[''] );
		$merged['flags'] = array_keys( $flags );
		sort( $merged['flags'] );

		return $merged;
	}

	private static function flag_for( string $type ): string {
		return [
			'dynamic_tag'        => 'dynamic_tags',
			'form'               => 'forms',
			'global_widget'      => 'global_widgets',
			'synced_pattern'     => 'synced_patterns',
			'third_party_widget' => 'third_party_widgets',
			'nested_template'    => 'nested_templates',
		][ $type ] ?? '';
	}

	/**
	 * Copies an element with placeholders for its id, its children's ids and, for V4, its local style ids.
	 *
	 * @param array<string, mixed>                                      $element
	 * @param array{n:int,styles:int,max:int,over:bool}                 $state
	 * @return array<string, mixed>|null Null when the element cap or the depth cap stopped the copy.
	 */
	private static function placeholder_element( array $element, array &$state, int $depth, int $max_depth ): ?array {
		if ( $state['n'] >= $state['max'] || $depth >= $max_depth ) {
			$state['over'] = true;
			return null;
		}
		++$state['n'];
		$new_id = 'ph-' . $state['n'];
		$copy   = $element;
		$copy['id'] = $new_id;

		if ( Builder::is_atomic( $element ) && is_array( $element['styles'] ?? null ) && [] !== $element['styles'] ) {
			$map    = [];
			$styles = [];
			foreach ( $element['styles'] as $old => $style ) {
				$map[ (string) $old ] = 'ls-' . ( ++$state['styles'] );
			}
			foreach ( $element['styles'] as $old => $style ) {
				$new = $map[ (string) $old ];
				if ( is_array( $style ) && isset( $style['id'] ) && (string) $style['id'] === (string) $old ) {
					$style['id'] = $new;
				}
				$styles[ $new ] = $style;
			}
			$copy['styles']   = $styles;
			$copy['settings'] = self::remap_classes( is_array( $element['settings'] ?? null ) ? $element['settings'] : [], $map );
		}

		$children = [];
		foreach ( is_array( $element['elements'] ?? null ) ? $element['elements'] : [] as $child ) {
			if ( ! is_array( $child ) ) {
				continue;
			}
			$next = self::placeholder_element( $child, $state, $depth + 1, $max_depth );
			if ( null === $next ) {
				return null;
			}
			$children[] = $next;
		}
		$copy['elements'] = $children;

		return $copy;
	}

	/**
	 * Replaces local style ids in an element's class list; global class ids stay as they are.
	 *
	 * @param array<string, mixed>  $settings
	 * @param array<string, string> $map      Old local id to new local id.
	 * @return array<string, mixed>
	 */
	public static function remap_classes( array $settings, array $map ): array {
		if ( is_array( $settings['classes'] ?? null ) && is_array( $settings['classes']['value'] ?? null ) ) {
			$settings['classes']['value'] = array_map(
				static fn( mixed $class ): mixed => is_string( $class ) && isset( $map[ $class ] ) ? $map[ $class ] : $class,
				array_values( $settings['classes']['value'] )
			);
		}

		return $settings;
	}

	/**
	 * What a payload records about where it came from: the post and the section locator, nothing else.
	 *
	 * @param array<string, mixed> $locator
	 * @return array<string, mixed>
	 */
	private static function source_locator( array $locator ): array {
		$out = [ 'kind' => (string) $locator['kind'] ];
		foreach ( [ 'id', 'path', 'anchor', 'synced' ] as $key ) {
			if ( array_key_exists( $key, $locator ) ) {
				$out[ $key ] = $locator[ $key ];
			}
		}

		return $out;
	}

	private static function too_large( string $what, int $have, int $limit ): \WP_Error {
		return new \WP_Error(
			'stonewright_section_too_large',
			'The section is too large to copy in one piece.',
			[ 'status' => 413, 'limit_kind' => $what, 'size' => $have, 'limit' => $limit ]
		);
	}

	// ---------------------------------------------------------------- validate

	/**
	 * Checks a payload an insert operation received and returns it normalized. The payload is untrusted: it is
	 * checked for shape and size only. Nothing in it is trusted to name a real element, post or style.
	 *
	 * @param array{max_elements?:int,max_depth?:int}|null $limits
	 * @return array<string, mixed>|\WP_Error
	 */
	public static function validate( mixed $payload, string $builder, ?array $limits = null ): array|\WP_Error {
		$limits = array_merge( ProviderRouter::element_limits(), $limits ?? [] );
		if ( ! is_array( $payload ) || self::SCHEMA !== ( $payload['schema'] ?? null ) ) {
			return self::invalid( 'The section must be a ' . self::SCHEMA . ' payload returned by stonewright-section-reuse-extract.' );
		}
		if ( ( $payload['builder'] ?? null ) !== $builder ) {
			return new \WP_Error(
				'stonewright_section_builder_mismatch',
				'This section belongs to a different builder; reuse never converts between builders.',
				[ 'status' => 409, 'section_builder' => is_string( $payload['builder'] ?? null ) ? $payload['builder'] : '', 'target_builder' => $builder ]
			);
		}
		if ( strlen( (string) wp_json_encode( $payload ) ) > self::MAX_BYTES ) {
			return self::too_large( 'bytes', strlen( (string) wp_json_encode( $payload ) ), self::MAX_BYTES );
		}
		$source = self::normalize_source( $payload['source'] ?? null );
		if ( $source instanceof \WP_Error ) {
			return $source;
		}
		$out = [ 'schema' => self::SCHEMA, 'builder' => $builder ];
		if ( null !== $source ) {
			$out['source'] = $source;
		}
		if ( Builder::GUTENBERG === $builder ) {
			$blocks = self::validate_blocks( $payload['blocks'] ?? null, $limits );
			if ( $blocks instanceof \WP_Error ) {
				return $blocks;
			}
			$out['blocks'] = $blocks;
			return $out;
		}
		$count   = 0;
		$ids     = [];
		$element = self::validate_element( $payload['element'] ?? null, $builder, $limits, 1, $count, $ids );
		if ( $element instanceof \WP_Error ) {
			return $element;
		}
		$out['element'] = $element;

		return $out;
	}

	/**
	 * @return array{post_id:int,locator:array<string,mixed>}|\WP_Error|null
	 */
	private static function normalize_source( mixed $source ): array|\WP_Error|null {
		if ( null === $source ) {
			return null;
		}
		if ( ! is_array( $source ) || ! is_numeric( $source['post_id'] ?? null ) || (int) $source['post_id'] < 1 || ! is_array( $source['locator'] ?? null ) ) {
			return self::invalid( 'The section source needs a post_id and a locator.' );
		}
		$locator = [ 'kind' => is_string( $source['locator']['kind'] ?? null ) ? substr( $source['locator']['kind'], 0, 16 ) : '' ];
		if ( ! in_array( $locator['kind'], [ 'element', 'block', 'pattern' ], true ) ) {
			return self::invalid( 'The section source locator kind must be element, block or pattern.' );
		}
		if ( isset( $source['locator']['id'] ) && is_string( $source['locator']['id'] ) ) {
			$locator['id'] = substr( $source['locator']['id'], 0, 64 );
		}
		if ( isset( $source['locator']['path'] ) && is_array( $source['locator']['path'] ) ) {
			$locator['path'] = array_slice( array_values( array_map( 'intval', $source['locator']['path'] ) ), 0, 12 );
		}
		if ( isset( $source['locator']['anchor'] ) && is_string( $source['locator']['anchor'] ) ) {
			$locator['anchor'] = substr( $source['locator']['anchor'], 0, 64 );
		}

		return [ 'post_id' => (int) $source['post_id'], 'locator' => $locator ];
	}

	/**
	 * @param array{max_elements:int,max_depth:int} $limits
	 * @param array<string, true>                   $ids
	 * @return array<string, mixed>|\WP_Error
	 */
	private static function validate_element( mixed $element, string $builder, array $limits, int $depth, int &$count, array &$ids ): array|\WP_Error {
		if ( ! is_array( $element ) ) {
			return self::invalid( 'The section needs an element.' );
		}
		if ( $depth > $limits['max_depth'] ) {
			return self::too_large( 'depth', $depth, $limits['max_depth'] );
		}
		if ( ++$count > $limits['max_elements'] ) {
			return self::too_large( 'elements', $count, $limits['max_elements'] );
		}
		$id = $element['id'] ?? null;
		if ( ! is_string( $id ) || 1 !== preg_match( self::ID_PATTERN, $id ) || isset( $ids[ $id ] ) ) {
			return self::invalid( 'Every element needs a unique id of letters, digits, hyphens and underscores (the placeholder returned by extract).' );
		}
		$ids[ $id ] = true;
		$el_type    = $element['elType'] ?? null;
		if ( ! is_string( $el_type ) || '' === $el_type ) {
			return self::invalid( 'Element ' . $id . ' has no elType.' );
		}
		if ( 'widget' === $el_type && ( ! is_string( $element['widgetType'] ?? null ) || '' === $element['widgetType'] ) ) {
			return self::invalid( 'Widget ' . $id . ' has no widgetType.' );
		}
		$atomic = Builder::is_atomic( $element );
		if ( ( Builder::ELEMENTOR_V4 === $builder ) !== $atomic ) {
			return new \WP_Error(
				'stonewright_section_builder_mismatch',
				'The section mixes V3 and V4 elements; reuse never converts between them.',
				[ 'status' => 409, 'element' => $id, 'element_type' => Builder::element_type( $element ), 'target_builder' => $builder ]
			);
		}
		if ( array_key_exists( 'settings', $element ) && ! is_array( $element['settings'] ) ) {
			return self::invalid( 'Settings of element ' . $id . ' must be an object.' );
		}
		if ( array_key_exists( 'styles', $element ) && ! is_array( $element['styles'] ) ) {
			return self::invalid( 'Styles of element ' . $id . ' must be an object.' );
		}
		$children = [];
		if ( array_key_exists( 'elements', $element ) && ! is_array( $element['elements'] ) ) {
			return self::invalid( 'Children of element ' . $id . ' must be a list.' );
		}
		foreach ( array_values( (array) ( $element['elements'] ?? [] ) ) as $child ) {
			$checked = self::validate_element( $child, $builder, $limits, $depth + 1, $count, $ids );
			if ( $checked instanceof \WP_Error ) {
				return $checked;
			}
			$children[] = $checked;
		}
		$element['elements'] = $children;

		return $element;
	}

	/**
	 * @param array{max_elements:int,max_depth:int} $limits
	 * @return list<array<string, mixed>>|\WP_Error
	 */
	private static function validate_blocks( mixed $blocks, array $limits ): array|\WP_Error {
		if ( ! is_array( $blocks ) || [] === $blocks || ! array_is_list( $blocks ) || count( $blocks ) > self::MAX_TOP_BLOCKS ) {
			return self::invalid( 'The section needs a list of one to ' . self::MAX_TOP_BLOCKS . ' blocks.' );
		}
		$count = 0;
		$out   = [];
		foreach ( $blocks as $block ) {
			$checked = self::validate_block( $block, $limits, 1, $count );
			if ( $checked instanceof \WP_Error ) {
				return $checked;
			}
			$out[] = $checked;
		}

		return $out;
	}

	/**
	 * @param array{max_elements:int,max_depth:int} $limits
	 * @return array<string, mixed>|\WP_Error
	 */
	private static function validate_block( mixed $block, array $limits, int $depth, int &$count ): array|\WP_Error {
		if ( ! is_array( $block ) || ! is_string( $block['blockName'] ?? null ) || '' === $block['blockName'] ) {
			return self::invalid( 'Every block needs a blockName.' );
		}
		if ( $depth > $limits['max_depth'] ) {
			return self::too_large( 'depth', $depth, $limits['max_depth'] );
		}
		if ( ++$count > $limits['max_elements'] ) {
			return self::too_large( 'blocks', $count, $limits['max_elements'] );
		}
		if ( array_key_exists( 'attrs', $block ) && ! is_array( $block['attrs'] ) ) {
			return self::invalid( 'Block attrs must be an object.' );
		}
		if ( array_key_exists( 'innerHTML', $block ) && ! is_string( $block['innerHTML'] ) ) {
			return self::invalid( 'Block innerHTML must be a string.' );
		}
		if ( array_key_exists( 'innerBlocks', $block ) && ! is_array( $block['innerBlocks'] ) ) {
			return self::invalid( 'Block innerBlocks must be a list.' );
		}
		$children = [];
		foreach ( array_values( (array) ( $block['innerBlocks'] ?? [] ) ) as $child ) {
			$checked = self::validate_block( $child, $limits, $depth + 1, $count );
			if ( $checked instanceof \WP_Error ) {
				return $checked;
			}
			$children[] = $checked;
		}
		$block['innerBlocks'] = $children;
		$block['attrs']       = is_array( $block['attrs'] ?? null ) ? $block['attrs'] : [];
		$block['innerHTML']   = (string) ( $block['innerHTML'] ?? '' );

		return $block;
	}

	private static function invalid( string $message ): \WP_Error {
		return new \WP_Error( 'stonewright_section_invalid', $message, [ 'status' => 400 ] );
	}
}
