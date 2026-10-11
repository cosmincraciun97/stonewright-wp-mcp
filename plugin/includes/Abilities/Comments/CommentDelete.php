<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\Abilities\Comments;

use Stonewright\WpMcp\Abilities\AbilityKernel;
use Stonewright\WpMcp\Abilities\Common\ConfirmationGuard;
use Stonewright\WpMcp\Security\Permissions;

/** @stonewright-status stable */
final class CommentDelete extends AbilityKernel {
	use ConfirmationGuard;

	public function name(): string {
 return 'stonewright/comment-delete'; }
	public function label(): string {
 return __( 'Comment: Delete', 'stonewright' ); }
	public function description(): string {
 return __( 'Moves a comment to the trash, or deletes it permanently with force. A comment that is already in the trash or spam is only deleted with force. Requires confirmation token in production-safe mode.', 'stonewright' ); }
	public function category(): string {
 return 'comments'; }
	public function input_schema(): array {
		return [
			'type' => 'object',
			'additionalProperties' => false,
			'properties' => [
				'id'                 => [ 'type' => 'integer', 'minimum' => 1 ],
				'force'              => [ 'type' => 'boolean', 'default' => false ],
				'confirmation_token' => [ 'type' => 'string' ],
			],
			'required' => [ 'id' ],
		];
	}
	public function output_schema(): array {
		return [ 'additionalProperties' => true, 'type' => 'object', 'properties' => [ 'deleted' => [ 'type' => 'boolean', 'description' => 'True when the comment was removed for good.' ], 'trashed' => [ 'type' => 'boolean', 'description' => 'True when the comment was moved to the trash and can still be restored.' ], 'id' => [ 'type' => 'integer' ] ], 'required' => [ 'deleted', 'trashed', 'id' ] ];
	}
	public function permission_callback( array $args ): bool|\WP_Error {
		if ( ! Permissions::moderate_comments() ) {
			return false;
		}
		return CommentAccess::refusal( (int) ( $args['id'] ?? 0 ) ) ?? true;
	}
	public function execute( array $args ): array|\WP_Error {
		return $this->audit( $args, function ( array $args ) {
			// Before the token is checked, so a refused call does not spend it.
			$refusal = CommentAccess::refusal( (int) ( $args['id'] ?? 0 ) );
			if ( null !== $refusal ) {
				return $refusal;
			}
			$verify = $args;
			unset( $verify['confirmation_token'] );
			$token_error = $this->confirmation_token_error( $args, $verify );
			if ( null !== $token_error ) {
				return $token_error;
			}
			$id      = (int) $args['id'];
			$force   = ! empty( $args['force'] );
			$comment = get_comment( $id );
			// Core deletes a comment for good when it is already in the trash or spam, whatever force says.
			// That is not what a caller who did not ask for force expects, so it is refused.
			if ( ! $force && in_array( CommentStatus::of( $comment ), [ 'trash', 'spam' ], true ) ) {
				return new \WP_Error( 'stonewright_comment_force_required', 'This comment is already in the trash or spam. Pass force: true to delete it permanently.', [ 'status' => 409, 'id' => $id ] );
			}
			$ok = wp_delete_comment( $id, $force );
			if ( ! $ok ) {
				return new \WP_Error( 'stonewright_comment_delete_failed', 'Could not delete comment.', [ 'id' => $id ] );
			}
			// Report what happened: with the trash off, core removes the comment even without force.
			$after   = get_comment( $id );
			$deleted = null === $after;
			return [ 'deleted' => $deleted, 'trashed' => ! $deleted && 'trash' === CommentStatus::of( $after ), 'id' => $id ];
		} );
	}
}
