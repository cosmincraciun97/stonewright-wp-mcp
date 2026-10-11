<?php
declare( strict_types=1 );
namespace Stonewright\WpMcp\Abilities\Users;

use Stonewright\WpMcp\Abilities\AbilityKernel;
use Stonewright\WpMcp\Abilities\Common\ConfirmationGuard;
use Stonewright\WpMcp\Security\Permissions;
use Stonewright\WpMcp\Security\UserGuard;
/** @stonewright-status stable */
final class UserDelete extends AbilityKernel {
	use ConfirmationGuard;

	public function name(): string {
		return 'stonewright/user-delete';
	}
	public function label(): string {
		return __( 'User: Delete', 'stonewright' );
	}
	public function description(): string {
		return __( 'Deletes a user and reassigns their content to another existing user. Needs permission to delete that user, and never deletes the last administrator. Requires confirmation in production-safe mode.', 'stonewright' );
	}
	public function category(): string {
		return 'users';
	}
	public function input_schema(): array {
		return [
			'type'=>'object',
			'additionalProperties'=>false,
			'properties'=>[
				'id'=>[ 'type'=>'integer', 'minimum'=>1 ],
				'reassign'=>[ 'type'=>'integer', 'minimum'=>1 ],
				'confirmation_token'=>[ 'type'=>'string' ],
			],
			'required'=>[ 'id', 'reassign' ],
		];
	}
	public function output_schema(): array {
		return [ 'additionalProperties' => true, 'type'=>'object', 'properties'=>[ 'deleted'=>[ 'type'=>'boolean' ], 'id'=>[ 'type'=>'integer' ] ], 'required'=>[ 'deleted', 'id' ] ];
	}
	public function permission_callback( array $args ): bool|\WP_Error {
		return Permissions::delete_users() ? ( self::authorization_error( $args ) ?? true ) : false;
	}

	/**
	 * The current user may delete this account. Deleting oneself passes here; execute() answers it with the
	 * self-delete refusal.
	 *
	 * @param array<string, mixed> $args
	 */
	private static function authorization_error( array $args ): ?\WP_Error {
		$id = (int) ( $args['id'] ?? 0 );
		return $id === get_current_user_id() ? null : UserGuard::target_error( $id, 'delete_user' );
	}
	public function execute( array $args ): array|\WP_Error {
		return $this->audit(
			$args,
			function ( array $args ) {
				$id = (int) $args['id'];
				if ( $id === get_current_user_id() ) {
					return new \WP_Error( 'stonewright_user_self_delete', 'Cannot delete the current user.' );
				}
				if ( ! Permissions::delete_users() ) {
					return new \WP_Error( 'stonewright_user_target_forbidden', __( 'You are not allowed to delete users.', 'stonewright' ), [ 'status' => 403 ] );
				}
				$denied = UserGuard::target_error( $id, 'delete_user' )
					?? UserGuard::reassign_error( $id, (int) $args['reassign'] )
					?? UserGuard::last_administrator_error( $id );
				if ( null !== $denied ) {
					return $denied;
				}
				$verify = $args;
				unset( $verify['confirmation_token'] );
				$token_error = $this->confirmation_token_error( $args, $verify );
				if ( null !== $token_error ) {
					return $token_error;
				}
				$ok = wp_delete_user( $id, (int) $args['reassign'] );
				if ( ! $ok ) {
					return new \WP_Error( 'stonewright_user_delete_failed', 'Could not delete user.' );
				}
				return [ 'deleted' => true, 'id' => $id ];
			}
		);
	}
}
