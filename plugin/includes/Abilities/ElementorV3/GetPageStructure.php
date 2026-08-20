<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\Abilities\ElementorV3;

use Stonewright\WpMcp\Abilities\AbilityKernel;
use Stonewright\WpMcp\Elementor\Schema\ContainerSchemaRepository;
use Stonewright\WpMcp\Elementor\Schema\WidgetSchemaRepository;
use Stonewright\WpMcp\Security\Permissions;
use Stonewright\WpMcp\Support\ElementorData;
use Stonewright\WpMcp\Support\TreeSummary;

/**
 * Contract decision: keep output_schema aligned to the handler response shape.
 *
 * @stonewright-status stable
 */
final class GetPageStructure extends AbilityKernel {

	private const STYLE_CSS_MAX = 8192;

	public function name(): string {
		return 'stonewright/elementor-v3-get-page-structure';
	}

	public function label(): string {
		return __( 'Get Elementor page structure', 'stonewright' );
	}

	public function description(): string {
		return __( 'Returns a compact Elementor V3 page outline by default, optional bounded node content, or the full element tree when responseMode=full.', 'stonewright' );
	}

	public function category(): string {
		return 'elementor';
	}

	public function input_schema(): array {
		return [
			'type'                 => 'object',
			'additionalProperties' => false,
			'properties'           => [
				'post_id'      => [ 'type' => 'integer', 'minimum' => 1 ],
				'responseMode' => [
					'type'        => 'string',
					'enum'        => [ 'summary', 'full' ],
					'default'     => 'summary',
					'description' => 'Use summary for compact element IDs, paths, widget types, and settings keys; use full only when raw Elementor JSON is required.',
				],
				'maxElements'  => [
					'type'        => 'integer',
					'minimum'     => 1,
					'maximum'     => 500,
					'default'     => 200,
					'description' => 'Maximum outline rows returned in summary mode.',
				],
				'knownHash'    => [
					'type'        => 'string',
					'description' => 'Optional. The hash returned by a previous read of this page. When it still matches, the response is only { post_id, active, hash, unchanged: true } and no outline or tree is built.',
				],
				'include_content' => [
					'type'        => 'boolean',
					'default'     => false,
					'description' => 'When true, return bounded per-node allowlisted settings and round-trippable {__style_id, css} styles. Never dumps the full document JSON or media binaries.',
				],
				'root_id'      => [
					'type'        => 'string',
					'description' => 'Optional. Limit the outline and include_content nodes to this element subtree.',
				],
				'element_id'   => [
					'type'        => 'string',
					'description' => 'Optional alias of root_id.',
				],
			],
			'required'             => [ 'post_id' ],
		];
	}

	public function output_schema(): array {
		return [ 'type' => 'object' ];
	}

	public function permission_callback( array $args ): bool|\WP_Error {
		$id = (int) ( $args['post_id'] ?? 0 );
		return Permissions::edit_post( $id );
	}

