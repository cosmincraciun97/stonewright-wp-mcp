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

		$instances = is_array( $expected['instances'] ?? null ) ? $expected['instances'] : [];
		$targets   = [] !== $instances
			? $instances
			: [
				[
					'widget_id'        => $widget_id,
					'widget_type'      => (string) ( $expected['widget_type'] ?? '' ),
					'template_id'      => (int) ( $expected['template_id'] ?? 0 ),
					'template_control' => (string) ( $expected['template_control'] ?? '' ),
					'settings'         => (array) ( $expected['settings'] ?? [] ),
				],
			];

		$checks = [ 'hash', 'parent' ];
		foreach ( $targets as $index => $target ) {
			$target = is_array( $target ) ? $target : [];
			$target_id = (string) ( $target['widget_id'] ?? $widget_id );
			$target_path = $index === 0 && $target_id === $widget_id
				? $widget_path
				: ElementorData::find_path( $tree, $target_id );
			if ( null === $target_path ) {
				return self::mismatch( 'widget_missing', [ 'widget_id' => $target_id ] );
			}
			if ( ! self::is_direct_child( $parent_path, $target_path ) ) {
				return self::mismatch( 'parent', [ 'widget_id' => $target_id ] );
			}
			$node = self::resolve( $tree, $target_path );
			if ( null === $node ) {
				return self::mismatch( 'widget_missing', [ 'widget_id' => $target_id ] );
			}
			$expected_type = (string) ( $target['widget_type'] ?? $expected['widget_type'] ?? '' );
			if ( (string) ( $node['widgetType'] ?? '' ) !== $expected_type ) {
				return self::mismatch( 'widget_type', [ 'widget_id' => $target_id ] );
			}
			$settings     = is_array( $node['settings'] ?? null ) ? $node['settings'] : [];
			$template_key = (string) ( $target['template_control'] ?? $expected['template_control'] ?? '' );
			$template_id  = (int) ( $target['template_id'] ?? $expected['template_id'] ?? 0 );
			if ( '' === $template_key || (int) ( $settings[ $template_key ] ?? 0 ) !== $template_id ) {
				return self::mismatch( 'template', [ 'control' => $template_key, 'widget_id' => $target_id ] );
			}
			foreach ( (array) ( $target['settings'] ?? [] ) as $control => $value ) {
				if ( ! array_key_exists( $control, $settings ) || $settings[ $control ] !== $value ) {
					return self::mismatch( 'settings', [ 'control' => sanitize_key( (string) $control ), 'widget_id' => $target_id ] );
				}
			}
			$probe = $expected['render_probe'] ?? null;
			if ( is_callable( $probe ) && ! $probe( $node ) ) {
				return self::mismatch( 'render', [ 'widget_id' => $target_id ] );
			}
		}
		$checks[] = 'widget_type';
		$checks[] = 'template';
		$checks[] = 'settings';
		if ( is_callable( $expected['render_probe'] ?? null ) ) {
			$checks[] = 'render';
		}

		return [
			'verified'      => true,
			'checks'        => $checks,
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
