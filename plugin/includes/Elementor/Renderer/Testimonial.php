<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\Elementor\Renderer;

use Stonewright\WpMcp\DesignTokens\Resolver;

/**
 * Renders a DesignSpec `testimonial` node as an Elementor testimonial widget.
 *
 * Spec shape:
 *   { type: "testimonial", content: "...", name: "...", job: "...", image: { url, id, alt } }
 */
final class Testimonial {

	/**
	 * @param array<string, mixed> $node
	 * @param Resolver             $resolver
	 * @param string               $canonical_path
	 * @return array<string, mixed>
	 */
	public static function render( array $node, Resolver $resolver, string $canonical_path ): array {
		$image_data = isset( $node['image'] ) && is_array( $node['image'] ) ? $node['image'] : [];

		$settings = [
			'testimonial_content'     => (string) ( $node['content'] ?? $node['text'] ?? '' ),
			'testimonial_name'        => (string) ( $node['name'] ?? '' ),
			'testimonial_job'         => (string) ( $node['job'] ?? $node['title'] ?? '' ),
			'testimonial_image'       => [
				'url' => (string) ( $image_data['url'] ?? '' ),
				'id'  => isset( $image_data['id'] ) ? (int) $image_data['id'] : '',
				'alt' => (string) ( $image_data['alt'] ?? '' ),
			],
		];

		if ( isset( $node['align'] ) ) {
			$settings['testimonial_alignment'] = (string) $node['align'];
		}

		if ( isset( $node['image_position'] ) ) {
			$settings['testimonial_image_position'] = (string) $node['image_position'];
		}

		return [
			'id'         => Section::stable_id( $canonical_path ),
			'elType'     => 'widget',
			'widgetType' => 'testimonial',
			'settings'   => $settings,
			'elements'   => [],
		];
	}
}
