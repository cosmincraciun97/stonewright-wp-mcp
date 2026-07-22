<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\Abilities\Design;

use Stonewright\WpMcp\Abilities\AbilityKernel;
use Stonewright\WpMcp\Abilities\Common\ConfirmationGuard;
use Stonewright\WpMcp\DesignSpec\AssetReferences;
use Stonewright\WpMcp\DesignSpec\Validator;
use Stonewright\WpMcp\Elementor\Renderer;
use Stonewright\WpMcp\Security\Backup;
use Stonewright\WpMcp\Security\Permissions;
use Stonewright\WpMcp\Support\ElementorData;

/**
 * Contract decision: keep output_schema aligned to the handler response shape.
 *
 * @stonewright-status stable
 */
final class SpecToElementorV3 extends AbilityKernel {
	use ConfirmationGuard;

	public function name(): string {
		return 'stonewright/design-spec-to-elementor-v3';
	}

	public function label(): string {
		return __( 'Render spec to Elementor V3', 'stonewright' );
	}

	public function description(): string {
		return __( 'Renders a Stonewright Design Spec into Elementor V3 element JSON and writes it to a post.', 'stonewright' );
	}

	public function category(): string {
		return 'design';
	}

	public function input_schema(): array {
		return [
			'type'                 => 'object',
			'additionalProperties' => false,
			'properties'           => [
				'spec'    => [ 'type' => 'object' ],
				'post_id' => [ 'type' => 'integer', 'minimum' => 1 ],
				'replace' => [ 'type' => 'boolean', 'default' => true ],
				'dry_run'            => [ 'type' => 'boolean', 'default' => false ],
				'confirmation_token' => [ 'type' => 'string' ],
			],
			'required'             => [ 'spec' ],
		];
	}

	public function output_schema(): array {
		return [
			'type'       => 'object',
			'properties' => [
				'post_id'     => [ 'type' => 'integer' ],
				'snapshot_id' => [ 'type' => 'string' ],
				'tree'        => [ 'type' => 'array' ],
				'count'       => [ 'type' => 'integer' ],
				'diagnostics' => [ 'type' => 'array' ],
				'sideloaded_assets' => [ 'type' => 'array' ],
			],
		];
	}

	public function permission_callback( array $args ): bool|\WP_Error {
		if ( ! empty( $args['post_id'] ) ) {
			return Permissions::edit_post( (int) $args['post_id'] );
		}
		return Permissions::edit_posts();
	}

	public function execute( array $args ): array|\WP_Error {
		return $this->audit(
			$args,
			function ( array $args ) {
				$normalized = Validator::validate( (array) $args['spec'] );
				if ( is_wp_error( $normalized ) ) {
					return $normalized;
				}

				if ( empty( $args['post_id'] ) || ! empty( $args['dry_run'] ) ) {
					$resolved    = AssetReferences::resolve( $normalized, false );
					$normalized  = $resolved['spec'];
					$diagnostics = [];
					$tree        = Renderer::render( $normalized, $diagnostics );

					return [
						'post_id'           => isset( $args['post_id'] ) ? (int) $args['post_id'] : 0,
						'snapshot_id'       => '',
						'tree'              => $tree,
						'count'             => count( ElementorData::flatten( $tree ) ),
						'diagnostics'       => $diagnostics,
						'sideloaded_assets' => [],
					];
				}

				$post_id = (int) $args['post_id'];
				if ( ! get_post( $post_id ) ) {
					return $this->error( 'not_found', __( 'Target post not found.', 'stonewright' ) );
				}

				$replace     = ! isset( $args['replace'] ) || (bool) $args['replace'];
				if ( $replace ) {
					$verify_args = array_filter(
						$args,
						static fn( string $key ): bool => 'confirmation_token' !== $key,
						ARRAY_FILTER_USE_KEY
					);
					$token_error = $this->confirmation_token_error( $args, $verify_args );
					if ( null !== $token_error ) {
						return $token_error;
					}
				}
				$resolved          = AssetReferences::resolve( $normalized, true );
				$normalized        = $resolved['spec'];
				$sideloaded_assets = $resolved['sideloaded_assets'];
				$validated         = Validator::validate( $normalized );
				if ( is_wp_error( $validated ) ) {
					return $validated;
				}
				$diagnostics = [];
				$tree        = Renderer::render( $validated, $diagnostics );

				$snapshot_id = Backup::snapshot_post( $post_id );
				$existing    = ElementorData::read( $post_id );
				$next_tree   = $replace ? $tree : array_merge( $existing, $tree );

				if ( ! ElementorData::write( $post_id, $next_tree ) ) {
					return ElementorData::write_error_for_ability();
				}
				$this->apply_page_shell_settings( $post_id );

				return [
					'post_id'           => $post_id,
					'snapshot_id'       => $snapshot_id,
					'tree'              => $next_tree,
					'count'             => count( ElementorData::flatten( $next_tree ) ),
					'diagnostics'       => $diagnostics,
					'sideloaded_assets' => $sideloaded_assets,
				];
			}
		);
	}

	private function apply_page_shell_settings( int $post_id ): void {
		$post = get_post( $post_id );
		if ( ! $post || 'page' !== $post->post_type ) {
			return;
		}

		$page_settings = get_post_meta( $post_id, '_elementor_page_settings', true );
		if ( ! is_array( $page_settings ) ) {
			$page_settings = [];
		}
		$page_settings['hide_title'] = 'yes';

		update_post_meta( $post_id, '_elementor_page_settings', $page_settings );
		update_post_meta( $post_id, '_wp_page_template', 'elementor_header_footer' );
	}
}
