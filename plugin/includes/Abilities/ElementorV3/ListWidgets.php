<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\Abilities\ElementorV3;

use Stonewright\WpMcp\Abilities\AbilityKernel;
use Stonewright\WpMcp\Elementor\Schema\WidgetSchemaRepository;
use Stonewright\WpMcp\Security\Permissions;

/**
 * Contract decision: keep output_schema aligned to the handler response shape.
 *
 * @stonewright-status stable
 */
final class ListWidgets extends AbilityKernel {

	public function name(): string {
		return 'stonewright/elementor-v3-list-widgets';
	}

	public function label(): string {
		return __( 'List Elementor widgets', 'stonewright' );
	}

	public function description(): string {
		return __( 'Returns all registered Elementor V3 widget types including third-party widgets.', 'stonewright' );
	}

	public function category(): string {
		return 'elementor';
	}

	public function output_schema(): array {
		return [
			'type'       => 'object',
			'properties' => [
				'widgets' => [ 'type' => 'array' ],
				'runtime_fingerprint' => [ 'type' => 'string' ],
			],
		];
	}

	public function permission_callback( array $args ): bool|\WP_Error {
		return Permissions::edit_posts();
	}

	public function execute( array $args ): array|\WP_Error {
		$result  = WidgetSchemaRepository::list( '', 1, 100 );
		$widgets = array_map(
			static fn( array $widget ): array => [
				'name'           => (string) $widget['widget_type'],
				'title'          => (string) $widget['title'],
				'categories'     => (array) $widget['categories'],
				'source_plugin'  => (string) $widget['source_plugin'],
				'source_version' => (string) $widget['source_version'],
				'schema_hash'    => (string) $widget['schema_hash'],
			],
			$result['items']
		);

		return [ 'widgets' => $widgets, 'runtime_fingerprint' => $result['fingerprint'] ];
	}
}
