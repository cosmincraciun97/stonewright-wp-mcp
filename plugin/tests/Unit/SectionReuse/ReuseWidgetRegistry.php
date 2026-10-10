<?php
/**
 * A live Elementor widget registry with placeholders and a legacy-aware heading, for the section reuse tests.
 * SPDX-License-Identifier: GPL-2.0-or-later
 *
 * @package Stonewright
 */

declare( strict_types=1 );

namespace Stonewright\WpMcp\Tests\Unit\SectionReuse;

use Elementor\Modules\Promotions\Widgets\Pro_Widget_Promotion;

/** Wraps the stub widgets manager of the test bootstrap. */
final class ReuseWidgetRegistry {

	private static ?object $original = null;

	/**
	 * @param list<string> $placeholders Widget names Elementor registers as placeholders (their plugin is not active).
	 * @param bool         $legacy_align Whether the heading's align control maps the older `left` and `right` values.
	 */
	public static function install( array $placeholders = [], bool $legacy_align = false ): void {
		self::$original ??= \Elementor\Plugin::$instance->widgets_manager;
		\Elementor\Plugin::$instance->widgets_manager = new ReuseWidgetsManager( self::$original, $placeholders, $legacy_align );
	}

	public static function restore(): void {
		if ( null !== self::$original ) {
			\Elementor\Plugin::$instance->widgets_manager = self::$original;
			self::$original                               = null;
		}
	}
}

/** The widgets manager of the test site. */
final class ReuseWidgetsManager {

	/** @param list<string> $placeholders */
	public function __construct( private object $inner, private array $placeholders, private bool $legacy_align ) {}

	/** @return array<string, object>|object|null */
	public function get_widget_types( ?string $name = null ): array|object|null {
		if ( null !== $name && in_array( $name, $this->placeholders, true ) ) {
			return new Pro_Widget_Promotion( [], [ 'widget_name' => $name, 'widget_title' => ucfirst( $name ) ] );
		}
		$widget = $this->inner->get_widget_types( $name );
		if ( 'heading' === $name && $this->legacy_align && is_object( $widget ) ) {
			return new LegacyAlignHeading( $widget );
		}

		return $widget;
	}
}

/** The heading of an Elementor that stores `start` and `end` but still renders the older `left` and `right`. */
final class LegacyAlignHeading {

	public function __construct( private object $inner ) {}

	public function get_title(): string {
		return $this->inner->get_title();
	}

	/** @return list<string> */
	public function get_categories(): array {
		return $this->inner->get_categories();
	}

	/** @return array<string, array<string, mixed>> */
	public function get_controls(): array {
		$controls          = $this->inner->get_controls();
		$controls['align'] = [
			'type'                 => 'choose',
			'label'                => 'Alignment',
			'tab'                  => 'style',
			'section'              => 'section_title_style',
			'responsive'           => true,
			'default'              => '',
			'options'              => [ 'start' => [ 'title' => 'Start' ], 'center' => [ 'title' => 'Center' ], 'end' => [ 'title' => 'End' ], 'justify' => [ 'title' => 'Justified' ] ],
			'selectors_dictionary' => [ 'left' => 'start', 'right' => 'end' ],
		];

		return $controls;
	}
}
