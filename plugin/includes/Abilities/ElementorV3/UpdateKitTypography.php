<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\Abilities\ElementorV3;

use Stonewright\WpMcp\Abilities\AbilityKernel;
use Stonewright\WpMcp\Abilities\Common\ConfirmationGuard;
use Stonewright\WpMcp\Elementor\KitRows;
use Stonewright\WpMcp\Elementor\PageCachePurger;
use Stonewright\WpMcp\Elementor\Schema\CssValueGuard;
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
		return __( 'Updates the custom global typography (font family, weight, size, line height) in the Elementor active kit, merging by id. Font families are plain names, weights are keywords or 1 to 1000, sizes are numbers with a supported unit; other values are refused. Run elementor-css-regenerate afterwards.', 'stonewright' );
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
				'unchanged'   => [ 'type' => 'boolean', 'description' => 'True when the kit already held this typography: nothing was snapshotted or written.' ],
				'next_step'   => [ 'type' => 'object' ],
				'cache_purge' => [ 'type' => 'object' ],
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

				foreach ( array_values( (array) $args['fonts'] ) as $index => $font ) {
					$violation = is_array( $font )
						? CssValueGuard::typography_row_violation( $font, true )
						: [ 'path' => '', 'expected' => 'a typography row object' ];
					if ( null !== $violation ) {
						$path = 'fonts.' . $index . ( '' === $violation['path'] ? '' : '.' . $violation['path'] );
						return CssValueGuard::refusal( $path, $violation['expected'], is_array( $font ) ? ( $font[ $violation['path'] ] ?? null ) : $font );
					}
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
					// Elementor expects keyed control values; they are passed through as sent. Only what the call sent is in the row.
					$entry = [ '_id' => (string) ( $f['id'] ?? '' ) ];
					if ( isset( $f['title'] ) ) {
						$entry['title'] = (string) $f['title'];
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

				$mode                        = isset( $args['mode'] ) ? (string) $args['mode'] : 'merge';
				$next                        = $settings;
				$next['custom_typography']   = KitRows::merge( $existing, $incoming, $mode );
				if ( $next === $settings ) {
					// The kit already holds this typography; update_post_meta() reports an unchanged value as false, which is no failure.
					return [ 'kit_id' => $kit_id, 'snapshot_id' => '', 'unchanged' => true ];
				}

				$snapshot_id = Backup::snapshot_post( $kit_id );
				if ( '' === $snapshot_id ) {
					return $this->backup_failed_error();
				}
				update_post_meta( $kit_id, '_elementor_page_settings', wp_slash( $next ) );
				if ( get_post_meta( $kit_id, '_elementor_page_settings', true ) !== $next ) {
					return $this->error( 'write_failed', __( 'Could not save Elementor kit typography.', 'stonewright' ) );
				}

				return [
					'kit_id'      => $kit_id,
					'snapshot_id' => $snapshot_id,
					'next_step'   => [
						'tool'    => 'stonewright/elementor-css-regenerate',
						'post_id' => $kit_id,
						'why'     => 'The kit typography is stored; regenerate the kit CSS so pages use it.',
					],
					'cache_purge' => PageCachePurger::purge_quietly( $kit_id ),
				];
			}
		);
	}
}
