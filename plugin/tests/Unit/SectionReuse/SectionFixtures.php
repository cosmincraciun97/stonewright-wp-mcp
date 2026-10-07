<?php
/**
 * Synthetic sections for the section reuse tests.
 * SPDX-License-Identifier: GPL-2.0-or-later
 *
 * @package Stonewright
 */

declare( strict_types=1 );

namespace Stonewright\WpMcp\Tests\Unit\SectionReuse;

/**
 * Builders of small Elementor V3, Elementor V4 and Gutenberg sections. Ids, text and image ids are made up.
 */
final class SectionFixtures {

	/**
	 * A V3 features section: a heading and a row of cards, each card a container with an icon box.
	 *
	 * @return array<string, mixed>
	 */
	public static function v3_features( string $prefix = 'f', int $cards = 3, string $title = 'Why choose us', string $color = '#112233' ): array {
		$children = [];
		for ( $i = 1; $i <= $cards; $i++ ) {
			$children[] = [
				'id'       => $prefix . 'c' . $i,
				'elType'   => 'container',
				'isInner'  => true,
				'settings' => [ 'flex_direction' => 'column', 'padding' => [ 'unit' => 'px', 'top' => '8', 'right' => '8', 'bottom' => '8', 'left' => '8', 'isLinked' => true ] ],
				'elements' => [
					[
						'id'         => $prefix . 'i' . $i,
						'elType'     => 'widget',
						'widgetType' => 'icon-box',
						'settings'   => [ 'title_text' => 'Card ' . $i, 'description_text' => 'Text ' . $i, 'title_color' => $color ],
						'elements'   => [],
					],
				],
			];
		}

		return [
			'id'       => $prefix . '000001',
			'elType'   => 'container',
			'isInner'  => false,
			'settings' => [ 'container_type' => 'flex', 'flex_direction' => 'column', 'content_width' => 'boxed' ],
			'elements' => [
				[
					'id'         => $prefix . '000002',
					'elType'     => 'widget',
					'widgetType' => 'heading',
					'settings'   => [ 'title' => $title, 'title_color' => $color ],
					'elements'   => [],
				],
				[
					'id'       => $prefix . '000003',
					'elType'   => 'container',
					'isInner'  => true,
					'settings' => [ 'container_type' => 'flex', 'flex_direction' => 'row' ],
					'elements' => $children,
				],
			],
		];
	}

	/**
	 * A V3 hero: heading, text, button and image in one container.
	 *
	 * @return array<string, mixed>
	 */
	public static function v3_hero( string $prefix = 'h', string $title = 'Build something solid', int $image_id = 41 ): array {
		return [
			'id'       => $prefix . '000001',
			'elType'   => 'container',
			'isInner'  => false,
			'settings' => [ 'container_type' => 'flex', 'flex_direction' => 'row', 'content_width' => 'full' ],
			'elements' => [
				[
					'id'       => $prefix . '000002',
					'elType'   => 'container',
					'isInner'  => true,
					'settings' => [ 'flex_direction' => 'column' ],
					'elements' => [
						[ 'id' => $prefix . '000003', 'elType' => 'widget', 'widgetType' => 'heading', 'settings' => [ 'title' => $title ], 'elements' => [] ],
						[ 'id' => $prefix . '000004', 'elType' => 'widget', 'widgetType' => 'text-editor', 'settings' => [ 'editor' => '<p>Short intro.</p>' ], 'elements' => [] ],
						[ 'id' => $prefix . '000005', 'elType' => 'widget', 'widgetType' => 'button', 'settings' => [ 'text' => 'Start' ], 'elements' => [] ],
					],
				],
				[
					'id'         => $prefix . '000006',
					'elType'     => 'widget',
					'widgetType' => 'image',
					'settings'   => [ 'image' => [ 'id' => $image_id, 'url' => 'https://example.test/wp-content/uploads/hero.jpg' ] ],
					'elements'   => [],
				],
			],
		];
	}

