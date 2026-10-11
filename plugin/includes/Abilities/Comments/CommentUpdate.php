<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\Abilities\Comments;

use Stonewright\WpMcp\Abilities\AbilityKernel;
use Stonewright\WpMcp\Security\Permissions;

/**
 * Updates comment content and/or moderation status (approve, hold, spam, trash).
 *
 * @stonewright-status stable
 */
final class CommentUpdate extends AbilityKernel {

	public function name(): string {
		return 'stonewright/comment-update';
	}

	public function label(): string {
		return __( 'Comment: Update', 'stonewright' );
	}

	public function description(): string {
		return __( 'Updates comment content and/or moderation status (approve, hold, spam, trash).', 'stonewright' );
	}

	public function category(): string {
		return 'comments';
	}

	public function input_schema(): array {
		return [
			'type'                 => 'object',
			'additionalProperties' => false,
			'properties'           => [
				'confirmation_token' => [ 'type' => 'string' ],
				'id'      => [ 'type' => 'integer', 'minimum' => 1 ],
				'content' => [ 'type' => 'string' ],
				'status'  => [
					'type' => 'string',
					'enum' => [ 'approve', 'hold', 'spam', 'trash' ],
				],
			],
			'required'             => [ 'id' ],
		];
	}

	public function output_schema(): array {
		return [
			'type'                 => 'object',
			'additionalProperties' => true,
			'properties'           => [
				'id'     => [ 'type' => 'integer' ],
				'status' => [ 'type' => 'string' ],
			],
			'required'             => [ 'id', 'status' ],
		];
	}

	public function permission_callback( array $args ): bool|\WP_Error {
		return Permissions::moderate_comments();
	}

	public function execute( array $args ): array|\WP_Error {
		return $this->audit_write(
			$args,
			static function ( array $args ) {
				$id = (int) $args['id'];
				if ( null === get_comment( $id ) ) {
					return new \WP_Error( 'stonewright_comment_not_found', 'Comment not found.', [ 'id' => $id ] );
				}
				if ( ! isset( $args['content'] ) && ! isset( $args['status'] ) ) {
					return new \WP_Error( 'stonewright_comment_nothing_to_update', 'Send content, status, or both.', [ 'status' => 400, 'id' => $id ] );
				}
				if ( isset( $args['content'] ) ) {
					// wp_update_comment takes slashed data and filters the content like a new comment. It returns 0 when
					// nothing changed, and false or an error when the write failed.
					$updated = wp_update_comment(
						wp_slash(
							[
								'comment_ID'      => $id,
								'comment_content' => (string) $args['content'],
							]
						),
						true
					);
					if ( false === $updated || $updated instanceof \WP_Error ) {
						return new \WP_Error( 'stonewright_comment_update_failed', 'The comment content could not be saved.', [ 'status' => 500, 'id' => $id ] );
					}
				}
				if ( isset( $args['status'] ) ) {
					$set = wp_set_comment_status( $id, (string) $args['status'], true );
					if ( false === $set || $set instanceof \WP_Error ) {
						return new \WP_Error( 'stonewright_comment_update_failed', 'The comment status could not be changed.', [ 'status' => 500, 'id' => $id ] );
					}
				}
				// Report what is stored, not what was asked for.
				$status = CommentStatus::of( get_comment( $id ) );
				if ( isset( $args['status'] ) && $status !== (string) $args['status'] ) {
					return new \WP_Error(
						'stonewright_comment_update_failed',
						'The comment status did not change to the requested value.',
						[ 'status' => 500, 'id' => $id, 'requested_status' => (string) $args['status'], 'stored_status' => $status ]
					);
				}
				return [
					'id'     => $id,
					'status' => $status,
				];
			}
		);
	}
}
