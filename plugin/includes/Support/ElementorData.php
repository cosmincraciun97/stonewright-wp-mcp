<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\Support;

use Stonewright\WpMcp\Elementor\Integrity\DocumentIntegrityGate;
use Stonewright\WpMcp\Elementor\PostCacheInvalidator;
use Stonewright\WpMcp\Elementor\Schema\SettingsValidator;

/**
 * Read/write helpers for Elementor V3 page data, which lives in the
 * `_elementor_data` post meta as JSON-encoded list of elements:
 *
 *   [ { id, elType, settings, elements, widgetType? }, … ]
 */
final class ElementorData {

	private static ?\WP_Error $last_write_error = null;

	public static function last_write_error(): ?\WP_Error {
		return self::$last_write_error;
	}

	/**
	 * WP_Error an ability should return after ElementorData::write() failed.
	 * Prefers the specific gate/validator error; falls back to a generic code.
	 */
	public static function write_error_for_ability( string $fallback_code = 'stonewright_write_failed' ): \WP_Error {
		if ( self::$last_write_error instanceof \WP_Error ) {
			return self::$last_write_error;
		}
		return new \WP_Error(
			$fallback_code,
			__( 'Could not save Elementor data.', 'stonewright' ),
			[ 'status' => 500 ]
		);
	}

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
			// Guard: if first decode is still a JSON string, decode once more
			// for read convenience — but never write that double-encoded form.
			if ( is_string( $decoded ) ) {
				$inner = json_decode( $decoded, true );
				if ( is_array( $inner ) ) {
					return $inner;
				}
			}
			if ( is_array( $decoded ) ) {
				return $decoded;
			}
		}
		return [];
	}

	/**
	 * Persist tree back to post meta. Elementor expects slashed JSON.
	 *
	 * P0 integrity gate runs first (size collapse, double-encode, widgetType
	 * remap). On readback failure the previous document is restored.
	 *
	 * @param array<int, array<string, mixed>> $tree    Document tree.
	 * @param array<string, mixed>             $options force_destructive?, allow_widget_type_remap?, min_size_ratio?, skip_integrity?, touched_ids?.
	 */
	public static function write( int $post_id, array $tree, array $options = [] ): bool {
		self::$last_write_error = null;
		$previous               = self::read( $post_id );

		if ( empty( $options['skip_integrity'] ) ) {
			$gate = DocumentIntegrityGate::assert_write_allowed( $tree, $previous, $options );
			if ( $gate instanceof \WP_Error ) {
				self::$last_write_error = $gate;
				return false;
			}
		}

		$touched_ids = isset( $options['touched_ids'] ) && is_array( $options['touched_ids'] )
			? array_values( array_map( 'strval', $options['touched_ids'] ) )
			: null;
		if ( ! SettingsValidator::validate_tree( $tree, $touched_ids ) ) {
			self::$last_write_error = SettingsValidator::last_error()
				?? new \WP_Error(
					'stonewright_elementor_tree_invalid',
					__( 'Elementor tree structure is invalid.', 'stonewright' ),
					[ 'status' => 400 ]
				);
			return false;
		}

		$json = wp_json_encode( $tree, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
		if ( false === $json ) {
			self::$last_write_error = new \WP_Error(
				'stonewright_elementor_json_encode_failed',
				__( 'Could not encode Elementor tree as JSON.', 'stonewright' ),
				[ 'status' => 500 ]
			);
			return false;
		}

		// Reject accidental double-encode of the encoded string itself.
		$payload_check = DocumentIntegrityGate::assert_meta_payload_not_double_encoded( $json );
		if ( $payload_check instanceof \WP_Error ) {
			self::$last_write_error = $payload_check;
			return false;
		}

		$ok = self::persist_encoded( $post_id, $json, $tree );
		if ( $ok ) {
			return true;
		}

		// Readback failed — restore previous document when we had one.
		if ( [] !== $previous ) {
			$prev_json = wp_json_encode( $previous, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
			$restored  = false;
			if ( false !== $prev_json ) {
				$restored = self::persist_encoded( $post_id, $prev_json, $previous );
			}
			if ( $restored ) {
				self::$last_write_error = new \WP_Error(
					'stonewright_elementor_readback_failed_restored',
					__( 'Elementor write readback failed; previous document was restored.', 'stonewright' ),
					[
						'status'  => 500,
						'post_id' => $post_id,
						'fix'   => [ 'use_batch_mutate', 'do_not_retry_raw_meta_write' ],
					]
				);
			} else {
				self::$last_write_error = new \WP_Error(
					'stonewright_elementor_readback_failed_restore_failed',
					__( 'Elementor write readback failed and the previous document could not be restored. Restore from a Stonewright snapshot before further edits.', 'stonewright' ),
					[
						'status'  => 500,
						'post_id' => $post_id,
						'fix'   => [ 'restore_snapshot', 'use_batch_mutate', 'do_not_retry_raw_meta_write' ],
					]
				);
			}
		} else {
			self::$last_write_error = new \WP_Error(
				'stonewright_elementor_readback_failed',
				__( 'Elementor write readback failed.', 'stonewright' ),
				[ 'status' => 500, 'post_id' => $post_id ]
			);
		}

		return false;
	}

	/**
	 * @param array<int, array<string, mixed>> $tree
	 */
	private static function persist_encoded( int $post_id, string $json, array $tree ): bool {
		$elementor_version = defined( 'ELEMENTOR_VERSION' ) ? ELEMENTOR_VERSION : '3.0.0';

		update_post_meta( $post_id, '_elementor_data', wp_slash( $json ) );
		update_post_meta( $post_id, '_elementor_edit_mode', 'builder' );
		update_post_meta( $post_id, '_elementor_version', $elementor_version );
		PostCacheInvalidator::invalidate( $post_id );

		$stored_data = (string) get_post_meta( $post_id, '_elementor_data', true );
		$stored_mode = (string) get_post_meta( $post_id, '_elementor_edit_mode', true );
		$stored_ver  = (string) get_post_meta( $post_id, '_elementor_version', true );

		if ( 'builder' !== $stored_mode || $stored_ver !== $elementor_version ) {
			return false;
		}

		if ( $stored_data === $json ) {
			return true;
		}

		if ( wp_unslash( $stored_data ) === $json ) {
			return true;
		}

		foreach ( [ $stored_data, wp_unslash( $stored_data ) ] as $candidate ) {
			$decoded = json_decode( (string) $candidate, true );
			if ( is_array( $decoded ) ) {
				$canonical_stored = wp_json_encode( $decoded, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
				if ( $canonical_stored === $json || $decoded === $tree ) {
					return true;
				}
			}
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
}
