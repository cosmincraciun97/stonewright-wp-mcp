<?php
declare( strict_types=1 );
namespace Stonewright\WpMcp\Abilities\Users;

use Stonewright\WpMcp\Abilities\AbilityKernel;
use Stonewright\WpMcp\Security\Permissions;
/** @stonewright-status stable */
final class UserCreate extends AbilityKernel {
	public function name(): string {
 return 'stonewright/user-create'; }
	public function label(): string {
 return __( 'User: Create', 'stonewright' ); }
	public function description(): string {
 return __( 'Creates a WordPress user. Never returns the password.', 'stonewright' ); }
	public function category(): string {
 return 'users'; }
	public function input_schema(): array {
		return [
			'type'=>'object',
			'additionalProperties'=>false,
			'properties'=>[
				'user_login'=>[ 'type'=>'string' ],
				'user_email'=>[ 'type'=>'string' ],
				'user_pass'=>[ 'type'=>'string', 'minLength'=>12 ],
				'role'=>[ 'type'=>'string' ],
				'display_name'=>[ 'type'=>'string' ],
			],
			'required'=>[ 'user_login', 'user_email', 'user_pass' ],
		];
	}
	public function output_schema(): array {
 return [ 'additionalProperties' => true, 'type'=>'object', 'properties'=>[ 'id'=>[ 'type'=>'integer' ] ], 'required'=>[ 'id' ] ]; }
	public function permission_callback( array $args ): bool|\WP_Error {
 return Permissions::create_users(); }
	public function execute( array $args ): array|\WP_Error {
		return $this->audit($args, static function ( array $args ) {
			$data=[ 'user_login'=> (string) $args['user_login'],'user_email'=> (string) $args['user_email'],'user_pass'=> (string) $args['user_pass'] ];
			if ( isset($args['role']) ) {
$data['role']= (string) $args['role'];
            }
			if ( isset($args['display_name']) ) {
$data['display_name']= (string) $args['display_name'];
            }
			$id=wp_insert_user($data);
			if ( is_wp_error($id) ) {
return $id;
            }
			return [ 'id'=> (int) $id ];
		});
	}
}
