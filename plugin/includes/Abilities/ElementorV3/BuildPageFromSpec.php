<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\Abilities\ElementorV3;

use Stonewright\WpMcp\Abilities\AbilityKernel;
use Stonewright\WpMcp\Abilities\Common\ConfirmationGuard;
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
final class BuildPageFromSpec extends AbilityKernel {
	use ConfirmationGuard;

	public function name(): string {
		return 'stonewright/elementor-v3-build-page-from-spec';
	}

	public function label(): string {
		return __( 'Build Elementor page from Stonewright spec', 'stonewright' );
	}

	public function description(): string {
		return __( 'Renders a validated Stonewright Design Spec into Elementor V3 elements and writes it to a post.', 'stonewright' );
	}

	public function category(): string {
		return 'elementor';
	}

	public function input_schema(): array {
		return [
			'type'                 => 'object',
			'additionalProperties' => false,
			'properties'           => [
				'post_id' => [ 'type' => 'integer', 'minimum' => 1 ],
				'spec'               => [ 'type' => 'object' ],
				'replace'            => [ 'type' => 'boolean', 'default' => true ],
				'confirmation_token' => [ 'type' => 'string' ],
			],
			'required'             => [ 'post_id', 'spec' ],
		];
	}

	public function output_schema(): array {
		return [
			'type'       => 'object',
			'properties' => [
				'post_id'     => [ 'type' => 'integer' ],
				'snapshot_id' => [ 'type' => 'string' ],
				'elements'    => [ 'type' => 'integer' ],
				'diagnostics' => [ 'type' => 'array' ],
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

				$normalized = Validator::validate( (array) $args['spec'] );
				if ( is_wp_error( $normalized ) ) {
					return $normalized;
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
				// Backup before any mutation (AGENTS.md hard rule #3).
				$snapshot_id = Backup::snapshot_post( $post_id );
				$diagnostics = [];
				$rendered    = Renderer::render( $normalized, $diagnostics );
				$existing    = ElementorData::read( $post_id );

				$tree = $replace ? $rendered : array_merge( $existing, $rendered );

				if ( ! ElementorData::write( $post_id, $tree ) ) {
					return $this->error( 'write_failed', __( 'Could not save Elementor data.', 'stonewright' ) );
				}

				return [
					'post_id'     => $post_id,
					'snapshot_id' => $snapshot_id,
					'elements'    => count( ElementorData::flatten( $tree ) ),
					'diagnostics' => $diagnostics,
				];
			}
		);
	}
}
