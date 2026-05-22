<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\Abilities\ElementorV4;

use Stonewright\WpMcp\Abilities\AbilityKernel;
use Stonewright\WpMcp\Security\Backup;
use Stonewright\WpMcp\Security\Permissions;

/**
 * Patches an existing design variable in the active Elementor kit.
 * Snapshots the kit post before any write.
 * Contract decision: keep output_schema aligned to the handler response shape.
 *
 * @stonewright-status experimental
 */
final class UpdateVariable extends AbilityKernel {

	public function name(): string {
		return 'stonewright/elementor-v4-update-variable';
	}

	public function label(): string {
		return __( 'Update Elementor V4 variable', 'stonewright' );
	}

	public function description(): string {
		return __( 'Patches the value and/or mode of an existing design variable in the active Elementor kit. Snapshots the kit before writing.', 'stonewright' );
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
				'id'    => [ 'type' => 'string', 'minLength' => 1 ],
				'value' => [ 'type' => 'string', 'minLength' => 1 ],
				'mode'  => [ 'type' => 'string' ],
			],
			'required'             => [ 'id', 'value' ],
		];
	}

	public function output_schema(): array {
		return [
			'type'       => 'object',
			'properties' => [
				'ok'          => [ 'type' => 'boolean' ],
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

				$collection = isset( $settings['e_atomic_variables'] ) && is_array( $settings['e_atomic_variables'] )
					? $settings['e_atomic_variables']
					: [];

				$target_id = (string) $args['id'];
				$found     = false;

				foreach ( $collection as &$var ) {
					if ( ! is_array( $var ) || (string) ( $var['id'] ?? '' ) !== $target_id ) {
						continue;
					}
					$var['value'] = (string) $args['value'];
					if ( isset( $args['mode'] ) ) {
						$var['default_mode'] = (string) $args['mode'];
					}
					$found = true;
					break;
				}
				unset( $var );

				if ( ! $found ) {
					return $this->error( 'variable_not_found', __( 'Variable not found.', 'stonewright' ) );
				}

				$settings['e_atomic_variables'] = $collection;
				if ( false === update_post_meta( $kit_id, '_elementor_page_settings', $settings ) ) {
					return $this->error( 'write_failed', __( 'Could not save Elementor atomic variable.', 'stonewright' ) );
				}

				return $this->ok( [ 'snapshot_id' => $snapshot_id ] );
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
