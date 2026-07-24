<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\Elementor\Loop;

use Stonewright\WpMcp\Elementor\Write\TreeHasher;
use Stonewright\WpMcp\Support\ElementorData;

/**
 * Verifies persisted loop linkage without returning page content.
 */
final class LoopReadbackVerifier {

	/**
	 * @param array<int, array<string, mixed>> $tree
	 * @param array<string, mixed>             $expected
	 * @return array<string, mixed>|\WP_Error
	 */
	public static function verify( array $tree, array $expected ): array|\WP_Error {
		$actual_hash = TreeHasher::hash( $tree );
		if ( ! hash_equals( (string) ( $expected['tree_hash'] ?? '' ), $actual_hash ) ) {
			return self::mismatch( 'hash', [ 'readback_hash' => $actual_hash ] );
		}

		$widget_id   = (string) ( $expected['widget_id'] ?? '' );
		$parent_id   = (string) ( $expected['parent_id'] ?? '' );
		$widget_path = ElementorData::find_path( $tree, $widget_id );
		$parent_path = ElementorData::find_path( $tree, $parent_id );
		if ( null === $widget_path ) {
			return self::mismatch( 'widget_missing' );
		}
		if ( null === $parent_path || ! self::is_direct_child( $parent_path, $widget_path ) ) {
			return self::mismatch( 'parent' );
		}

		$widget = self::resolve( $tree, $widget_path );
		if ( null === $widget ) {
			return self::mismatch( 'widget_missing' );
		}
		if ( (string) ( $widget['widgetType'] ?? '' ) !== (string) ( $expected['widget_type'] ?? '' ) ) {
			return self::mismatch( 'widget_type' );
		}

		$settings     = is_array( $widget['settings'] ?? null ) ? $widget['settings'] : [];
		$template_key = (string) ( $expected['template_control'] ?? '' );
		if ( '' === $template_key || (int) ( $settings[ $template_key ] ?? 0 ) !== (int) ( $expected['template_id'] ?? 0 ) ) {
			return self::mismatch( 'template', [ 'control' => $template_key ] );
		}
		foreach ( (array) ( $expected['settings'] ?? [] ) as $control => $value ) {
			if ( ! array_key_exists( $control, $settings ) || $settings[ $control ] !== $value ) {
				return self::mismatch( 'settings', [ 'control' => sanitize_key( (string) $control ) ] );
			}
		}

		return [
			'verified'      => true,
			'checks'        => [ 'hash', 'parent', 'widget_type', 'template', 'settings' ],
			'readback_hash' => $actual_hash,
		];
	}

	/**
	 * @param array<int, int> $parent_path
	 * @param array<int, int> $widget_path
	 */
	private static function is_direct_child( array $parent_path, array $widget_path ): bool {
		if ( count( $widget_path ) !== count( $parent_path ) + 1 ) {
			return false;
		}

		return $parent_path === array_slice( $widget_path, 0, count( $parent_path ) );
	}

	/**
	 * @param array<int, array<string, mixed>> $tree
	 * @param array<int, int>                  $path
	 * @return array<string, mixed>|null
	 */
	private static function resolve( array $tree, array $path ): ?array {
		$current = null;
		foreach ( $path as $index ) {
			if ( ! isset( $tree[ $index ] ) || ! is_array( $tree[ $index ] ) ) {
				return null;
			}
			$current = $tree[ $index ];
			$tree    = is_array( $current['elements'] ?? null ) ? $current['elements'] : [];
		}

		return $current;
	}

	/** @param array<string, mixed> $data */
	private static function mismatch( string $invariant, array $data = [] ): \WP_Error {
		return new \WP_Error(
			'stonewright_loop_readback_mismatch',
			sprintf( __( 'Loop readback failed the %s invariant.', 'stonewright' ), $invariant ),
			array_merge(
				[
					'status'           => 500,
					'failed_invariant' => $invariant,
				],
				$data
			)
		);
	}
}
