<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\Design\Manifest;

use Stonewright\WpMcp\Design\Assets\AssetNormalizer;
use Stonewright\WpMcp\Support\Json;

/** Vendor-neutral, compact contract between external design extraction and renderers. */
final class SectionManifest {

	public const VERSION = '2.0.0';

	private const MAX_SECTIONS = 100;
	private const MAX_INTENTS = 50;
	private const MAX_EVIDENCE_ROWS = 24;
	private const MAX_ANCHORS = 250;

	/** @param array<string,mixed> $input @return array{manifest:array<string,mixed>,digest_hash:string}|\WP_Error */
	public static function validate( array $input ): array|\WP_Error {
		$is_normalized_section = 'section' === (string) ( $input['manifest_type'] ?? '' );
		if ( ! $is_normalized_section && ( 'page' === (string) ( $input['manifest_type'] ?? '' ) || array_key_exists( 'sections', $input ) ) ) {
			return self::validate_page( $input );
		}
		return self::validate_section( $input );
	}

	/** @param array<string,mixed> $input @return array{manifest:array<string,mixed>,digest_hash:string}|\WP_Error */
	private static function validate_page( array $input ): array|\WP_Error {
		$raw_sections = $input['sections'];
		if ( ! is_array( $raw_sections ) || [] === $raw_sections ) {
			return self::invalid( [ self::error_row( 'sections', 'page_sections_missing', 'Provide at least one section object.' ) ] );
		}
		if ( count( $raw_sections ) > self::MAX_SECTIONS ) {
			return self::invalid( [ self::error_row( 'sections', 'page_section_limit_exceeded', 'Split the page into no more than ' . self::MAX_SECTIONS . ' sections.' ) ] );
		}

		$base_input = $input;
		unset( $base_input['sections'] );
		$page   = self::normalize( $base_input );
		$errors = self::common_errors( $page, false );
		if ( array_key_exists( 'assets', $base_input ) && ! is_array( $base_input['assets'] ) ) {
			$errors[] = self::error_row( 'assets', 'assets_invalid', 'Assets must be an array.' );
		}
		$assets = AssetNormalizer::normalize_many( self::array_value( $base_input, 'assets' ) );
		if ( $assets instanceof \WP_Error ) {
			return $assets;
		}
		$page['assets']        = $assets;
		$page['manifest_type'] = 'page';
		$page['sections']      = [];

		$ids             = [];
		$explicit_orders = [];
		foreach ( array_values( $raw_sections ) as $source_index => $raw_section ) {
			$path = 'sections.' . $source_index;
			if ( ! is_array( $raw_section ) ) {
				$errors[] = self::error_row( $path, 'page_section_invalid', 'Every page section must be an object.' );
				continue;
			}
			if ( array_key_exists( 'assets', $raw_section ) && ! is_array( $raw_section['assets'] ) ) {
				$errors[] = self::error_row( $path . '.assets', 'assets_invalid', 'Section assets must be an array.' );
				continue;
			}

			$original_source_index = 'page' === (string) ( $input['manifest_type'] ?? '' ) && self::non_negative_integer( $raw_section['source_index'] ?? null )
				? (int) $raw_section['source_index']
				: $source_index;
			$order = $original_source_index;
			if ( array_key_exists( 'order', $raw_section ) ) {
				if ( ! self::non_negative_integer( $raw_section['order'] ) ) {
					$errors[] = self::error_row( $path . '.order', 'page_section_order_invalid', 'Section order must be a non-negative integer.' );
					continue;
				}
				$order = (int) $raw_section['order'];
				if ( isset( $explicit_orders[ $order ] ) ) {
					$errors[] = self::error_row( $path . '.order', 'page_section_order_duplicate', 'Explicit section order values must be unique.' );
					continue;
				}
				$explicit_orders[ $order ] = true;
			}

			$section_input = self::inherit_page_context( $base_input, $raw_section );
			$validated     = self::validate_section( $section_input );
			if ( $validated instanceof \WP_Error ) {
				self::append_nested_errors( $errors, $validated, $path );
				continue;
			}

			$section = $validated['manifest'];
			$id      = (string) $section['section_id'];
			if ( isset( $ids[ $id ] ) ) {
				$errors[] = self::error_row( $path . '.section_id', 'page_section_id_duplicate', 'Section IDs must be unique within a page manifest.' );
				continue;
			}
			$ids[ $id ]             = true;
			$section['order']        = $order;
			$section['source_index'] = $original_source_index;
			$section['digest_hash']  = self::digest( $section );
			$page['sections'][]      = $section;
		}

		if ( [] !== $errors ) {
			return self::invalid( $errors );
		}

		usort(
			$page['sections'],
			static fn( array $a, array $b ): int => [ (int) $a['order'], (int) $a['source_index'], (string) $a['section_id'] ] <=> [ (int) $b['order'], (int) $b['source_index'], (string) $b['section_id'] ]
		);
		$page['digest_hash'] = self::digest( $page );
		return [ 'manifest' => $page, 'digest_hash' => (string) $page['digest_hash'] ];
	}

