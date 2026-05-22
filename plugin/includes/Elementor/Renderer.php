<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\Elementor;

use Stonewright\WpMcp\DesignSpec\Validator;
use Stonewright\WpMcp\DesignTokens\Resolver;
use Stonewright\WpMcp\Elementor\Renderer\Accordion;
use Stonewright\WpMcp\Elementor\Renderer\Button;
use Stonewright\WpMcp\Elementor\Renderer\Column;
use Stonewright\WpMcp\Elementor\Renderer\Container;
use Stonewright\WpMcp\Elementor\Renderer\Counter;
use Stonewright\WpMcp\Elementor\Renderer\Divider;
use Stonewright\WpMcp\Elementor\Renderer\Form;
use Stonewright\WpMcp\Elementor\Renderer\Heading;
use Stonewright\WpMcp\Elementor\Renderer\Icon;
use Stonewright\WpMcp\Elementor\Renderer\IconBox;
use Stonewright\WpMcp\Elementor\Renderer\Image;
use Stonewright\WpMcp\Elementor\Renderer\ImageBox;
use Stonewright\WpMcp\Elementor\Renderer\ProgressBar;
use Stonewright\WpMcp\Elementor\Renderer\Section;
use Stonewright\WpMcp\Elementor\Renderer\Slides;
use Stonewright\WpMcp\Elementor\Renderer\SocialIcons;
use Stonewright\WpMcp\Elementor\Renderer\Spacer;
use Stonewright\WpMcp\Elementor\Renderer\Tabs;
use Stonewright\WpMcp\Elementor\Renderer\Testimonial;
use Stonewright\WpMcp\Elementor\Renderer\TextEditor;
use Stonewright\WpMcp\Elementor\Renderer\Toggle;
use Stonewright\WpMcp\Elementor\Renderer\Video;

/**
 * Routes validated DesignSpec nodes to per-widget Elementor V3 renderers.
 *
 * All element IDs are stable: `substr( sha1( canonical_key_path ), 0, 7 )`.
 * Same input always produces the same output.
 */
final class Renderer {

	/**
	 * @param array<string, mixed>              $spec        Validated DesignSpec (already through Validator::validate).
	 * @param array<int, array<string, mixed>>  $diagnostics Unsupported/Pro-gated node diagnostics appended here.
	 * @return array<int, array<string, mixed>>
	 */
	public static function render( array $spec, array &$diagnostics = [] ): array {
		$resolver = Resolver::from_spec( $spec );
		$sections = isset( $spec['sections'] ) && is_array( $spec['sections'] ) ? $spec['sections'] : [];
		$out      = [];

		foreach ( $sections as $s_idx => $section ) {
			$section_path    = 's' . $s_idx;
			$section_element = Section::render( (array) $section, $resolver, $section_path );
			$blocks          = isset( $section['blocks'] ) && is_array( $section['blocks'] ) ? $section['blocks'] : [];
			$children        = [];

			foreach ( $blocks as $b_idx => $block ) {
				$block_path = $section_path . '.b' . $b_idx;
				$rendered   = self::render_block( (array) $block, $resolver, $block_path, $diagnostics );
				if ( null !== $rendered ) {
					$children[] = $rendered;
				}
			}

			$section_element['elements'] = $children;
			$out[]                       = $section_element;
		}

		return $out;
	}

	/**
	 * @param array<string, mixed>              $block
	 * @param array<int, array<string, mixed>>  $diagnostics
	 * @return array<string, mixed>|null
	 */
	private static function render_block( array $block, Resolver $resolver, string $path, array &$diagnostics ): ?array {
		$type = (string) ( $block['type'] ?? '' );

		switch ( $type ) {
			// ------- layout shells -------
			case 'section':
			case 'row':
				return Section::render( $block, $resolver, $path );

			case 'column':
				return Column::render( $block, $resolver, $path );

			case 'group':
			case 'container':
				return Container::render( $block, $resolver, $path );

			// ------- text -------
			case 'heading':
			case 'paragraph':
				return Heading::render( $block, $resolver, $path );

			case 'text-editor':
			case 'embed':
				return TextEditor::render( $block, $resolver, $path );

			// ------- media -------
			case 'image':
				return Image::render( $block, $resolver, $path );

			case 'video':
				return Video::render( $block, $resolver, $path );

			// ------- interactive -------
			case 'button':
				return Button::render( $block, $resolver, $path );

			case 'spacer':
				return Spacer::render( $block, $resolver, $path );

			case 'divider':
				return Divider::render( $block, $resolver, $path );

			// ------- icons -------
			case 'icon':
				return Icon::render( $block, $resolver, $path );

			case 'icon-box':
				return IconBox::render( $block, $resolver, $path );

			case 'image-box':
				return ImageBox::render( $block, $resolver, $path );

			// ------- content blocks -------
			case 'testimonial':
				return Testimonial::render( $block, $resolver, $path );

			case 'tabs':
				return Tabs::render( $block, $resolver, $path );

			case 'accordion':
				return Accordion::render( $block, $resolver, $path );

			case 'toggle':
				return Toggle::render( $block, $resolver, $path );

			case 'social-icons':
				return SocialIcons::render( $block, $resolver, $path );

			case 'progress-bar':
				return ProgressBar::render( $block, $resolver, $path );

			case 'counter':
				return Counter::render( $block, $resolver, $path );

			// ------- Pro-gated -------
			case 'form':
			case 'form-placeholder':
				return Form::render( $block, $resolver, $path, $diagnostics );

			case 'slides':
			case 'slider':
			case 'card':
				return Slides::render( $block, $resolver, $path, $diagnostics );

			// ------- list (free-form, no native Elementor list widget in free) -------
			case 'list':
				return self::render_list_as_text_editor( $block, $resolver, $path );

			default:
				$diagnostics[] = [
					'code'     => 'unsupported_node',
					'type'     => '' !== $type ? $type : 'unknown',
					'path'     => $path,
					'renderer' => 'elementor_v3',
					'message'  => 'Spec node type is not supported by the Elementor V3 renderer.',
				];
				return null;
		}
	}

	/**
	 * Render a `list` spec node as a text-editor widget with <ul> markup.
	 * Elementor free does not have a dedicated list widget; we produce valid HTML.
	 *
	 * @param array<string, mixed> $block
	 * @return array<string, mixed>
	 */
	private static function render_list_as_text_editor( array $block, Resolver $resolver, string $path ): array {
		$items = isset( $block['items'] ) && is_array( $block['items'] ) ? $block['items'] : [];
		$html  = '<ul>';
		foreach ( $items as $item ) {
			$html .= '<li>' . esc_html( (string) $item ) . '</li>';
		}
		$html .= '</ul>';

		return TextEditor::render(
			array_merge( $block, [ 'html' => $html ] ),
			$resolver,
			$path
		);
	}
}