	/**
	 * A V4 features section: a div block with a heading and a row of three card blocks.
	 *
	 * @return array<string, mixed>
	 */
	public static function v4_features( string $prefix = 'v', int $cards = 3, string $title = 'Why choose us', string $color = '#112233', string $direction = 'row' ): array {
		$local  = static fn( string $element, string $suffix ): string => 'e-' . $element . '-' . $suffix;
		$style  = static fn( string $id, array $props ): array => [
			'id'       => $id,
			'label'    => 'local',
			'type'     => 'class',
			'variants' => [ [ 'meta' => [ 'breakpoint' => 'desktop', 'state' => null ], 'props' => $props, 'custom_css' => null ] ],
		];
		$cards_list = [];
		for ( $i = 1; $i <= $cards; $i++ ) {
			$cards_list[] = [
				'id'              => $prefix . 'card' . $i,
				'version'         => '0.0',
				'elType'          => 'e-div-block',
				'isInner'         => true,
				'settings'        => [ 'classes' => [ '$$type' => 'classes', 'value' => [ $local( $prefix . 'card' . $i, 'a1b2c3d' ), 'g-card' ] ] ],
				'editor_settings' => [],
				'interactions'    => [],
				'styles'          => [
					$local( $prefix . 'card' . $i, 'a1b2c3d' ) => $style( $local( $prefix . 'card' . $i, 'a1b2c3d' ), [ 'display' => [ '$$type' => 'string', 'value' => 'flex' ], 'flex-direction' => [ '$$type' => 'string', 'value' => 'column' ] ] ),
				],
				'elements'        => [
					[
						'id'              => $prefix . 'ctitle' . $i,
						'version'         => '0.0',
						'elType'          => 'widget',
						'widgetType'      => 'e-heading',
						'isInner'         => false,
						'settings'        => [ 'title' => [ '$$type' => 'html-v3', 'value' => [ 'content' => [ '$$type' => 'string', 'value' => 'Card ' . $i ], 'children' => [] ] ], 'tag' => [ '$$type' => 'string', 'value' => 'h3' ] ],
						'editor_settings' => [],
						'interactions'    => [],
						'styles'          => [],
						'elements'        => [],
					],
				],
			];
		}

		return [
			'id'              => $prefix . '000001',
			'version'         => '0.0',
			'elType'          => 'e-div-block',
			'isInner'         => false,
			'settings'        => [ 'classes' => [ '$$type' => 'classes', 'value' => [ $local( $prefix . '000001', 'f00ba12' ) ] ] ],
			'editor_settings' => [],
			'interactions'    => [],
			'styles'          => [
				$local( $prefix . '000001', 'f00ba12' ) => $style(
					$local( $prefix . '000001', 'f00ba12' ),
					[
						'display'        => [ '$$type' => 'string', 'value' => 'flex' ],
						'flex-direction' => [ '$$type' => 'string', 'value' => 'column' ],
						'background'     => [ '$$type' => 'background', 'value' => [ 'color' => [ '$$type' => 'color', 'value' => $color ] ] ],
					]
				),
			],
			'elements'        => [
				[
					'id'              => $prefix . '000002',
					'version'         => '0.0',
					'elType'          => 'widget',
					'widgetType'      => 'e-heading',
					'isInner'         => false,
					'settings'        => [ 'title' => [ '$$type' => 'html-v3', 'value' => [ 'content' => [ '$$type' => 'string', 'value' => $title ], 'children' => [] ] ], 'tag' => [ '$$type' => 'string', 'value' => 'h2' ] ],
					'editor_settings' => [],
					'interactions'    => [],
					'styles'          => [],
					'elements'        => [],
				],
				[
					'id'              => $prefix . '000003',
					'version'         => '0.0',
					'elType'          => 'e-div-block',
					'isInner'         => true,
					'settings'        => [ 'classes' => [ '$$type' => 'classes', 'value' => [ $local( $prefix . '000003', '9e8d7c6' ) ] ] ],
					'editor_settings' => [],
					'interactions'    => [],
					'styles'          => [
						$local( $prefix . '000003', '9e8d7c6' ) => $style( $local( $prefix . '000003', '9e8d7c6' ), [ 'display' => [ '$$type' => 'string', 'value' => 'flex' ], 'flex-direction' => [ '$$type' => 'string', 'value' => $direction ] ] ),
					],
					'elements'        => $cards_list,
				],
			],
		];
	}

	/**
	 * Gutenberg content of a features section: a group with a heading and a three-column row.
	 */
	public static function gutenberg_features_content( string $title = 'Why choose us', int $columns = 3, string $anchor = 'features' ): string {
		$cols = '';
		for ( $i = 1; $i <= $columns; $i++ ) {
			$cols .= "<!-- wp:column -->\n<div class=\"wp-block-column\"><!-- wp:heading {\"level\":3} -->\n<h3 class=\"wp-block-heading\">Card {$i}</h3>\n<!-- /wp:heading -->\n\n<!-- wp:paragraph -->\n<p>Text {$i}</p>\n<!-- /wp:paragraph --></div>\n<!-- /wp:column -->\n\n";
		}

		return "<!-- wp:group {\"anchor\":\"{$anchor}\",\"layout\":{\"type\":\"constrained\"}} -->\n<div id=\"{$anchor}\" class=\"wp-block-group\"><!-- wp:heading -->\n<h2 class=\"wp-block-heading\">{$title}</h2>\n<!-- /wp:heading -->\n\n<!-- wp:columns -->\n<div class=\"wp-block-columns\">" . rtrim( $cols ) . "</div>\n<!-- /wp:columns --></div>\n<!-- /wp:group -->";
	}

	/**
	 * A post object as the WordPress stubs return it.
	 *
	 * @param array<string, mixed> $meta
	 */
	public static function post( int $id, string $type = 'page', string $status = 'publish', string $title = 'A page', string $content = '', array $meta = [], string $modified = '2026-01-02 03:04:05' ): object {
		return (object) [
			'ID'                => $id,
			'post_type'         => $type,
			'post_status'       => $status,
			'post_title'        => $title,
			'post_content'      => $content,
			'post_excerpt'      => '',
			'post_modified_gmt' => $modified,
			'post_modified'     => $modified,
			'meta'              => $meta,
		];
	}

	/**
	 * Elementor meta of a post.
	 *
	 * @param list<array<string, mixed>> $tree
	 * @return array<string, mixed>
	 */
	public static function elementor_meta( array $tree, string $version = '3.30.0', string $template_type = '' ): array {
		$meta = [
			'_elementor_data'      => (string) wp_json_encode( $tree ),
			'_elementor_edit_mode' => 'builder',
			'_elementor_version'   => $version,
		];
		if ( '' !== $template_type ) {
			$meta['_elementor_template_type'] = $template_type;
		}

		return $meta;
	}
}
