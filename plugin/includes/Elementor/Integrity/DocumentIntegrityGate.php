<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\Elementor\Integrity;

use Stonewright\WpMcp\Elementor\Write\TreeHasher;

/**
 * P0 integrity checks before any `_elementor_data` persistence.
 *
 * Blocks the failure modes that corrupt layouts:
 * - double-encoded JSON strings
 * - mass size collapse (silent strip of settings)
 * - widgetType remaps without explicit allow
 * - non-array / non-list document roots
 */
final class DocumentIntegrityGate {

	public const MIN_SIZE_RATIO = 0.85;

	/**
	 * @param array<int, array<string, mixed>>|mixed $incoming Incoming document tree (must be array list).
	 * @param array<int, array<string, mixed>>       $previous Previous tree from the post (may be empty).
	 * @param array<string, mixed>                   $options  force_destructive?, allow_widget_type_remap?, min_size_ratio?.
	 * @return true|\WP_Error
	 */
	public static function assert_write_allowed( mixed $incoming, array $previous = [], array $options = [] ): bool|\WP_Error {
		if ( ! is_array( $incoming ) ) {
			return self::error(
				'stonewright_elementor_integrity_not_array',
				__( 'Elementor document must be a JSON array tree, not a string or object map.', 'stonewright' ),
				[
					'got_type' => gettype( $incoming ),
					'fix'     => [ 'decode_json_once', 'pass_array_tree', 'use_batch_mutate' ],
				]
			);
		}

		// Double-encoded: root is a list of length 1 whose only element is a JSON string of an array.
		if ( self::looks_double_encoded_tree( $incoming ) ) {
			return self::error(
				'stonewright_elementor_double_encoded',
				__( 'Elementor document appears double-encoded JSON. Decode once to an array tree before write.', 'stonewright' ),
				[
					'fix' => [ 'json_decode_once', 'never_json_encode_then_store_as_string_in_tree', 'use_elementor_data_write' ],
				]
			);
		}

		if ( ! array_is_list( $incoming ) ) {
			return self::error(
				'stonewright_elementor_integrity_not_list',
				__( 'Elementor document root must be a JSON list of elements.', 'stonewright' ),
				[ 'fix' => [ 'use_list_root', 'get_page_structure_first' ] ]
			);
		}

		$structure = self::assert_basic_structure( $incoming, 'root' );
		if ( $structure instanceof \WP_Error ) {
			return $structure;
		}

		$force    = ! empty( $options['force_destructive'] );
		$allow_remap = ! empty( $options['allow_widget_type_remap'] );
		$ratio    = isset( $options['min_size_ratio'] ) ? (float) $options['min_size_ratio'] : self::MIN_SIZE_RATIO;
		if ( $ratio <= 0 || $ratio > 1 ) {
			$ratio = self::MIN_SIZE_RATIO;
		}

		$prev_bytes = self::json_bytes( $previous );
		$next_bytes = self::json_bytes( $incoming );
		if ( ! $force && $prev_bytes > 2048 && $next_bytes < (int) floor( $prev_bytes * $ratio ) ) {
			return self::error(
				'stonewright_elementor_size_collapse',
				__( 'Incoming Elementor document is much smaller than the existing one. Refusing silent layout strip.', 'stonewright' ),
				[
					'previous_bytes'   => $prev_bytes,
					'incoming_bytes'   => $next_bytes,
					'min_size_ratio'   => $ratio,
					'required_minimum' => (int) floor( $prev_bytes * $ratio ),
					'fix'            => [
						'use_surgical_batch_mutate',
						'do_not_strip_unknown_settings',
						'pass_force_destructive_only_with_user_confirm',
					],
				]
			);
		}

		if ( ! $allow_remap && [] !== $previous ) {
			$remaps = self::widget_type_remaps( $previous, $incoming );
			if ( [] !== $remaps ) {
				return self::error(
					'stonewright_elementor_widget_type_remap_blocked',
					__( 'Widget type changes on existing element ids are blocked. Do not convert e-paragraph/text-editor/etc. to pass validation.', 'stonewright' ),
					[
						'remaps'  => array_slice( $remaps, 0, 20 ),
						'count'   => count( $remaps ),
						'fix'   => [
							'keep_original_widgetType',
							'use_path_settings_patch_only',
							'allow_widget_type_remap_only_with_explicit_user_intent',
						],
					]
				);
			}
		}

		return true;
	}

