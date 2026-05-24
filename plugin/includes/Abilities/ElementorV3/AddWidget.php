<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\Abilities\ElementorV3;

use Stonewright\WpMcp\Abilities\AbilityKernel;
use Stonewright\WpMcp\Security\Backup;
use Stonewright\WpMcp\Security\Permissions;
use Stonewright\WpMcp\Support\ElementorData;

/**
 * Contract decision: keep output_schema aligned to the handler response shape.
 *
 * @stonewright-status stable
 */
final class AddWidget extends AbilityKernel {

	public function name(): string {
		return 'stonewright/elementor-v3-add-widget';
	}

	public function label(): string {
		return __( 'Add raw Elementor widget', 'stonewright' );
	}

	public function description(): string {
		return __( 'Adds a raw Elementor widget by writing the literal Elementor settings JSON for any widget_type. USE THIS WHEN the dedicated per-widget renderer doesn\'t cover a widget or a setting structure that DesignSpec can\'t express (custom widgets, third-party Pro widgets, experimental Elementor V3 controls). Bypasses DesignSpec validation entirely — caller is responsible for emitting a valid Elementor settings dict. Snapshots before write.', 'stonewright' );
	}

	public function category(): string {
		return 'elementor';
	}

	public function input_schema(): array {
		return [
			'type'                 => 'object',
			'additionalProperties' => false,
			'properties'           => [
				'post_id'     => [ 'type' => 'integer', 'minimum' => 1 ],
				'parent_id'   => [ 'type' => 'string' ],
				'widget_type' => [ 'type' => 'string' ],
				'settings'    => [ 'type' => 'object' ],
				'position'    => [ 'type' => 'integer' ],
				'allow_html_widget' => [
					'type'        => 'boolean',
					'description' => 'Must be true only when the user explicitly asked for widget_type=html. Native Elementor widgets must be used first.',
					'default'     => false,
				],
			],
			'required'             => [ 'post_id', 'parent_id', 'widget_type' ],
		];
	}

	public function output_schema(): array {
		return [
			'type'       => 'object',
			'properties' => [
				'post_id'     => [ 'type' => 'integer' ],
				'element_id'  => [ 'type' => 'string' ],
				'snapshot_id' => [ 'type' => 'string' ],
			],
		];
	}

	public function permission_callback( array $args ): bool|\WP_Error {
		$id = (int) ( $args['post_id'] ?? 0 );
		return Permissions::edit_post( $id );
	}

	public function execute( array $args ): array|\WP_Error {
		return $this->audit(
			$args,
			function ( array $args ) {
				$widget_type = isset( $args['widget_type'] ) ? (string) $args['widget_type'] : '';
				if ( 'html' === $widget_type && empty( $args['allow_html_widget'] ) ) {
					return new \WP_Error(
						'html_widget_requires_explicit_approval',
						__( 'Elementor HTML widgets are disabled by default. Use native Elementor widgets first, or pass allow_html_widget=true only when the user explicitly requested HTML.', 'stonewright' ),
						[ 'status' => 400 ]
					);
				}

				$post_id = (int) $args['post_id'];
				if ( ! get_post( $post_id ) ) {
					return $this->error( 'not_found', __( 'Post not found.', 'stonewright' ) );
				}

				$snapshot_id = Backup::snapshot_post( $post_id );
				$tree        = ElementorData::read( $post_id );
				$parent_path = ElementorData::find_path( $tree, (string) $args['parent_id'] );
				if ( null === $parent_path ) {
					return $this->error( 'parent_not_found', __( 'Parent element not found.', 'stonewright' ) );
				}

				$widget = [
					'id'         => ElementorData::generate_id(),
					'elType'     => 'widget',
					'widgetType' => $widget_type,
					'settings'   => isset( $args['settings'] ) && is_array( $args['settings'] ) ? $args['settings'] : new \stdClass(),
					'elements'   => [],
				];

				$position = isset( $args['position'] ) ? (int) $args['position'] : PHP_INT_MAX;
				$new_tree = ElementorData::insert( $tree, $parent_path, $position, $widget );

				if ( ! ElementorData::write( $post_id, $new_tree ) ) {
					return $this->error( 'write_failed', __( 'Could not save Elementor data.', 'stonewright' ) );
				}

				return [
					'post_id'     => $post_id,
					'element_id'  => $widget['id'],
					'snapshot_id' => $snapshot_id,
				];
			}
		);
	}
}
