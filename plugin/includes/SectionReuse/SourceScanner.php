<?php
/**
 * Bounded scan of the sources section reuse can draw from.
 * SPDX-License-Identifier: GPL-2.0-or-later
 *
 * @package Stonewright
 */

declare( strict_types=1 );

namespace Stonewright\WpMcp\SectionReuse;

use Stonewright\WpMcp\Elementor\Provider\ProviderRouter;

/**
 * Finds the sections of one builder family on the site, newest sources first.
 *
 * Sources are published and draft pages and posts of public post types, Elementor saved section and
 * container templates, Gutenberg patterns and, for Gutenberg on a block theme, the customized templates and
 * template parts of the active theme (a template that exists only as a theme file has no post and is not a
 * source); never trashed posts, autosaves or revisions. Only a post the
 * current user may both read and edit is considered. The scan looks at the 200 most recent sources and says
 * when there were more. Each source is summarized once per modification time and builder version
 * ({@see SignatureCache}); the summary is layout-only and deterministic.
 *
 * A section is walked up to the element and depth caps every Elementor route applies; a section over a cap is
 * summarized from what was read and reported as capped.
 */
final class SourceScanner {

	public const MAX_SOURCES             = 200;
	public const MAX_SECTIONS_PER_SOURCE = 12;
	private const MAX_TYPES              = 6;
	private const MAX_REFERENCES         = 24;

	/**
	 * @return array{sections:list<array<string,mixed>>,scan:array<string,mixed>}
	 *         Each section carries its source (`post`) and its compact summary; `scan` says what was looked at.
	 */
	public static function scan( string $builder, int $exclude_post_id = 0 ): array {
		$fetched    = self::fetch( $builder, $exclude_post_id );
		$limits     = ProviderRouter::element_limits();
		$sections   = [];
		$scanned    = 0;
		$with       = 0;
		$capped     = 0;
		$cache_base = SignatureCache::stats();

		foreach ( $fetched['posts'] as $post ) {
			if ( ! self::may_use( (int) $post->ID ) ) {
				continue;
			}
			++$scanned;
			$modified = SectionSource::modified( $post );
			$version  = SectionSource::builder_version( $post );
			$compact  = SignatureCache::get( (int) $post->ID, $modified, $version );
			if ( null === $compact ) {
				$compact = self::summarize( $post, $limits );
				SignatureCache::put( (int) $post->ID, $modified, $version, $compact );
			}
			$found = false;
			foreach ( $compact as $section ) {
				if ( $section['builder'] !== $builder ) {
					continue;
				}
				$found = true;
				if ( ! empty( $section['layout']['capped'] ) ) {
					++$capped;
				}
				$section['post'] = $post;
				$sections[]      = $section;
			}
			$with += $found ? 1 : 0;
		}
		SignatureCache::flush();
		$cache = SignatureCache::stats();

		return [
			'sections' => $sections,
			'scan'     => [
				'sources_limit'        => self::MAX_SOURCES,
				'sources_scanned'      => $scanned,
				'sources_with_sections' => $with,
				'truncated'            => $fetched['truncated'],
				'cache'                => [ 'hits' => $cache['hits'] - $cache_base['hits'], 'misses' => $cache['misses'] - $cache_base['misses'] ],
				'limits'               => $limits,
				'capped_sections'      => $capped,
			],
		];
	}

	/** Whether the current user may read and edit a source; only such a source is a candidate. */
	public static function may_use( int $post_id ): bool {
		return $post_id > 0 && current_user_can( 'read_post', $post_id ) && current_user_can( 'edit_post', $post_id );
	}

