<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\Abilities\ElementorV3;

use Stonewright\WpMcp\Abilities\AbilityKernel;
use Stonewright\WpMcp\Abilities\Common\ConfirmationGuard;
use Stonewright\WpMcp\Security\Backup;
use Stonewright\WpMcp\Security\Permissions;

/**
 * Contract decision: keep output_schema aligned to the handler response shape.
 *
 * @stonewright-status stable
 */
final class UpdateKitColors extends AbilityKernel {

	use ConfirmationGuard;

	public function name(): string {
		return 'stonewright/elementor-v3-update-kit-colors';
	}

	public function label(): string {
		return __( 'Update Elementor kit colors', 'stonewright' );
	}

	public function description(): string {
		return __( 'Replaces or merges the global color palette in the Elementor active kit.', 'stonewright' );
	}

	public function category(): string {
		return 'elementor';
	}

	public function input_schema(): array {
		return [
			'type'                 => 'object',
			'additionalProperties' => false,
			'properties'           => [
				'colors' => [
					'type'  => 'array',
					'items' => [
						'type'       => 'object',
						'properties' => [
							'id'    => [ 'type' => 'string' ],
							'title' => [ 'type' => 'string' ],
							'color' => [ 'type' => 'string' ],
						],
						'required'   => [ 'id', 'color' ],
					],
				],
				'mode'               => [ 'type' => 'string', 'enum' => [ 'merge', 'replace' ], 'default' => 'merge' ],
				'confirmation_token' => [ 'type' => 'string' ],
			],
			'required'             => [ 'colors' ],
		];
	}

	public function output_schema(): array {
		return [
			'type'       => 'object',
			'properties' => [
				'kit_id'      => [ 'type' => 'integer' ],
				'snapshot_id' => [ 'type' => 'string' ],
			],
		];
	}

	public function permission_callback( array $args ): bool|\WP_Error {
		return Permissions::edit_theme_options();
	}

	public function execute( array $args ): array|\WP_Error {
		return $this->audit(
			$args,
			function ( array $args ) {
				$verify_args = array_filter(
					$args,
					static fn( string $k ) => 'confirmation_token' !== $k,
					ARRAY_FILTER_USE_KEY
				);

				$token_error = $this->confirmation_token_error( $args, $verify_args );
				if ( null !== $token_error ) {
					return $token_error;
				}

				$kit_id = $this->resolve_kit_id();
				if ( 0 === $kit_id ) {
					return $this->error( 'no_kit', __( 'No active Elementor kit.', 'stonewright' ) );
				}

				$settings = get_post_meta( $kit_id, '_elementor_page_settings', true );
				if ( ! is_array( $settings ) ) {
					$settings = [];
				}

				$mode     = isset( $args['mode'] ) ? (string) $args['mode'] : 'merge';
				$existing = isset( $settings['custom_colors'] ) && is_array( $settings['custom_colors'] ) ? $settings['custom_colors'] : [];

				$incoming = [];
				foreach ( (array) $args['colors'] as $c ) {
					$incoming[] = [
						'_id'    => (string) ( $c['id'] ?? '' ),
						'title'  => (string) ( $c['title'] ?? '' ),
						'color'  => (string) ( $c['color'] ?? '' ),
					];
				}

				$settings['custom_colors'] = 'replace' === $mode ? $incoming : array_merge( $existing, $incoming );

				$snapshot_id = Backup::snapshot_post( $kit_id );
				if ( false === update_post_meta( $kit_id, '_elementor_page_settings', $settings ) ) {
					return $this->error( 'write_failed', __( 'Could not save Elementor kit colors.', 'stonewright' ) );
				}

				return [ 'kit_id' => $kit_id, 'snapshot_id' => $snapshot_id ];
			}
		);
	}

	private function resolve_kit_id(): int {
		$id = (int) get_option( 'elementor_active_kit', 0 );
		return $id > 0 ? $id : 0;
	}
}
