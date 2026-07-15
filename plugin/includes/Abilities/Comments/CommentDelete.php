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
 return __( 'Deletes a comment. Requires confirmation token in production-safe mode.', 'stonewright' ); }
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
		return [ 'additionalProperties' => true, 'type' => 'object', 'properties' => [ 'deleted' => [ 'type' => 'boolean' ], 'id' => [ 'type' => 'integer' ] ], 'required' => [ 'deleted', 'id' ] ];
	}
	public function permission_callback( array $args ): bool|\WP_Error {
 return Permissions::moderate_comments(); }
	public function execute( array $args ): array|\WP_Error {
		return $this->audit( $args, function ( array $args ) {
			$verify = $args;
			unset( $verify['confirmation_token'] );
			$token_error = $this->confirmation_token_error( $args, $verify );
			if ( null !== $token_error ) {
				return $token_error;
			}
			$id = (int) $args['id'];
			$ok = wp_delete_comment( $id, ! empty( $args['force'] ) );
			if ( ! $ok ) {
				return new \WP_Error( 'stonewright_comment_delete_failed', 'Could not delete comment.', [ 'id' => $id ] );
			}
			return [ 'deleted' => true, 'id' => $id ];
		} );
	}
}
