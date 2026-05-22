<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\Abilities\ElementorV4;

use Stonewright\WpMcp\Abilities\AbilityKernel;
use Stonewright\WpMcp\Security\Backup;
use Stonewright\WpMcp\Security\Permissions;

/**
 * Registers a new CSS class in the active Elementor kit's classes collection.
 * Snapshots the kit post before any write.
 * Contract decision: keep output_schema aligned to the handler response shape.
 *
 * @stonewright-status experimental
 */
final class CreateClass extends AbilityKernel {

	public function name(): string {
		return 'stonewright/elementor-v4-create-class';
	}

	public function label(): string {
		return __( 'Create Elementor V4 class', 'stonewright' );
	}

	public function description(): string {
		return __( 'Adds a new CSS class to the active Elementor kit\'s classes collection. Snapshots the kit before writing.', 'stonewright' );
	}

	public function category(): string {
		return 'elementor';
	}

	public function meta(): array {
		return [ 'experimental' => true ];
	}

	public function input_schema(): array {
		return [
			'type'                 => 'object',
			'additionalProperties' => false,
			'properties'           => [
				'name'       => [ 'type' => 'string', 'minLength' => 1 ],
				'selectors'  => [ 'type' => 'array', 'items' => [ 'type' => 'string' ] ],
				'properties' => [ 'type' => 'object' ],
			],
			'required'             => [ 'name', 'selectors', 'properties' ],
		];
	}

	public function output_schema(): array {
		return [
			'type'       => 'object',
			'properties' => [
				'id'          => [ 'type' => 'string' ],
				'snapshot_id' => [ 'type' => 'string' ],
			],
		];
	}

	public function permission_callback( array $args ): bool|\WP_Error {
		if ( ! get_option( 'stonewright_elementor_v4_atomic', false ) ) {
			return new \WP_Error( 'feature_disabled', __( 'Elementor V4 atomic features are disabled.', 'stonewright' ) );
		}
		return Permissions::edit_theme_options();
	}

	public function execute( array $args ): array|\WP_Error {
		return $this->audit(
			$args,
			function ( array $args ) {
				$kit_id = $this->get_active_kit_id();
				if ( 0 === $kit_id ) {
					return $this->error( 'no_kit', __( 'No active Elementor kit found.', 'stonewright' ) );
				}

				$snapshot_id = Backup::snapshot_post( $kit_id );
				$settings    = $this->read_kit_settings( $kit_id );

				$collection = isset( $settings['e_atomic_classes'] ) && is_array( $settings['e_atomic_classes'] )
					? $settings['e_atomic_classes']
					: [];

				$new_id = 'cls_' . substr( md5( uniqid( '', true ) ), 0, 8 );
				$selectors = isset( $args['selectors'] ) && is_array( $args['selectors'] )
					? array_values( array_map( 'strval', $args['selectors'] ) )
					: [];
				$properties = isset( $args['properties'] ) && is_array( $args['properties'] )
					? $args['properties']
					: new \stdClass();

				$collection[] = [
					'id'         => $new_id,
					'name'       => (string) $args['name'],
					'selectors'  => $selectors,
					'properties' => $properties,
				];

				$settings['e_atomic_classes'] = $collection;
				if ( false === update_post_meta( $kit_id, '_elementor_page_settings', $settings ) ) {
					return $this->error( 'write_failed', __( 'Could not save Elementor atomic classes.', 'stonewright' ) );
				}

				return [
					'id'          => $new_id,
					'snapshot_id' => $snapshot_id,
				];
			}
		);
	}

	private function get_active_kit_id(): int {
		if ( ! did_action( 'elementor/loaded' ) || ! class_exists( '\\Elementor\\Plugin' ) ) {
			return 0;
		}
		try {
			$kit = \Elementor\Plugin::$instance->kits_manager->get_active_kit();
			return $kit ? (int) $kit->get_id() : 0;
		} catch ( \Throwable $e ) {
			return 0;
		}
	}

	/**
	 * @return array<string, mixed>
	 */
	private function read_kit_settings( int $kit_id ): array {
		$raw = get_post_meta( $kit_id, '_elementor_page_settings', true );
		if ( is_array( $raw ) ) {
			return $raw;
		}
		if ( is_string( $raw ) && '' !== $raw ) {
			$decoded = json_decode( $raw, true );
			return is_array( $decoded ) ? $decoded : [];
		}
		return [];
	}
}
