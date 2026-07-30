<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\Design\Evidence;

use Stonewright\WpMcp\Design\Semantics\ActionValidator;
use Stonewright\WpMcp\Support\Json;

/** Normalizes vendor-neutral design evidence and rejects unsupported inference. */
final class Validator {

	public const VERSION = '1.0.0';

	private const SOURCE_TYPES = [ 'figma', 'screenshot', 'image', 'user_brief', 'live_site', 'official_docs' ];
	private const PROVENANCE_TYPES = [ 'design', 'live_schema', 'official_docs', 'user', 'verified_memory', 'inference' ];
	private const ROLES = [
		'section',
		'group',
		'container',
		'heading',
		'paragraph',
		'text',
		'image',
		'gallery',
		'button',
		'cta',
		'link',
		'navigation',
		'form',
		'repeated-cards',
		'tabs',
		'accordion',
		'carousel',
		'countdown',
		'social-links',
		'icon-list',
		'video',
		'header',
		'footer',
	];

	/**
	 * @param array<string, mixed> $input
	 * @return array{evidence:array<string,mixed>,evidence_hash:string,node_count:int,source_count:int,viewport_count:int}|\WP_Error
	 */
	public static function validate( array $input ): array|\WP_Error {
		$evidence    = self::normalize( $input );
		$diagnostics = [];
		$sources     = (array) $evidence['sources'];
		$source_ids  = [];
		$has_visual_source = false;

		if ( [] === $sources ) {
			$diagnostics[] = self::diagnostic( 'sources', 'sources_missing', 'Add at least one Figma, screenshot, image, live-site, documentation, or user source.' );
		}
		foreach ( $sources as $index => $source ) {
			$source = is_array( $source ) ? $source : [];
			$id     = trim( (string) ( $source['id'] ?? '' ) );
			$type   = (string) ( $source['type'] ?? '' );
			if ( '' === $id || isset( $source_ids[ $id ] ) ) {
				$diagnostics[] = self::diagnostic( 'sources[' . $index . '].id', 'invalid_source_id', 'Use a unique non-empty source id.' );
			} else {
				$source_ids[ $id ] = true;
			}
			if ( ! in_array( $type, self::SOURCE_TYPES, true ) ) {
				$diagnostics[] = self::diagnostic( 'sources[' . $index . '].type', 'invalid_source_type', 'Use one of: ' . implode( ', ', self::SOURCE_TYPES ) . '.' );
			}
			if ( in_array( $type, [ 'figma', 'screenshot', 'image' ], true ) ) {
				$has_visual_source = true;
				if ( '' === trim( (string) ( $source['ref'] ?? '' ) ) || ! preg_match( '/^[a-f0-9]{64}$/i', (string) ( $source['hash'] ?? '' ) ) ) {
					$diagnostics[] = self::diagnostic( 'sources[' . $index . ']', 'visual_source_unverifiable', 'Provide a stable source ref and SHA-256 for each Figma, screenshot, or image reference.' );
				}
			}
		}

		$viewports = (array) $evidence['viewports'];
		if ( [] === $viewports ) {
			$diagnostics[] = self::diagnostic( 'viewports', 'viewports_missing', 'Add at least one measured viewport width and height.' );
		}
		foreach ( $viewports as $index => $viewport ) {
			$viewport = is_array( $viewport ) ? $viewport : [];
			if ( '' === trim( (string) ( $viewport['id'] ?? '' ) ) || (int) ( $viewport['width'] ?? 0 ) < 1 || (int) ( $viewport['height'] ?? 0 ) < 1 ) {
				$diagnostics[] = self::diagnostic( 'viewports[' . $index . ']', 'invalid_viewport', 'Provide id, positive width, and positive height.' );
			}
		}
		if ( $has_visual_source && count( $viewports ) < 2 ) {
			$diagnostics[] = self::diagnostic( 'viewports', 'responsive_evidence_missing', 'Visual implementation needs at least two measured viewports, including desktop and mobile, before write planning.' );
		}

		$viewport_ids = [];
		foreach ( $viewports as $viewport ) {
			$viewport = is_array( $viewport ) ? $viewport : [];
			$vid      = trim( (string) ( $viewport['id'] ?? '' ) );
			if ( '' !== $vid ) {
				$viewport_ids[ $vid ] = true;
			}
		}

		// Pixel-perfect measured targets (optional but structured when present).
		foreach ( (array) ( $evidence['measured_targets'] ?? [] ) as $index => $target ) {
			$target = is_array( $target ) ? $target : [];
			$vid    = trim( (string) ( $target['viewport_id'] ?? '' ) );
			$prop   = trim( (string) ( $target['property'] ?? '' ) );
			if ( '' === $vid || '' === $prop || ! is_numeric( $target['value_px'] ?? null ) ) {
				$diagnostics[] = self::diagnostic(
					'measured_targets[' . $index . ']',
					'invalid_measured_target',
					'Provide viewport_id, property, and numeric value_px for each measured target.'
				);
				continue;
			}
			if ( [] !== $viewport_ids && ! isset( $viewport_ids[ $vid ] ) ) {
				$diagnostics[] = self::diagnostic(
					'measured_targets[' . $index . '].viewport_id',
					'measured_target_viewport_unknown',
					'measured_targets.viewport_id must reference a declared viewport id.'
				);
			}
		}

		// Structured token tables when present.
		$global = (array) ( $evidence['global'] ?? [] );
		foreach ( [ 'spacing_scale', 'typography_ramp', 'figma_token_table' ] as $token_key ) {
			if ( isset( $global[ $token_key ] ) && ! is_array( $global[ $token_key ] ) ) {
				$diagnostics[] = self::diagnostic(
					'global.' . $token_key,
					'invalid_token_table',
					'global.' . $token_key . ' must be an object or list of token entries.'
				);
			}
		}

		self::validate_unresolved( (array) $evidence['unresolved'], 'unresolved', $diagnostics );

		$nodes = (array) $evidence['nodes'];
		if ( [] === $nodes ) {
			$diagnostics[] = self::diagnostic( 'nodes', 'nodes_missing', 'Add at least one semantic design node.' );
		}
		$seen_nodes = [];
		$global     = (array) $evidence['global'];
		$global_provenance = isset( $global['provenance'] ) && is_array( $global['provenance'] ) ? $global['provenance'] : [];
		// Provenance required for concrete style leaves; structured tables (scales/ramps/token tables)
		// are metadata containers and may omit per-leaf provenance.
		foreach ( [ 'container_max_widths', 'colors', 'typography', 'spacing' ] as $group ) {
			foreach ( self::leaf_paths( (array) ( $global[ $group ] ?? [] ), $group ) as $setting => $value ) {
				if ( self::neutral( $value ) ) {
					continue;
				}
				$row = isset( $global_provenance[ $setting ] ) && is_array( $global_provenance[ $setting ] ) ? $global_provenance[ $setting ] : null;
				if ( null === $row ) {
					$diagnostics[] = self::diagnostic( 'global.provenance.' . $setting, 'style_provenance_missing', 'Attach provenance for this global design value.' );
				} else {
					self::validate_provenance( $row, 'global.provenance.' . $setting, $source_ids, $diagnostics );
				}
			}
		}
		foreach ( $nodes as $index => $node ) {
			if ( is_array( $node ) ) {
				self::validate_node( $node, 'nodes[' . $index . ']', $source_ids, $seen_nodes, $diagnostics, $has_visual_source );
			}
		}
		$diagnostics = array_merge( $diagnostics, ActionValidator::validate_evidence_nodes( $nodes ) );

		if ( [] !== $diagnostics ) {
			return new \WP_Error(
				'stonewright_design_evidence_invalid',
				__( 'Design evidence is incomplete or cannot safely drive a write plan.', 'stonewright' ),
				[ 'status' => 400, 'schema_version' => self::VERSION, 'diagnostics' => $diagnostics ]
			);
		}

		return [
			'evidence'       => $evidence,
			'evidence_hash'  => Json::hash( self::canonicalize( $evidence ) ),
			'node_count'     => self::node_count( $nodes ),
			'source_count'   => count( $sources ),
			'viewport_count' => count( $viewports ),
		];
	}

