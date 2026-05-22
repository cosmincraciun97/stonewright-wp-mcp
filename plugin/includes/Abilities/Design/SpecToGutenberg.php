<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\Abilities\Design;

use Stonewright\WpMcp\Abilities\AbilityKernel;
use Stonewright\WpMcp\Abilities\Common\ConfirmationGuard;
use Stonewright\WpMcp\DesignSpec\Validator;
use Stonewright\WpMcp\Renderers\GutenbergSpecRenderer;
use Stonewright\WpMcp\Security\Backup;
use Stonewright\WpMcp\Security\Permissions;
use Stonewright\WpMcp\Support\BlockSerializer;

/**
 * Contract decision: keep output_schema aligned to the handler response shape.
 *
 * @stonewright-status stable
 */
final class SpecToGutenberg extends AbilityKernel {
	use ConfirmationGuard;

	public function name(): string {
		return 'stonewright/design-spec-to-gutenberg';
	}

	public function label(): string {
		return __( 'Render spec to Gutenberg', 'stonewright' );
	}

	public function description(): string {
		return __( 'Renders a Stonewright Design Spec into Gutenberg block content and writes it to a post.', 'stonewright' );
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
				'append'  => [ 'type' => 'boolean', 'default' => false ],
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
				'content'     => [ 'type' => 'string' ],
				'blocks'      => [ 'type' => 'integer' ],
				'diagnostics' => [ 'type' => 'array' ],
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

				$diagnostics = [];
				$blocks      = GutenbergSpecRenderer::render( $normalized, $diagnostics );
				if ( is_wp_error( $blocks ) ) {
					return $blocks;
				}
				$content = BlockSerializer::serialize( $blocks );

				if ( empty( $args['post_id'] ) || ! empty( $args['dry_run'] ) ) {
					return [
						'post_id'     => isset( $args['post_id'] ) ? (int) $args['post_id'] : 0,
						'snapshot_id' => '',
						'content'     => $content,
						'blocks'      => count( $blocks ),
						'diagnostics' => $diagnostics,
					];
				}

				$post_id = (int) $args['post_id'];
				$append  = ! empty( $args['append'] );

				$existing_post = get_post( $post_id );
				if ( ! $existing_post ) {
					return $this->error( 'not_found', __( 'Target post not found.', 'stonewright' ) );
				}

				if ( ! $append ) {
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

				$snapshot_id  = Backup::snapshot_post( $post_id );
				$next_content = $append ? trim( $existing_post->post_content . "\n\n" . $content ) : $content;

				$result = wp_update_post(
					[
						'ID'           => $post_id,
						'post_content' => $next_content,
					],
					true
				);
				if ( is_wp_error( $result ) ) {
					return $result;
				}

				return [
					'post_id'     => $post_id,
					'snapshot_id' => $snapshot_id,
					'content'     => $next_content,
					'blocks'      => count( $blocks ),
					'diagnostics' => $diagnostics,
				];
			}
		);
	}
}
