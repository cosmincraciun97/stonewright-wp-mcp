<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\Abilities\Knowledge;

use Stonewright\WpMcp\Abilities\AbilityKernel;
use Stonewright\WpMcp\Elementor\WidgetRegistry\WidgetImplementationGuide as Guide;
use Stonewright\WpMcp\Security\Permissions;

/**
 * Produces an implementation checklist for selected Elementor widgets.
 *
 * @stonewright-status stable
 */
final class WidgetImplementationGuide extends AbilityKernel {

	public function name(): string {
		return 'stonewright/elementor-widget-implementation-guide';
	}

	public function label(): string {
		return __( 'Guide Elementor widget implementation', 'stonewright' );
	}

	public function description(): string {
		return __( 'MUST be used after widget intent resolution and before Elementor writes. Returns the widget controls, Content/Style/Advanced checklist, and official documentation research signal needed to configure widgets accurately.', 'stonewright' );
	}

	public function category(): string {
		return 'knowledge';
	}

	public function input_schema(): array {
		return [
			'type'                 => 'object',
			'additionalProperties' => false,
			'required'             => [ 'task', 'candidate_widgets' ],
			'properties'           => [
				'task'              => [ 'type' => 'string', 'minLength' => 1 ],
				'candidate_widgets' => [
					'type'  => 'array',
					'items' => [ 'type' => 'string' ],
				],
				'design_context'    => [
					'type'        => 'string',
					'default'     => '',
					'description' => 'A short summary of the design, image, page section, or interaction requirements.',
				],
			],
		];
	}

	public function output_schema(): array {
		return [
			'type'       => 'object',
			'required'   => [ 'ok', 'recommendations', 'global_required_steps' ],
			'properties' => [
				'ok'                    => [ 'type' => 'boolean' ],
				'task'                  => [ 'type' => 'string' ],
				'design_context'        => [ 'type' => 'string' ],
				'recommendations'       => [ 'type' => 'array' ],
				'global_required_steps' => [ 'type' => 'array' ],
			],
		];
	}

	public function permission_callback( array $args ): bool|\WP_Error {
		return Permissions::read();
	}

	public function execute( array $args ): array|\WP_Error {
		$task = isset( $args['task'] ) && is_string( $args['task'] ) ? trim( $args['task'] ) : '';
		if ( '' === $task ) {
			return $this->error( 'missing_task', __( 'A non-empty task is required.', 'stonewright' ), [ 'status' => 400 ] );
		}

		$candidate_widgets = isset( $args['candidate_widgets'] ) && is_array( $args['candidate_widgets'] ) ? $args['candidate_widgets'] : [];
		$design_context    = isset( $args['design_context'] ) && is_string( $args['design_context'] ) ? $args['design_context'] : '';

		return Guide::build( $task, array_values( array_filter( $candidate_widgets, 'is_string' ) ), $design_context );
	}
}
