<?php
/**
 * The sections a source post holds, in its builder's own format.
 * SPDX-License-Identifier: GPL-2.0-or-later
 *
 * @package Stonewright
 */

declare( strict_types=1 );

namespace Stonewright\WpMcp\SectionReuse;

use Stonewright\WpMcp\Elementor\V4\AtomicTreeInspector;
use Stonewright\WpMcp\Support\BlockTree;
use Stonewright\WpMcp\Support\ElementorData;

/**
 * Reads the sections of a page, post, Elementor saved template or Gutenberg pattern. A top-level Elementor
 * element is a section; so is a top-level block. A container nested inside an Elementor section is one too when
 * it is a valid copy root ({@see NestedContainers}); it is addressed by its element id. A Gutenberg pattern is
 * one section, the whole pattern. An Elementor section that mixes V3 and V4 nodes belongs to neither builder
 * family and is left out, because section reuse never converts between them. It reads only; no post is changed.
 */
final class SectionSource {

	public const KIND_PAGE     = 'page';
	public const KIND_TEMPLATE = 'template';
	public const KIND_PATTERN  = 'pattern';
	/** A customized template or template part of a block theme. */
	public const KIND_SITE_TEMPLATE = 'site-template';

	public const TEMPLATE_POST_TYPE = 'elementor_library';
	public const PATTERN_POST_TYPE  = 'wp_block';

	/** Post types a block theme's Site Editor saves a customized template or template part in. */
	public const SITE_TEMPLATE_POST_TYPES = [ 'wp_template', 'wp_template_part' ];

	/** Elementor saved-template types that hold a section. */
	private const SECTION_TEMPLATE_TYPES = [ 'section', 'container' ];

	/** Post statuses a source may have. Trashed posts and automatic drafts never are. */
	public const SOURCE_STATUSES = [ 'publish', 'draft', 'pending', 'future', 'private' ];

	/** Post types that are never a source. */
	private const EXCLUDED_POST_TYPES = [ 'revision', 'attachment', 'auto-draft' ];

	/** Whether a post is of a kind and in a status a source may have. Who may use it is {@see SourceScanner::may_use()}. */
	public static function is_source( mixed $post ): bool {
		return is_object( $post )
			&& in_array( self::field( $post, 'post_status' ), self::SOURCE_STATUSES, true )
			&& ! in_array( self::field( $post, 'post_type' ), self::EXCLUDED_POST_TYPES, true );
	}

	/** @return 'page'|'template'|'pattern'|'site-template' */
	public static function kind( object $post ): string {
		return match ( self::field( $post, 'post_type' ) ) {
			self::TEMPLATE_POST_TYPE => self::KIND_TEMPLATE,
			self::PATTERN_POST_TYPE  => self::KIND_PATTERN,
			'wp_template', 'wp_template_part' => self::KIND_SITE_TEMPLATE,
			default                  => self::KIND_PAGE,
		};
	}

	/** A string property of a post object, or an empty string when it has none. */
	public static function field( object $post, string $name ): string {
		return isset( $post->{$name} ) && is_scalar( $post->{$name} ) ? (string) $post->{$name} : '';
	}

	/** The time a post was last modified, GMT when the post has it. */
	public static function modified( object $post ): string {
		$gmt = self::field( $post, 'post_modified_gmt' );

		return '' !== $gmt ? $gmt : self::field( $post, 'post_modified' );
	}

	/** Whether the post is built with Elementor. */
	public static function is_elementor( object $post ): bool {
		return 'builder' === (string) get_post_meta( (int) $post->ID, '_elementor_edit_mode', true );
	}

	/** Whether the post is a pattern that stays linked to its uses. */
	public static function is_synced_pattern( object $post ): bool {
		return self::PATTERN_POST_TYPE === self::field( $post, 'post_type' ) && 'unsynced' !== (string) get_post_meta( (int) $post->ID, 'wp_pattern_sync_status', true );
	}

	/** The version of the builder that saved the post; part of the signature cache key. */
	public static function builder_version( object $post ): string {
		if ( self::is_elementor( $post ) ) {
			return 'elementor:' . (string) get_post_meta( (int) $post->ID, '_elementor_version', true );
		}

		return 'gutenberg:' . (string) get_bloginfo( 'version' );
	}

