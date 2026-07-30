<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\Abilities\Gutenberg;

use Stonewright\WpMcp\Abilities\AbilityKernel;
use Stonewright\WpMcp\Abilities\Common\ConfirmationGuard;
use Stonewright\WpMcp\DesignSpec\Validator;
use Stonewright\WpMcp\Gutenberg\Renderer;
use Stonewright\WpMcp\Security\Backup;
use Stonewright\WpMcp\Security\Permissions;

/**
 * Ability: stonewright/gutenberg.apply_to_post
 *
 * Security envelope (AGENTS.md rules 3, 4, 5):
 *   1. Validate spec via DesignSpec\Validator (rule 4).
 *   2. Backup::snapshot_post before write (rule 3).
 *   3. Render to Gutenberg block markup.
 *   4. wp_update_post.
 *   5. Confirmation token required in production-safe mode (rule 5).
 *
 * Permissions: can_manage_design() + can_edit_post($post_id).
 *
 * @stonewright-status stable
 */
final class ApplyToPost extends AbilityKernel {

	use ConfirmationGuard;

	public function name(): string {
		return 'stonewright/gutenberg-apply-to-post';
	}

	public function label(): string {
		return __( 'Apply design spec to post (Gutenberg)', 'stonewright' );
	}

	public function description(): string {
		return __( 'Validates a Stonewright Design Spec, takes a backup, renders to Gutenberg block markup, and updates the post content. Requires a confirmation token in production-safe mode.', 'stonewright' );
	}

	public function category(): string {
		return 'gutenberg';
	}

	public function input_schema(): array {
		return [
			'type'                 => 'object',
			'additionalProperties' => false,
			'properties'           => [
				'spec'               => [ 'type' => 'object', 'description' => 'Stonewright Design Spec.' ],
				'post_id'            => [ 'type' => 'integer', 'minimum' => 1, 'description' => 'Post to update.' ],
				'confirmation_token' => [ 'type' => 'string', 'description' => 'Required in production-safe mode.' ],
			],
			'required' => [ 'spec', 'post_id' ],
		];
	}

	public function output_schema(): array {
		return [
			'type'       => 'object',
			'properties' => [
				'post_id'     => [ 'type' => 'integer' ],
				'snapshot_id' => [ 'type' => 'string' ],
				'diagnostics' => [ 'type' => 'array', 'items' => [ 'type' => 'object' ] ],
				'spec_sha8'   => [ 'type' => 'string' ],
			],
			'required' => [ 'post_id', 'snapshot_id', 'diagnostics', 'spec_sha8' ],
		];
	}

	public function permission_callback( array $args ): bool|\WP_Error {
		if ( ! Permissions::can_manage_design() ) {
			return false;
		}
		$post_id = (int) ( $args['post_id'] ?? 0 );
		if ( $post_id <= 0 ) {
			return false;
		}
		return Permissions::can_edit_post( $post_id );
	}

	public function execute( array $args ): array|\WP_Error {
		return $this->audit(
			$args,
			function ( array $args ): array|\WP_Error {
				$post_id  = (int) ( $args['post_id'] ?? 0 );
				$raw_spec = $args['spec'] ?? null;

				if ( $post_id <= 0 ) {
					return $this->error( 'invalid_post_id', __( 'post_id must be a positive integer.', 'stonewright' ) );
				}
				if ( ! is_array( $raw_spec ) ) {
					return $this->error( 'missing_spec', __( 'The spec parameter is required and must be an object.', 'stonewright' ) );
				}

				// ── Confirmation token ───────────────────────────────────────────
				$verify_args = array_filter(
					$args,
					static fn( string $k ) => 'confirmation_token' !== $k,
					ARRAY_FILTER_USE_KEY
				);
				$token_error = $this->confirmation_token_error( $args, $verify_args );
				if ( null !== $token_error ) {
					return $token_error;
				}

				// ── Validate spec (AGENTS.md rule 4) ────────────────────────────
				$validated = \Stonewright\WpMcp\DesignSpec\Validator::validate( $raw_spec );
				if ( is_wp_error( $validated ) ) {
					return $validated;
				}

				// ── Backup BEFORE write (AGENTS.md rule 3) ───────────────────────
				$snapshot_id = Backup::snapshot_post( $post_id );

				// ── Render ───────────────────────────────────────────────────────
				$diagnostics = [];
				$block_dicts = Renderer::render( $validated, $diagnostics );

				$markup = '';
				foreach ( $block_dicts as $block ) {
					$markup .= serialize_block( $block );
				}

				// ── Write ────────────────────────────────────────────────────────
				$result = wp_update_post(
					[
						'ID'           => $post_id,
						'post_content' => $markup,
					],
					true
				);
				if ( is_wp_error( $result ) ) {
					return $result;
				}

				$spec_json = (string) wp_json_encode( $validated, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
				$spec_sha8 = substr( sha1( $spec_json ), 0, 8 );

				return [
					'post_id'     => $post_id,
					'snapshot_id' => $snapshot_id,
					'diagnostics' => $diagnostics,
					'spec_sha8'   => $spec_sha8,
				];
			}
		);
	}

	/**
	 * @return array<int, string>
	 */
	protected function audit_redacted_keys(): array {
		return array_merge( parent::audit_redacted_keys(), [ 'confirmation_token' ] );
	}
}
