<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\Abilities\ElementorV3;

use Stonewright\WpMcp\Abilities\AbilityKernel;
use Stonewright\WpMcp\Elementor\Schema\PlainLlmSchemaConverter;
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
		return __( 'Returns all registered Elementor V3 widget types including third-party widgets. Summary mode is the default; request responseMode=full for provenance metadata.', 'stonewright' );
	}

	public function category(): string {
		return 'elementor';
	}

	public function input_schema(): array {
		return [
			'type'                 => 'object',
			'additionalProperties' => false,
			'properties'           => [
				'responseMode' => [
					'type'        => 'string',
					'enum'        => [ 'summary', 'full' ],
					'default'     => 'summary',
					'description' => 'Use summary for widget type, title, and category description only; use full for provenance metadata such as schema_hash and source_plugin.',
				],
			],
		];
	}

	public function output_schema(): array {
		return [
			'type'       => 'object',
			'properties' => [
				'response_mode'       => [ 'type' => 'string' ],
				'widgets'             => [ 'type' => 'array' ],
				'runtime_fingerprint' => [ 'type' => 'string' ],
				'full_mode_hint'      => [ 'type' => 'string' ],
			],
		];
	}

	public function permission_callback( array $args ): bool|\WP_Error {
		return Permissions::edit_posts();
	}

	public function execute( array $args ): array|\WP_Error {
		$response_mode = (string) ( $args['responseMode'] ?? 'summary' );
		if ( ! in_array( $response_mode, [ 'summary', 'full' ], true ) ) {
			$response_mode = 'summary';
		}

		$result  = WidgetSchemaRepository::list( '', 1, 100 );
		$widgets = array_map(
			static fn( array $widget ): array => [
				'name'           => (string) $widget['widget_type'],
				'title'          => (string) $widget['title'],
				'categories'     => (array) $widget['categories'],
				'source_plugin'  => (string) $widget['source_plugin'],
				'source_version' => (string) $widget['source_version'],
				'schema_hash'    => (string) $widget['schema_hash'],
				'pro_required'   => (bool) ( $widget['pro_required'] ?? false ),
			],
			$result['items']
		);

		return [
			'response_mode'       => $response_mode,
			'widgets'             => PlainLlmSchemaConverter::convert_widget_list(
				$widgets,
				[ 'mode' => $response_mode ]
			),
			'runtime_fingerprint' => $result['fingerprint'],
			'full_mode_hint'      => 'Call with responseMode=full only when provenance metadata such as schema_hash or source_plugin is required.',
		];
	}
}