	/** @param array<string,mixed> $input @return array{manifest:array<string,mixed>,digest_hash:string}|\WP_Error */
	private static function validate_section( array $input ): array|\WP_Error {
		$manifest = self::normalize( $input );
		$errors   = self::common_errors( $manifest, true );
		if ( array_key_exists( 'assets', $input ) && ! is_array( $input['assets'] ) ) {
			$errors[] = self::error_row( 'assets', 'assets_invalid', 'Assets must be an array.' );
		}

		$assets = AssetNormalizer::normalize_many( self::array_value( $input, 'assets' ) );
		if ( $assets instanceof \WP_Error ) {
			return $assets;
		}
		$manifest['assets'] = $assets;

		$manifest['interaction_intents'] = self::interaction_intents( $input['interaction_intents'] ?? $input['interactions'] ?? [], $assets, $errors );
		$manifest['responsive_evidence'] = self::responsive_evidence( $input['responsive_evidence'] ?? [], $errors );
		$manifest['comparison_anchors']  = self::comparison_anchors( $input['comparison_anchors'] ?? [], $errors );

		if ( [] !== $errors ) {
			return self::invalid( $errors );
		}
		$manifest['manifest_type'] = 'section';
		$manifest['digest_hash']   = self::digest( $manifest );
		return [ 'manifest' => $manifest, 'digest_hash' => (string) $manifest['digest_hash'] ];
	}

	/** @param array<string,mixed> $input @return array<string,mixed> */
	public static function normalize( array $input ): array {
		$box        = is_array( $input['bounding_box'] ?? null ) ? $input['bounding_box'] : [];
		$unresolved = [];
		foreach ( array_slice( (array) ( $input['unresolved_evidence'] ?? $input['unresolved'] ?? [] ), 0, 100 ) as $item ) {
			if ( is_array( $item ) ) {
				$unresolved[] = [
					'code'   => self::text( $item['code'] ?? '', 96 ),
					'path'   => self::text( $item['path'] ?? '', 190 ),
					'repair' => self::text( $item['repair'] ?? '', 500 ),
				];
			} else {
				$unresolved[] = [ 'code' => '', 'path' => '', 'repair' => '' ];
			}
		}

		$declared_type = self::text( $input['manifest_type'] ?? '', 16 );
		$manifest_type = in_array( $declared_type, [ 'page', 'section' ], true ) ? $declared_type : ( array_key_exists( 'sections', $input ) ? 'page' : 'section' );

		return [
			'manifest_version'        => self::VERSION,
			'manifest_type'           => $manifest_type,
			'source_type'             => self::text( $input['source_type'] ?? '', 32 ),
			'source_file_fingerprint' => self::hash( $input['source_file_fingerprint'] ?? $input['source_hash'] ?? '' ),
			'page_id'                 => self::text( $input['page_id'] ?? '', 96 ),
			'frame_id'                => self::text( $input['frame_id'] ?? '', 96 ),
			'section_id'              => self::text( $input['section_id'] ?? '', 96 ),
			'node_provenance'         => self::provenance( $input['node_provenance'] ?? $input['provenance'] ?? [] ),
			'bounding_box'            => [
				'x'      => is_numeric( $box['x'] ?? null ) ? (float) $box['x'] : null,
				'y'      => is_numeric( $box['y'] ?? null ) ? (float) $box['y'] : null,
				'width'  => is_numeric( $box['width'] ?? null ) ? (float) $box['width'] : null,
				'height' => is_numeric( $box['height'] ?? null ) ? (float) $box['height'] : null,
			],
			'layout_mode'             => self::text( $input['layout_mode'] ?? ( $input['layout']['mode'] ?? '' ), 32 ),
			'grid'                    => self::safe_map( $input['grid'] ?? [] ),
			'spacing'                 => self::safe_map( $input['spacing'] ?? [] ),
			'typography_tokens'       => self::safe_map( $input['typography_tokens'] ?? $input['typography'] ?? [] ),
			'color_tokens'            => self::safe_map( $input['color_tokens'] ?? $input['colors'] ?? [] ),
			'border'                  => self::safe_map( $input['border'] ?? [] ),
			'radius'                  => self::safe_map( $input['radius'] ?? [] ),
			'shadow'                  => self::safe_map( $input['shadow'] ?? [] ),
			'assets'                  => is_array( $input['assets'] ?? null ) ? array_values( $input['assets'] ) : [],
			'interaction_intents'     => is_array( $input['interaction_intents'] ?? $input['interactions'] ?? null ) ? array_values( $input['interaction_intents'] ?? $input['interactions'] ) : [],
			'responsive_evidence'     => is_array( $input['responsive_evidence'] ?? null ) ? array_values( $input['responsive_evidence'] ) : [],
			'semantic_roles'         => self::strings( $input['semantic_roles'] ?? $input['roles'] ?? [] ),
			'target_renderer'        => self::text( $input['target_renderer'] ?? 'auto', 32 ),
			'confidence'             => is_numeric( $input['confidence'] ?? null ) ? (float) $input['confidence'] : -1.0,
			'unresolved_evidence'    => $unresolved,
			'comparison_anchors'     => is_array( $input['comparison_anchors'] ?? null ) ? array_values( $input['comparison_anchors'] ) : [],
			'order'                  => self::non_negative_integer( $input['order'] ?? null ) ? (int) $input['order'] : null,
			'sections'               => is_array( $input['sections'] ?? null ) ? array_values( $input['sections'] ) : [],
		];
	}

