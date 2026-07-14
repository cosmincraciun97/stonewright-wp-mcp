<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\Abilities\ElementorV3;

use Stonewright\WpMcp\Abilities\AbilityKernel;
use Stonewright\WpMcp\Elementor\Schema\WidgetSchemaRepository;
use Stonewright\WpMcp\Security\Permissions;

/**
 * Unified, paginated access to live Elementor V3 widget schemas.
 *
 * @stonewright-status stable
 */
final class ElementorSchema extends AbilityKernel {

	public function name(): string {
		return 'stonewright/elementor-schema';
	}

	public function label(): string {
		return __( 'Inspect live Elementor widget schemas', 'stonewright' );
	}

	public function description(): string {
		return __( 'Lists and searches live Elementor widgets or returns compact, selected-control, and paginated full schemas. Use this before every widget write; unknown controls are rejected.', 'stonewright' );
	}

	public function category(): string {
		return 'elementor';
	}

	public function input_schema(): array {
		return [
			'type'                 => 'object',
			'additionalProperties' => false,
			'properties'           => [
				'mode'        => [ 'type' => 'string', 'enum' => [ 'list', 'summary', 'control', 'full', 'search' ], 'default' => 'list' ],
				'widget_type' => [ 'type' => 'string' ],
				'control'     => [ 'type' => 'string' ],
				'query'       => [ 'type' => 'string' ],
				'page'        => [ 'type' => 'integer', 'minimum' => 1, 'default' => 1 ],
				'per_page'    => [ 'type' => 'integer', 'minimum' => 1, 'maximum' => 100, 'default' => 18 ],
				'refresh'     => [ 'type' => 'boolean', 'default' => false ],
			],
		];
	}

	public function output_schema(): array {
		return [
			'type'       => 'object',
			'properties' => [
				'mode'                => [ 'type' => 'string' ],
				'items'               => [ 'type' => 'array' ],
				'total'               => [ 'type' => 'integer' ],
				'widget_type'         => [ 'type' => 'string' ],
				'title'               => [ 'type' => 'string' ],
				'source_plugin'       => [ 'type' => 'string' ],
				'source_version'      => [ 'type' => 'string' ],
				'schema_hash'         => [ 'type' => 'string' ],
				'runtime_fingerprint' => [ 'type' => 'string' ],
				'controls'            => [ 'type' => 'object' ],
				'control'             => [ 'type' => 'object' ],
				'sections'            => [ 'type' => 'array' ],
				'provenance'          => [ 'type' => 'object' ],
				'page'                => [ 'type' => 'integer' ],
				'per_page'            => [ 'type' => 'integer' ],
				'required_for_render' => [ 'type' => 'array' ],
			],
		];
	}

	public function permission_callback( array $args ): bool|\WP_Error {
		return Permissions::edit_posts();
	}

	public function execute( array $args ): array|\WP_Error {
		$mode     = (string) ( $args['mode'] ?? 'list' );
		$page     = max( 1, (int) ( $args['page'] ?? 1 ) );
		$per_page = max( 1, min( 100, (int) ( $args['per_page'] ?? 18 ) ) );

		if ( in_array( $mode, [ 'list', 'search' ], true ) ) {
			$query  = 'search' === $mode ? (string) ( $args['query'] ?? '' ) : '';
			$result = WidgetSchemaRepository::list( $query, $page, $per_page );
			return [
				'mode'                => $mode,
				'items'               => $result['items'],
				'total'               => $result['total'],
				'page'                => $page,
				'per_page'            => $per_page,
				'runtime_fingerprint' => $result['fingerprint'],
			];
		}

		$widget_type = (string) ( $args['widget_type'] ?? '' );
		$schema      = WidgetSchemaRepository::get( $widget_type, ! empty( $args['refresh'] ) );
		if ( $schema instanceof \WP_Error ) {
			return $schema;
		}
		$base = [
			'mode'                => $mode,
			'widget_type'         => $widget_type,
			'title'               => (string) $schema['title'],
			'source_plugin'       => (string) $schema['source_plugin'],
			'source_version'      => (string) $schema['source_version'],
			'schema_hash'         => (string) $schema['schema_hash'],
			'runtime_fingerprint' => (string) $schema['runtime_fingerprint'],
			'provenance'          => (array) $schema['provenance'],
		];

		if ( 'control' === $mode ) {
			$key      = (string) ( $args['control'] ?? '' );
			$controls = (array) $schema['controls'];
			if ( '' === $key || ! isset( $controls[ $key ] ) ) {
				return $this->error( 'elementor_control_unknown', __( 'The requested control is not present in the live widget schema.', 'stonewright' ), [ 'status' => 404, 'widget_type' => $widget_type, 'control' => $key ] );
			}
			return $base + [ 'control' => $controls[ $key ] ];
		}

		if ( 'full' === $mode ) {
			$sections = array_values( (array) $schema['sections'] );
			$slice    = array_slice( $sections, ( $page - 1 ) * $per_page, $per_page );
			$keys     = [];
			foreach ( $slice as $section ) {
				$keys = array_merge( $keys, (array) ( $section['controls'] ?? [] ) );
			}
			return $base + [
				'sections' => $slice,
				'controls' => array_intersect_key( (array) $schema['controls'], array_fill_keys( $keys, true ) ),
				'total'    => count( $sections ),
				'page'     => $page,
				'per_page' => $per_page,
			];
		}

		$controls = [];
		foreach ( array_slice( (array) $schema['controls'], ( $page - 1 ) * $per_page, $per_page, true ) as $key => $control ) {
			$controls[ $key ] = [
				'key'        => (string) $key,
				'type'       => (string) ( $control['type'] ?? '' ),
				'label'      => (string) ( $control['label'] ?? '' ),
				'section'    => (string) ( $control['section'] ?? '' ),
				'responsive' => (bool) ( $control['responsive'] ?? false ),
			];
		}
		return $base + [
			'controls'            => $controls,
			'total'               => count( (array) $schema['controls'] ),
			'page'                => $page,
			'per_page'            => $per_page,
			'required_for_render' => (array) $schema['required_for_render'],
		];
	}
}