	/**
	 * The source posts, most recently modified first, and whether more than the limit exist.
	 *
	 * @return array{posts:list<object>,truncated:bool}
	 */
	private static function fetch( string $builder, int $exclude_post_id ): array {
		$types = array_values( array_diff( array_map( 'strval', (array) get_post_types( [ 'public' => true ], 'names' ) ), [ 'attachment' ] ) );
		if ( [] === $types ) {
			$types = [ 'post', 'page' ];
		}
		$types[] = Builder::is_elementor( $builder ) ? SectionSource::TEMPLATE_POST_TYPE : SectionSource::PATTERN_POST_TYPE;
		$args    = [
			'post_type'        => array_values( array_unique( $types ) ),
			'post_status'      => [ 'publish', 'draft' ],
			'posts_per_page'   => self::MAX_SOURCES + 1,
			'orderby'          => 'modified',
			'order'            => 'DESC',
			'no_found_rows'    => true,
			'suppress_filters' => true,
			'post__not_in'     => $exclude_post_id > 0 ? [ $exclude_post_id ] : [],
			'meta_query'       => Builder::is_elementor( $builder )
				? [ [ 'key' => '_elementor_edit_mode', 'value' => 'builder' ] ]
				: [ 'relation' => 'OR', [ 'key' => '_elementor_edit_mode', 'compare' => 'NOT EXISTS' ], [ 'key' => '_elementor_edit_mode', 'value' => 'builder', 'compare' => '!=' ] ],
		];
		$found = (array) get_posts( $args );
		if ( ! Builder::is_elementor( $builder ) && function_exists( 'wp_is_block_theme' ) && wp_is_block_theme() ) {
			// Customized templates belong to the theme that was active when they were saved, so only the active one counts.
			$found = array_merge(
				$found,
				(array) get_posts(
					array_merge(
						$args,
						[
							'post_type'  => SectionSource::SITE_TEMPLATE_POST_TYPES,
							'meta_query' => [],
							'tax_query'  => [ [ 'taxonomy' => 'wp_theme', 'field' => 'name', 'terms' => [ get_stylesheet() ] ] ],
						]
					)
				)
			);
		}
		$posts = array_values(
			array_filter(
				$found,
				static fn( mixed $post ): bool => is_object( $post )
					&& (int) $post->ID !== $exclude_post_id
					&& in_array( SectionSource::field( $post, 'post_status' ), [ 'publish', 'draft' ], true )
					&& ! in_array( SectionSource::field( $post, 'post_type' ), [ 'revision', 'attachment', 'auto-draft' ], true )
			)
		);
		usort(
			$posts,
			static fn( object $left, object $right ): int => [ SectionSource::modified( $right ), (int) $right->ID ] <=> [ SectionSource::modified( $left ), (int) $left->ID ]
		);
		$truncated = count( $posts ) > self::MAX_SOURCES;

		return [ 'posts' => array_slice( $posts, 0, self::MAX_SOURCES ), 'truncated' => $truncated ];
	}

	/**
	 * The compact summaries of every section of a source, for every builder family it holds.
	 *
	 * @param array{max_elements:int,max_depth:int} $limits
	 * @return list<array<string, mixed>>
	 */
	private static function summarize( object $post, array $limits ): array {
		$out = [];
		foreach ( array_slice( SectionSource::sections( $post ), 0, self::MAX_SECTIONS_PER_SOURCE ) as $section ) {
			$out[] = self::compact( $section, $limits );
		}

		return $out;
	}

	/**
	 * @param array{builder:string,index:int,locator:array<string,mixed>,node:array<string,mixed>} $section
	 * @param array{max_elements:int,max_depth:int}                                                 $limits
	 * @return array<string, mixed>
	 */
	private static function compact( array $section, array $limits ): array {
		$layout     = LayoutSummary::of( $section['builder'], $section['node'], $limits );
		$inspection = SectionInspector::inspect( $section['builder'], $section['node'], $limits );
		$references = [];
		foreach ( array_slice( $inspection['references'], 0, self::MAX_REFERENCES ) as $reference ) {
			$references[] = [ 'type' => $reference['type'], 'id' => $reference['id'], 'count' => $reference['count'] ];
		}
		$layout['types']    = array_slice( $layout['types'], 0, self::MAX_TYPES, true );
		$layout['capped']   = $layout['capped'] || $inspection['capped'];
		$entry              = [
			'builder'    => $section['builder'],
			'index'      => $section['index'],
			'locator'    => $section['locator'],
			'role'       => RoleGuesser::guess( $section['builder'], $layout, $inspection, $section['index'] ),
			'layout'     => $layout,
			'outline'    => $inspection['outline'],
			'references' => $references,
			'flags'      => $inspection['flags'],
		];

		return $entry;
	}
}