	/** @param array<string, mixed> $input */
	private static function normalize( array $input ): array {
		$global = self::pick(
			(array) ( $input['global'] ?? [] ),
			[
				'container_max_widths',
				'colors',
				'color_tokens',
				'typography',
				'typography_ramp',
				'spacing',
				'spacing_scale',
				'figma_token_table',
				'assets',
				'provenance',
			]
		);
		// color_tokens is an alias surface for pixel-perfect token tables.
		if ( isset( $global['color_tokens'] ) && is_array( $global['color_tokens'] ) ) {
			$colors = isset( $global['colors'] ) && is_array( $global['colors'] ) ? $global['colors'] : [];
			$global['colors'] = array_merge( $colors, $global['color_tokens'] );
		}

		return [
			'schema_version'    => self::VERSION,
			'sources'           => array_values( array_map( [ self::class, 'normalize_source' ], (array) ( $input['sources'] ?? [] ) ) ),
			'viewports'         => array_values( array_map( [ self::class, 'normalize_viewport' ], (array) ( $input['viewports'] ?? [] ) ) ),
			'global'            => $global,
			'nodes'             => array_values( array_map( [ self::class, 'normalize_node' ], (array) ( $input['nodes'] ?? [] ) ) ),
			'measured_targets'  => array_values( array_map( [ self::class, 'normalize_measured_target' ], (array) ( $input['measured_targets'] ?? [] ) ) ),
			'unresolved'        => array_values( array_map( [ self::class, 'normalize_unresolved' ], (array) ( $input['unresolved'] ?? [] ) ) ),
		];
	}