	/** @param array<string,mixed> $manifest */
	public static function digest( array $manifest ): string {
		unset( $manifest['digest_hash'] );
		return Json::hash( $manifest );
	}

	/** @param array<string,mixed> $manifest @return list<array<string,mixed>> */
	public static function decompose( array $manifest ): array {
		$sections = isset( $manifest['sections'] ) && is_array( $manifest['sections'] ) ? $manifest['sections'] : [];
		if ( [] === $sections ) {
			return [ $manifest ];
		}
		$out = [];
		foreach ( $sections as $section ) {
			if ( is_array( $section ) ) {
				$out[] = $section;
			}
		}
		usort(
			$out,
			static fn( array $a, array $b ): int => [ (int) ( $a['order'] ?? PHP_INT_MAX ), (int) ( $a['source_index'] ?? PHP_INT_MAX ), (string) ( $a['section_id'] ?? '' ) ] <=> [ (int) ( $b['order'] ?? PHP_INT_MAX ), (int) ( $b['source_index'] ?? PHP_INT_MAX ), (string) ( $b['section_id'] ?? '' ) ]
		);
		return $out;
	}

	/** @param array<string,mixed> $manifest @return list<array{path:string,code:string,repair:string}> */
	private static function common_errors( array $manifest, bool $section ): array {
		$errors = [];
		if ( ! in_array( $manifest['source_type'], [ 'figma', 'screenshot', 'brief', 'live_site', 'composed' ], true ) ) {
			$errors[] = self::error_row( 'source_type', 'invalid_source_type', 'Use a supported vendor-neutral source type.' );
		}
		if ( ! preg_match( '/^[a-f0-9]{64}$/', (string) $manifest['source_file_fingerprint'] ) && 'brief' !== $manifest['source_type'] ) {
			$errors[] = self::error_row( 'source_file_fingerprint', 'source_fingerprint_missing', 'A visual source needs a stable SHA-256 fingerprint.' );
		}
		foreach ( $section ? [ 'page_id', 'frame_id', 'section_id' ] : [ 'page_id', 'frame_id' ] as $key ) {
			if ( '' === (string) $manifest[ $key ] ) {
				$errors[] = self::error_row( $key, 'manifest_identity_missing', 'The manifest needs stable page, frame, and section identity.' );
			}
		}
		if ( $section && ! self::valid_box( $manifest['bounding_box'] ) ) {
			$errors[] = self::error_row( 'bounding_box', 'bounding_box_invalid', 'Provide numeric x and y plus positive width and height.' );
		}
		if ( $section && ! in_array( $manifest['layout_mode'], [ 'flow', 'grid', 'flex', 'absolute', 'stack' ], true ) ) {
			$errors[] = self::error_row( 'layout_mode', 'layout_mode_invalid', 'Use flow, grid, flex, absolute, or stack.' );
		}
		if ( ! in_array( $manifest['target_renderer'], [ 'elementor-v3', 'elementor-v4', 'gutenberg', 'fse', 'auto' ], true ) ) {
			$errors[] = self::error_row( 'target_renderer', 'renderer_invalid', 'Use a supported native renderer.' );
		}
		if ( $manifest['confidence'] < 0 || $manifest['confidence'] > 1 ) {
			$errors[] = self::error_row( 'confidence', 'confidence_invalid', 'Confidence must be between 0 and 1.' );
		}
		if ( $section && [] === $manifest['semantic_roles'] ) {
			$errors[] = self::error_row( 'semantic_roles', 'semantic_roles_missing', 'Declare the semantic roles represented by this section.' );
		}
		if ( $section && in_array( $manifest['source_type'], [ 'figma', 'screenshot', 'live_site', 'composed' ], true ) && [] === $manifest['node_provenance'] ) {
			$errors[] = self::error_row( 'node_provenance', 'node_provenance_missing', 'Visual-source sections need at least one stable source node reference.' );
		}
		foreach ( $manifest['unresolved_evidence'] as $index => $item ) {
			if ( '' === (string) ( $item['code'] ?? '' ) || '' === (string) ( $item['repair'] ?? '' ) ) {
				$errors[] = self::error_row( 'unresolved_evidence.' . $index, 'unresolved_evidence_invalid', 'Every unresolved item needs a stable code and repair action.' );
			}
		}
		return $errors;
	}

