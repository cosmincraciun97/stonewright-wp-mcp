<?php
// SPDX-License-Identifier: GPL-2.0-or-later
declare( strict_types=1 );

namespace Stonewright\WpMcp\Tests\Unit\Elementor\Renderer;

use PHPUnit\Framework\TestCase;
use Stonewright\WpMcp\Elementor\Renderer;
use Stonewright\WpMcp\Support\ElementorData;

/**
 * Every block type the Elementor V3 renderer supports must produce settings the write validation accepts.
 *
 * The check is the write gate itself: the rendered tree goes through the preflight a write runs against an
 * empty document, which validates each element's settings against the bundled widget and container schemas
 * with conditions enforced and refuses a key the schema does not define.
 *
 * @covers \Stonewright\WpMcp\Elementor\Renderer
 */
final class RendererSchemaConformanceTest extends TestCase {

	protected function setUp(): void {
		$GLOBALS['stonewright_test_options']   = [ 'stonewright_mode' => 'development' ];
		$GLOBALS['stonewright_test_transients'] = [];
	}

	protected function tearDown(): void {
		$GLOBALS['stonewright_test_options']    = [];
		$GLOBALS['stonewright_test_transients'] = [];
	}

	/**
	 * Every `type` the renderer's dispatcher handles, with a representative block that uses the common
	 * content and style fields.
	 *
	 * @return array<string, array{0:array<string,mixed>}>
	 */
	public static function block_types(): array {
		$style = [ 'color' => '#112233', 'font_size' => 18, 'font_weight' => '600', 'align' => 'center' ];
		$link  = [ 'url' => 'https://example.test/page' ];
		return [
			'heading'              => [ [ 'type' => 'heading', 'text' => 'Title', 'level' => 2, 'style' => $style ] ],
			'paragraph'            => [ [ 'type' => 'paragraph', 'text' => 'Body copy.', 'style' => $style ] ],
			'text-editor'          => [ [ 'type' => 'text-editor', 'text' => '<p>Rich body.</p>', 'style' => $style ] ],
			'embed'                => [ [ 'type' => 'embed', 'html' => '<p>Embedded.</p>' ] ],
			'image'                => [ [ 'type' => 'image', 'src' => 'https://example.test/a.jpg', 'alt' => 'Alt', 'link' => $link ] ],
			'image-gallery'        => [ [ 'type' => 'image-gallery', 'images' => [ [ 'url' => 'https://example.test/a.jpg', 'id' => 11 ], [ 'url' => 'https://example.test/b.jpg', 'id' => 12 ] ], 'columns' => 3 ] ],
			'video'                => [ [ 'type' => 'video', 'url' => 'https://example.test/v.mp4' ] ],
			'button'               => [ [ 'type' => 'button', 'text' => 'Go', 'url' => 'https://example.test/go', 'style' => [ 'background_color' => '#223344', 'color' => '#ffffff', 'font_size' => 16 ] ] ],
			'call-to-action'       => [ [ 'type' => 'call-to-action', 'title' => 'Act now', 'description' => 'Do it.', 'button_text' => 'Go', 'url' => 'https://example.test/go' ] ],
			'chip-list'            => [ [ 'type' => 'chip-list', 'items' => [ [ 'text' => 'Audit', 'url' => 'https://example.test/audit' ], [ 'text' => 'Native', 'style' => 'text' ] ], 'gap' => 10 ] ],
			'spacer'               => [ [ 'type' => 'spacer', 'height' => 40 ] ],
			'divider'              => [ [ 'type' => 'divider', 'color' => '#cccccc', 'weight' => 2, 'width' => 60 ] ],
			'icon'                 => [ [ 'type' => 'icon', 'icon' => 'fas fa-star', 'size' => 32, 'color' => '#aa0000', 'align' => 'center' ] ],
			'icon-box'             => [ [ 'type' => 'icon-box', 'title' => 'Fast', 'description' => 'Quick.', 'icon' => 'fas fa-bolt', 'icon_color' => '#223344' ] ],
			'image-box'            => [ [ 'type' => 'image-box', 'title' => 'Team', 'description' => 'People.', 'image' => 'https://example.test/t.jpg' ] ],
			'testimonial'          => [ [ 'type' => 'testimonial', 'text' => 'Great.', 'name' => 'A. Person', 'job' => 'Owner' ] ],
			'testimonial-carousel' => [ [ 'type' => 'testimonial-carousel', 'slides' => [ [ 'text' => 'Great.', 'name' => 'A. Person', 'job' => 'Owner' ] ] ] ],
			'tabs'                 => [ [ 'type' => 'tabs', 'tabs' => [ [ 'title' => 'One', 'content' => 'First.' ], [ 'title' => 'Two', 'content' => 'Second.' ] ] ] ],
			'accordion'            => [ [ 'type' => 'accordion', 'items' => [ [ 'title' => 'Q1', 'content' => 'A1.' ], [ 'title' => 'Q2', 'content' => 'A2.' ] ] ] ],
			'toggle'               => [ [ 'type' => 'toggle', 'items' => [ [ 'title' => 'Q1', 'content' => 'A1.' ] ] ] ],
			'social-icons'         => [ [ 'type' => 'social-icons', 'icons' => [ [ 'network' => 'facebook', 'url' => 'https://example.test/f' ], [ 'network' => 'x', 'url' => 'https://example.test/x' ] ] ] ],
			'progress-bar'         => [ [ 'type' => 'progress-bar', 'title' => 'Skill', 'percent' => 80, 'color' => '#223344' ] ],
			'counter'              => [ [ 'type' => 'counter', 'from' => 0, 'to' => 250, 'suffix' => '+', 'title' => 'Clients' ] ],
			'countdown'            => [ [ 'type' => 'countdown', 'due_date' => '2030-01-01 00:00' ] ],
			'nav-menu'             => [ [ 'type' => 'nav-menu', 'items' => [ [ 'text' => 'Home', 'url' => 'https://example.test/' ] ] ] ],
			'icon-list'            => [ [ 'type' => 'icon-list', 'items' => [ [ 'text' => 'One', 'icon' => 'fas fa-check' ], [ 'text' => 'Two', 'icon' => 'fas fa-check' ] ] ] ],
			'form'                 => [ [ 'type' => 'form', 'fields' => [ [ 'type' => 'text', 'label' => 'Name' ], [ 'type' => 'email', 'label' => 'Email' ] ], 'button_text' => 'Send' ] ],
			'slides'               => [ [ 'type' => 'slides', 'slides' => [ [ 'heading' => 'One', 'description' => 'First.' ] ] ] ],
			'list'                 => [ [ 'type' => 'list', 'items' => [ 'One', 'Two' ] ] ],
			'container'            => [ [ 'type' => 'container', 'direction' => 'row', 'gap' => 16, 'padding' => 24, 'blocks' => [ [ 'type' => 'heading', 'text' => 'Inner' ], [ 'type' => 'paragraph', 'text' => 'Inner body.' ] ] ] ],
			// Top-level content fields rather than a style map.
			'heading-direct'       => [ [ 'type' => 'heading', 'text' => 'Title', 'level' => 3, 'font_size' => 28, 'align' => 'center', 'color' => '#112233', 'url' => 'https://example.test/t' ] ],
			'heading-responsive'   => [ [ 'type' => 'heading', 'text' => 'Title', 'font_size' => [ 'desktop' => 40, 'tablet' => 32, 'mobile' => 24 ], 'align' => [ 'desktop' => 'left', 'mobile' => 'center' ] ] ],
			'paragraph-direct'     => [ [ 'type' => 'paragraph', 'text' => 'Body', 'font_size' => 16, 'color' => '#334455' ] ],
			'text-editor-direct'   => [ [ 'type' => 'text-editor', 'text' => '<p>Body</p>', 'font_size' => 17, 'color' => '#334455', 'align' => 'center' ] ],
			'button-direct'        => [ [ 'type' => 'button', 'text' => 'Go', 'url' => 'https://example.test/go', 'font_size' => 15, 'color' => '#ffffff', 'background_color' => '#223344', 'align' => 'center', 'size' => 'lg', 'padding' => 12 ] ],
			'button-with-icon'     => [ [ 'type' => 'button', 'text' => 'Go', 'url' => 'https://example.test/go', 'icon' => 'fas fa-arrow-right', 'icon_position' => 'right' ] ],
			'icon-with-library'    => [ [ 'type' => 'icon', 'icon' => 'fa-heart', 'library' => 'fa-solid', 'link' => [ 'url' => 'https://example.test/i' ] ] ],
			'icon-responsive-size' => [ [ 'type' => 'icon', 'icon' => 'eicon-star', 'size' => [ 'desktop' => [ 'unit' => 'px', 'size' => 40 ], 'mobile' => [ 'unit' => 'px', 'size' => 24 ] ] ] ],
			'chip-list-styled'     => [ [ 'type' => 'pills', 'items' => [ 'One', [ 'text' => 'Two', 'style' => 'button', 'url' => 'https://example.test/two' ] ], 'background_color' => '#eeeeee', 'color' => '#111111', 'border_radius' => 12, 'justify_content' => 'center' ] ],
			// Optional fields.
			'divider-full'         => [ [ 'type' => 'divider', 'style' => 'dashed', 'color' => '#cccccc', 'weight' => 3, 'width' => 80, 'align' => 'center', 'gap' => 20 ] ],
			'separator'            => [ [ 'type' => 'separator' ] ],
			'image-full'           => [ [ 'type' => 'image', 'url' => 'https://example.test/a.jpg', 'id' => 5, 'alt' => 'Alt', 'caption' => 'Cap', 'align' => 'center', 'width' => 50, 'height' => 200, 'size' => 'large' ] ],
			'gallery-full'         => [ [ 'type' => 'gallery', 'images' => [ [ 'url' => 'https://example.test/a.jpg', 'id' => 11 ] ], 'columns' => 4, 'spacing' => 10, 'link_to' => 'file', 'open_lightbox' => 'yes', 'orderby' => 'rand', 'image_size' => 'medium' ] ],
			'video-youtube'        => [ [ 'type' => 'video', 'url' => 'https://www.youtube.com/watch?v=abcdefghijk', 'autoplay' => true, 'mute' => true, 'loop' => true, 'controls' => false, 'aspect_ratio' => '169' ] ],
			'video-vimeo'          => [ [ 'type' => 'video', 'url' => 'https://vimeo.com/123456789' ] ],
			'video-hosted-poster'  => [ [ 'type' => 'video', 'url' => 'https://example.test/v.mp4', 'poster' => [ 'url' => 'https://example.test/p.jpg' ], 'autoplay' => true ] ],
			'counter-full'         => [ [ 'type' => 'counter', 'starting_number' => 10, 'ending_number' => 90, 'prefix' => '~', 'suffix' => '%', 'duration' => 1500, 'title' => 'Rate' ] ],
			'progress-full'        => [ [ 'type' => 'progress-bar', 'title' => 'Skill', 'percent' => 60, 'display_percentage' => 'show', 'style' => [ 'color' => '#aa0000' ] ] ],
			'spacer-space'         => [ [ 'type' => 'spacer', 'space' => [ 'desktop' => [ 'unit' => 'px', 'size' => 80 ], 'mobile' => [ 'unit' => 'px', 'size' => 40 ] ] ] ],
			'social-full'          => [ [ 'type' => 'social-icons', 'icons' => [ [ 'network' => 'linkedin', 'url' => 'https://example.test/l' ] ], 'shape' => 'rounded', 'align' => 'center' ] ],
			'tabs-vertical'        => [ [ 'type' => 'tabs', 'type_of_tabs' => 'vertical', 'tabs' => [ [ 'title' => 'One', 'content' => 'First.' ] ] ] ],
			'icon-box-full'        => [ [ 'type' => 'icon-box', 'title' => 'Fast', 'description' => 'Quick.', 'icon' => 'fas fa-bolt', 'align' => 'center', 'title_size' => 'h4', 'link' => [ 'url' => 'https://example.test/b' ], 'icon_color' => '#223344' ] ],
			'image-box-full'       => [ [ 'type' => 'image-box', 'title' => 'Team', 'description' => 'People.', 'image' => [ 'url' => 'https://example.test/t.jpg', 'id' => 9 ], 'alt' => 'Team', 'align' => 'center', 'link' => [ 'url' => 'https://example.test/team' ] ] ],
			'testimonial-full'     => [ [ 'type' => 'testimonial', 'content' => 'Great.', 'name' => 'A. Person', 'title' => 'Owner', 'image' => 'https://example.test/p.jpg', 'image_position' => 'top', 'align' => 'center' ] ],
			'carousel-full'        => [ [ 'type' => 'carousel', 'slides' => [ [ 'content' => 'Great.', 'name' => 'A. Person', 'title' => 'Owner', 'image' => 'https://example.test/p.jpg' ] ], 'autoplay' => true, 'slides_to_show' => 2 ] ],
			'countdown-full'       => [ [ 'type' => 'countdown', 'countdown_type' => 'due_date', 'due_date' => '2030-01-01 00:00', 'show_days' => true, 'show_hours' => true, 'show_minutes' => true, 'show_seconds' => true, 'show_labels' => true, 'label_days' => 'D', 'expire_message' => 'Done' ] ],
			'countdown-evergreen'  => [ [ 'type' => 'countdown', 'countdown_type' => 'evergreen', 'evergreen_hours' => 2, 'evergreen_minutes' => 30 ] ],
			'nav-menu-full'        => [ [ 'type' => 'nav-menu', 'menu' => 'main', 'layout' => 'horizontal', 'pointer' => 'underline', 'dropdown' => 'tablet', 'align_items' => 'center', 'full_width' => true, 'toggle' => 'burger', 'toggle_align' => 'right', 'items' => [ [ 'text' => 'Home', 'url' => 'https://example.test/' ] ] ] ],
			'icon-list-full'       => [ [ 'type' => 'icon-list', 'view' => 'inline', 'divider' => true, 'divider_color' => '#cccccc', 'items' => [ [ 'text' => 'One', 'icon' => 'fas fa-check', 'url' => 'https://example.test/one' ] ] ] ],
			'form-full'            => [ [ 'type' => 'form', 'form_name' => 'Contact', 'fields' => [ [ 'type' => 'text', 'label' => 'Name', 'required' => true ], [ 'type' => 'textarea', 'label' => 'Message' ] ], 'button_text' => 'Send', 'submit_actions' => [ 'email' ] ] ],
			'slides-full'          => [ [ 'type' => 'slider', 'slides' => [ [ 'heading' => 'One', 'description' => 'First.', 'button_text' => 'Go' ] ], 'autoplay' => true, 'infinite' => true, 'pause_on_hover' => true ] ],
			'cta-full'             => [ [ 'type' => 'cta', 'skin' => 'cover', 'title' => 'Act', 'description' => 'Do it.', 'button' => 'Go', 'url' => 'https://example.test/go', 'image' => [ 'url' => 'https://example.test/c.jpg', 'id' => 7 ], 'overlay' => '#00000080', 'alignment' => 'center', 'vertical_position' => 'middle', 'title_tag' => 'h3', 'min_height' => [ 'desktop' => 400, 'mobile' => 300 ] ] ],
			'cta-classic'          => [ [ 'type' => 'call-to-action', 'skin' => 'classic', 'title' => 'Act' ] ],
			'embed-text'           => [ [ 'type' => 'embed', 'text' => 'Plain text' ] ],
			'container-styled'     => [ [ 'type' => 'group', 'direction' => 'column', 'full_width' => true, 'align_items' => 'center', 'justify_content' => 'center', 'wrap' => true, 'height' => 300, 'width' => 80, 'z_index' => 2, 'css_classes' => 'hero', 'background' => '#f5f5f5', 'hide_on' => [ 'mobile' ], 'blocks' => [ [ 'type' => 'heading', 'text' => 'Inside' ] ] ] ],
			'row-and-column'       => [ [ 'type' => 'row', 'columns' => 2, 'blocks' => [ [ 'type' => 'column', 'width' => 50, 'blocks' => [ [ 'type' => 'heading', 'text' => 'Left' ] ] ], [ 'type' => 'column', 'width' => 50, 'blocks' => [ [ 'type' => 'paragraph', 'text' => 'Right' ] ] ] ] ] ],
		];
	}

