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
final class UpdateElement extends AbilityKernel {

	public function name(): string {
		return 'stonewright/elementor-v3-update-element';
	}

	public function label(): string {
		return __( 'Update Elementor element', 'stonewright' );
	}

	public function description(): string {
		return __( 'Patches settings of an element identified by id. Snapshots before write.', 'stonewright' );
	}

	public function category(): string {
		return 'elementor';
	}

	public function input_schema(): array {
		return [
			'type'                 => 'object',
			'additionalProperties' => false,
			'properties'           => [
				'post_id'    => [ 'type' => 'integer', 'minimum' => 1 ],
				'element_id' => [ 'type' => 'string' ],
				'settings'   => [ 'type' => 'object' ],
				'mode'       => [ 'type' => 'string', 'enum' => [ 'merge', 'replace' ], 'default' => 'merge' ],
			],
			'required'             => [ 'post_id', 'element_id', 'settings' ],
		];
	}

	public function output_schema(): array {
		return [
			'type'       => 'object',
			'properties' => [
				'post_id'     => [ 'type' => 'integer' ],
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
				$post_id = (int) $args['post_id'];
				if ( ! get_post( $post_id ) ) {
					return $this->error( 'not_found', __( 'Post not found.', 'stonewright' ) );
				}

				$tree        = ElementorData::read( $post_id );
				$path        = ElementorData::find_path( $tree, (string) $args['element_id'] );
				if ( null === $path ) {
					return $this->error( 'element_not_found', __( 'Element not found.', 'stonewright' ) );
				}

				$existing = $this->resolve( $tree, $path );
				if ( null === $existing ) {
					return $this->error( 'element_not_found', __( 'Element not found.', 'stonewright' ) );
				}

				$settings = isset( $existing['settings'] ) && is_array( $existing['settings'] ) ? $existing['settings'] : [];
				$mode     = isset( $args['mode'] ) ? (string) $args['mode'] : 'merge';
				$incoming = (array) $args['settings'];
				$next     = 'replace' === $mode ? $incoming : array_merge( $settings, $incoming );
				$element_type = (string) ( $existing['elType'] ?? '' );
				if ( in_array( $element_type, [ 'container', 'section', 'column' ], true ) ) {
					$next      = 'container' === $element_type ? ContainerSettings::normalize( $next ) : $next;
					$validated = SettingsValidator::validate_container( $next, $element_type );
					if ( $validated instanceof \WP_Error ) {
						return $validated;
					}
					$next = $validated['settings'];
				} elseif ( 'widget' === ( $existing['elType'] ?? '' ) ) {
					$validated = SettingsValidator::validate( (string) ( $existing['widgetType'] ?? '' ), $next );
					if ( $validated instanceof \WP_Error ) {
						return $validated;
					}
					$next = $validated['settings'];
				}

				$existing['settings'] = $next;

				$new_tree = ElementorData::set( $tree, $path, $existing );
				$snapshot_id = Backup::snapshot_post( $post_id );
				if ( ! ElementorData::write( $post_id, $new_tree ) ) {
					return $this->error( 'write_failed', __( 'Could not save Elementor data.', 'stonewright' ) );
				}

				return [
					'post_id'     => $post_id,
					'snapshot_id' => $snapshot_id,
				];
			}
		);
	}

	private function resolve( array $tree, array $path ): ?array {
		$current = null;
		foreach ( $path as $index ) {
			if ( ! isset( $tree[ $index ] ) ) {
				return null;
			}
			$current = $tree[ $index ];
			$tree    = isset( $current['elements'] ) && is_array( $current['elements'] ) ? $current['elements'] : [];
		}
		return $current;
	}
}
