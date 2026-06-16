<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\Abilities\ElementorV3;

use Stonewright\WpMcp\Abilities\AbilityKernel;
use Stonewright\WpMcp\Security\Permissions;
use Stonewright\WpMcp\Support\ElementorData;

/**
 * Contract decision: keep output_schema aligned to the handler response shape.
 *
 * @stonewright-status stable
 */
final class GetPageStructure extends AbilityKernel {

	public function name(): string {
		return 'stonewright/elementor-v3-get-page-structure';
	}

	public function label(): string {
		return __( 'Get Elementor page structure', 'stonewright' );
	}

	public function description(): string {
		return __( 'Returns a compact Elementor V3 page outline by default, or the full element tree when responseMode=full.', 'stonewright' );
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
		$count = count( ElementorData::flatten( $tree ) );
		if ( 'full' === (string) ( $args['responseMode'] ?? 'summary' ) ) {
			return [
				'post_id'       => $post_id,
				'active'        => ElementorData::is_active( $post_id ),
				'response_mode' => 'full',
				'tree'          => $tree,
				'count'         => $count,
			];
		}

		$max_elements = min( 500, max( 1, (int) ( $args['maxElements'] ?? 200 ) ) );
		$outline      = self::outline( $tree, $max_elements );

		return [
			'post_id'        => $post_id,
			'active'         => ElementorData::is_active( $post_id ),
			'response_mode'  => 'summary',
			'count'          => $count,
			'returned_count' => count( $outline ),
			'truncated'      => $count > count( $outline ),
			'tree_omitted'   => true,
			'outline'        => $outline,
			'full_mode_hint' => 'Call with responseMode=full only when raw Elementor JSON is required for the next edit.',
		];
	}

	/**
	 * @param array<int, array<string, mixed>> $tree
	 * @return list<array<string, mixed>>
	 */
	private static function outline( array $tree, int $max_elements ): array {
		$out = [];
		self::walk_outline( $tree, [], null, $out, $max_elements );
		return $out;
	}

	/**
	 * @param array<int, array<string, mixed>> $elements
	 * @param list<int>                       $path
	 * @param list<array<string, mixed>>      $out
	 */
	private static function walk_outline( array $elements, array $path, ?string $parent_id, array &$out, int $max_elements ): void {
		foreach ( $elements as $index => $element ) {
			if ( count( $out ) >= $max_elements ) {
				return;
			}

			$current_path = array_merge( $path, [ (int) $index ] );
			$id           = (string) ( $element['id'] ?? '' );
			$settings     = isset( $element['settings'] ) && is_array( $element['settings'] ) ? $element['settings'] : [];
			$children     = isset( $element['elements'] ) && is_array( $element['elements'] ) ? $element['elements'] : [];

			$out[] = [
				'id'            => $id,
				'parent_id'     => $parent_id,
				'path'          => implode( '.', array_map( 'strval', $current_path ) ),
				'depth'         => count( $current_path ) - 1,
				'elType'        => (string) ( $element['elType'] ?? '' ),
				'widgetType'    => (string) ( $element['widgetType'] ?? '' ),
				'label'         => self::label_from_settings( $settings ),
				'settings_keys' => array_values( array_slice( array_map( 'strval', array_keys( $settings ) ), 0, 30 ) ),
				'child_count'   => count( $children ),
			];

			if ( [] !== $children ) {
				self::walk_outline( $children, $current_path, '' !== $id ? $id : null, $out, $max_elements );
			}
		}
	}

	/**
	 * @param array<string, mixed> $settings
	 */
	private static function label_from_settings( array $settings ): string {
		foreach ( [ '_title', 'title', 'header_title', 'text', 'editor' ] as $key ) {
			if ( ! isset( $settings[ $key ] ) || ! is_scalar( $settings[ $key ] ) ) {
				continue;
			}
			$raw_label = (string) $settings[ $key ];
			$label     = function_exists( 'wp_strip_all_tags' )
				? trim( wp_strip_all_tags( $raw_label ) )
				: trim( strip_tags( $raw_label ) );
			if ( '' === $label ) {
				continue;
			}
			return strlen( $label ) > 80 ? substr( $label, 0, 77 ) . '...' : $label;
		}
		return '';
	}
}