	public function execute( array $args ): array|\WP_Error {
		$post_id = (int) $args['post_id'];
		if ( ! get_post( $post_id ) ) {
			return $this->error( 'not_found', __( 'Post not found.', 'stonewright' ) );
		}

		$tree = ElementorData::read( $post_id );
		$hash = self::tree_hash( $tree );

		$known_hash = isset( $args['knownHash'] ) && is_string( $args['knownHash'] ) ? trim( $args['knownHash'] ) : '';
		if ( '' !== $known_hash && hash_equals( $hash, $known_hash ) ) {
			// The caller already has this document. Answering here skips both the
			// flatten and the outline build, which is the whole cost of the read.
			return [
				'post_id'   => $post_id,
				'active'    => ElementorData::is_active( $post_id ),
				'hash'      => $hash,
				'unchanged' => true,
			];
		}

		$include_content = ! empty( $args['include_content'] );
		$root_id         = trim( (string) ( $args['root_id'] ?? $args['element_id'] ?? '' ) );
		$view            = $tree;
		if ( '' !== $root_id ) {
			$found = self::find_element( $tree, $root_id );
			if ( null === $found ) {
				return $this->error( 'element_not_found', __( 'Element not found.', 'stonewright' ), [ 'root_id' => $root_id ] );
			}
			$view = [ $found ];
		}

		if ( $include_content ) {
			$max_elements = min( 500, max( 1, (int) ( $args['maxElements'] ?? 200 ) ) );
			$summary      = TreeSummary::outline(
				$view,
				$max_elements,
				static fn( array $element, array $ctx ): array => TreeSummary::default_row( $element, $ctx )
			);
			$nodes = self::content_nodes( $view, $max_elements );

			return [
				'post_id'        => $post_id,
				'active'         => ElementorData::is_active( $post_id ),
				'response_mode'  => 'summary',
				'hash'           => $hash,
				'unchanged'      => false,
				'count'          => $summary['count'],
				'returned_count' => count( $nodes ),
				'truncated'      => $summary['truncated'] || $summary['count'] > count( $nodes ),
				'tree_omitted'   => true,
				'outline'        => $summary['outline'],
				'nodes'          => $nodes,
				'full_mode_hint' => 'Call with responseMode=full only when raw Elementor JSON is required for the next edit.',
			];
		}

		if ( 'full' === (string) ( $args['responseMode'] ?? 'summary' ) ) {
			return [
				'post_id'       => $post_id,
				'active'        => ElementorData::is_active( $post_id ),
				'response_mode' => 'full',
				'hash'          => $hash,
				'unchanged'     => false,
				'tree'          => $view,
				'count'         => count( ElementorData::flatten( $view ) ),
			];
		}

		$max_elements = min( 500, max( 1, (int) ( $args['maxElements'] ?? 200 ) ) );
		$summary      = TreeSummary::outline(
			$view,
			$max_elements,
			static fn( array $element, array $ctx ): array => TreeSummary::default_row( $element, $ctx )
		);

		return [
			'post_id'        => $post_id,
			'active'         => ElementorData::is_active( $post_id ),
			'response_mode'  => 'summary',
			'hash'           => $hash,
			'unchanged'      => false,
			'count'          => $summary['count'],
			'returned_count' => $summary['returned_count'],
			'truncated'      => $summary['truncated'],
			'tree_omitted'   => true,
			'outline'        => $summary['outline'],
			'full_mode_hint' => 'Call with responseMode=full only when raw Elementor JSON is required for the next edit.',
		];
	}

	/**
	 * @param array<int, mixed> $tree
	 * @return array<string, mixed>|null
	 */
	private static function find_element( array $tree, string $id ): ?array {
		foreach ( $tree as $element ) {
			if ( ! is_array( $element ) ) {
				continue;
			}
			if ( (string) ( $element['id'] ?? '' ) === $id ) {
				return $element;
			}
			$found = self::find_element( is_array( $element['elements'] ?? null ) ? $element['elements'] : [], $id );
			if ( null !== $found ) {
				return $found;
			}
		}
		return null;
	}

	/**
	 * @param array<int, mixed> $tree
	 * @return list<array<string, mixed>>
	 */
	private static function content_nodes( array $tree, int $max_elements ): array {
		$nodes = [];
		self::collect_content_nodes( $tree, $nodes, $max_elements );
		return $nodes;
	}

	/**
	 * @param array<int, mixed>            $tree
	 * @param list<array<string, mixed>>   $nodes
	 */
	private static function collect_content_nodes( array $tree, array &$nodes, int $max_elements ): void {
		foreach ( $tree as $element ) {
			if ( count( $nodes ) >= $max_elements ) {
				return;
			}
			if ( ! is_array( $element ) ) {
				continue;
			}
			$nodes[] = [
				'id'         => (string) ( $element['id'] ?? '' ),
				'elType'     => (string) ( $element['elType'] ?? '' ),
				'widgetType' => (string) ( $element['widgetType'] ?? '' ),
				'settings'   => self::allowlisted_settings( $element ),
				'styles'     => self::round_trip_styles( $element ),
			];
			self::collect_content_nodes( is_array( $element['elements'] ?? null ) ? $element['elements'] : [], $nodes, $max_elements );
		}
	}

	/**
	 * @param array<string, mixed> $element
	 * @return array<string, mixed>
	 */
	private static function allowlisted_settings( array $element ): array {
		$settings = is_array( $element['settings'] ?? null ) ? $element['settings'] : [];
		$controls = self::live_controls( $element );
		$out      = [];
		foreach ( $settings as $key => $value ) {
			$key = (string) $key;
			if ( in_array( $key, [ '__dynamic__', '__globals__' ], true ) || self::control_is_known( $key, $controls ) ) {
				$out[ $key ] = self::sanitize_setting_value( $value );
			}
		}
		return $out;
	}

	/**
	 * @param array<string, mixed> $element
	 * @return array<string, array<string, mixed>>
	 */
	private static function live_controls( array $element ): array {
		$el_type = (string) ( $element['elType'] ?? '' );
		if ( 'widget' === $el_type ) {
			$schema = WidgetSchemaRepository::get( (string) ( $element['widgetType'] ?? '' ) );
			return is_array( $schema ) ? (array) ( $schema['controls'] ?? [] ) : [];
		}
		if ( in_array( $el_type, [ 'container', 'section', 'column' ], true ) ) {
			$schema = ContainerSchemaRepository::get( $el_type );
			return is_array( $schema ) ? (array) ( $schema['controls'] ?? [] ) : [];
		}
		return [];
	}