	/**
	 * Renders one block and returns the write validation's complaint, or an empty string.
	 *
	 * @param array<string, mixed> $block
	 */
	private static function refusal( array $block ): string {
		$diagnostics = [];
		$tree        = Renderer::render( [ 'sections' => [ [ 'id' => 'only', 'blocks' => [ $block ] ] ] ], $diagnostics );
		if ( [] === $tree ) {
			return 'nothing was rendered';
		}
		$error = ElementorData::preflight( [], $tree, [ 'force_destructive' => true ] );
		return $error instanceof \WP_Error ? $error->get_error_message() . ' ' . (string) wp_json_encode( $error->get_error_data()['violations'] ?? [] ) : '';
	}

	/**
	 * @dataProvider block_types
	 * @param array<string, mixed> $block
	 */
	public function test_the_rendered_block_passes_the_write_validation( array $block ): void {
		self::assertSame( '', self::refusal( $block ) );
	}

	/**
	 * The Pro-gated blocks render their widget only when Elementor Pro is active, so this runs with it present.
	 *
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_every_rendered_block_passes_the_write_validation_with_elementor_pro_active(): void {
		define( 'ELEMENTOR_PRO_VERSION', '3.0.0' );

		$refused = [];
		foreach ( self::block_types() as $name => [ $block ] ) {
			if ( 'form' === $block['type'] ) {
				// The bundled catalog has no schema for the Pro form widget, so there is nothing to validate it against.
				continue;
			}
			$message = self::refusal( $block );
			if ( '' !== $message ) {
				$refused[] = $name . ': ' . $message;
			}
		}
		self::assertSame( [], $refused );
	}
}
