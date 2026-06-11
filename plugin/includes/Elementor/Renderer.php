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

	private const DEFAULT_LAYOUT_WIDTH = 1280.0;

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
			$out[]                       = self::normalise_container_layout( $section_element, $diagnostics, $section_path );
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
			// outer render() loop. Caught during nested layout verification: the
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
		return self::normalise_container_layout( $rendered, $diagnostics, $path );
	}

	/**
	 * @param array<string, mixed> $rendered
	 * @param array<int, array<string, mixed>> $diagnostics
	 * @return array<string, mixed>
	 */
	private static function normalise_container_layout( array $rendered, array &$diagnostics = [], string $path = '' ): array {
		$settings = isset( $rendered['settings'] ) && is_array( $rendered['settings'] )
			? $rendered['settings']
			: [];
		$children = isset( $rendered['elements'] ) && is_array( $rendered['elements'] )
			? $rendered['elements']
			: [];

		if (
			'full' === ( $settings['content_width'] ?? null )
			&& 'column' === ( $settings['flex_direction'] ?? null )
			&& ! isset( $settings['flex_align_items'] )
			&& self::has_fixed_width_child( $children )
		) {
			$settings['flex_align_items'] = 'center';
		}

		if ( 'row' === ( $settings['flex_direction'] ?? null ) ) {
			self::report_fixed_width_overflow( $children, $settings, $diagnostics, $path );
			$children = self::fit_percent_children_with_gap( $children, $settings );
		}

		$rendered['settings'] = $settings;
		$rendered['elements'] = $children;
		return $rendered;
	}

	/**
	 * @param array<int, array<string, mixed>> $children
	 */
	private static function has_fixed_width_child( array $children ): bool {
		foreach ( $children as $child ) {
			$width = $child['settings']['width'] ?? null;
			if ( is_array( $width ) && 'px' === ( $width['unit'] ?? null ) && is_numeric( $width['size'] ?? null ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * @param array<int, array<string, mixed>> $children
	 * @param array<string, mixed>            $settings
	 * @return array<int, array<string, mixed>>
	 */
	private static function fit_percent_children_with_gap( array $children, array $settings ): array {
		$child_count = count( $children );
		if ( $child_count < 2 ) {
			return $children;
		}

		$widths = [];
		$total  = 0.0;
		foreach ( $children as $index => $child ) {
			$width = $child['settings']['width'] ?? null;
			if ( ! is_array( $width ) || '%' !== ( $width['unit'] ?? null ) || ! is_numeric( $width['size'] ?? null ) ) {
				return $children;
			}

			$size             = (float) $width['size'];
			$widths[ $index ] = $size;
			$total           += $size;
		}

		$gap_px     = self::gap_px( $settings['flex_gap'] ?? null );
		$parent_px  = self::parent_width_px( $settings );
		$gap_ratio  = ( $gap_px * ( $child_count - 1 ) / $parent_px ) * 100.0;
		$available  = max( 0.0, 100.0 - $gap_ratio );
		if ( $total <= $available || $available <= 0.0 ) {
			return $children;
		}

		$scale = $available / $total;
		foreach ( $widths as $index => $size ) {
			$children[ $index ]['settings']['width']['size'] = round( $size * $scale, 3 );
		}

		return $children;
	}

	/**
	 * @param array<int, array<string, mixed>>  $children
	 * @param array<string, mixed>             $settings
	 * @param array<int, array<string, mixed>> $diagnostics
	 */
	private static function report_fixed_width_overflow( array $children, array $settings, array &$diagnostics, string $path ): void {
		$child_count = count( $children );
		if ( $child_count < 2 ) {
			return;
		}

		$total_width = 0.0;
		foreach ( $children as $child ) {
			$width = $child['settings']['width'] ?? null;
			if ( ! is_array( $width ) || 'px' !== ( $width['unit'] ?? null ) || ! is_numeric( $width['size'] ?? null ) ) {
				return;
			}
			$total_width += (float) $width['size'];
		}

		$parent_width = self::parent_width_px( $settings );
		$total        = $total_width + self::gap_px( $settings['flex_gap'] ?? null ) * ( $child_count - 1 );
		if ( $total <= $parent_width ) {
			return;
		}

		$diagnostics[] = [
			'code'      => 'layout_width_overflow_risk',
			'path'      => $path,
			'renderer'  => 'elementor_v3',
			'message'   => 'Fixed-width row children exceed parent width after gaps. Use wider parent, wrapping, smaller children, or responsive hide rules.',
			'total_px'  => $total,
			'parent_px' => $parent_width,
		];
	}

	/**
	 * @param mixed $gap
	 */
	private static function gap_px( mixed $gap ): float {
		if ( ! is_array( $gap ) ) {
			return 0.0;
		}

		foreach ( [ 'column', 'size' ] as $key ) {
			if ( isset( $gap[ $key ] ) && is_numeric( $gap[ $key ] ) ) {
				return (float) $gap[ $key ];
			}
		}

		return 0.0;
	}

	/**
	 * @param array<string, mixed> $settings
	 */
	private static function parent_width_px( array $settings ): float {
		$content_width = $settings['content_width'] ?? null;
		if ( is_numeric( $content_width ) ) {
			return max( 1.0, (float) $content_width );
		}

		$width = $settings['width'] ?? null;
		if ( is_array( $width ) && 'px' === ( $width['unit'] ?? null ) && is_numeric( $width['size'] ?? null ) ) {
			return max( 1.0, (float) $width['size'] );
		}

		return self::DEFAULT_LAYOUT_WIDTH;
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
