<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\Abilities\Knowledge;

use Stonewright\WpMcp\Abilities\AbilityKernel;
use Stonewright\WpMcp\Knowledge\ElementorKnowledgeBase;

/**
 * Search the harvested Elementor knowledge base before choosing widgets or
 * renderer settings.
 *
 * @stonewright-status stable
 */
final class KnowledgeSearch extends AbilityKernel {

	public function name(): string {
		return 'stonewright/elementor-knowledge-search';
	}

	public function label(): string {
		return __( 'Search Elementor knowledge', 'stonewright' );
	}

	public function description(): string {
		return __(
			'USE THIS WHEN implementing or debugging an Elementor build and you need current Stonewright knowledge about widgets, editor behavior, Theme Builder, or developer APIs. Returns ranked harvested docs plus stale flags; call stonewright/elementor-knowledge-refresh when results are stale or missing.',
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
			'required'             => [ 'query' ],
			'properties'           => [
				'query' => [ 'type' => 'string', 'minLength' => 1 ],
				'area'  => [
					'type' => 'string',
					'enum' => [ 'widgets', 'widget', 'editor', 'theme', 'developer', 'custom-widget', 'help-root' ],
				],
				'limit' => [ 'type' => 'integer', 'minimum' => 1, 'maximum' => 20 ],
			],
		];
	}

	public function output_schema(): array {
		return [
			'type'       => 'object',
			'required'   => [ 'ok', 'query', 'results' ],
			'properties' => [
				'ok'      => [ 'type' => 'boolean' ],
				'query'   => [ 'type' => 'string' ],
				'area'    => [ 'type' => [ 'string', 'null' ] ],
				'limit'   => [ 'type' => 'integer' ],
				'max_age_days' => [ 'type' => 'integer' ],
				'refresh_ability' => [ 'type' => 'string' ],
				'results' => [ 'type' => 'array' ],
			],
		];
	}

	public function execute( array $args ): array|\WP_Error {
		$query = isset( $args['query'] ) && is_string( $args['query'] ) ? trim( $args['query'] ) : '';
		if ( '' === $query ) {
			return $this->error( 'missing_query', __( 'A non-empty query is required.', 'stonewright' ), [ 'status' => 400 ] );
		}

		$result = ElementorKnowledgeBase::search(
			$query,
			isset( $args['area'] ) && is_string( $args['area'] ) ? $args['area'] : null,
			isset( $args['limit'] ) ? (int) $args['limit'] : 5
		);

		return $this->ok( $result );
	}
}