	/**
	 * Every section of the post that belongs to a builder family.
	 *
	 * @return list<array{builder:string,index:int,locator:array<string,mixed>,node:array<string,mixed>}>
	 */
	public static function sections( object $post ): array {
		if ( self::is_elementor( $post ) ) {
			return self::elementor_sections( $post );
		}

		return self::block_sections( $post );
	}

	/**
	 * One section of the post by its locator, or null when it is not there.
	 *
	 * @param array<string, mixed> $locator `{kind:'element', id}`, `{kind:'block', path|anchor}` or `{kind:'pattern'}`.
	 * @return array{builder:string,index:int,locator:array<string,mixed>,node:array<string,mixed>}|null
	 */
	public static function find( object $post, array $locator ): ?array {
		return self::resolve( $post, $locator )['section'];
	}

	/**
	 * One section of the post by its locator, with the reason when there is none.
	 *
	 * An element id matches a top-level element first, then a container nested at any depth. `problem` says what
	 * was wrong, so the caller can answer with more than "not found":
	 *
	 * - `no_document`: the locator names an Elementor element and the post has no valid Elementor document
	 *   (`reason` is `not_built_with_elementor`, `elementor_mode_missing` or `document_empty_or_unreadable`);
	 * - `not_copyable`: the element exists, but is not a valid copy root (`reason` is `widget`, `column`,
	 *   `unsupported` or `mixed_builders`, with its `element_type`);
	 * - `not_found`: nothing matches (`reason` is `template_type`, `unknown_id` or `no_match`).
	 *
	 * @param array<string, mixed> $locator
	 * @return array{section:array{builder:string,index:int,locator:array<string,mixed>,node:array<string,mixed>}|null,problem:array<string,string>|null}
	 */
	public static function resolve( object $post, array $locator ): array {
		$kind = (string) ( $locator['kind'] ?? '' );
		if ( 'element' === $kind ) {
			return self::resolve_element( $post, (string) ( $locator['id'] ?? '' ) );
		}
		foreach ( self::sections( $post ) as $section ) {
			$have = $section['locator'];
			if ( $kind !== (string) $have['kind'] ) {
				continue;
			}
			if ( 'pattern' === $kind ) {
				return [ 'section' => $section, 'problem' => null ];
			}
			if ( 'block' === $kind ) {
				$path = isset( $locator['path'] ) && is_array( $locator['path'] ) ? array_values( array_map( 'intval', $locator['path'] ) ) : null;
				if ( null !== $path && $path === $have['path'] ) {
					return [ 'section' => $section, 'problem' => null ];
				}
				if ( null === $path && '' !== (string) ( $locator['anchor'] ?? '' ) && (string) $locator['anchor'] === (string) ( $have['anchor'] ?? '' ) ) {
					return [ 'section' => $section, 'problem' => null ];
				}
			}
		}

		return [ 'section' => null, 'problem' => [ 'code' => 'not_found', 'reason' => 'no_match' ] ];
	}

	/**
	 * @return array{section:array<string,mixed>|null,problem:array<string,string>|null}
	 */
	private static function resolve_element( object $post, string $id ): array {
		if ( ! self::is_elementor( $post ) ) {
			$reason = self::has_elementor_data( $post ) ? 'elementor_mode_missing' : 'not_built_with_elementor';

			return [ 'section' => null, 'problem' => [ 'code' => 'no_document', 'reason' => $reason ] ];
		}
		if ( self::TEMPLATE_POST_TYPE === (string) $post->post_type
			&& ! in_array( (string) get_post_meta( (int) $post->ID, '_elementor_template_type', true ), self::SECTION_TEMPLATE_TYPES, true ) ) {
			return [ 'section' => null, 'problem' => [ 'code' => 'not_found', 'reason' => 'template_type' ] ];
		}
		$tree = array_values( array_filter( ElementorData::read( (int) $post->ID ), static fn( mixed $element ): bool => is_array( $element ) && '' !== (string) ( $element['id'] ?? '' ) ) );
		if ( [] === $tree ) {
			return [ 'section' => null, 'problem' => [ 'code' => 'no_document', 'reason' => 'document_empty_or_unreadable' ] ];
		}
		foreach ( self::elementor_sections( $post ) as $section ) {
			if ( $id === (string) $section['locator']['id'] ) {
				return [ 'section' => $section, 'problem' => null ];
			}
		}
		$path = '' === $id ? null : ElementorData::find_path( $tree, $id );
		$node = null === $path ? null : ElementorData::element_at( $tree, $path );
		if ( null === $path || null === $node ) {
			return [ 'section' => null, 'problem' => [ 'code' => 'not_found', 'reason' => 'unknown_id' ] ];
		}
		$type    = Builder::element_type( $node );
		$refused = static fn( string $reason ): array => [ 'section' => null, 'problem' => [ 'code' => 'not_copyable', 'reason' => $reason, 'element_type' => $type, 'element_id' => $id ] ];
		$builder = match ( (string) ( AtomicTreeInspector::inspect( [ $node ] )['architecture'] ?? 'empty' ) ) {
			'v3'    => Builder::ELEMENTOR_V3,
			'v4'    => Builder::ELEMENTOR_V4,
			default => '',
		};
		if ( '' === $builder ) {
			return $refused( 'mixed_builders' );
		}
		$problem = NestedContainers::root_problem( $node, $builder );
		if ( null !== $problem ) {
			return $refused( $problem );
		}

		return [
			'section' => [
				'builder' => $builder,
				'index'   => (int) $path[0],
				'locator' => [ 'kind' => 'element', 'id' => $id ],
				'node'    => $node,
			],
			'problem' => null,
		];
	}

