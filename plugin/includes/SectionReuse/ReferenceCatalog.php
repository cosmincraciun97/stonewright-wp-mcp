<?php
/**
 * Whether the things a section refers to still exist on this site.
 * SPDX-License-Identifier: GPL-2.0-or-later
 *
 * @package Stonewright
 */

declare( strict_types=1 );

namespace Stonewright\WpMcp\SectionReuse;

use Stonewright\WpMcp\Elementor\V4\AtomicClassRepositoryAdapter;
use Stonewright\WpMcp\Elementor\V4\AtomicVariableRepositoryAdapter;

/**
 * Answers one question for a reference found by {@see SectionInspector}: does it resolve here and now? An
 * answer is true, false, or null when this site cannot tell (a form identity, a dynamic tag, or a registry the
 * installed Elementor does not expose). A reference that is false makes an insert fail with that exact
 * reference; a reference that is null is reported and never guessed.
 */
final class ReferenceCatalog {

	/** @var (callable(string,string):?bool)|null */
	private static $provider = null;

	/** @var array<string, array<string, true>|null> */
	private static array $lists = [];

	/** Replaces the live checks. For tests; pass null to restore them. */
	public static function set_provider( ?callable $provider ): void {
		self::$provider = $provider;
		self::$lists    = [];
	}

	public static function reset(): void {
		self::$lists = [];
	}

	/**
	 * The reference list with an `exists` entry on each item.
	 *
	 * @param list<array<string, mixed>> $references
	 * @return list<array<string, mixed>>
	 */
	public static function annotate( array $references ): array {
		foreach ( $references as $index => $reference ) {
			$references[ $index ]['exists'] = self::exists( (string) $reference['type'], (string) $reference['id'] );
		}

		return $references;
	}

	public static function exists( string $type, string $id ): ?bool {
		if ( null !== self::$provider ) {
			return ( self::$provider )( $type, $id );
		}

		return match ( $type ) {
			'global_color'    => self::in_list( 'colors', $id ),
			'global_font'     => self::in_list( 'fonts', $id ),
			'global_class'    => self::in_list( 'classes', $id ),
			'variable'        => self::in_list( 'variables', $id ),
			'synced_pattern'  => self::post_is( $id, [ 'wp_block' ] ),
			'global_widget', 'nested_template' => ctype_digit( $id ) ? self::post_is( $id, [ 'elementor_library' ] ) : null,
			'media'           => self::post_is( $id, [ 'attachment' ] ),
			default           => null,
		};
	}

	/** @param list<string> $types */
	private static function post_is( string $id, array $types ): bool {
		if ( ! ctype_digit( $id ) ) {
			return false;
		}
		$post = get_post( (int) $id );

		return is_object( $post ) && in_array( SectionSource::field( $post, 'post_type' ), $types, true ) && 'trash' !== SectionSource::field( $post, 'post_status' );
	}

	private static function in_list( string $list, string $id ): ?bool {
		if ( ! array_key_exists( $list, self::$lists ) ) {
			self::$lists[ $list ] = self::load( $list );
		}

		return null === self::$lists[ $list ] ? null : isset( self::$lists[ $list ][ $id ] );
	}

	/** @return array<string, true>|null */
	private static function load( string $list ): ?array {
		if ( 'colors' === $list || 'fonts' === $list ) {
			return self::kit_ids( 'colors' === $list ? [ 'system_colors', 'custom_colors' ] : [ 'system_typography', 'custom_typography' ] );
		}
		$adapter = 'classes' === $list ? AtomicClassRepositoryAdapter::runtime() : AtomicVariableRepositoryAdapter::runtime();
		if ( $adapter instanceof \WP_Error ) {
			return null;
		}
		$all = $adapter->all();
		if ( $all instanceof \WP_Error ) {
			return null;
		}
		$ids = [];
		foreach ( $all as $key => $item ) {
			$ids[ (string) $key ] = true;
			if ( is_array( $item ) && isset( $item['id'] ) && is_scalar( $item['id'] ) ) {
				$ids[ (string) $item['id'] ] = true;
			}
		}

		return $ids;
	}

	/**
	 * Ids of the colors or fonts of the active kit. Elementor's own kit answers first, because a kit that was
	 * never saved still has its default colors and fonts; the stored settings answer when Elementor does not.
	 *
	 * @param list<string> $keys
	 * @return array<string, true>|null
	 */
	private static function kit_ids( array $keys ): ?array {
		$kit = (int) get_option( 'elementor_active_kit', 0 );
		if ( $kit <= 0 ) {
			return null;
		}
		$settings = [];
		foreach ( $keys as $key ) {
			$settings[ $key ] = self::live_kit_setting( $key );
		}
		$stored = get_post_meta( $kit, '_elementor_page_settings', true );
		$stored = is_array( $stored ) ? $stored : [];
		$ids    = [];
		foreach ( $keys as $key ) {
			foreach ( [ $settings[ $key ], $stored[ $key ] ?? null ] as $items ) {
				foreach ( is_array( $items ) ? $items : [] as $item ) {
					if ( is_array( $item ) && isset( $item['_id'] ) && is_scalar( $item['_id'] ) ) {
						$ids[ (string) $item['_id'] ] = true;
					}
				}
			}
		}

		return $ids;
	}

	/** One setting of the active kit as Elementor reports it, or null when Elementor is not there to ask. */
	private static function live_kit_setting( string $key ): mixed {
		if ( ! class_exists( '\\Elementor\\Plugin' ) ) {
			return null;
		}
		try {
			$manager = isset( \Elementor\Plugin::$instance ) ? ( \Elementor\Plugin::$instance->kits_manager ?? null ) : null;
			$active  = is_object( $manager ) && method_exists( $manager, 'get_active_kit' ) ? $manager->get_active_kit() : null;

			return is_object( $active ) && method_exists( $active, 'get_settings' ) ? $active->get_settings( $key ) : null;
		} catch ( \Throwable $failure ) {
			unset( $failure );

			return null;
		}
	}
}