	/**
	 * @param array<int,array<string,mixed>> $assets
	 * @param list<array{path:string,code:string,repair:string}> $errors
	 * @return list<array<string,mixed>>
	 */
	private static function interaction_intents( mixed $value, array $assets, array &$errors ): array {
		if ( ! is_array( $value ) ) {
			$errors[] = self::error_row( 'interaction_intents', 'interaction_intents_invalid', 'Interaction intents must be an array.' );
			return [];
		}
		if ( count( $value ) > self::MAX_INTENTS ) {
			$errors[] = self::error_row( 'interaction_intents', 'interaction_intent_limit_exceeded', 'Keep interaction intents bounded to the current section.' );
			return [];
		}
		$out = [];
		foreach ( array_values( $value ) as $index => $intent ) {
			if ( ! is_array( $intent ) ) {
				$errors[] = self::error_row( 'interaction_intents.' . $index, 'interaction_intent_invalid', 'Every interaction intent must be an object.' );
				continue;
			}
			$type = self::text( $intent['type'] ?? '', 64 );
			if ( '' === $type ) {
				$errors[] = self::error_row( 'interaction_intents.' . $index . '.type', 'interaction_type_missing', 'Every interaction needs an explicit semantic type.' );
				continue;
			}
			if ( 'carousel' === $type ) {
				$carousel = CarouselIntent::validate( $intent, $assets );
				if ( $carousel instanceof \WP_Error ) {
					self::append_nested_errors( $errors, $carousel, 'interaction_intents.' . $index );
					continue;
				}
				$out[] = $carousel;
				continue;
			}
			$normalized         = self::safe_map( $intent );
			$normalized['type'] = $type;
			$out[]              = $normalized;
		}
		return $out;
	}

