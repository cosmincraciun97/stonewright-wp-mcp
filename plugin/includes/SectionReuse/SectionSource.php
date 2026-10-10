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
 * Reads the top-level sections of a page, post, Elementor saved template or Gutenberg pattern. A top-level
 * Elementor element is a section; so is a top-level block. A Gutenberg pattern is one section, the whole
 * pattern. An Elementor section that mixes V3 and V4 nodes belongs to neither builder family and is left out,
 * because section reuse never converts between them. It reads only; no post is changed.
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
		$kind = (string) ( $locator['kind'] ?? '' );
		foreach ( self::sections( $post ) as $section ) {
			$have = $section['locator'];
			if ( $kind !== (string) $have['kind'] ) {
				continue;
			}
			if ( 'element' === $kind && (string) ( $locator['id'] ?? '' ) === (string) $have['id'] ) {
				return $section;
			}
			if ( 'pattern' === $kind ) {
				return $section;
			}
			if ( 'block' === $kind ) {
				$path = isset( $locator['path'] ) && is_array( $locator['path'] ) ? array_values( array_map( 'intval', $locator['path'] ) ) : null;
				if ( null !== $path && $path === $have['path'] ) {
					return $section;
				}
				if ( null === $path && '' !== (string) ( $locator['anchor'] ?? '' ) && (string) $locator['anchor'] === (string) ( $have['anchor'] ?? '' ) ) {
					return $section;
				}
			}
		}

		return null;
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
