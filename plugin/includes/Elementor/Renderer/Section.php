<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\Elementor\Renderer;

use Stonewright\WpMcp\DesignTokens\Resolver;

/**
 * Renders a DesignSpec section node as an Elementor V3 container element.
 *
 * Elementor V3 dropped the classic section/column model in favour of flex
 * containers. We emit elType=container with flex_direction=column so inner
 * column-type containers lay out correctly.
 */
final class Section {

	/**
	 * @param array<string, mixed> $node            Validated DesignSpec section node.
	 * @param Resolver             $resolver         Token resolver from the same spec.
	 * @param string               $canonical_path   Dot-delimited path used for stable IDs (e.g. "s0").
	 * @return array<string, mixed>
	 */
	public static function render( array $node, Resolver $resolver, string $canonical_path ): array {
		return [
			'id'       => self::stable_id( $canonical_path ),
			'elType'   => 'container',
			'isInner'  => false,
			'settings' => self::build_settings( $node, $resolver ),
			'elements' => [],
		];
	}

	/**
	 * @param array<string, mixed> $node
	 * @return array<string, mixed>
	 */
	private static function build_settings( array $node, Resolver $resolver ): array {
		// `row` block-type auto-implies horizontal layout, even if the spec
		// doesn't set layout explicitly. For sections, explicit `layout` wins.
		$type      = isset( $node['type'] ) ? (string) $node['type'] : '';
		$layout    = isset( $node['layout'] ) ? (string) $node['layout'] : ( 'row' === $type ? 'row' : 'stack' );
		$direction = 'row' === $layout ? 'row' : 'column';

		$settings = [
			'content_width'  => isset( $node['width'] ) ? (string) $node['width'] : 'boxed',
			'flex_direction' => $direction,
		];

		// gap → flex_gap (Elementor's atomic gap setting). Accept either a
		// scalar (single value, applied to both axes) or a viewport-keyed
		// dict (responsive). Numbers and bare px strings both work.
		if ( isset( $node['gap'] ) ) {
			$gap = $node['gap'];
			if ( is_numeric( $gap ) ) {
				$gap = (string) (int) $gap . 'px';
			}
			$settings['flex_gap'] = [
				'unit'     => 'px',
				'size'     => (int) preg_replace( '/[^0-9]/', '', (string) $gap ) ?: 0,
				'column'   => preg_replace( '/[^0-9.]/', '', (string) $gap ) ?: '0',
				'row'      => preg_replace( '/[^0-9.]/', '', (string) $gap ) ?: '0',
				'isLinked' => true,
			];
		}

		if ( isset( $node['background'] ) && is_array( $node['background'] ) ) {
			$bg = $node['background'];
			if ( isset( $bg['color'] ) ) {
				$color                             = (string) $resolver->resolve( $bg['color'] );
				$settings['background_background'] = 'classic';
				$settings['background_color']      = $color;
			}
			if ( isset( $bg['image'] ) ) {
				$settings['background_background'] = 'classic';
				$settings['background_image']      = [ 'url' => (string) $resolver->resolve( $bg['image'] ) ];
			}
		}

		if ( isset( $node['padding'] ) && is_array( $node['padding'] ) ) {
			$settings['padding'] = self::dimensions( $node['padding'] );
		}

		return $settings;
	}

	/**
	 * @param array<string, mixed> $dim
	 * @return array<string, mixed>
	 */
	private static function dimensions( array $dim ): array {
		return [
			'unit'     => 'px',
			'top'      => isset( $dim['top'] ) ? (string) (int) $dim['top'] : '0',
			'right'    => isset( $dim['right'] ) ? (string) (int) $dim['right'] : '0',
			'bottom'   => isset( $dim['bottom'] ) ? (string) (int) $dim['bottom'] : '0',
			'left'     => isset( $dim['left'] ) ? (string) (int) $dim['left'] : '0',
			'isLinked' => false,
		];
	}

	public static function stable_id( string $canonical_path ): string {
		return substr( sha1( $canonical_path ), 0, 7 );
	}
}