	/**
	 * @param mixed $row
	 * @return array<string, mixed>
	 */
	private static function normalize_measured_target( mixed $row ): array {
		$row = self::pick(
			is_array( $row ) ? $row : [],
			[ 'viewport_id', 'node_id', 'property', 'value_px', 'unit', 'tolerance_px' ]
		);
		if ( isset( $row['value_px'] ) && is_numeric( $row['value_px'] ) ) {
			$row['value_px'] = (float) $row['value_px'];
		}
		if ( isset( $row['tolerance_px'] ) && is_numeric( $row['tolerance_px'] ) ) {
			$row['tolerance_px'] = (float) $row['tolerance_px'];
		}
		if ( ! isset( $row['unit'] ) || '' === (string) $row['unit'] ) {
			$row['unit'] = 'px';
		}
		return $row;
	}

	private static function normalize_source( mixed $source ): array {
		return self::pick( is_array( $source ) ? $source : [], [ 'id', 'type', 'ref', 'hash', 'captured_at', 'viewport_id' ] );
	}

	private static function normalize_viewport( mixed $viewport ): array {
		$viewport = self::pick( is_array( $viewport ) ? $viewport : [], [ 'id', 'width', 'height', 'device_pixel_ratio' ] );
		if ( isset( $viewport['width'] ) ) {
			$viewport['width'] = (int) $viewport['width'];
		}
		if ( isset( $viewport['height'] ) ) {
			$viewport['height'] = (int) $viewport['height'];
		}
		return $viewport;
	}

	private static function normalize_node( mixed $node ): array {
		$node = self::pick(
			is_array( $node ) ? $node : [],
			[
				'id',
				'role',
				'name',
				'bounds',
				'layout',
				'style',
				'content',
				'action',
				'asset',
				'responsive',
				'measured_targets',
				'provenance',
				'menu_id',
				'links',
				'fields',
				'content_model',
				'conditions',
				'customization_needs',
				'unresolved',
				'children',
			]
		);
		if ( isset( $node['layout'] ) && is_array( $node['layout'] ) ) {
			$node['layout'] = self::pick(
				$node['layout'],
				[
					'type',
					'direction',
					'align_items',
					'justify_content',
					'gap',
					'row_gap',
					'column_gap',
					'columns',
					'wrap',
					'content_width',
				]
			);
		}
		$node['measured_targets'] = array_values(
			array_map( [ self::class, 'normalize_measured_target' ], (array) ( $node['measured_targets'] ?? [] ) )
		);
		$node['children']   = array_values( array_map( [ self::class, 'normalize_node' ], (array) ( $node['children'] ?? [] ) ) );
		$node['unresolved'] = array_values( array_map( [ self::class, 'normalize_unresolved' ], (array) ( $node['unresolved'] ?? [] ) ) );
		$node['customization_needs'] = array_values( array_map( [ self::class, 'normalize_customization_need' ], (array) ( $node['customization_needs'] ?? [] ) ) );
		return $node;
	}

	private static function normalize_unresolved( mixed $item ): array {
		return self::pick( is_array( $item ) ? $item : [], [ 'code', 'path', 'repair' ] );
	}

