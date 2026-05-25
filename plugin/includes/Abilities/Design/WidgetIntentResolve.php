<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\Abilities\Design;

use Stonewright\WpMcp\Abilities\AbilityKernel;
use Stonewright\WpMcp\Design\WidgetIntentResolver;
use Stonewright\WpMcp\Elementor\WidgetRegistry\WidgetRecommender;
use Stonewright\WpMcp\Security\Permissions;

/**
 * Phase D.4 — `stonewright/widget-intent-resolve`.
 *
 * Smart-detection entry point: the LLM passes a design intent name
 * (`'nav'`, `'countdown'`, `'social-row'`, `'logo+nav'`, …) and gets
 * back the right Stonewright widget choice + a sensible settings
 * template + the upstream steps it must run first.
 *
 * This is how Stonewright surpasses competitor MCPs on "which widget
 * should I use?" — instead of dumping the full catalog and asking the
 * model to pick, the model can ask the catalog to pick.
 *
 * @stonewright-status stable
 */
final class WidgetIntentResolve extends AbilityKernel {

	public function name(): string {
		return 'stonewright/widget-intent-resolve';
	}

	public function label(): string {
		return __( 'Resolve widget intent', 'stonewright' );
	}

	public function description(): string {
		$names = WidgetIntentResolver::intent_names();
		return sprintf(
			/* translators: %s — comma-separated list of recognised intent names. */
			__(
				'Maps a high-level design intent to the right Elementor widget choice + a settings template + the prerequisite steps to run first. USE THIS WHEN you want to ask Stonewright "what widget should I use for a navigation row / a footer column / a countdown / a social icon row?" instead of guessing or hacking buttons. Recognised intents: %s. Pass `intent` plus optional `context` (free-form hints). Unknown intents return an empty record — caller should fall back to the universal `stonewright/elementor-v3-add-widget` escape hatch.',
				'stonewright'
			),
			implode( ', ', $names )
		);
	}

	public function category(): string {
		return 'design';
	}

	public function input_schema(): array {
		return [
			'type'                 => 'object',
			'additionalProperties' => false,
			'properties'           => [
				'intent'  => [
					'type'        => 'string',
					'description' => 'The design intent name. See description() for the full enumeration; common values: nav, countdown, social-row, icon-bullet-list, footer-link-column, logo+nav, hero-cta-pair, header-template, footer-template, cta-button, image-with-caption, tabs, accordion, testimonial, pricing-table.',
				],
				'prompt'  => [
					'type'        => 'string',
					'description' => 'Optional plain-language build request. When intent is omitted, Stonewright detects the widget intent from this prompt.',
				],
				'design_node'  => [
					'type'        => 'object',
					'description' => 'Optional structured design node. When intent is omitted, Stonewright detects the widget intent from this node signature.',
				],
				'context' => [
					'type'        => 'object',
					'description' => 'Optional free-form hints (e.g. { brand_color: "#FF0", menu_term_id: 5001, count: 4 }) the resolver may use in future versions to refine the template. Currently passed through verbatim.',
				],
			],
		];
	}

	public function output_schema(): array {
		return [
			'type'       => 'object',
			'properties' => [
				'intent'            => [ 'type' => 'string' ],
				'matched'           => [ 'type' => 'boolean' ],
				'source'            => [ 'type' => 'string' ],
				'widget'            => [ 'type' => [ 'string', 'null' ] ],
				'widgets'           => [
					'type'  => 'array',
					'items' => [ 'type' => 'string' ],
				],
				'settings_template' => [ 'type' => 'object' ],
				'required_steps'    => [
					'type'  => 'array',
					'items' => [ 'type' => 'string' ],
				],
				'description'       => [ 'type' => [ 'string', 'null' ] ],
				'recommendations'   => [
					'type'  => 'array',
					'items' => [
						'type'                 => 'object',
						'additionalProperties' => true,
						'properties'           => [
							'slug'    => [ 'type' => 'string' ],
							'title'   => [ 'type' => 'string' ],
							'source'  => [ 'type' => 'string' ],
							'ability' => [ 'type' => 'string' ],
							'score'   => [ 'type' => 'integer' ],
						],
					],
				],
			],
			'required'   => [ 'intent', 'matched' ],
		];
	}

	public function permission_callback( array $args ): bool|\WP_Error {
		return Permissions::read();
	}

	public function execute( array $args ): array|\WP_Error {
		$intent = isset( $args['intent'] ) && is_string( $args['intent'] ) ? $args['intent'] : '';
		$source = 'intent';

		if ( '' === $intent && isset( $args['design_node'] ) && is_array( $args['design_node'] ) ) {
			$intent = WidgetIntentResolver::detect_from_design_tree( $args['design_node'] ) ?? '';
			$source = 'design_node';
		}

		if ( '' === $intent && isset( $args['prompt'] ) && is_string( $args['prompt'] ) ) {
			$intent = WidgetIntentResolver::detect_from_prompt( $args['prompt'] ) ?? '';
			$source = 'prompt';
		}

		if ( $intent === '' ) {
			return new \WP_Error(
				'stonewright_invalid_intent',
				__( 'intent, prompt, or design_node is required and must resolve to a known design intent.', 'stonewright' ),
				[ 'status' => 400 ]
			);
		}

		$entry = WidgetIntentResolver::resolve( $intent );

		// Recast `settings_template` to an object so the schema validates
		// as `object` even when the resolved template is an empty array.
		$template = $entry['settings_template'] ?? [];
		if ( is_array( $template ) && empty( $template ) ) {
			$template = new \stdClass();
		}

		$prompt = isset( $args['prompt'] ) && is_string( $args['prompt'] ) ? $args['prompt'] : $intent;
		$context = isset( $args['context'] ) && is_array( $args['context'] ) ? $args['context'] : [];
		$recommendations = WidgetRecommender::recommend( $prompt, 5, $context );

		return [
			'intent'            => $intent,
			'matched'           => ! empty( $entry ),
			'source'            => $source,
			'widget'            => $entry['widget']  ?? null,
			'widgets'           => array_values( (array) ( $entry['widgets'] ?? [] ) ),
			'settings_template' => $template,
			'required_steps'    => array_values( (array) ( $entry['required_steps'] ?? [] ) ),
			'description'       => $entry['description'] ?? null,
			'recommendations'   => $recommendations,
		];
	}
}
