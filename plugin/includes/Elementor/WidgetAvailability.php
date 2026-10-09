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