	private static function normalize_customization_need( mixed $item ): array {
		return self::pick( is_array( $item ) ? $item : [], [ 'delta', 'reason' ] );
	}

	/**
	 * @param array<string, mixed>       $node
	 * @param array<string, bool>        $source_ids
	 * @param array<string, bool>        $seen_nodes
	 * @param list<array<string, mixed>> $diagnostics
	 */
	private static function validate_node( array $node, string $path, array $source_ids, array &$seen_nodes, array &$diagnostics, bool $require_bounds ): void {
		$id   = trim( (string) ( $node['id'] ?? '' ) );
		$role = strtolower( trim( (string) ( $node['role'] ?? '' ) ) );
		if ( '' === $id || isset( $seen_nodes[ $id ] ) ) {
			$diagnostics[] = self::diagnostic( $path . '.id', 'invalid_node_id', 'Use a unique non-empty node id.' );
		} else {
			$seen_nodes[ $id ] = true;
		}
		if ( ! in_array( $role, self::ROLES, true ) ) {
			$diagnostics[] = self::diagnostic( $path . '.role', 'unknown_semantic_role', 'Use a supported semantic role; do not pass raw Figma node types.' );
		}
		if ( $require_bounds && ! self::valid_bounds( $node['bounds'] ?? null ) ) {
			$diagnostics[] = self::diagnostic( $path . '.bounds', 'measured_bounds_missing', 'Provide measured x, y, width, and height for every visual semantic node.' );
		}
		foreach ( self::human_copy( $node ) as $copy_path => $copy ) {
			if ( self::is_placeholder_copy( $copy ) ) {
				$diagnostics[] = self::diagnostic( $path . '.' . $copy_path, 'placeholder_copy', 'Replace placeholder copy with verified design or user content before planning.' );
			}
		}
		self::validate_unresolved( (array) ( $node['unresolved'] ?? [] ), $path . '.unresolved', $diagnostics );
		foreach ( (array) ( $node['customization_needs'] ?? [] ) as $index => $need ) {
			$need = is_array( $need ) ? $need : [];
			if ( '' === trim( (string) ( $need['delta'] ?? '' ) ) || '' === trim( (string) ( $need['reason'] ?? '' ) ) ) {
				$diagnostics[] = self::diagnostic( $path . '.customization_needs[' . $index . ']', 'invalid_customization_need', 'Describe the remaining delta and why verified native controls cannot cover it.' );
			}
		}

		$style      = isset( $node['style'] ) && is_array( $node['style'] ) ? $node['style'] : [];
		$provenance = isset( $node['provenance'] ) && is_array( $node['provenance'] ) ? $node['provenance'] : [];
		foreach ( self::leaf_paths( $style ) as $setting => $value ) {
			if ( self::neutral( $value ) ) {
				continue;
			}
			$row = isset( $provenance[ $setting ] ) && is_array( $provenance[ $setting ] ) ? $provenance[ $setting ] : null;
			if ( null === $row ) {
				$diagnostics[] = self::diagnostic( $path . '.provenance.' . $setting, 'style_provenance_missing', 'Attach source_id, source, confidence, and requires_confirmation for this style.' );
				continue;
			}
			self::validate_provenance( $row, $path . '.provenance.' . $setting, $source_ids, $diagnostics );
		}

		foreach ( (array) ( $node['children'] ?? [] ) as $index => $child ) {
			if ( is_array( $child ) ) {
				self::validate_node( $child, $path . '.children[' . $index . ']', $source_ids, $seen_nodes, $diagnostics, $require_bounds );
			}
		}
	}

	/**
	 * @param list<array<string, mixed>> $items
	 * @param list<array<string, mixed>> $diagnostics
	 */
	private static function validate_unresolved( array $items, string $path, array &$diagnostics ): void {
		foreach ( $items as $index => $item ) {
			if ( '' === trim( (string) ( $item['code'] ?? '' ) ) || '' === trim( (string) ( $item['repair'] ?? '' ) ) ) {
				$diagnostics[] = self::diagnostic( $path . '[' . $index . ']', 'invalid_unresolved_item', 'Describe the unresolved item with a stable code and one repair action.' );
			}
		}
	}