	/** Whether the post stores Elementor data, whatever its edit mode says. */
	private static function has_elementor_data( object $post ): bool {
		$data = get_post_meta( (int) $post->ID, '_elementor_data', true );

		return is_array( $data ) ? [] !== $data : ( is_scalar( $data ) && '' !== (string) $data );
	}

	/** @return list<array{builder:string,index:int,locator:array<string,mixed>,node:array<string,mixed>}> */
	private static function elementor_sections( object $post ): array {
		if ( self::TEMPLATE_POST_TYPE === (string) $post->post_type
			&& ! in_array( (string) get_post_meta( (int) $post->ID, '_elementor_template_type', true ), self::SECTION_TEMPLATE_TYPES, true ) ) {
			return [];
		}
		$out = [];
		foreach ( ElementorData::read( (int) $post->ID ) as $index => $element ) {
			if ( ! is_array( $element ) || '' === (string) ( $element['id'] ?? '' ) ) {
				continue;
			}
			$architecture = (string) ( AtomicTreeInspector::inspect( [ $element ] )['architecture'] ?? 'empty' );
			$builder      = match ( $architecture ) {
				'v3'    => Builder::ELEMENTOR_V3,
				'v4'    => Builder::ELEMENTOR_V4,
				default => '',
			};
			if ( '' === $builder ) {
				continue;
			}
			$out[] = [
				'builder' => $builder,
				'index'   => (int) $index,
				'locator' => [ 'kind' => 'element', 'id' => (string) $element['id'] ],
				'node'    => $element,
			];
		}

		return $out;
	}

	/** @return list<array{builder:string,index:int,locator:array<string,mixed>,node:array<string,mixed>}> */
	private static function block_sections( object $post ): array {
		$blocks = BlockTree::parse( (string) $post->post_content );
		if ( self::KIND_PATTERN === self::kind( $post ) ) {
			if ( [] === $blocks ) {
				return [];
			}
			$node = 1 === count( $blocks ) ? $blocks[0] : [ 'blockName' => 'core/group', 'attrs' => [], 'innerBlocks' => $blocks, 'innerHTML' => '', 'innerContent' => array_fill( 0, count( $blocks ), null ) ];

			return [ [ 'builder' => Builder::GUTENBERG, 'index' => 0, 'locator' => [ 'kind' => 'pattern', 'synced' => self::is_synced_pattern( $post ) ], 'node' => $node ] ];
		}
		$out = [];
		foreach ( $blocks as $index => $block ) {
			if ( ! is_string( $block['blockName'] ?? null ) || '' === $block['blockName'] ) {
				continue;
			}
			$locator = [ 'kind' => 'block', 'path' => [ (int) $index ] ];
			$anchor  = is_array( $block['attrs'] ?? null ) ? ( $block['attrs']['anchor'] ?? null ) : null;
			if ( is_string( $anchor ) && '' !== $anchor ) {
				$locator['anchor'] = $anchor;
			}
			$out[] = [ 'builder' => Builder::GUTENBERG, 'index' => (int) $index, 'locator' => $locator, 'node' => $block ];
		}

		return $out;
	}
}
