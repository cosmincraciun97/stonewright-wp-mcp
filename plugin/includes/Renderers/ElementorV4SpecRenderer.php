<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\Renderers;

use Stonewright\WpMcp\DesignSpec\Validator;
use Stonewright\WpMcp\Elementor\V4\AtomicRenderer;

/**
 * Renders a Stonewright Design Spec into an Elementor V4 atomic element tree.
 *
 * V4 dropped the V3 section/column/widget hierarchy in favour of flexbox
 * containers (`e-flexbox`) and atomic widgets (`e-heading`, `e-image`, …).
 * Every prop is wrapped in V4's typed envelope `{ $$type, value }`.
 *
 * Pipeline:
 *
 *   spec → translate to PascalCase node tree → AtomicRenderer::render_node()
 *
 * Unknown nodes and settings are hard errors. A partial V4 tree is never
 * returned as success.
 */
final class ElementorV4SpecRenderer {

	/**
	 * Lowercase DesignSpec block type → PascalCase node type understood by
	 * {@see AtomicRenderer}. `row` / `column` are layout primitives without
	 * a one-to-one V4 atomic; both collapse onto `Container` (a flexbox).
	 */
	private const BLOCK_TYPE_TO_NODE_TYPE = [
		'heading'   => 'Heading',
		'paragraph' => 'TextEditor',
		'image'     => 'Image',
		'button'    => 'Button',
		'separator' => 'Divider',
		'icon'      => 'Icon',
		'row'       => 'Container',
		'column'    => 'Container',
	];

	/**
	 * Convert a Stonewright Design Spec to an Elementor V4 atomic element tree.
	 *
	 * @param array<string, mixed>                  $spec
	 * @param array<int, array<string, mixed>>|null $diagnostics Unsupported-node diagnostics are appended here.
	 * @return array<int, array<string, mixed>>|\WP_Error
	 */
	public static function render( array $spec, ?array &$diagnostics = null ): array|\WP_Error {
		$validated = Validator::validate( $spec );
		if ( is_wp_error( $validated ) ) {
			return $validated;
		}
		$diagnostics ??= [];
		$spec         = $validated;
		$sections     = isset( $spec['sections'] ) && is_array( $spec['sections'] ) ? $spec['sections'] : [];
		$out          = [];
		foreach ( $sections as $section_index => $section ) {
			$node     = self::section_to_node( (array) $section );
			$rendered = AtomicRenderer::render_node( $node, [ 'sections', (int) $section_index ] );
			if ( is_wp_error( $rendered ) ) {
				$diagnostics[] = [
					'code'    => $rendered->get_error_code(),
					'message' => $rendered->get_error_message(),
					'data'    => $rendered->get_error_data(),
				];
				return $rendered;
			}
			$out[] = $rendered;
		}
		return $out;
	}

	/**
	 * Map a Design Spec section (with its child blocks) onto the canonical
	 * node-tree shape that {@see AtomicRenderer} consumes.
	 *
	 * @param array<string, mixed> $section
	 * @return array<string, mixed>
	 */
	private static function section_to_node( array $section ): array {
		$node = [
			'type'  => 'Section',
			'props' => self::section_props( $section ),
		];

		$blocks = isset( $section['blocks'] ) && is_array( $section['blocks'] ) ? $section['blocks'] : [];

		$children = [];
		foreach ( $blocks as $block ) {
			$children[] = self::block_to_node( (array) $block );
		}
		$node['children'] = $children;

		if ( isset( $section['label'] ) ) {
			$node['editor_settings'] = [ 'label' => (string) $section['label'] ];
		}

		return $node;
	}

	/**
	 * @param array<string, mixed> $section
	 * @return array<string, mixed>
	 */
	private static function section_props( array $section ): array {
		$props = [];
		if ( isset( $section['direction'] ) ) {
			$props['direction'] = (string) $section['direction'];
		}
		if ( isset( $section['gap'] ) ) {
			$props['gap'] = (string) $section['gap'];
		}
		return $props;
	}

	/**
	 * Convert a spec block (with lowercase `type` + flat scalar props) into
	 * a renderer node (`type` PascalCase, `props` bag, recursive `children`).
	 *
	 * @param array<string, mixed> $block
	 * @return array<string, mixed>
	 */
	private static function block_to_node( array $block ): array {
		$type      = (string) ( $block['type'] ?? '' );
		$node_type = self::BLOCK_TYPE_TO_NODE_TYPE[ $type ] ?? $type;

		$props = [];
		switch ( $type ) {
			case 'heading':
				if ( isset( $block['text'] ) ) {
					$props['text'] = (string) $block['text'];
				}
				if ( isset( $block['level'] ) ) {
					$props['level'] = (int) $block['level'];
				}
				break;
			case 'paragraph':
				if ( isset( $block['text'] ) ) {
					$props['text'] = (string) $block['text'];
				}
				break;
			case 'image':
				if ( isset( $block['url'] ) ) {
					$props['url'] = (string) $block['url'];
				}
				if ( isset( $block['alt'] ) ) {
					$props['alt'] = (string) $block['alt'];
				}
				break;
			case 'button':
				if ( isset( $block['text'] ) ) {
					$props['text'] = (string) $block['text'];
				}
				if ( isset( $block['url'] ) ) {
					$props['link'] = (string) $block['url'];
				}
				break;
			case 'icon':
				if ( isset( $block['url'] ) ) {
					$props['url'] = (string) $block['url'];
				}
				break;
			case 'separator':
				// no atomic-level props
				break;
			case 'row':
			case 'column':
				$props['direction'] = 'row' === $type ? 'row' : 'column';
				if ( isset( $block['gap'] ) ) {
					$props['gap'] = (string) $block['gap'];
				}
				break;
		}

		$children = [];
		foreach ( (array) ( $block['blocks'] ?? [] ) as $child ) {
			$children[] = self::block_to_node( (array) $child );
		}

		return [
			'type'     => $node_type,
			'props'    => $props,
			'children' => $children,
		];
	}
}