	/** @param list<array{path:string,code:string,repair:string}> $errors @return list<array<string,mixed>> */
	private static function responsive_evidence( mixed $value, array &$errors ): array {
		if ( ! is_array( $value ) ) {
			$errors[] = self::error_row( 'responsive_evidence', 'responsive_evidence_invalid', 'Responsive evidence must be an array.' );
			return [];
		}
		if ( count( $value ) > self::MAX_EVIDENCE_ROWS ) {
			$errors[] = self::error_row( 'responsive_evidence', 'responsive_evidence_limit_exceeded', 'Keep viewport evidence bounded to the current section.' );
			return [];
		}
		$out = [];
		foreach ( array_values( $value ) as $index => $row ) {
			if ( ! is_array( $row ) ) {
				$errors[] = self::error_row( 'responsive_evidence.' . $index, 'responsive_evidence_invalid', 'Every viewport evidence row must be an object.' );
				continue;
			}
			$id     = self::text( $row['viewport_id'] ?? $row['id'] ?? '', 96 );
			$width  = $row['width'] ?? null;
			$height = $row['height'] ?? null;
			if ( '' === $id || ! is_numeric( $width ) || ! is_numeric( $height ) || (float) $width <= 0 || (float) $height <= 0 ) {
				$errors[] = self::error_row( 'responsive_evidence.' . $index, 'responsive_evidence_invalid', 'Viewport evidence needs an ID plus positive measured width and height.' );
				continue;
			}
			$out[] = [
				'viewport_id' => $id,
				'device'      => self::text( $row['device'] ?? '', 32 ),
				'width'       => (float) $width,
				'height'      => (float) $height,
				'source_ref'  => self::text( $row['source_ref'] ?? '', 190 ),
				'source_hash' => self::hash( $row['source_hash'] ?? '' ),
				'measurements' => self::safe_map( $row['measurements'] ?? [] ),
			];
		}
		return $out;
	}

	/** @param list<array{path:string,code:string,repair:string}> $errors @return list<array<string,mixed>> */
	private static function comparison_anchors( mixed $value, array &$errors ): array {
		if ( ! is_array( $value ) ) {
			$errors[] = self::error_row( 'comparison_anchors', 'comparison_anchors_invalid', 'Comparison anchors must be an array.' );
			return [];
		}
		if ( count( $value ) > self::MAX_ANCHORS ) {
			$errors[] = self::error_row( 'comparison_anchors', 'comparison_anchor_limit_exceeded', 'Keep comparison anchors bounded to the current section.' );
			return [];
		}
		$out = [];
		foreach ( array_values( $value ) as $index => $row ) {
			if ( ! is_array( $row ) ) {
				$errors[] = self::error_row( 'comparison_anchors.' . $index, 'comparison_anchor_invalid', 'Every comparison anchor must be an object.' );
				continue;
			}
			$ref = self::text( $row['ref'] ?? $row['node_id'] ?? '', 96 );
			if ( '' === $ref ) {
				$errors[] = self::error_row( 'comparison_anchors.' . $index . '.ref', 'comparison_anchor_ref_missing', 'Every comparison anchor needs a stable reference.' );
				continue;
			}
			$out[] = [
				'ref'            => $ref,
				'node_id'        => self::text( $row['node_id'] ?? '', 96 ),
				'selector'       => self::text( $row['selector'] ?? '', 190 ),
				'target_setting' => self::text( $row['target_setting'] ?? '', 190 ),
				'metrics'        => self::safe_map( $row['metrics'] ?? [] ),
			];
		}
		return $out;
	}

	/** @param array<string,mixed> $page @param array<string,mixed> $section @return array<string,mixed> */
	private static function inherit_page_context( array $page, array $section ): array {
		foreach ( [ 'section_id', 'node_provenance', 'provenance', 'bounding_box', 'layout', 'layout_mode', 'semantic_roles', 'roles', 'interaction_intents', 'interactions', 'responsive_evidence', 'comparison_anchors', 'unresolved_evidence', 'unresolved', 'order' ] as $key ) {
			unset( $page[ $key ] );
		}
		$parent_assets = is_array( $page['assets'] ?? null ) ? $page['assets'] : [];
		$child_assets  = is_array( $section['assets'] ?? null ) ? $section['assets'] : [];
		$input         = array_replace( $page, $section );
		$input['assets'] = array_values( array_merge( $parent_assets, $child_assets ) );
		unset( $input['sections'] );
		return $input;
	}

	/** @param list<array{path:string,code:string,repair:string}> $errors */
	private static function append_nested_errors( array &$errors, \WP_Error $error, string $prefix ): void {
		$data        = $error->get_error_data();
		$diagnostics = is_array( $data ) && is_array( $data['diagnostics'] ?? null ) ? $data['diagnostics'] : [];
		if ( [] === $diagnostics ) {
			$errors[] = self::error_row( $prefix, (string) $error->get_error_code(), $error->get_error_message() );
			return;
		}
		foreach ( $diagnostics as $diagnostic ) {
			if ( ! is_array( $diagnostic ) ) {
				continue;
			}
			$path     = self::text( $diagnostic['path'] ?? '', 190 );
			$errors[] = self::error_row(
				$prefix . ( '' !== $path ? '.' . $path : '' ),
				self::text( $diagnostic['code'] ?? $error->get_error_code(), 96 ),
				self::text( $diagnostic['repair'] ?? $error->get_error_message(), 500 )
			);
		}
	}

