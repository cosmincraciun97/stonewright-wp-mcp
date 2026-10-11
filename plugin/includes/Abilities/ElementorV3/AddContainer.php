<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\Abilities\ElementorV3;

use Stonewright\WpMcp\Abilities\AbilityKernel;
use Stonewright\WpMcp\Elementor\ContainerSettings;
use Stonewright\WpMcp\Elementor\ElementorCustomCssGate;
use Stonewright\WpMcp\Elementor\Schema\SettingsValidator;
use Stonewright\WpMcp\Elementor\Schema\SparseSettingsNormalizer;
use Stonewright\WpMcp\Elementor\Write\PostWriteLock;
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
				'custom_code_grant'  => [ 'type' => 'string', 'description' => 'Single-use human-issued custom-code grant for custom CSS or an HTML widget style tag in this call, bound to the exact candidate code. Send it only when the user has approved that code.' ],
				'confirmation_token' => [ 'type' => 'string' ],
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
				'write_receipt' => [ 'type' => 'object' ],
			],
		];
	}

	public function permission_callback( array $args ): bool|\WP_Error {
		$id = (int) ( $args['post_id'] ?? 0 );
		return Permissions::edit_post( $id );
	}

	public function execute( array $args ): array|\WP_Error {
		return $this->audit_write(
			$args,
			function ( array $args ) {
				$post_id = (int) $args['post_id'];
				if ( ! get_post( $post_id ) ) {
					return $this->error( 'not_found', __( 'Post not found.', 'stonewright' ) );
				}

				$settings    = isset( $args['settings'] ) && is_array( $args['settings'] ) ? $args['settings'] : [];
				$css_gate    = ElementorCustomCssGate::assert_incoming( $settings, $args, 'container' );
				if ( $css_gate instanceof \WP_Error ) {
					return $css_gate;
				}
				$settings    = ContainerSettings::normalize( $settings );
				$validated   = SettingsValidator::validate_container( $settings );
				if ( $validated instanceof \WP_Error ) {
					return $validated;
				}
				$settings = SparseSettingsNormalizer::for_new_write( $validated['settings'], 'container', $validated['settings'] );

				$element = [
					'id'       => ElementorData::generate_id(),
					'elType'   => 'container',
					'settings' => $settings,
					'elements' => [],
				];

				// The lease is taken before the document is read, so no other writer changes it between the read and the write.
				return PostWriteLock::with_lease(
					$post_id,
					'add-container',
					function ( string $lock_owner ) use ( $args, $post_id, $element ) {
						$tree        = ElementorData::read( $post_id );
						$parent_path = [];
						if ( ! empty( $args['parent_id'] ) ) {
							$parent_path = ElementorData::find_path( $tree, (string) $args['parent_id'] );
							if ( null === $parent_path ) {
								return $this->error( 'parent_not_found', __( 'Parent element not found.', 'stonewright' ) );
							}
							if ( ! ElementorData::accepts_children( $tree, $parent_path ) ) {
								return $this->error(
									'parent_not_container',
									__( 'The parent must be a container, a section or a column; a widget cannot hold other elements.', 'stonewright' ),
									[
										'status'         => 400,
										'parent_id'      => (string) $args['parent_id'],
										'parent_el_type' => (string) ( ElementorData::element_at( $tree, $parent_path )['elType'] ?? '' ),
									]
								);
							}
						}

						// A container inside another element is an inner container; one at the root is not.
						$element['isInner'] = [] !== $parent_path;

						$position = ElementorData::insertion_index( $tree, $parent_path, isset( $args['position'] ) ? (int) $args['position'] : PHP_INT_MAX );

						$new_tree    = ElementorData::insert( $tree, $parent_path, $position, $element );
						$snapshot_id = Backup::snapshot_post( $post_id );
						if ( '' === $snapshot_id ) {
							return $this->backup_failed_error();
						}
						if ( ! ElementorData::write( $post_id, $new_tree, [ 'lock_owner' => $lock_owner ] ) ) {
							return ElementorData::write_error_for_ability();
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
		);
	}
}
