<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\Abilities\ElementorV4;

use Stonewright\WpMcp\Abilities\AbilityKernel;
use Stonewright\WpMcp\Abilities\Common\ConfirmationGuard;
use Stonewright\WpMcp\DesignSpec\Validator;
use Stonewright\WpMcp\Renderers\ElementorV4SpecRenderer;
use Stonewright\WpMcp\Security\Backup;
use Stonewright\WpMcp\Security\Permissions;
use Stonewright\WpMcp\Support\ElementorData;

/**
 * Validates a Stonewright Design Spec and renders it into an Elementor V4 atomic
 * element tree. Writes to the post unless dry_run is true (default).
 * When replace is false (default) the rendered tree is appended; when true it
 * replaces the entire existing tree.
 *
 * Rendering is delegated to ElementorV4SpecRenderer, which is currently a stub
 * that emits e-flexbox placeholder containers. See that class for the roadmap.
 * Contract decision: keep output_schema aligned to the handler response shape.
 *
 * @stonewright-status experimental
 */
final class RenderFromSpec extends AbilityKernel {
	use ConfirmationGuard;

	public function name(): string {
		return 'stonewright/elementor-v4-render-from-spec';
	}

	public function label(): string {
		return __( 'Render spec to Elementor V4 (experimental)', 'stonewright' );
	}

	public function description(): string {
		return __( 'Validates a Stonewright Design Spec and renders it as an Elementor V4 atomic tree. dry_run=true (default) returns the tree without writing.', 'stonewright' );
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
				'spec'               => [ 'type' => 'object' ],
				'post_id'            => [ 'type' => 'integer', 'minimum' => 1 ],
				'dry_run'            => [ 'type' => 'boolean', 'default' => true ],
				'replace'            => [ 'type' => 'boolean', 'default' => false ],
				'confirmation_token' => [ 'type' => 'string' ],
			],
			'required'             => [ 'spec' ],
		];
	}

	public function output_schema(): array {
		return [
			'type'       => 'object',
			'properties' => [
				'ok'          => [ 'type' => 'boolean' ],
				'dry_run'     => [ 'type' => 'boolean' ],
				'atomic_tree' => [ 'type' => 'array' ],
				'snapshot_id' => [ 'type' => 'string' ],
				'errors'      => [ 'type' => 'array' ],
				'diagnostics' => [ 'type' => 'array' ],
			],
		];
	}

	public function permission_callback( array $args ): bool|\WP_Error {
		if ( ! get_option( 'stonewright_elementor_v4_atomic', false ) ) {
			return new \WP_Error( 'feature_disabled', __( 'Elementor V4 atomic features are disabled.', 'stonewright' ) );
		}
		if ( ! empty( $args['post_id'] ) ) {
			return Permissions::edit_post( (int) $args['post_id'] );
		}
		return Permissions::edit_posts();
	}

	public function execute( array $args ): array|\WP_Error {
		return $this->audit(
			$args,
			function ( array $args ) {
				$spec    = is_array( $args['spec'] ) ? $args['spec'] : [];
				$dry_run = isset( $args['dry_run'] ) ? (bool) $args['dry_run'] : true;
				$replace = isset( $args['replace'] ) ? (bool) $args['replace'] : false;

				$normalized = Validator::validate( $spec );
				if ( is_wp_error( $normalized ) ) {
					return $normalized;
				}

				$diagnostics = [];
				$atomic_tree = ElementorV4SpecRenderer::render( $normalized, $diagnostics );
				if ( is_wp_error( $atomic_tree ) ) {
					return $atomic_tree;
				}

				if ( $dry_run || empty( $args['post_id'] ) ) {
					return [
						'ok'          => true,
						'dry_run'     => true,
						'atomic_tree' => $atomic_tree,
						'snapshot_id' => '',
						'errors'      => [],
						'diagnostics' => $diagnostics,
					];
				}

				$post_id = (int) $args['post_id'];
				if ( ! get_post( $post_id ) ) {
					return $this->error( 'not_found', __( 'Post not found.', 'stonewright' ) );
				}

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
					$new_tree = $atomic_tree;
				} else {
					$existing = ElementorData::read( $post_id );
					$new_tree = array_merge( $existing, $atomic_tree );
				}

				$snapshot_id = Backup::snapshot_post( $post_id );

				if ( ! ElementorData::write( $post_id, $new_tree ) ) {
					return $this->error( 'write_failed', __( 'Could not save Elementor data.', 'stonewright' ) );
				}

				return [
					'ok'          => true,
					'dry_run'     => false,
					'atomic_tree' => $atomic_tree,
					'snapshot_id' => $snapshot_id,
					'errors'      => [],
					'diagnostics' => $diagnostics,
				];
			}
		);
	}
}
