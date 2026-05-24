<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\Elementor;

use Stonewright\WpMcp\DesignSpec\Validator;
use Stonewright\WpMcp\DesignTokens\Resolver;
use Stonewright\WpMcp\Elementor\Renderer\Accordion;
use Stonewright\WpMcp\Elementor\Renderer\Button;
use Stonewright\WpMcp\Elementor\Renderer\Column;
use Stonewright\WpMcp\Elementor\Renderer\Container;
use Stonewright\WpMcp\Elementor\Renderer\Countdown;
use Stonewright\WpMcp\Elementor\Renderer\Counter;
use Stonewright\WpMcp\Elementor\Renderer\IconList;
use Stonewright\WpMcp\Elementor\Renderer\NavMenu;
use Stonewright\WpMcp\Elementor\Renderer\Divider;
use Stonewright\WpMcp\Elementor\Renderer\Form;
use Stonewright\WpMcp\Elementor\Renderer\Heading;
use Stonewright\WpMcp\Elementor\Renderer\Icon;
use Stonewright\WpMcp\Elementor\Renderer\IconBox;
use Stonewright\WpMcp\Elementor\Renderer\Image;
use Stonewright\WpMcp\Elementor\Renderer\ImageGallery;
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
			// Container types render their own shell, then we walk their
			// `blocks` and append the rendered children to `elements`. Before
			// this, the per-renderer methods (Section::render, Column::render,
			// Container::render) always returned `'elements' => []`, so any
			// widget nested inside a column or row block was silently dropped
			// — only the top-level section's block list got processed by the
			// outer render() loop. Caught during the nZeb Expo live build: the
			// hero section's two columns rendered as empty containers because
			// their heading/paragraph/button children never reached Elementor.
			case 'section':
			case 'row':
				return self::with_children( Section::render( $block, $resolver, $path ), $block, $resolver, $path, $diagnostics );

			case 'column':
				return self::with_children( Column::render( $block, $resolver, $path ), $block, $resolver, $path, $diagnostics );

			case 'group':
			case 'container':
				return self::with_children( Container::render( $block, $resolver, $path ), $block, $resolver, $path, $diagnostics );

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

			case 'image-gallery':
			case 'gallery':
				return ImageGallery::render( $block, $resolver, $path );

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
			case 'countdown':
				return Countdown::render( $block, $resolver, $path, $diagnostics );

			case 'nav-menu':
				return NavMenu::render( $block, $resolver, $path, $diagnostics );

			case 'icon-list':
				return IconList::render( $block, $resolver, $path );

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
	 * Walk the given container block's nested `blocks`, render each through
	 * the dispatcher, and append the results to the rendered shell's
	 * `elements` field.
	 *
	 * @param array<string, mixed>              $rendered    The container shell produced by Section/Column/Container::render.
	 * @param array<string, mixed>              $block       The original spec block whose `blocks` we still need to render.
	 * @param array<int, array<string, mixed>>  $diagnostics Forwarded so unsupported children are reported.
	 * @return array<string, mixed>
	 */
	private static function with_children( array $rendered, array $block, Resolver $resolver, string $path, array &$diagnostics ): array {
		$nested = isset( $block['blocks'] ) && is_array( $block['blocks'] ) ? $block['blocks'] : [];
		if ( empty( $nested ) ) {
			return $rendered;
		}
		$children = isset( $rendered['elements'] ) && is_array( $rendered['elements'] ) ? $rendered['elements'] : [];
		foreach ( $nested as $i => $child ) {
			$child_path     = $path . '.b' . $i;
			$child_rendered = self::render_block( (array) $child, $resolver, $child_path, $diagnostics );
			if ( null !== $child_rendered ) {
				$children[] = $child_rendered;
			}
		}
		$rendered['elements'] = $children;
		return $rendered;
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
