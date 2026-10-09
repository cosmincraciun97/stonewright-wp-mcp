<?php
/**
 * Moves block attributes that WordPress itself migrates into the form it uses today.
 * SPDX-License-Identifier: GPL-2.0-or-later
 *
 * @package Stonewright
 */

declare( strict_types=1 );

namespace Stonewright\WpMcp\SectionReuse;

/**
 * A page saved by an older WordPress can hold an attribute that the block no longer declares, so the strict
 * attribute check of a block write would refuse the whole section. The block editor moves such an attribute
 * when it opens the block; this class makes the same move for an explicit, short list, and nothing else.
 *
 * The list is the text alignment of the blocks whose deprecations move `textAlign` to
 * `style.typography.textAlign`. A block is only changed when the live site registers it with the alignment as
 * a typography support and without a `textAlign` attribute of its own, so a site that still declares the
 * attribute keeps it. The saved markup of the block is carried as it is: the current support writes the same
 * `has-text-align-*` class the older attribute wrote. An attribute that is not on the list is not touched; the
 * insert keeps refusing it, naming the key.
 */
final class LegacyBlockAttributes {

	public const WARNING = 'legacy_attributes';

	private const MAX_ITEMS = 5;

	/** @var list<string> Blocks whose `textAlign` attribute core moves to `style.typography.textAlign`. */
	public const TEXT_ALIGN_BLOCKS = [
		'core/button',
		'core/comment-author-name',
		'core/comment-content',
		'core/comment-edit-link',
		'core/comment-reply-link',
		'core/comments-title',
		'core/heading',
		'core/post-author-biography',
		'core/post-author-name',
		'core/post-comments-count',
		'core/post-comments-form',
		'core/post-comments-link',
		'core/post-date',
		'core/post-excerpt',
		'core/post-navigation-link',
		'core/post-terms',
		'core/post-time-to-read',
		'core/post-title',
		'core/pullquote',
		'core/query-title',
		'core/site-tagline',
		'core/site-title',
		'core/term-description',
		'core/term-name',
		'core/verse',
	];

	/**
	 * The blocks with every listed attribute moved, at any depth.
	 *
	 * @param array<int, array<string, mixed>> $blocks Blocks as the block parser returns them.
	 * @return array{blocks:array<int,array<string,mixed>>,migrated:list<array{block:string,key:string,to:string}>}
	 */
	public static function migrate( array $blocks ): array {
		$migrated = [];
		$cache    = [];

		return [ 'blocks' => self::walk( $blocks, $migrated, $cache ), 'migrated' => $migrated ];
	}

	/**
	 * The warnings that report a migration, for the response of an extract or an insert.
	 *
	 * @param list<array{block:string,key:string,to:string}> $migrated
	 * @return list<array{code:string,count:int,items:list<string>}>
	 */
	public static function warnings( array $migrated ): array {
		if ( [] === $migrated ) {
			return [];
		}
		$items = [];
		foreach ( $migrated as $move ) {
			$items[ $move['block'] . ': ' . $move['key'] . ' -> ' . $move['to'] ] = true;
		}

		return [ [ 'code' => self::WARNING, 'count' => count( $migrated ), 'items' => array_slice( array_keys( $items ), 0, self::MAX_ITEMS ) ] ];
	}

	/**
	 * @param array<int, array<string, mixed>>                     $blocks
	 * @param list<array{block:string,key:string,to:string}>        $migrated
	 * @param array<string, bool>                                   $cache    Block name to whether it takes the move here.
	 * @return array<int, array<string, mixed>>
	 */
	private static function walk( array $blocks, array &$migrated, array &$cache ): array {
		foreach ( $blocks as $index => $block ) {
			if ( ! is_array( $block ) ) {
				continue;
			}
			$name  = is_string( $block['blockName'] ?? null ) ? $block['blockName'] : '';
			$attrs = is_array( $block['attrs'] ?? null ) ? $block['attrs'] : [];
			if ( in_array( $name, self::TEXT_ALIGN_BLOCKS, true ) && self::has_text( $attrs, 'textAlign' ) ) {
				$cache[ $name ] ??= self::takes_style_text_align( $name );
				$style           = $attrs['style'] ?? [];
				$typography      = is_array( $style ) ? ( $style['typography'] ?? [] ) : null;
				if ( $cache[ $name ] && is_array( $style ) && is_array( $typography ) ) {
					$typography['textAlign'] = $attrs['textAlign'];
					$style['typography']     = $typography;
					unset( $attrs['textAlign'] );
					$attrs['style']          = $style;
					$block['attrs']          = $attrs;
					$migrated[]              = [ 'block' => $name, 'key' => 'textAlign', 'to' => 'style.typography.textAlign' ];
				}
			}
			if ( is_array( $block['innerBlocks'] ?? null ) && [] !== $block['innerBlocks'] ) {
				$block['innerBlocks'] = self::walk( $block['innerBlocks'], $migrated, $cache );
			}
			$blocks[ $index ] = $block;
		}

		return $blocks;
	}

	/** @param array<string, mixed> $attrs */
	private static function has_text( array $attrs, string $key ): bool {
		return isset( $attrs[ $key ] ) && is_string( $attrs[ $key ] ) && '' !== $attrs[ $key ];
	}

	/**
	 * Whether this site registers the block with the alignment as a typography support and no `textAlign`
	 * attribute. A block that is not registered, or a registry that cannot be read, answers no.
	 */
	private static function takes_style_text_align( string $name ): bool {
		if ( ! class_exists( '\WP_Block_Type_Registry' ) || ! method_exists( '\WP_Block_Type_Registry', 'get_instance' ) ) {
			return false;
		}
		try {
			$registered = \WP_Block_Type_Registry::get_instance()->get_registered( $name );
		} catch ( \Throwable $failure ) {
			unset( $failure );
			return false;
		}
		if ( ! is_object( $registered ) ) {
			return false;
		}
		$attributes = isset( $registered->attributes ) && is_array( $registered->attributes ) ? $registered->attributes : [];
		$supports   = isset( $registered->supports ) && is_array( $registered->supports ) ? $registered->supports : [];
		$typography = isset( $supports['typography'] ) && is_array( $supports['typography'] ) ? $supports['typography'] : [];

		return ! array_key_exists( 'textAlign', $attributes ) && ! empty( $typography['textAlign'] );
	}
}
