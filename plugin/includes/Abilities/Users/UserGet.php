<?php
declare( strict_types=1 );
namespace Stonewright\WpMcp\Abilities\Users;

use Stonewright\WpMcp\Abilities\AbilityKernel;
use Stonewright\WpMcp\Security\Permissions;
/** @stonewright-status stable */
final class UserGet extends AbilityKernel {
	public function name(): string {
 return 'stonewright/user-get'; }
	public function label(): string {
 return __( 'User: Get', 'stonewright' ); }
	public function description(): string {
 return __( 'Gets a single WordPress user.', 'stonewright' ); }
	public function category(): string {
 return 'users'; }
	public function input_schema(): array {
 return [ 'type'=>'object', 'additionalProperties'=>false, 'properties'=>[ 'id'=>[ 'type'=>'integer', 'minimum'=>1 ] ], 'required'=>[ 'id' ] ]; }
	public function output_schema(): array {
 return [ 'type'=>'object', 'additionalProperties'=>true, 'properties'=>[ 'id'=>[ 'type'=>'integer' ] ], 'required'=>[ 'id' ] ]; }
	public function permission_callback( array $args ): bool|\WP_Error {
 return Permissions::list_users(); }
	public function execute( array $args ): array|\WP_Error {
		return $this->audit($args, static function ( array $args ) {
			$u=get_user_by('id', (int) $args['id']);
			if ( !$u ) {
return new \WP_Error('stonewright_user_not_found', 'User not found.');
            }
			return [ 'id'=> (int) $u->ID,'login'=> (string) $u->user_login,'display_name'=> (string) $u->display_name,'email'=> (string) $u->user_email,'roles'=>array_values( (array) $u->roles) ];
		});
	}
}