	/**
	 * Detect payload that would store a JSON string (double encode risk).
	 *
	 * @param mixed $payload Raw value intended for meta (array tree or string).
	 */
	public static function assert_meta_payload_not_double_encoded( mixed $payload ): bool|\WP_Error {
		if ( is_array( $payload ) ) {
			if ( self::looks_double_encoded_tree( $payload ) ) {
				return self::error(
					'stonewright_elementor_double_encoded',
					__( 'Elementor document appears double-encoded JSON. Decode once to an array tree before write.', 'stonewright' ),
					[ 'fix' => [ 'json_decode_once' ] ]
				);
			}
			return true;
		}
		if ( ! is_string( $payload ) ) {
			return self::error(
				'stonewright_elementor_integrity_bad_payload',
				__( 'Elementor meta payload must be an array tree or a single JSON object/array string.', 'stonewright' ),
				[ 'got_type' => gettype( $payload ) ]
			);
		}
		$trim = trim( $payload );
		if ( '' === $trim ) {
			return self::error(
				'stonewright_elementor_integrity_empty',
				__( 'Elementor meta payload is empty.', 'stonewright' ),
				[]
			);
		}
		$once = json_decode( $trim, true );
		// Double-encoded meta: first decode is a JSON string that itself decodes to an array tree.
		if ( is_string( $once ) ) {
			$twice = json_decode( $once, true );
			if ( is_array( $twice ) ) {
				return self::error(
					'stonewright_elementor_double_encoded',
					__( 'Elementor meta string is double-encoded JSON. Decode once before write.', 'stonewright' ),
					[ 'fix' => [ 'json_decode_once', 'store_single_encode_only' ] ]
				);
			}
			return self::error(
				'stonewright_elementor_integrity_invalid_json',
				__( 'Elementor meta string is not valid JSON for a document tree.', 'stonewright' ),
				[ 'json_error' => function_exists( 'json_last_error_msg' ) ? json_last_error_msg() : 'invalid' ]
			);
		}
		if ( ! is_array( $once ) ) {
			return self::error(
				'stonewright_elementor_integrity_invalid_json',
				__( 'Elementor meta string is not valid JSON for a document tree.', 'stonewright' ),
				[ 'json_error' => function_exists( 'json_last_error_msg' ) ? json_last_error_msg() : 'invalid' ]
			);
		}
		return true;
	}

	/**
	 * @param array<int, mixed> $tree
	 */
	private static function looks_double_encoded_tree( array $tree ): bool {
		if ( 1 === count( $tree ) && isset( $tree[0] ) && is_string( $tree[0] ) ) {
			$decoded = json_decode( $tree[0], true );
			return is_array( $decoded );
		}
		return false;
	}