	/**
	 * @param array<string, mixed>       $row
	 * @param array<string, bool>        $source_ids
	 * @param list<array<string, mixed>> $diagnostics
	 */
	private static function validate_provenance( array $row, string $path, array $source_ids, array &$diagnostics ): void {
		$source    = (string) ( $row['source'] ?? '' );
		$source_id = (string) ( $row['source_id'] ?? '' );
		$confidence = $row['confidence'] ?? null;
		$confirm   = $row['requires_confirmation'] ?? null;
		if ( ! in_array( $source, self::PROVENANCE_TYPES, true ) || ! isset( $source_ids[ $source_id ] ) || ! is_numeric( $confidence ) || (float) $confidence < 0 || (float) $confidence > 1 || ! is_bool( $confirm ) ) {
			$diagnostics[] = self::diagnostic( $path, 'invalid_provenance', 'Reference a declared source_id and provide valid source, confidence 0..1, and requires_confirmation.' );
			return;
		}
		if ( 'inference' === $source && true !== $confirm ) {
			$diagnostics[] = self::diagnostic( $path, 'unconfirmed_inference', 'Inference cannot drive a write unless requires_confirmation=true.' );
		}
	}

	/** @param array<string, mixed> $input @param list<string> $keys @return array<string, mixed> */
	private static function pick( array $input, array $keys ): array {
		return array_intersect_key( $input, array_fill_keys( $keys, true ) );
	}

	/** @param array<string, mixed> $value @return array<string, mixed> */
	private static function leaf_paths( array $value, string $prefix = '' ): array {
		$out = [];
		foreach ( $value as $key => $item ) {
			$path = '' === $prefix ? (string) $key : $prefix . '.' . (string) $key;
			if ( is_array( $item ) && ! array_is_list( $item ) ) {
				$out += self::leaf_paths( $item, $path );
			} else {
				$out[ $path ] = $item;
			}
		}
		return $out;
	}

	private static function neutral( mixed $value ): bool {
		return null === $value || '' === $value || 0 === $value || '0' === $value || in_array( strtolower( is_string( $value ) ? $value : '' ), [ 'none', 'transparent', 'normal' ], true );
	}

	/** @param list<array<string, mixed>> $nodes */
	private static function node_count( array $nodes ): int {
		$count = 0;
		foreach ( $nodes as $node ) {
			if ( is_array( $node ) ) {
				++$count;
				$count += self::node_count( (array) ( $node['children'] ?? [] ) );
			}
		}
		return $count;
	}

	private static function canonicalize( mixed $value ): mixed {
		if ( ! is_array( $value ) ) {
			return $value;
		}
		if ( ! array_is_list( $value ) ) {
			ksort( $value );
		}
		foreach ( $value as $key => $item ) {
			$value[ $key ] = self::canonicalize( $item );
		}
		return $value;
	}

	private static function valid_bounds( mixed $bounds ): bool {
		if ( ! is_array( $bounds ) ) {
			return false;
		}
		return is_numeric( $bounds['x'] ?? null )
			&& is_numeric( $bounds['y'] ?? null )
			&& is_numeric( $bounds['width'] ?? null )
			&& is_numeric( $bounds['height'] ?? null )
			&& (float) $bounds['width'] > 0
			&& (float) $bounds['height'] > 0;
	}

	/** @param array<string, mixed> $node @return array<string, string> */
	private static function human_copy( array $node ): array {
		$out = [];
		foreach ( [ 'name' ] as $key ) {
			if ( is_string( $node[ $key ] ?? null ) ) {
				$out[ $key ] = (string) $node[ $key ];
			}
		}
		foreach ( (array) ( $node['content'] ?? [] ) as $key => $value ) {
			if ( is_string( $value ) ) {
				$out[ 'content.' . (string) $key ] = $value;
			}
		}
		return $out;
	}

	private static function is_placeholder_copy( string $value ): bool {
		$value = strtolower( trim( strip_tags( $value ) ) );
		return 1 === preg_match( '/^(?:titlu(?:\s+card|\s+\d+)?|text(?:\s+card)?|icon\s*\+\s*title\s*\d*|type your paragraph here|lorem ipsum|card(?: featured)?|beneficiu\s*\d+)$/u', $value );
	}

	/** @return array<string, mixed> */
	private static function diagnostic( string $path, string $code, string $repair ): array {
		return [ 'path' => $path, 'code' => $code, 'blocking' => true, 'repair' => $repair ];
	}
}
