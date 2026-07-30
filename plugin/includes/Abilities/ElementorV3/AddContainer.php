<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\Abilities\ElementorV3;

use Stonewright\WpMcp\Abilities\AbilityKernel;
use Stonewright\WpMcp\Elementor\ContainerSettings;
use Stonewright\WpMcp\Elementor\Schema\SettingsValidator;
use Stonewright\WpMcp\Security\Backup;
use Stonewright\WpMcp\Security\Permissions;
use Stonewright\WpMcp\Support\ElementorData;

/**
 * Contract decision: keep output_schema aligned to the handler response shape.
 *
 * @stonewright-status stable
 */
final class AddContainer extends AbilityKernel {

	public function name(): string {
		return 'stonewright/elementor-v3-add-container';
	}

	public function label(): string {
		return __( 'Add Elementor container', 'stonewright' );
	}

	public function description(): string {
		return __( 'Appends a new flex or grid container to an Elementor page. Snapshots before write.', 'stonewright' );
	}

	public function category(): string {
		return 'elementor';
	}

	public function input_schema(): array {
		return [
			'type'                 => 'object',
			'additionalProperties' => false,
			'properties'           => [
				'post_id'        => [ 'type' => 'integer', 'minimum' => 1 ],
				'parent_id'      => [ 'type' => 'string' ],
				'position'       => [ 'type' => 'integer' ],
				'el_type'        => [ 'type' => 'string', 'enum' => [ 'container' ], 'default' => 'container' ],
				'settings'       => [ 'type' => 'object' ],
			],
			'required'             => [ 'post_id' ],
		];
	}

	public function output_schema(): array {
		return [
			'type'       => 'object',
			'properties' => [
				'post_id'     => [ 'type' => 'integer' ],
				'element_id'  => [ 'type' => 'string' ],
				'snapshot_id' => [ 'type' => 'string' ],
				'path'        => [ 'type' => 'array' ],
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
				$post_id = (int) $args['post_id'];
				if ( ! get_post( $post_id ) ) {
					return $this->error( 'not_found', __( 'Post not found.', 'stonewright' ) );
				}

				$tree        = ElementorData::read( $post_id );
				$settings    = isset( $args['settings'] ) && is_array( $args['settings'] ) ? $args['settings'] : [];
				$settings    = ContainerSettings::normalize( $settings );
				$validated   = SettingsValidator::validate_container( $settings );
				if ( $validated instanceof \WP_Error ) {
					return $validated;
				}
				$settings = $validated['settings'];

				$element = [
					'id'       => ElementorData::generate_id(),
					'elType'   => 'container',
					'settings' => $settings,
					'elements' => [],
				];
				$element['isInner'] = false;

				$parent_path = [];
				if ( ! empty( $args['parent_id'] ) ) {
					$parent_path = ElementorData::find_path( $tree, (string) $args['parent_id'] );
					if ( null === $parent_path ) {
						return $this->error( 'parent_not_found', __( 'Parent element not found.', 'stonewright' ) );
					}
				}

				$position = isset( $args['position'] ) ? (int) $args['position'] : PHP_INT_MAX;

				$new_tree = ElementorData::insert( $tree, $parent_path, $position, $element );
				$snapshot_id = Backup::snapshot_post( $post_id );
				if ( ! ElementorData::write( $post_id, $new_tree ) ) {
					return $this->error( 'write_failed', __( 'Could not save Elementor data.', 'stonewright' ) );
				}

				return [
					'post_id'     => $post_id,
					'element_id'  => $element['id'],
					'snapshot_id' => $snapshot_id,
					'path'        => array_merge( $parent_path, [ $position ] ),
				];
			}
		);
	}
}
