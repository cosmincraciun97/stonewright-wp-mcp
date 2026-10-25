<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\Abilities\ElementorV3;

use Stonewright\WpMcp\Abilities\AbilityKernel;
use Stonewright\WpMcp\Abilities\Common\ConfirmationGuard;
use Stonewright\WpMcp\Elementor\CachePurgeAdvisor;
use Stonewright\WpMcp\Elementor\KitRows;
use Stonewright\WpMcp\Elementor\PageCachePurger;
use Stonewright\WpMcp\Elementor\Schema\CssValueGuard;
use Stonewright\WpMcp\Elementor\Write\EditorKses;
use Stonewright\WpMcp\Elementor\Write\PostWriteLock;
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
		return __( 'Replaces or merges the custom global colors (custom_colors) of the Elementor active kit by their id; the four system colors (primary, secondary, text, accent) are not changed. Colour values must be hex, rgb(), rgba(), hsl(), hsla(), a CSS colour name, transparent or a global colour variable; ids are letters, digits, hyphen and underscore. Run elementor-css-regenerate afterwards.', 'stonewright' );
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
				'unchanged'   => [ 'type' => 'boolean', 'description' => 'True when the kit already held these colors: nothing was snapshotted or written.' ],
				'content_filtered' => [ 'type' => 'boolean', 'description' => 'True when the user cannot unfiltered_html and Elementor\'s filtering changed the settings before they were stored.' ],
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

				foreach ( array_values( (array) $args['colors'] ) as $index => $row ) {
					$candidate = is_array( $row ) ? [ 'id' => $row['id'] ?? '', 'color' => $row['color'] ?? '' ] : [];
					$violation = CssValueGuard::color_row_violation( $candidate, true );
					if ( null !== $violation ) {
						return CssValueGuard::refusal( 'colors.' . $index . '.' . $violation['path'], $violation['expected'], $candidate[ $violation['path'] ] ?? null );
					}
				}

				$kit_id = $this->resolve_kit_id();
				if ( 0 === $kit_id ) {
					return $this->error( 'no_kit', __( 'No active Elementor kit.', 'stonewright' ) );
				}

				// The lease is taken before the kit settings are read, so no other writer changes them between the read and the write.
				return PostWriteLock::with_lease(
					$kit_id,
					'update-kit-colors',
					function () use ( $args, $kit_id ) {
						$settings = get_post_meta( $kit_id, '_elementor_page_settings', true );
						if ( ! is_array( $settings ) ) {
							$settings = [];
						}

						$mode     = isset( $args['mode'] ) ? (string) $args['mode'] : 'merge';
						$existing = isset( $settings['custom_colors'] ) && is_array( $settings['custom_colors'] ) ? $settings['custom_colors'] : [];

						$incoming = [];
						foreach ( (array) $args['colors'] as $c ) {
							$row = [ '_id' => (string) ( $c['id'] ?? '' ) ];
							if ( isset( $c['title'] ) ) {
								$row['title'] = (string) $c['title'];
							}
							$row['color'] = (string) ( $c['color'] ?? '' );
							$incoming[]   = $row;
						}

						$next                    = $settings;
						$next['custom_colors']   = KitRows::merge( $existing, $incoming, $mode );
						// For a user without unfiltered_html Elementor filters the settings when it stores them; filter first, the way its editor does.
						$filtered         = EditorKses::filter( $next );
						$content_filtered = $filtered !== $next ? [ 'content_filtered' => true ] : [];
						$next             = $filtered;
						if ( $next === $settings ) {
							// The kit already holds these colors; update_post_meta() reports an unchanged value as false, which is no failure.
							return [ 'kit_id' => $kit_id, 'snapshot_id' => '', 'unchanged' => true ];
						}

						$snapshot_id = Backup::snapshot_post( $kit_id );
						if ( '' === $snapshot_id ) {
							return $this->backup_failed_error();
						}
						update_post_meta( $kit_id, '_elementor_page_settings', wp_slash( $next ) );
						if ( get_post_meta( $kit_id, '_elementor_page_settings', true ) !== $next ) {
							return $this->error( 'write_failed', __( 'Could not save Elementor kit colors.', 'stonewright' ) );
						}
						CachePurgeAdvisor::note_write();

						return $content_filtered + [
							'kit_id'      => $kit_id,
							'snapshot_id' => $snapshot_id,
							'next_step'   => [
								'tool'    => 'stonewright/elementor-css-regenerate',
								'post_id' => $kit_id,
								'why'     => 'The kit colors are stored; regenerate the kit CSS so pages use them.',
							],
							'cache_purge' => PageCachePurger::purge_quietly( $kit_id ),
						];
					}
				);
			}
		);
	}
	private function resolve_kit_id(): int {
		$id = (int) get_option( 'elementor_active_kit', 0 );
		return $id > 0 ? $id : 0;
	}
}
