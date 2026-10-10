<?php
/**
 * Decides whether an Elementor widget can render on this site.
 * SPDX-License-Identifier: GPL-2.0-or-later
 *
 * @package Stonewright
 */

declare( strict_types=1 );

namespace Stonewright\WpMcp\Elementor;

use Stonewright\WpMcp\Elementor\WidgetRegistry\WidgetCatalog;

/**
 * Decides whether a widget can render on this site.
 *
 * Elementor lists some Elementor Pro and WooCommerce widgets as placeholders
 * even when the plugin that renders them is not installed. A document that
 * stores one of those widgets shows an empty wrapper on the front end, so the
 * write is refused instead.
 */
final class WidgetAvailability {

	/** The class Elementor registers in place of a widget whose plugin is not active. */
	private const PLACEHOLDER_CLASS = 'Elementor\Modules\Promotions\Widgets\Pro_Widget_Promotion';

	private static ?bool $pro_override         = null;
	private static ?bool $woocommerce_override = null;

	/**
	 * Fixes the answers of has_pro() and has_woocommerce() for the current
	 * process. Constants cannot be undefined, so tests that exercise both sides
	 * use this; null restores detection.
	 */
	public static function override_plugins( ?bool $pro, ?bool $woocommerce ): void {
		self::$pro_override         = $pro;
		self::$woocommerce_override = $woocommerce;
	}

	public static function has_pro(): bool {
		return self::$pro_override ?? ( defined( 'ELEMENTOR_PRO_VERSION' ) || class_exists( '\\ElementorPro\\Plugin' ) );
	}

	public static function has_woocommerce(): bool {
		return self::$woocommerce_override ?? ( class_exists( '\\WooCommerce' ) || defined( 'WC_VERSION' ) );
	}

	/**
	 * How the live widget registry holds a widget: `registered` (a widget that can render, or no live registry to ask),
	 * `placeholder` (Elementor registered a stand-in because the plugin that provides the widget is not active; it
	 * renders nothing) or `unregistered` (no such widget).
	 *
	 * @return 'registered'|'placeholder'|'unregistered'
	 */
	public static function registration( string $widget_type ): string {
		if ( ! class_exists( '\Elementor\Plugin' ) || ! isset( \Elementor\Plugin::$instance ) ) {
			return 'registered';
		}
		$manager = \Elementor\Plugin::$instance->widgets_manager ?? null;
		if ( ! is_object( $manager ) || ! method_exists( $manager, 'get_widget_types' ) ) {
			return 'registered';
		}
		$widget = $manager->get_widget_types( $widget_type );
		if ( ! is_object( $widget ) ) {
			return 'unregistered';
		}

		return self::is_placeholder( $widget ) ? 'placeholder' : 'registered';
	}

	/** The plugin whose widget a placeholder stands in for. */
	public static function placeholder_requirement( string $widget_type ): string {
		$requires = self::requirement( $widget_type );

		return '' !== $requires ? $requires : 'elementor-pro';
	}

	/**
	 * Elementor's placeholder for a Pro widget is an instance of its promotion class; the flags that make it one (it
	 * is hidden from the panel and from search and sits in the Pro category) identify the same stand-in.
	 */
	private static function is_placeholder( object $widget ): bool {
		if ( is_a( $widget, self::PLACEHOLDER_CLASS ) ) {
			return true;
		}
		if ( ! method_exists( $widget, 'show_in_panel' ) || ! method_exists( $widget, 'hide_on_search' ) || ! method_exists( $widget, 'get_categories' ) ) {
			return false;
		}

		return false === $widget->show_in_panel() && true === $widget->hide_on_search() && in_array( 'pro-elements', (array) $widget->get_categories(), true );
	}

	/**
	 * The plugin a widget needs, from the bundled catalog.
	 *
	 * @return ''|'elementor-pro'|'woocommerce'
	 */
	public static function requirement( string $widget_type ): string {
		if ( ! WidgetCatalog::has( $widget_type ) ) {
			return '';
		}
		$source = (string) ( WidgetCatalog::entry( $widget_type )['source'] ?? 'free' );
		return match ( $source ) {
			'pro'   => 'elementor-pro',
			'wc'    => 'woocommerce',
			default => '',
		};
	}

	/**
	 * Refusal for a widget whose plugin is missing on this site, or null when
	 * the widget can render here.
	 */
	public static function refusal( string $widget_type ): ?\WP_Error {
		$requires = self::requirement( $widget_type );
		if ( '' === $requires ) {
			return null;
		}

		$missing = [];
		if ( ! self::has_pro() ) {
			$missing[] = 'elementor-pro';
		}
		if ( 'woocommerce' === $requires && ! self::has_woocommerce() ) {
			$missing[] = 'woocommerce';
		}
		if ( [] === $missing ) {
			return null;
		}

		return new \WP_Error(
			'stonewright_widget_unavailable',
			sprintf(
				/* translators: 1: widget type, 2: required plugins */
				__( 'The "%1$s" widget needs %2$s, which is not active on this site, so it would render empty. Use a free widget or activate the plugin first.', 'stonewright' ),
				$widget_type,
				implode( ' and ', $missing )
			),
			[
				'status'      => 409,
				'widget_type' => $widget_type,
				'requires'    => $missing[0],
				'missing'     => $missing,
				'has_pro'     => self::has_pro(),
				'retryable'   => false,
			]
		);
	}
}
