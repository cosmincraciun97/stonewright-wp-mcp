<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\Elementor\Renderer;

use Stonewright\WpMcp\DesignTokens\Resolver;

/**
 * Renders a generic DesignSpec container/group node as an Elementor V3 container.
 *
 * Used for the `group` spec type and as a generic wrapper.
 */
final class Container {

	/**
	 * @return array<string, string|array<string, mixed>>
	 */
	private static function style_map(): array {
		return [
			'background'    => [ 'key' => 'background_color', 'is_background' => true ],
			'padding'       => [ 'key' => 'padding', 'is_dimension' => true ],
			'margin'        => [ 'key' => '_margin', 'is_dimension' => true ],
			'border'        => [ 'is_border' => true, 'prefix' => 'border' ],
			'border_radius' => [ 'key' => 'border_radius', 'is_dimension' => true ],
			'width'         => [ 'key' => 'width', 'is_size' => true ],
			'height'        => [ 'key' => 'height', 'is_size' => true ],
			'min_height'    => [ 'key' => 'min_height', 'is_size' => true ],
		];
	}

	/**
	 * @param array<string, mixed> $node
	 * @param Resolver             $resolver
	 * @param string               $canonical_path
	 * @return array<string, mixed>
	 */
	public static function render( array $node, Resolver $resolver, string $canonical_path ): array {
		$style            = StyleMapper::node_style( $node, $resolver );
		$raw_layout       = isset( $node['layout'] ) ? (string) $node['layout'] : '';
		$layout           = 'grid' === $raw_layout ? 'grid' : 'flex';
		$direction_source = $node['direction'] ?? ( $style['direction'] ?? ( $style['flex_direction'] ?? null ) );
		if ( null === $direction_source && in_array( $raw_layout, [ 'horizontal', 'vertical' ], true ) ) {
			$direction_source = 'horizontal' === $raw_layout ? 'row' : 'column';
		}
		$direction        = 'row' === $direction_source ? 'row' : 'column';

		$is_companion_sized = ! empty( $node['fullWidth'] )
			|| ! empty( $node['full_width'] )
			|| array_key_exists( 'width', $node )
			|| array_key_exists( 'height', $node );

		$settings = [
			'container_type' => $layout,
		];
		if ( $is_companion_sized ) {
			$settings['content_width'] = 'full';
			$settings['padding']       = StyleMapper::dimensions( 0 );
		}
		if ( 'grid' === $layout ) {
			$settings['grid_columns_grid'] = [
				'unit' => 'fr',
				'size' => isset( $node['columns'] ) ? max( 1, (int) $node['columns'] ) : 2,
			];
		} else {
			$settings['flex_direction'] = $direction;
			if ( isset( $node['justify_content'] ) ) {
				$settings['flex_justify_content'] = self::flex_alignment( (string) $node['justify_content'] );
			}
			if ( isset( $node['align_items'] ) ) {
				$settings['flex_align_items'] = self::flex_alignment( (string) $node['align_items'] );
			}
			if ( isset( $node['wrap'] ) ) {
				$settings['flex_wrap'] = (string) $node['wrap'];
			}
		}

		$gap = $node['gap'] ?? ( $style['gap'] ?? null );
		if ( null !== $gap ) {
			$settings['flex_gap'] = self::gap( $gap );
		}

		if ( isset( $node['background'] ) && is_array( $node['background'] ) ) {
			$bg = $node['background'];
			if ( isset( $bg['color'] ) ) {
				$settings['background_background'] = 'classic';
				$settings['background_color']      = (string) $resolver->resolve( $bg['color'] );
			}
			if ( isset( $bg['image'] ) ) {
				$settings['background_background'] = 'classic';
				$settings['background_image']      = [ 'url' => (string) $resolver->resolve( $bg['image'] ) ];
				if ( isset( $bg['image_id'] ) ) {
					$settings['background_image']['id'] = (int) $bg['image_id'];
				}
			}
			if ( isset( $bg['position'] ) ) {
				$settings['background_position'] = (string) $bg['position'];
			}
			if ( isset( $bg['size'] ) ) {
				$settings['background_size'] = (string) $bg['size'];
			}
			if ( isset( $bg['repeat'] ) ) {
				$settings['background_repeat'] = (string) $bg['repeat'];
			}
		}
		$background = $style['background'] ?? ( $style['background_color'] ?? null );
		if ( null !== $background ) {
			$settings['background_background'] = 'classic';
			$settings['background_color']      = (string) $background;
		}

		if ( isset( $node['padding'] ) && is_array( $node['padding'] ) ) {
			$settings['padding'] = [
				'unit'     => 'px',
				'top'      => isset( $node['padding']['top'] ) ? (string) (int) $node['padding']['top'] : '0',
				'right'    => isset( $node['padding']['right'] ) ? (string) (int) $node['padding']['right'] : '0',
				'bottom'   => isset( $node['padding']['bottom'] ) ? (string) (int) $node['padding']['bottom'] : '0',
				'left'     => isset( $node['padding']['left'] ) ? (string) (int) $node['padding']['left'] : '0',
				'isLinked' => false,
			];
		}
		if ( array_key_exists( 'padding', $style ) ) {
			$padding = StyleMapper::dimensions( $style['padding'] );
			if ( null !== $padding ) {
				$settings['padding'] = $padding;
			}
		}
		if ( array_key_exists( 'width', $node ) ) {
			$width = StyleMapper::size( $node['width'] );
			if ( null !== $width ) {
				$settings['width'] = $width;
			}
		}
		if ( array_key_exists( 'height', $node ) ) {
			$height = StyleMapper::size( $node['height'] );
			if ( null !== $height ) {
				$settings['height'] = $height;
			}
		}
		if ( array_key_exists( 'width', $style ) ) {
			$width = StyleMapper::size( $style['width'] );
			if ( null !== $width ) {
				$settings['width'] = $width;
			}
		}
		if ( array_key_exists( 'height', $style ) ) {
			$height = StyleMapper::size( $style['height'] );
			if ( null !== $height ) {
				$settings['height'] = $height;
			}
		}
		if ( [] !== $style ) {
			$settings = StyleMapper::apply( $settings, $style, self::style_map() );
		}
		$settings = self::apply_advanced_settings( $settings, $node );

		return [
			'id'       => Section::stable_id( $canonical_path ),
			'elType'   => 'container',
			'isInner'  => true,
			'settings' => $settings,
			'elements' => [],
		];
	}

