<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\Abilities\Patterns;

use Stonewright\WpMcp\Abilities\AbilityKernel;
use Stonewright\WpMcp\Abilities\Common\ConfirmationGuard;
use Stonewright\WpMcp\Security\Backup;

/**
 * Updates a user synced pattern (wp_block). Compatibility fields stay closed.
 *
 * Envelope: Permissions, Backup::snapshot_post, ConfirmationGuard in
 * production-safe, sanitized post_content, AbilityKernel::audit().
 *
 * @stonewright-status stable
 */
final class UpdatePattern extends AbilityKernel {
	use ConfirmationGuard;

	public function name(): string {
		return 'stonewright/patterns-update';
	}

	public function label(): string {
		return __( 'Update synced pattern', 'stonewright' );
	}

	public function description(): string {
		return __( 'Updates a user-defined synced pattern (wp_block CPT). Snapshots before write.', 'stonewright' );
	}

	public function category(): string {
		return 'patterns';
	}

	public function input_schema(): array {
		return [
			'type'                 => 'object',
			'additionalProperties' => false,
			'properties'           => [
				'id'                 => [ 'type' => 'integer', 'minimum' => 1 ],
				'title'              => [ 'type' => 'string', 'maxLength' => 255 ],
				'content'            => [ 'type' => 'string' ],
				'status'             => [ 'type' => 'string', 'enum' => [ 'publish', 'draft', 'private' ] ],
				'confirmation_token' => [ 'type' => 'string' ],
			],
			'required'             => [ 'id' ],
		];
	}

	public function output_schema(): array {
		return [
			'type'       => 'object',
			'properties' => [
				'id'          => [ 'type' => 'integer' ],
				'slug'        => [ 'type' => 'string' ],
				'snapshot_id' => [ 'type' => 'string' ],
			],
			'required'   => [ 'id', 'snapshot_id' ],
		];
	}

	public function permission_callback( array $args ): bool|\WP_Error {
		return PatternSupport::can_write();
	}

	public function execute( array $args ): array|\WP_Error {
		return $this->audit(
			$args,
			function ( array $args ) {
				$verify = $args;
				unset( $verify['confirmation_token'] );
				$token_error = $this->confirmation_token_error( $args, $verify );
				if ( null !== $token_error ) {
					return $token_error;
				}

				$post = PatternSupport::require_pattern( (int) ( $args['id'] ?? 0 ) );
				if ( is_wp_error( $post ) ) {
					return $post;
				}

				$payload = [ 'ID' => (int) $post->ID ];
				if ( isset( $args['title'] ) ) {
					$payload['post_title'] = sanitize_text_field( (string) $args['title'] );
				}
				if ( isset( $args['status'] ) ) {
					$payload['post_status'] = (string) $args['status'];
				}
				if ( isset( $args['content'] ) ) {
					$content = PatternSupport::sanitize_content( (string) $args['content'] );
					if ( is_wp_error( $content ) ) {
						return $content;
					}
					$payload['post_content'] = $content;
				}

				$snapshot_id = Backup::snapshot_post( (int) $post->ID );
				$result      = wp_update_post( $payload, true );
				if ( is_wp_error( $result ) ) {
					return $result;
				}

				$fresh = get_post( (int) $post->ID );
				return [
					'id'          => (int) $post->ID,
					'slug'        => $fresh ? (string) $fresh->post_name : (string) $post->post_name,
					'snapshot_id' => $snapshot_id,
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
