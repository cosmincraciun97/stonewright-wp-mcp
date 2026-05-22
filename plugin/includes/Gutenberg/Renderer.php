<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\Gutenberg;

use Stonewright\WpMcp\DesignTokens\Resolver;
use Stonewright\WpMcp\Gutenberg\Renderer\Buttons;
use Stonewright\WpMcp\Gutenberg\Renderer\Columns;
use Stonewright\WpMcp\Gutenberg\Renderer\Cover;
use Stonewright\WpMcp\Gutenberg\Renderer\Embed;
use Stonewright\WpMcp\Gutenberg\Renderer\Group;
use Stonewright\WpMcp\Gutenberg\Renderer\Heading;
use Stonewright\WpMcp\Gutenberg\Renderer\Image;
use Stonewright\WpMcp\Gutenberg\Renderer\ListBlock;
use Stonewright\WpMcp\Gutenberg\Renderer\MediaText;
use Stonewright\WpMcp\Gutenberg\Renderer\Paragraph;
use Stonewright\WpMcp\Gutenberg\Renderer\Quote;
use Stonewright\WpMcp\Gutenberg\Renderer\Reusable;
use Stonewright\WpMcp\Gutenberg\Renderer\Separator;
use Stonewright\WpMcp\Gutenberg\Renderer\Spacer;
use Stonewright\WpMcp\Gutenberg\Renderer\Video;

/**
 * Routes validated DesignSpec nodes to per-block Gutenberg renderers.
 *
 * Mirrors the Elementor\Renderer dispatcher pattern. Each per-block renderer
 * returns an array shaped for serialize_block() / serialize_blocks().
 *
 * All output is deterministic: same spec input → same block array output.
 * Token resolution is threaded via an optional Resolver so each renderer can
 * apply theme.json-compatible style attributes from the spec's token map.
 */
final class Renderer {

	/**
	 * Render a fully-validated DesignSpec into a flat array of block dicts.
	 *
	 * The caller is responsible for having already run Validator::validate() on
	 * the spec. The ability layer (RenderBlocks) enforces this.
	 *
	 * @param array<string, mixed>             $spec        Validated DesignSpec.
	 * @param array<int, array<string, mixed>> $diagnostics Unsupported-node diagnostics appended here.
	 * @return array<int, array<string, mixed>>
	 */
	public static function render( array $spec, array &$diagnostics = [] ): array {
		$sections = isset( $spec['sections'] ) && is_array( $spec['sections'] ) ? $spec['sections'] : [];
		$resolver = Resolver::from_spec( $spec );
		$out      = [];

		foreach ( $sections as $s_idx => $section ) {
			$section  = (array) $section;
			$blocks   = isset( $section['blocks'] ) && is_array( $section['blocks'] ) ? $section['blocks'] : [];
			$children = [];
			$s_path   = 's' . $s_idx;

			foreach ( $blocks as $b_idx => $block ) {
				$rendered = self::render_block( (array) $block, $s_path . '.b' . $b_idx, $diagnostics, $resolver );
				if ( null !== $rendered ) {
					$children[] = $rendered;
				}
			}

			$out[] = Group::from_section( $section, $children, $s_path );
		}

		return $out;
	}

	/**
	 * @param array<string, mixed>             $block
	 * @param array<int, array<string, mixed>> $diagnostics
	 * @param Resolver|null                    $resolver    Token resolver (null = no token mapping).
	 * @return array<string, mixed>|null
	 */
	public static function render_block( array $block, string $path, array &$diagnostics, ?Resolver $resolver = null ): ?array {
		$type = (string) ( $block['type'] ?? '' );

		switch ( $type ) {
			case 'heading':
				return Heading::render( $block, $path, $resolver );

			case 'paragraph':
				return Paragraph::render( $block, $path, $resolver );

			case 'image':
				return Image::render( $block, $path, $resolver );

			case 'columns':
				return Columns::render( $block, $path, $diagnostics, $resolver );

			case 'group':
			case 'row':
			case 'section':
				return Group::render( $block, $path, $diagnostics, $resolver );

			case 'buttons':
			case 'button':
				return Buttons::render( $block, $path, $resolver );

			case 'quote':
				return Quote::render( $block, $path );

			case 'list':
				return ListBlock::render( $block, $path );

			case 'cover':
				return Cover::render( $block, $path, $diagnostics, $resolver );

			case 'spacer':
				return Spacer::render( $block, $path, $resolver );

			case 'separator':
				return Separator::render( $block, $path );

			case 'media-text':
				return MediaText::render( $block, $path, $diagnostics, $resolver );

			case 'video':
				return Video::render( $block, $path, $diagnostics );

			case 'embed':
				return Embed::render( $block, $path, $diagnostics );

			case 'reusable':
				return Reusable::render( $block, $path, $diagnostics );

			default:
				$diagnostics[] = [
					'code'     => 'unsupported_node',
					'type'     => '' !== $type ? $type : 'unknown',
					'path'     => $path,
					'renderer' => 'gutenberg',
					'message'  => 'Spec node type is not supported by the Gutenberg renderer.',
				];
				return null;
		}
	}
}
