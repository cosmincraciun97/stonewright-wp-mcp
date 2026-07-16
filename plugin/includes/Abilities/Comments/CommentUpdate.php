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
		return $this->audit(
			$args,
			static function ( array $args ) {
				$id = (int) $args['id'];
				if ( null === get_comment( $id ) ) {
					return new \WP_Error( 'stonewright_comment_not_found', 'Comment not found.', [ 'id' => $id ] );
				}
				if ( isset( $args['content'] ) ) {
					wp_update_comment(
						[
							'comment_ID'      => $id,
							'comment_content' => (string) $args['content'],
						]
					);
				}
				if ( isset( $args['status'] ) ) {
					wp_set_comment_status( $id, (string) $args['status'] );
				}
				$comment = get_comment( $id );
				$status  = isset( $args['status'] )
					? (string) $args['status']
					: ( is_object( $comment ) ? (string) $comment->comment_approved : '' );
				return [
					'id'     => $id,
					'status' => $status,
				];
			}
		);
	}
}
