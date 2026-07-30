<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\Abilities\Knowledge;

use Stonewright\WpMcp\Abilities\AbilityKernel;
use Stonewright\WpMcp\Knowledge\ElementorKnowledgeBase;

/**
 * Explain Elementor editor, Theme Builder, or developer concepts from the
 * harvested knowledge base.
 *
 * @stonewright-status stable
 */
final class ExplainEditor extends AbilityKernel {

	public function name(): string {
		return 'stonewright/elementor-explain-editor';
	}

	public function label(): string {
		return __( 'Explain Elementor editor topic', 'stonewright' );
	}

	public function description(): string {
		return __(
			'USE THIS WHEN an Elementor implementation depends on editor V3/V4 behavior, Theme Builder display rules, custom widgets, or developer API details. Returns ranked knowledge snippets and refresh guidance.',
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
			'required'             => [ 'topic' ],
			'properties'           => [
				'topic' => [ 'type' => 'string', 'minLength' => 1 ],
				'area'  => [
					'type' => 'string',
					'enum' => [ 'editor', 'theme', 'developer', 'custom-widget', 'help-root' ],
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
		$topic = isset( $args['topic'] ) && is_string( $args['topic'] ) ? trim( $args['topic'] ) : '';
		if ( '' === $topic ) {
			return $this->error( 'missing_topic', __( 'A non-empty topic is required.', 'stonewright' ), [ 'status' => 400 ] );
		}

		return $this->ok(
			ElementorKnowledgeBase::explain_editor(
				$topic,
				isset( $args['area'] ) && is_string( $args['area'] ) ? $args['area'] : null,
				isset( $args['limit'] ) ? (int) $args['limit'] : 5
			)
		);
	}
}
