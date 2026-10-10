<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\Abilities\Knowledge;

use Stonewright\WpMcp\Abilities\AbilityKernel;
use Stonewright\WpMcp\Knowledge\ElementorKnowledgeBase;

/**
 * Describe one Elementor widget using manifest controls plus harvested docs.
 *
 * @stonewright-status stable
 */
final class DescribeWidget extends AbilityKernel {

	public function name(): string {
		return 'stonewright/elementor-describe-widget';
	}

	public function label(): string {
		return __( 'Describe Elementor widget', 'stonewright' );
	}

	public function description(): string {
		return __(
			'USE THIS WHEN deciding how to render a design pattern as a real Elementor widget. Returns the widget manifest entry, harvested documentation from the private Elementor knowledge store on this site, stale flags, and refresh guidance. The store is empty until the first stonewright/elementor-knowledge-refresh; the documents then stay empty and a hint says so. Prefer this over simulating widgets with headings/buttons.',
			'stonewright'
		);
	}

	public function category(): string {
		return 'knowledge';
	}

	public function input_schema(): array {
		return [
			'type'                 => 'object',
			'additionalProperties' => false,
			'required'             => [ 'widget' ],
			'properties'           => [
				'widget' => [ 'type' => 'string', 'minLength' => 1 ],
			],
		];
	}

	public function output_schema(): array {
		return [
			'type'       => 'object',
			'required'   => [ 'ok', 'widget', 'manifest', 'documents' ],
			'properties' => [
				'ok'        => [ 'type' => 'boolean' ],
				'widget'    => [ 'type' => 'string' ],
				'manifest'  => [ 'type' => 'object' ],
				'documents' => [ 'type' => 'array' ],
				'stale'     => [ 'type' => 'boolean' ],
				'max_age_days' => [ 'type' => 'integer' ],
				'refresh_ability' => [ 'type' => 'string' ],
				'hint'      => [ 'type' => 'string', 'description' => 'Present when the knowledge store is empty; names the refresh ability.' ],
			],
		];
	}

	public function execute( array $args ): array|\WP_Error {
		$widget = isset( $args['widget'] ) && is_string( $args['widget'] ) ? trim( $args['widget'] ) : '';
		if ( '' === $widget ) {
			return $this->error( 'missing_widget', __( 'A widget slug is required.', 'stonewright' ), [ 'status' => 400 ] );
		}

		return $this->ok( ElementorKnowledgeBase::describe_widget( $widget ) );
	}
}