	/**
	 * @return array<string, mixed>
	 */
	private static function gap( mixed $gap ): array {
		if ( is_numeric( $gap ) ) {
			$gap = (string) (int) $gap . 'px';
		}
		$value = preg_replace( '/[^0-9.]/', '', (string) $gap ) ?: '0';

		return [
			'unit'     => 'px',
			'size'     => (int) $value,
			'column'   => $value,
			'row'      => $value,
			'isLinked' => true,
		];
	}

	private static function flex_alignment( string $value ): string {
		return match ( $value ) {
			'start' => 'flex-start',
			'end'   => 'flex-end',
			default => $value,
		};
	}

	/**
	 * @param array<string, mixed> $settings
	 * @param array<string, mixed> $node
	 * @return array<string, mixed>
	 */
	private static function apply_advanced_settings( array $settings, array $node ): array {
		if ( isset( $node['sticky'] ) ) {
			$sticky = (string) $node['sticky'];
			$settings['sticky'] = in_array( $sticky, [ 'top', 'bottom' ], true ) ? $sticky : 'top';
		}

		if ( isset( $node['sticky_on'] ) && is_array( $node['sticky_on'] ) ) {
			$settings['sticky_on'] = array_values( array_intersect( (array) $node['sticky_on'], [ 'desktop', 'tablet', 'mobile' ] ) );
		}

		if ( isset( $node['sticky_offset'] ) ) {
			$settings['sticky_offset'] = (int) $node['sticky_offset'];
		}

		if ( isset( $node['z_index'] ) ) {
			$settings['z_index'] = (int) $node['z_index'];
		}

		if ( isset( $node['css_classes'] ) ) {
			$settings['_css_classes'] = sanitize_html_class( (string) $node['css_classes'] );
		}

		foreach ( (array) ( $node['hide_on'] ?? [] ) as $viewport ) {
			if ( 'desktop' === $viewport ) {
				$settings['hide_desktop'] = 'hidden-desktop';
			} elseif ( 'tablet' === $viewport ) {
				$settings['hide_tablet'] = 'hidden-tablet';
			} elseif ( 'mobile' === $viewport ) {
				$settings['hide_mobile'] = 'hidden-mobile';
			}
		}

		return $settings;
	}
}
