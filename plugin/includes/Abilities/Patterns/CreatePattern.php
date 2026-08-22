<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\Abilities\Patterns;

use Stonewright\WpMcp\Abilities\AbilityKernel;
use Stonewright\WpMcp\Abilities\Common\ConfirmationGuard;
use Stonewright\WpMcp\Security\Backup;
use Stonewright\WpMcp\Security\Permissions;

/**
 * Contract decision: keep output_schema aligned to the handler response shape.
 *
 * Write envelope: Permissions, Backup::snapshot_post after insert, ConfirmationGuard
 * in production-safe, sanitized post_content, AbilityKernel::audit().
 *
 * @stonewright-status stable
 */
final class CreatePattern extends AbilityKernel {
	use ConfirmationGuard;

	public function name(): string {
		return 'stonewright/patterns-create';
	}

	public function label(): string {
		return __( 'Create synced pattern', 'stonewright' );
	}

	public function description(): string {
		return __( 'Creates a synced pattern (wp_block CPT) from block content.', 'stonewright' );
	}

	public function category(): string {
		return 'patterns';
	}

	public function input_schema(): array {
		return [
			'type'                 => 'object',
			'additionalProperties' => false,
			'properties'           => [
				'title'              => [ 'type' => 'string', 'maxLength' => 255 ],
				'content'            => [ 'type' => 'string' ],
				'slug'               => [ 'type' => 'string', 'maxLength' => 200 ],
				'status'             => [ 'type' => 'string', 'enum' => [ 'publish', 'draft', 'private' ], 'default' => 'publish' ],
				'confirmation_token' => [ 'type' => 'string' ],
			],
			'required'             => [ 'title', 'content' ],
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
		];
	}

	public function permission_callback( array $args ): bool|\WP_Error {
		return Permissions::edit_posts() && PatternSupport::can_write();
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

				$content = PatternSupport::sanitize_content( (string) $args['content'] );
				if ( is_wp_error( $content ) ) {
					return $content;
				}

				$id = wp_insert_post(
					[
						'post_title'   => sanitize_text_field( (string) $args['title'] ),
						'post_name'    => isset( $args['slug'] ) ? sanitize_title( (string) $args['slug'] ) : '',
						'post_content' => $content,
						'post_status'  => (string) ( $args['status'] ?? 'publish' ),
						'post_type'    => 'wp_block',
					],
					true
				);

				if ( is_wp_error( $id ) ) {
					return $id;
				}

				$snapshot_id = Backup::snapshot_post( (int) $id );
				$post        = get_post( (int) $id );
				return [
					'id'          => (int) $id,
					'slug'        => $post ? (string) $post->post_name : '',
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
