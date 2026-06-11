<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\Elementor\Renderer;

use Stonewright\WpMcp\DesignTokens\Resolver;

/**
 * Renders a DesignSpec `social-icons` node as an Elementor social-icons widget.
 *
 * Spec shape:
 *   {
 *     type: "social-icons",
 *     icons: [
 *       { network: "facebook", url: "...", icon: "fab fa-facebook-f" },
 *       { network: "twitter",  url: "...", icon: "fab fa-x-twitter"  }
 *     ]
 *   }
 */
final class SocialIcons {

	/**
	 * @param array<string, mixed> $node
	 * @param Resolver             $resolver
	 * @param string               $canonical_path
	 * @return array<string, mixed>
	 */
	public static function render( array $node, Resolver $resolver, string $canonical_path ): array {
		$social_items = [];
		$raw_icons    = isset( $node['icons'] ) && is_array( $node['icons'] ) ? $node['icons'] : [];
		foreach ( $raw_icons as $i => $icon_item ) {
			$icon_item = is_array( $icon_item ) ? $icon_item : [];
			$network   = (string) ( $icon_item['network'] ?? 'facebook' );
			$icon_val  = (string) ( $icon_item['icon'] ?? 'fab fa-' . $network );
			$social_items[] = [
				'_id'         => Section::stable_id( $canonical_path . '.icon.' . $i ),
				'social_icon' => [
					'value'   => $icon_val,
					'library' => 'fa-brands',
				],
				'link'        => [
					'url'         => (string) ( $icon_item['url'] ?? '' ),
					'is_external' => ! empty( $icon_item['external'] ),
					'nofollow'    => ! empty( $icon_item['nofollow'] ),
				],
			];
		}

		$settings = [
			'social_icon_list' => $social_items,
		];

		if ( isset( $node['align'] ) ) {
			$settings['align'] = (string) $node['align'];
		}

		if ( isset( $node['shape'] ) ) {
			$settings['shape'] = (string) $node['shape'];
		}

		return [
			'id'         => Section::stable_id( $canonical_path ),
			'elType'     => 'widget',
			'widgetType' => 'social-icons',
			'settings'   => $settings,
			'elements'   => [],
		];
	}
}