	/**
	 * @param array<string, array<string, mixed>> $controls
	 */
	private static function control_is_known( string $key, array $controls ): bool {
		if ( isset( $controls[ $key ] ) ) {
			return true;
		}
		foreach ( [ '_widescreen', '_laptop', '_tablet_extra', '_tablet', '_mobile_extra', '_mobile' ] as $suffix ) {
			if ( ! str_ends_with( $key, $suffix ) ) {
				continue;
			}
			$base = substr( $key, 0, -strlen( $suffix ) );
			if ( isset( $controls[ $base ] ) ) {
				return true;
			}
		}
		return false;
	}

	private static function sanitize_setting_value( mixed $value ): mixed {
		if ( is_string( $value ) && str_starts_with( $value, 'data:' ) && strlen( $value ) > 256 ) {
			return substr( $value, 0, 256 );
		}
		if ( ! is_array( $value ) ) {
			return $value;
		}
		$out = [];
		foreach ( $value as $key => $item ) {
			$normalized = strtolower( (string) $key );
			if ( in_array( $normalized, [ 'data', 'bytes', 'binary', 'blob', 'file_bytes', 'file_contents' ], true ) ) {
				continue;
			}
			$out[ $key ] = self::sanitize_setting_value( $item );
		}
		return $out;
	}

	/**
	 * @param array<string, mixed> $element
	 * @return list<array{__style_id:string, css:string}>
	 */
	private static function round_trip_styles( array $element ): array {
		$out    = [];
		$styles = $element['styles'] ?? [];
		if ( is_array( $styles ) ) {
			foreach ( $styles as $style_id => $style ) {
				if ( is_string( $style ) ) {
					$out[] = [
						'__style_id' => is_string( $style_id ) ? $style_id : (string) count( $out ),
						'css'        => self::bound_css( $style ),
					];
					continue;
				}
				if ( ! is_array( $style ) ) {
					continue;
				}
				$id  = (string) ( $style['__style_id'] ?? $style['id'] ?? $style_id );
				$css = $style['css'] ?? $style['value'] ?? '';
				if ( ! is_string( $css ) ) {
					continue;
				}
				$out[] = [
					'__style_id' => '' !== $id ? $id : (string) count( $out ),
					'css'        => self::bound_css( $css ),
				];
			}
		}
		$settings = is_array( $element['settings'] ?? null ) ? $element['settings'] : [];
		foreach ( [ 'custom_css', '_custom_css' ] as $css_key ) {
			if ( ! isset( $settings[ $css_key ] ) || ! is_string( $settings[ $css_key ] ) || '' === $settings[ $css_key ] ) {
				continue;
			}
			$out[] = [
				'__style_id' => $css_key . ':' . (string) ( $element['id'] ?? '' ),
				'css'        => self::bound_css( $settings[ $css_key ] ),
			];
		}
		return $out;
	}

	private static function bound_css( string $css ): string {
		if ( strlen( $css ) <= self::STYLE_CSS_MAX ) {
			return $css;
		}
		return substr( $css, 0, self::STYLE_CSS_MAX );
	}

	/**
	 * Fingerprint of the decoded Elementor tree.
	 *
	 * Taken from the decoded tree rather than the raw `_elementor_data` string so
	 * a re-save that only reorders JSON keys or changes escaping does not read as
	 * a content change. It describes the document, not the response, so summary
	 * and full mode report the same value and a caller can compare across modes.
	 *
	 * @param array<int, mixed> $tree
	 */
	private static function tree_hash( array $tree ): string {
		return hash(
			'sha256',
			(string) wp_json_encode(
				self::canonicalize_tree( $tree ),
				JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
			)
		);
	}

	/**
	 * Sort object keys recursively while preserving list order.
	 *
	 * Elementor element order is semantic, so lists must stay untouched. Object
	 * key order is serialization noise and must not invalidate a known hash.
	 */
	private static function canonicalize_tree( mixed $value ): mixed {
		if ( ! is_array( $value ) ) {
			return $value;
		}

		if ( ! array_is_list( $value ) ) {
			ksort( $value );
		}

		foreach ( $value as $key => $item ) {
			$value[ $key ] = self::canonicalize_tree( $item );
		}

		return $value;
	}
}
