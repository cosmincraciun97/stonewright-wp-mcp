<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\Elementor\Renderer;

use Stonewright\WpMcp\DesignTokens\Resolver;

/**
 * Renders a DesignSpec column node as an Elementor V3 inner container.
 *
 * In Elementor V3 flex containers, columns are inner containers with
 * flex_direction=column nested inside a row container.
 */
final class Column {

	/**
	 * @param array<string, mixed> $node
	 * @param Resolver             $resolver
	 * @param string               $canonical_path
	 * @return array<string, mixed>
	 */
	public static function render( array $node, Resolver $resolver, string $canonical_path ): array {
		$style = StyleMapper::node_style( $node, $resolver );

		$settings = [
			'container_type' => 'flex',
			'flex_direction' => isset( $style['flex_direction'] ) && 'row' === $style['flex_direction'] ? 'row' : 'column',
		];

		if ( isset( $node['width'] ) ) {
			$settings['width'] = [
				'unit' => '%',
				'size' => (int) $node['width'],
			];
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
		if ( array_key_exists( 'height', $node ) && ! array_key_exists( 'height', $style ) ) {
			$height = StyleMapper::size( $node['height'] );
			if ( null !== $height ) {
				$settings['height'] = $height;
			}
		}

		if ( isset( $node['padding'] ) && is_array( $node['padding'] ) ) {
			$settings['padding'] = self::dimensions( $node['padding'] );
		}
		if ( array_key_exists( 'padding', $style ) ) {
			$padding = StyleMapper::dimensions( $style['padding'] );
			if ( null !== $padding ) {
				$settings['padding'] = $padding;
			}
		}
		$gap = $node['gap'] ?? ( $style['gap'] ?? null );
		if ( null !== $gap ) {
			$settings['flex_gap'] = self::gap( $gap );
		}
		$background = $style['background'] ?? ( $style['background_color'] ?? null );
		if ( null !== $background ) {
			$settings['background_background'] = 'classic';
			$settings['background_color']      = (string) $background;
		}

		return [
			'id'       => Section::stable_id( $canonical_path ),
			'elType'   => 'container',
			'isInner'  => true,
			'settings' => $settings,
			'elements' => [],
		];
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
}
