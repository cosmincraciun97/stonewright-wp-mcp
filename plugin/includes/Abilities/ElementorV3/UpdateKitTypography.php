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
final class UpdateKitTypography extends AbilityKernel {

	use ConfirmationGuard;

	public function name(): string {
		return 'stonewright/elementor-v3-update-kit-typography';
	}

	public function label(): string {
		return __( 'Update Elementor kit typography', 'stonewright' );
	}

	public function description(): string {
		return __( 'Updates global typography (font family, weight, size, line height) in the Elementor active kit.', 'stonewright' );
	}

	public function category(): string {
		return 'elementor';
	}

	public function input_schema(): array {
		return [
			'type'                 => 'object',
			'additionalProperties' => false,
			'properties'           => [
				'fonts' => [
					'type'  => 'array',
					'items' => [
						'type'       => 'object',
						'properties' => [
							'id'              => [ 'type' => 'string' ],
							'title'           => [ 'type' => 'string' ],
							'font_family'     => [ 'type' => 'string' ],
							'font_weight'     => [ 'type' => 'string' ],
							'font_size'       => [ 'type' => 'object' ],
							'line_height'     => [ 'type' => 'object' ],
							'letter_spacing'  => [ 'type' => 'object' ],
						],
						'required'   => [ 'id' ],
					],
				],
				'mode'               => [ 'type' => 'string', 'enum' => [ 'merge', 'replace' ], 'default' => 'merge' ],
				'confirmation_token' => [ 'type' => 'string' ],
			],
			'required'             => [ 'fonts' ],
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

				$kit_id = (int) get_option( 'elementor_active_kit', 0 );
				if ( 0 === $kit_id ) {
					return $this->error( 'no_kit', __( 'No active Elementor kit.', 'stonewright' ) );
				}

				$settings = get_post_meta( $kit_id, '_elementor_page_settings', true );
				if ( ! is_array( $settings ) ) {
					$settings = [];
				}

				$existing = isset( $settings['custom_typography'] ) && is_array( $settings['custom_typography'] )
					? $settings['custom_typography']
					: [];

				$incoming = [];
				foreach ( (array) $args['fonts'] as $f ) {
					$entry = [
						'_id'   => (string) ( $f['id'] ?? '' ),
						'title' => (string) ( $f['title'] ?? '' ),
					];
					foreach ( [ 'typography_typography', 'typography_font_family', 'typography_font_weight' ] as $_ ) {
						// Elementor expects keyed control values - we pass them through verbatim.
					}
					if ( isset( $f['font_family'] ) ) {
						$entry['typography_font_family'] = (string) $f['font_family'];
						$entry['typography_typography']  = 'custom';
					}
					if ( isset( $f['font_weight'] ) ) {
						$entry['typography_font_weight'] = (string) $f['font_weight'];
					}
					if ( isset( $f['font_size'] ) && is_array( $f['font_size'] ) ) {
						$entry['typography_font_size'] = $f['font_size'];
					}
					if ( isset( $f['line_height'] ) && is_array( $f['line_height'] ) ) {
						$entry['typography_line_height'] = $f['line_height'];
					}
					if ( isset( $f['letter_spacing'] ) && is_array( $f['letter_spacing'] ) ) {
						$entry['typography_letter_spacing'] = $f['letter_spacing'];
					}
					$incoming[] = $entry;
				}

				$mode = isset( $args['mode'] ) ? (string) $args['mode'] : 'merge';
				$settings['custom_typography'] = 'replace' === $mode ? $incoming : array_merge( $existing, $incoming );

				$snapshot_id = Backup::snapshot_post( $kit_id );
				if ( false === update_post_meta( $kit_id, '_elementor_page_settings', $settings ) ) {
					return $this->error( 'write_failed', __( 'Could not save Elementor kit typography.', 'stonewright' ) );
				}

				return [ 'kit_id' => $kit_id, 'snapshot_id' => $snapshot_id ];
			}
		);
	}
}
