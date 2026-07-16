<?php
declare( strict_types=1 );
namespace Stonewright\WpMcp\Abilities\Users;

use Stonewright\WpMcp\Abilities\AbilityKernel;
use Stonewright\WpMcp\Abilities\Common\ConfirmationGuard;
use Stonewright\WpMcp\Security\Permissions;
/** @stonewright-status stable */
final class UserDelete extends AbilityKernel {
	use ConfirmationGuard;

	public function name(): string {
 return 'stonewright/user-delete'; }
	public function label(): string {
 return __( 'User: Delete', 'stonewright' ); }
	public function description(): string {
 return __( 'Deletes a user and reassigns content. Requires confirmation in production-safe mode.', 'stonewright' ); }
	public function category(): string {
 return 'users'; }
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
 return [ 'additionalProperties' => true, 'type'=>'object', 'properties'=>[ 'deleted'=>[ 'type'=>'boolean' ], 'id'=>[ 'type'=>'integer' ] ], 'required'=>[ 'deleted', 'id' ] ]; }
	public function permission_callback( array $args ): bool|\WP_Error {
 return Permissions::delete_users(); }
	public function execute( array $args ): array|\WP_Error {
		return $this->audit($args, function ( array $args ) {
			$verify=$args;
unset($verify['confirmation_token']);
			$err=$this->confirmation_token_error($args, $verify);
if ( null!==$err ) {
return $err;
            }
			$id= (int) $args['id'];
			if ( $id===get_current_user_id() ) {
return new \WP_Error('stonewright_user_self_delete', 'Cannot delete the current user.');
            }
			$ok=wp_delete_user($id, (int) $args['reassign']);
			if ( !$ok ) {
return new \WP_Error('stonewright_user_delete_failed', 'Could not delete user.');
            }
			return [ 'deleted'=>true,'id'=>$id ];
		});
	}
}
