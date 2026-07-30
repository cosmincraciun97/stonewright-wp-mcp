<?php
declare( strict_types=1 );
namespace Stonewright\WpMcp\Abilities\Users;

use Stonewright\WpMcp\Abilities\AbilityKernel;
use Stonewright\WpMcp\Security\Permissions;
/** @stonewright-status stable */
final class UserUpdate extends AbilityKernel {
	public function name(): string {
 return 'stonewright/user-update'; }
	public function label(): string {
 return __( 'User: Update', 'stonewright' ); }
	public function description(): string {
 return __( 'Updates a WordPress user (only provided fields).', 'stonewright' ); }
	public function category(): string {
 return 'users'; }
	public function input_schema(): array {
		return [
			'type'=>'object',
			'additionalProperties'=>false,
			'properties'=>[
				'id'=>[ 'type'=>'integer', 'minimum'=>1 ],
				'user_email'=>[ 'type'=>'string' ],
				'display_name'=>[ 'type'=>'string' ],
				'role'=>[ 'type'=>'string' ],
				'user_pass'=>[ 'type'=>'string', 'minLength'=>12 ],
			],
			'required'=>[ 'id' ],
		];
	}
	public function output_schema(): array {
 return [ 'additionalProperties' => true, 'type'=>'object', 'properties'=>[ 'id'=>[ 'type'=>'integer' ] ], 'required'=>[ 'id' ] ]; }
	public function permission_callback( array $args ): bool|\WP_Error {
 return Permissions::edit_users(); }
	public function execute( array $args ): array|\WP_Error {
		return $this->audit($args, static function ( array $args ) {
			$data=[ 'ID'=> (int) $args['id'] ];
			foreach ( [ 'user_email', 'display_name', 'role', 'user_pass' ] as $k ) {
if ( isset($args[ $k ]) ) {
$data[ $k ]= (string) $args[ $k ];
            }
}
			$r=wp_update_user($data);
			if ( is_wp_error($r) ) {
return $r;
            }
			return [ 'id'=> (int) $r ];
		});
	}
}
