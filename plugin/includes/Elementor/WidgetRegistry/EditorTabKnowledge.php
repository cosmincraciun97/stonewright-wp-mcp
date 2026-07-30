<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\Elementor\WidgetRegistry;

/**
 * Shared Elementor editor-tab knowledge used by planning abilities.
 */
final class EditorTabKnowledge {

	/**
	 * @return list<string>
	 */
	public static function advanced_control_keys(): array {
		return [
			'position_absolute',
			'z_index',
			'motion_effects',
			'transform',
			'background',
			'background_overlay',
			'border',
			'mask',
			'responsive',
			'attributes',
			'order',
			'align_self',
			'width',
			'padding',
			'margin',
			'css_id',
			'css_classes',
		];
	}

	/**
	 * @return list<string>
	 */
	public static function advanced_control_labels(): array {
		return [
			'position absolute/fixed when design requires it',
			'z-index and order',
			'motion effects and transform',
			'background, background overlay, border, mask, and shadow',
			'responsive visibility',
			'attributes, CSS ID, and CSS classes',
			'width, align self, margin, and padding',
		];
	}

	/**
	 * @param list<array<string, mixed>> $controls
	 * @return array<string, array<string, mixed>>
	 */
	public static function group_controls( array $controls ): array {
		$groups = [
			'Content'  => self::empty_group(),
			'Style'    => self::empty_group(),
			'Advanced' => self::empty_group(),
			'Unknown'  => self::empty_group(),
		];

		foreach ( $controls as $control ) {
			$tab   = self::normalise_tab( (string) ( $control['tab'] ?? '' ) );
			$group = $tab ?: 'Unknown';

			$groups[ $group ]['controls'][] = [
				'name'    => (string) ( $control['name'] ?? '' ),
				'type'    => (string) ( $control['type'] ?? '' ),
				'label'   => (string) ( $control['label'] ?? '' ),
				'section' => (string) ( $control['section'] ?? '' ),
			];
		}

		foreach ( $groups as $name => $group ) {
			$groups[ $name ]['count'] = count( $group['controls'] );
		}

		$groups['Advanced']['global_controls'] = self::advanced_control_keys();

		return $groups;
	}

	/**
	 * @return array{count:int,controls:list<array<string,string>>,global_controls?:list<string>}
	 */
	private static function empty_group(): array {
		return [
			'count'    => 0,
			'controls' => [],
		];
	}

	private static function normalise_tab( string $tab ): string {
		$tab = strtolower( trim( $tab ) );

		return match ( $tab ) {
			'content', 'tab_content' => 'Content',
			'style', 'tab_style' => 'Style',
			'advanced', 'tab_advanced' => 'Advanced',
			default => '',
		};
	}
}