	/**
	 * @param array<int, mixed> $tree
	 */
	private static function assert_basic_structure( array $tree, string $path ): bool|\WP_Error {
		foreach ( $tree as $index => $element ) {
			if ( ! is_array( $element ) ) {
				return self::error(
					'stonewright_elementor_integrity_invalid_element',
					__( 'Each Elementor tree node must be an object.', 'stonewright' ),
					[ 'path' => $path . '.' . (string) $index ]
				);
			}
			$id = isset( $element['id'] ) && is_scalar( $element['id'] ) ? trim( (string) $element['id'] ) : '';
			if ( '' === $id ) {
				return self::error(
					'stonewright_elementor_integrity_missing_id',
					__( 'Every Elementor node needs a non-empty id.', 'stonewright' ),
					[ 'path' => $path . '.' . (string) $index . '.id' ]
				);
			}
			$el_type = (string) ( $element['elType'] ?? '' );
			if ( '' === $el_type ) {
				return self::error(
					'stonewright_elementor_integrity_missing_eltype',
					__( 'Every Elementor node needs elType.', 'stonewright' ),
					[ 'path' => $path . '.' . (string) $index . '.elType', 'id' => $id ]
				);
			}
			if ( 'widget' === $el_type ) {
				$wt = (string) ( $element['widgetType'] ?? '' );
				if ( '' === $wt ) {
					return self::error(
						'stonewright_elementor_integrity_missing_widget_type',
						__( 'Widget nodes need widgetType.', 'stonewright' ),
						[ 'path' => $path . '.' . (string) $index . '.widgetType', 'id' => $id ]
					);
				}
			}
			if ( isset( $element['elements'] ) ) {
				if ( ! is_array( $element['elements'] ) ) {
					return self::error(
						'stonewright_elementor_integrity_invalid_children',
						__( 'elements must be an array.', 'stonewright' ),
						[ 'path' => $path . '.' . (string) $index . '.elements', 'id' => $id ]
					);
				}
				$child = self::assert_basic_structure( $element['elements'], $path . '.' . (string) $index . '.elements' );
				if ( $child instanceof \WP_Error ) {
					return $child;
				}
			}
		}
		return true;
	}

	/**
	 * @param array<int, array<string, mixed>> $previous
	 * @param array<int, array<string, mixed>> $incoming
	 * @return list<array{id:string,from:string,to:string}>
	 */
	public static function widget_type_remaps( array $previous, array $incoming ): array {
		$prev_map = self::widget_type_map( $previous );
		$next_map = self::widget_type_map( $incoming );
		$remaps   = [];
		foreach ( $prev_map as $id => $from ) {
			if ( ! isset( $next_map[ $id ] ) ) {
				continue;
			}
			$to = $next_map[ $id ];
			if ( $from !== $to && '' !== $from && '' !== $to ) {
				$remaps[] = [
					'id'   => $id,
					'from' => $from,
					'to'   => $to,
				];
			}
		}
		return $remaps;
	}

	/**
	 * @param array<int, array<string, mixed>> $tree
	 * @return array<string, string> id => widgetType
	 */
	public static function widget_type_map( array $tree ): array {
		$map = [];
		self::walk(
			$tree,
			static function ( array $el ) use ( &$map ): void {
				$id = isset( $el['id'] ) ? trim( (string) $el['id'] ) : '';
				if ( '' === $id ) {
					return;
				}
				if ( 'widget' === (string) ( $el['elType'] ?? '' ) ) {
					$map[ $id ] = (string) ( $el['widgetType'] ?? '' );
				}
			}
		);
		return $map;
	}

	/**
	 * @param array<int, mixed> $tree
	 * @param callable(array<string,mixed>):void $visitor
	 */
	private static function walk( array $tree, callable $visitor ): void {
		foreach ( $tree as $el ) {
			if ( ! is_array( $el ) ) {
				continue;
			}
			$visitor( $el );
			if ( isset( $el['elements'] ) && is_array( $el['elements'] ) ) {
				self::walk( $el['elements'], $visitor );
			}
		}
	}

	/** @param mixed $value */
	public static function json_bytes( mixed $value ): int {
		if ( is_string( $value ) ) {
			return strlen( $value );
		}
		$json = wp_json_encode( $value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
		return false === $json ? 0 : strlen( $json );
	}

	public static function structural_hash( mixed $tree ): string {
		return TreeHasher::hash( $tree );
	}

	/**
	 * @param array<string, mixed> $data
	 */
	private static function error( string $code, string $message, array $data ): \WP_Error {
		return new \WP_Error(
			$code,
			$message,
			array_merge(
				[
					'status'    => 400,
					'retryable' => false,
					'error_code'=> $code,
					'do_not_retry_php_execute' => true,
					'recommended_tools' => [
						'stonewright/elementor-v3-batch-mutate',
						'stonewright/elementor-v3-get-page-structure',
						'stonewright/change-restore',
					],
				],
				$data
			)
		);
	}
}
