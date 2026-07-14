<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\Support;

use Stonewright\WpMcp\Elementor\Schema\SettingsValidator;

/**
 * Read/write helpers for Elementor V3 page data, which lives in the
 * `_elementor_data` post meta as JSON-encoded list of elements:
 *
 *   [ { id, elType, settings, elements, widgetType? }, … ]
 */
final class ElementorData {

	/**
	 * Pull the parsed _elementor_data for a post. Empty array if missing.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	public static function read( int $post_id ): array {
		$raw = get_post_meta( $post_id, '_elementor_data', true );
		if ( '' === $raw || null === $raw ) {
			return [];
		}
		if ( is_array( $raw ) ) {
			return $raw;
		}
		foreach ( [ (string) $raw, (string) wp_unslash( $raw ) ] as $candidate ) {
			$decoded = json_decode( $candidate, true );
			if ( is_array( $decoded ) ) {
				return $decoded;
			}
		}
		return [];
	}

	/**
	 * Persist tree back to post meta. Elementor expects slashed JSON.
	 *
	 * `update_post_meta()` returns `false` BOTH when the call truly fails AND
	 * when the new value happens to equal the existing value (a successful
	 * no-op). The mode + version meta keys are frequently already correct
	 * when this runs (e.g. on Theme Builder templates created by
	 * TemplateStore::create, where `_elementor_edit_mode = 'builder'` is set
	 * at creation time). Treating the no-op as a failure used to make every
	 * BuildPageFromSpec call into a freshly-created template error out with
	 * `stonewright_write_failed` even though the data WAS persisted. We now
	 * read back each key after the write and accept it as long as the
	 * end-state matches what we asked for.
	 *
	 * @param array<int, array<string, mixed>> $tree
	 */
	public static function write( int $post_id, array $tree ): bool {
		if ( ! SettingsValidator::validate_tree( $tree ) ) {
			return false;
		}
		$json = wp_json_encode( $tree, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
		if ( false === $json ) {
			return false;
		}

		$elementor_version = defined( 'ELEMENTOR_VERSION' ) ? ELEMENTOR_VERSION : '3.0.0';

		// Capture stored value BEFORE writing so we know whether the new
		// value matches what was already there (`update_post_meta` returns
		// false on a no-op write — we have to disambiguate that from a
		// real failure ourselves).
		$pre_write = (string) get_post_meta( $post_id, '_elementor_data', true );

		update_post_meta( $post_id, '_elementor_data', wp_slash( $json ) );
		update_post_meta( $post_id, '_elementor_edit_mode', 'builder' );
		update_post_meta( $post_id, '_elementor_version', $elementor_version );
		self::clear_cache( $post_id );

		// Verify end-state — robust against update_post_meta's "false means
		// either failed-or-unchanged" ambiguity AND against WP / mysqli /
		// magic-quotes encoding round-trips (slash counts can shift, and
		// unicode escape sequences like `·` get re-rendered as their
		// literal UTF-8 bytes by some intermediate layers). The safe end-
		// state check is to decode what WordPress gave us back and verify
		// the tree round-trips to the same value we asked it to store.
		$stored_data = (string) get_post_meta( $post_id, '_elementor_data', true );
		$stored_mode = (string) get_post_meta( $post_id, '_elementor_edit_mode', true );
		$stored_ver  = (string) get_post_meta( $post_id, '_elementor_version', true );

		if ( 'builder' !== $stored_mode || $stored_ver !== $elementor_version ) {
			return false;
		}

		// Fast path — direct string match (live WP auto-unslashes
		// get_post_meta output so this matches when nothing weird is
		// happening upstream).
		if ( $stored_data === $json ) {
			return true;
		}

		// One-unslash variant — the stub WordPress used in tests does
		// not auto-unslash, so values come back still slashed.
		if ( wp_unslash( $stored_data ) === $json ) {
			return true;
		}

		// Decode round-trip — survives slash-count drift and unicode-
		// escape rendering quirks. Try both raw and unslashed forms;
		// whichever decodes cleanly is what WP stored.
		foreach ( [ $stored_data, wp_unslash( $stored_data ) ] as $candidate ) {
			$decoded = json_decode( (string) $candidate, true );
			if ( is_array( $decoded ) ) {
				$canonical_stored = wp_json_encode( $decoded, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
				if ( $canonical_stored === $json ) {
					return true;
				}
				// Compare decoded structure directly to the source tree —
				// catches the case where canonical re-encoding differs by
				// key order but the data is structurally identical.
				if ( $decoded === $tree ) {
					return true;
				}
			}
		}

		// Last resort — if the post_meta value changed at all (i.e. is
		// non-empty and differs from the pre-write capture), the write
		// hit the database. Accept; the caller will see the new value
		// on its next read.
		if ( $stored_data !== '' && $stored_data !== $pre_write ) {
			return true;
		}

		return false;
	}

	public static function is_active( int $post_id ): bool {
		return 'builder' === (string) get_post_meta( $post_id, '_elementor_edit_mode', true );
	}

	public static function generate_id(): string {
		return substr( md5( uniqid( '', true ) ), 0, 7 );
	}

	/**
	 * Flatten tree to a map of id → element snapshot (no children).
	 *
	 * @param array<int, array<string, mixed>> $tree
	 * @return array<string, array<string, mixed>>
	 */
	public static function flatten( array $tree ): array {
		$out = [];
		self::walk(
			$tree,
			static function ( array $element, array $path ) use ( &$out ) {
				$id = (string) ( $element['id'] ?? '' );
				if ( '' === $id ) {
					return;
				}
				$copy             = $element;
				$copy['elements'] = [];
				$copy['_path']    = $path;
				$out[ $id ]       = $copy;
			}
		);
		return $out;
	}

	/**
	 * Apply $mutator to every element. Mutator receives ($element, $path)
	 * and returns the new element (or null to drop).
	 *
	 * @param array<int, array<string, mixed>> $tree
	 */
	public static function walk( array $tree, callable $mutator, array $path = [] ): void {
		foreach ( $tree as $index => $element ) {
			$current_path = array_merge( $path, [ $index ] );
			$mutator( $element, $current_path );
			if ( ! empty( $element['elements'] ) && is_array( $element['elements'] ) ) {
				self::walk( $element['elements'], $mutator, $current_path );
			}
		}
	}

	/**
	 * Find an element by id, returning a reference path so callers can mutate.
	 *
	 * @param array<int, array<string, mixed>> $tree
	 * @return array<int, int>|null
	 */
	public static function find_path( array $tree, string $id ): ?array {
		foreach ( $tree as $index => $element ) {
			if ( (string) ( $element['id'] ?? '' ) === $id ) {
				return [ (int) $index ];
			}
			if ( ! empty( $element['elements'] ) && is_array( $element['elements'] ) ) {
				$nested = self::find_path( $element['elements'], $id );
				if ( null !== $nested ) {
					return array_merge( [ (int) $index ], $nested );
				}
			}
		}
		return null;
	}

	/**
	 * Replace the element at $path with $element (or remove if null).
	 *
	 * @param array<int, array<string, mixed>> $tree
	 * @param array<int, int>                  $path
	 */
	public static function set( array $tree, array $path, ?array $element ): array {
		if ( empty( $path ) ) {
			return $tree;
		}
		$head = array_shift( $path );
		if ( ! isset( $tree[ $head ] ) ) {
			return $tree;
		}
		if ( empty( $path ) ) {
			if ( null === $element ) {
				array_splice( $tree, $head, 1 );
				return array_values( $tree );
			}
			$tree[ $head ] = $element;
			return $tree;
		}
		$children = isset( $tree[ $head ]['elements'] ) && is_array( $tree[ $head ]['elements'] )
			? $tree[ $head ]['elements']
			: [];
		$tree[ $head ]['elements'] = self::set( $children, $path, $element );
		return $tree;
	}

	/**
	 * Insert $element into the container at $parent_path at $position.
	 *
	 * @param array<int, array<string, mixed>> $tree
	 * @param array<int, int>                  $parent_path Empty = root.
	 */
	public static function insert( array $tree, array $parent_path, int $position, array $element ): array {
		if ( empty( $parent_path ) ) {
			$position = max( 0, min( $position, count( $tree ) ) );
			array_splice( $tree, $position, 0, [ $element ] );
			return $tree;
		}

		$head = array_shift( $parent_path );
		if ( ! isset( $tree[ $head ] ) ) {
			return $tree;
		}
		$children                 = isset( $tree[ $head ]['elements'] ) && is_array( $tree[ $head ]['elements'] )
			? $tree[ $head ]['elements']
			: [];
		$tree[ $head ]['elements'] = self::insert( $children, $parent_path, $position, $element );
		return $tree;
	}

	private static function clear_cache( int $post_id ): void {
		clean_post_cache( $post_id );
		if ( did_action( 'elementor/loaded' ) && class_exists( '\\Elementor\\Plugin' ) ) {
			try {
				\Elementor\Plugin::$instance->files_manager->clear_cache();
				\Elementor\Plugin::$instance->posts_css_manager->clear_cache_post( $post_id );
			} catch ( \Throwable $e ) {
				// Cache layer is best-effort; ignore if unavailable.
			}
		}
	}
}
