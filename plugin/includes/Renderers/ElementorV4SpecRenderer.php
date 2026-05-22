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
 *        → strip `__unsupported` markers into diagnostics → return tree
 *
 * Anything the renderer doesn't know how to emit becomes an
 * `unsupported_node` diagnostic so callers can warn about partial renders
 * instead of silently dropping content.
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
			$rendered = AtomicRenderer::render_node( $node );
			self::collect_unsupported( $rendered, [ 'sections', (int) $section_index ], $diagnostics );
			$out[] = self::strip_unsupported_markers( $rendered );
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
			$node['props']['label'] = (string) $section['label'];
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
		$type     = (string) ( $block['type'] ?? '' );
		$nodeType = self::BLOCK_TYPE_TO_NODE_TYPE[ $type ] ?? $type;

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
				if ( isset( $block['svg'] ) ) {
					$props['svg'] = (string) $block['svg'];
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
			'type'     => $nodeType,
			'props'    => $props,
			'children' => $children,
		];
	}

	/**
	 * Walk the rendered tree and append an `unsupported_node` diagnostic for
	 * every `__unsupported` marker encountered.
	 *
	 * @param array<string, mixed>           $node
	 * @param array<int, int|string>         $path
	 * @param array<int, array<string, mixed>> $diagnostics
	 */
	private static function collect_unsupported( array $node, array $path, array &$diagnostics ): void {
		if ( array_key_exists( '__unsupported', $node ) ) {
			$type = (string) $node['__unsupported'];
			$diagnostics[] = [
				'code'     => 'unsupported_node',
				'type'     => '' !== $type ? $type : 'unknown',
				'path'     => $path,
				'renderer' => 'elementor_v4',
				'message'  => __( 'Spec node type is not supported by the Elementor V4 atomic renderer.', 'stonewright' ),
			];
		}
		if ( ! empty( $node['elements'] ) && is_array( $node['elements'] ) ) {
			foreach ( $node['elements'] as $index => $child ) {
				if ( is_array( $child ) ) {
					self::collect_unsupported( $child, array_merge( $path, [ 'elements', (int) $index ] ), $diagnostics );
				}
			}
		}
	}

	/**
	 * Remove `__unsupported` markers — they're a renderer-internal signal,
	 * not part of Elementor's element schema. The fallback envelope around
	 * them (an empty `e-paragraph`) stays so the tree still has a valid
	 * placeholder where unknown content used to be.
	 *
	 * @param array<string, mixed> $node
	 * @return array<string, mixed>
	 */
	private static function strip_unsupported_markers( array $node ): array {
		unset( $node['__unsupported'] );
		if ( ! empty( $node['elements'] ) && is_array( $node['elements'] ) ) {
			foreach ( $node['elements'] as $index => $child ) {
				if ( is_array( $child ) ) {
					$node['elements'][ $index ] = self::strip_unsupported_markers( $child );
				}
			}
		}
		return $node;
	}
}