	private static function valid_box( mixed $box ): bool {
		if ( ! is_array( $box ) ) {
			return false;
		}
		foreach ( [ 'x', 'y', 'width', 'height' ] as $key ) {
			if ( ! is_numeric( $box[ $key ] ?? null ) ) {
				return false;
			}
		}
		return (float) $box['width'] > 0 && (float) $box['height'] > 0;
	}

	private static function non_negative_integer( mixed $value ): bool {
		return is_numeric( $value ) && (float) $value >= 0 && floor( (float) $value ) === (float) $value;
	}

	private static function hash( mixed $value ): string {
		$value = is_scalar( $value ) ? strtolower( trim( (string) $value ) ) : '';
		return 1 === preg_match( '/^[a-f0-9]{64}$/', $value ) ? $value : '';
	}

	private static function text( mixed $value, int $length ): string {
		return is_scalar( $value ) ? mb_substr( sanitize_text_field( (string) $value ), 0, $length ) : '';
	}

	/** @return array<string,mixed> */
	private static function safe_map( mixed $value, int $depth = 0 ): array {
		if ( ! is_array( $value ) || $depth > 4 ) {
			return [];
		}
		$out = [];
		foreach ( array_slice( $value, 0, 100, true ) as $key => $item ) {
			$safe_key = is_string( $key ) ? sanitize_key( $key ) : (string) $key;
			if ( '' === $safe_key ) {
				continue;
			}
			if ( is_array( $item ) ) {
				$out[ $safe_key ] = self::safe_map( $item, $depth + 1 );
			} elseif ( is_bool( $item ) || is_int( $item ) || is_float( $item ) || null === $item ) {
				$out[ $safe_key ] = $item;
			} elseif ( is_scalar( $item ) ) {
				$out[ $safe_key ] = self::text( $item, 500 );
			}
		}
		if ( ! array_is_list( $out ) ) {
			ksort( $out );
		}
		return $out;
	}

	/** @return list<string> */
	private static function strings( mixed $value ): array {
		if ( ! is_array( $value ) ) {
			return [];
		}
		$out = [];
		foreach ( array_slice( $value, 0, 100 ) as $item ) {
			if ( is_scalar( $item ) ) {
				$text = self::text( $item, 96 );
				if ( '' !== $text ) {
					$out[] = $text;
				}
			}
		}
		return array_values( array_unique( $out ) );
	}

	/** @return list<array<string,mixed>> */
	private static function provenance( mixed $value ): array {
		$out = [];
		foreach ( array_slice( is_array( $value ) ? $value : [], 0, 250 ) as $item ) {
			if ( ! is_array( $item ) ) {
				continue;
			}
			$node_id   = self::text( $item['node_id'] ?? '', 96 );
			$source_id = self::text( $item['source_id'] ?? '', 96 );
			if ( '' === $node_id && '' === $source_id ) {
				continue;
			}
			$out[] = [
				'node_id'   => $node_id,
				'source_id' => $source_id,
				'path'      => self::text( $item['path'] ?? '', 190 ),
			];
		}
		return $out;
	}

	/** @param array<string,mixed> $input @return array<int,mixed> */
	private static function array_value( array $input, string $key ): array {
		return is_array( $input[ $key ] ?? null ) ? array_values( $input[ $key ] ) : [];
	}

	/** @param list<array{path:string,code:string,repair:string}> $errors */
	private static function invalid( array $errors ): \WP_Error {
		return new \WP_Error(
			'stonewright_section_manifest_invalid',
			__( 'Section manifest is incomplete or unsafe.', 'stonewright' ),
			[ 'status' => 400, 'schema_version' => self::VERSION, 'diagnostics' => $errors ]
		);
	}

	/** @return array{path:string,code:string,repair:string} */
	private static function error_row( string $path, string $code, string $repair ): array {
		return [ 'path' => $path, 'code' => $code, 'repair' => $repair ];
	}
}
