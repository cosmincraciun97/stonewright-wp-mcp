<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\Gutenberg;

/**
 * One-call Gutenberg / FSE editor snapshot for precision workflows.
 */
final class EditorSnapshot {

	/**
	 * @return array{
	 *   ok: bool,
	 *   theme: array<string, mixed>,
	 *   templates: array{count: int, items: list<array<string, mixed>>},
	 *   template_parts: array{count: int, items: list<array<string, mixed>>},
	 *   global_styles: array<string, mixed>,
	 *   blocks: array{registered_count: int, sample: list<string>},
	 *   capabilities: array<string, bool>
	 * }
	 */
	public static function capture(): array {
		$theme      = self::theme_summary();
		$templates  = self::templates_summary( 'wp_template' );
		$parts      = self::templates_summary( 'wp_template_part' );
		$styles     = self::global_styles_summary();
		$blocks     = self::blocks_summary();

		return [
			'ok'             => true,
			'theme'          => $theme,
			'templates'      => $templates,
			'template_parts' => $parts,
			'global_styles'  => $styles,
			'blocks'         => $blocks,
			'capabilities'   => [
				'block_theme'            => (bool) ( $theme['is_block_theme'] ?? false ),
				'has_get_block_templates'=> function_exists( 'get_block_templates' ),
				'has_parse_blocks'       => function_exists( 'parse_blocks' ),
				'has_serialize_blocks'   => function_exists( 'serialize_blocks' ),
				'has_wp_theme_json'      => class_exists( '\WP_Theme_JSON_Resolver' ),
			],
		];
	}

	/**
	 * @return array<string, mixed>
	 */
	private static function theme_summary(): array {
		$stylesheet = function_exists( 'get_stylesheet' ) ? (string) get_stylesheet() : '';
		$template   = function_exists( 'get_template' ) ? (string) get_template() : '';
		$is_block   = function_exists( 'wp_is_block_theme' ) ? (bool) wp_is_block_theme() : false;

		$theme_name = $stylesheet;
		if ( function_exists( 'wp_get_theme' ) ) {
			$theme = wp_get_theme();
			if ( is_object( $theme ) && method_exists( $theme, 'get' ) ) {
				$theme_name = (string) $theme->get( 'Name' );
			}
		}

		return [
			'stylesheet'    => $stylesheet,
			'template'      => $template,
			'name'          => $theme_name,
			'is_block_theme'=> $is_block,
			'type'          => $is_block ? 'block' : 'classic',
		];
	}

	/**
	 * @return array{count: int, items: list<array<string, mixed>>}
	 */
	private static function templates_summary( string $post_type ): array {
		$items = [];
		if ( function_exists( 'get_block_templates' ) ) {
			$results = get_block_templates( [], $post_type );
			if ( is_array( $results ) ) {
				foreach ( array_slice( $results, 0, 40 ) as $tpl ) {
					$items[] = [
						'id'          => is_object( $tpl ) ? (string) ( $tpl->id ?? '' ) : (string) ( $tpl['id'] ?? '' ),
						'slug'        => is_object( $tpl ) ? (string) ( $tpl->slug ?? '' ) : (string) ( $tpl['slug'] ?? '' ),
						'title'       => self::template_title( $tpl ),
						'source'      => is_object( $tpl ) ? (string) ( $tpl->source ?? '' ) : (string) ( $tpl['source'] ?? '' ),
						'theme'       => is_object( $tpl ) ? (string) ( $tpl->theme ?? '' ) : (string) ( $tpl['theme'] ?? '' ),
						'has_content' => is_object( $tpl )
							? ( '' !== trim( (string) ( $tpl->content ?? '' ) ) )
							: ( '' !== trim( (string) ( $tpl['content'] ?? '' ) ) ),
					];
				}
			}
		}

		return [
			'count' => count( $items ),
			'items' => $items,
		];
	}

	/**
	 * @param mixed $tpl
	 */
	private static function template_title( $tpl ): string {
		if ( is_object( $tpl ) ) {
			if ( isset( $tpl->title ) && is_object( $tpl->title ) && isset( $tpl->title->rendered ) ) {
				return (string) $tpl->title->rendered;
			}
			return (string) ( $tpl->slug ?? '' );
		}
		if ( is_array( $tpl ) ) {
			return (string) ( $tpl['title']['rendered'] ?? $tpl['slug'] ?? '' );
		}
		return '';
	}

	/**
	 * @return array<string, mixed>
	 */
	private static function global_styles_summary(): array {
		$present = false;
		$post_id = 0;
		$user_id = 0;

		if ( function_exists( 'wp_get_global_styles' ) || class_exists( '\WP_Theme_JSON_Resolver' ) ) {
			$present = true;
		}

		// Best-effort: locate a custom global styles post when available.
		if ( function_exists( 'get_posts' ) ) {
			$posts = get_posts(
				[
					'post_type'      => 'wp_global_styles',
					'post_status'    => [ 'publish', 'draft' ],
					'posts_per_page' => 1,
					'orderby'        => 'modified',
					'order'          => 'DESC',
				]
			);
			if ( is_array( $posts ) && isset( $posts[0] ) ) {
				$post    = $posts[0];
				$post_id = (int) ( is_object( $post ) ? ( $post->ID ?? 0 ) : 0 );
				$present = $present || $post_id > 0;
			}
		}

		if ( function_exists( 'get_current_user_id' ) ) {
			$user_id = (int) get_current_user_id();
		}

		return [
			'present'               => $present,
			'global_styles_post_id' => $post_id,
			'user_id'               => $user_id,
		];
	}

	/**
	 * @return array{registered_count: int, sample: list<string>}
	 */
	private static function blocks_summary(): array {
		$count  = 0;
		$sample = [];
		if ( class_exists( '\WP_Block_Type_Registry' ) ) {
			$registry = \WP_Block_Type_Registry::get_instance();
			$all      = $registry->get_all_registered();
			$count    = count( $all );
			$sample   = array_values( array_slice( array_keys( $all ), 0, 25 ) );
		}
		return [
			'registered_count' => $count,
			'sample'           => $sample,
		];
	}
}
