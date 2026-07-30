<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\Elementor\Renderer;

use Stonewright\WpMcp\DesignTokens\Resolver;

/**
 * Renders a DesignSpec `slides` or `slider` or `card` node as an Elementor Slides widget.
 *
 * **Elementor Pro is required.** If Elementor Pro is not active a diagnostic is
 * emitted and a heading placeholder is returned — same pattern as Form.
 *
 * Spec shape:
 *   {
 *     type: "slides",
 *     slides: [
 *       {
 *         heading: "Slide 1",
 *         description: "...",
 *         image: { url: "...", id: 0, alt: "..." },
 *         button: { text: "Learn more", url: "..." }
 *       }
 *     ]
 *   }
 */
final class Slides {

	/**
	 * @param array<string, mixed>         $node
	 * @param Resolver                     $resolver
	 * @param string                       $canonical_path
	 * @param array<int, array<string, mixed>> $diagnostics
	 * @return array<string, mixed>
	 */
	public static function render( array $node, Resolver $resolver, string $canonical_path, array &$diagnostics = [] ): array {
		if ( ! ProGate::active() ) {
			$diagnostics[] = [
				'code'     => ProGate::DIAGNOSTIC_REQUIRED,
				'type'     => (string) ( $node['type'] ?? 'slides' ),
				'path'     => $canonical_path,
				'renderer' => 'elementor_v3',
				'message'  => 'The Elementor Slides widget requires Elementor Pro. Activate Elementor Pro to render this node.',
			];

			return [
				'id'         => Section::stable_id( $canonical_path ),
				'elType'     => 'widget',
				'widgetType' => 'heading',
				'settings'   => [
					'title'       => '[Slides placeholder — Elementor Pro required]',
					'header_size' => 'p',
				],
				'elements'   => [],
			];
		}

		$slides     = [];
		$raw_slides = isset( $node['slides'] ) && is_array( $node['slides'] ) ? $node['slides'] : [];
		foreach ( $raw_slides as $i => $slide ) {
			$slide     = is_array( $slide ) ? $slide : [];
			$image     = isset( $slide['image'] ) && is_array( $slide['image'] ) ? $slide['image'] : [];
			$button    = isset( $slide['button'] ) && is_array( $slide['button'] ) ? $slide['button'] : [];
			$slides[]  = [
				'_id'               => Section::stable_id( $canonical_path . '.slide.' . $i ),
				'heading'           => (string) ( $slide['heading'] ?? '' ),
				'description'       => (string) ( $slide['description'] ?? '' ),
				'background_image'  => [
					'url' => (string) ( $image['url'] ?? '' ),
					'id'  => isset( $image['id'] ) ? (int) $image['id'] : '',
					'alt' => (string) ( $image['alt'] ?? '' ),
				],
				'button_text'       => (string) ( $button['text'] ?? '' ),
				'link'              => [ 'url' => (string) ( $button['url'] ?? '' ) ],
				'background_color'  => isset( $slide['background_color'] )
					? (string) $resolver->resolve( (string) $slide['background_color'] )
					: '',
			];
		}

		$settings = [
			'slides' => $slides,
		];

		if ( isset( $node['autoplay'] ) ) {
			$settings['autoplay'] = ! empty( $node['autoplay'] ) ? 'yes' : '';
		}

		if ( isset( $node['pause_on_hover'] ) ) {
			$settings['pause_on_hover'] = ! empty( $node['pause_on_hover'] ) ? 'yes' : '';
		}

		if ( isset( $node['infinite'] ) ) {
			$settings['infinite'] = ! empty( $node['infinite'] ) ? 'yes' : '';
		}

		return [
			'id'         => Section::stable_id( $canonical_path ),
			'elType'     => 'widget',
			'widgetType' => 'slides',
			'settings'   => $settings,
			'elements'   => [],
		];
	}
}
