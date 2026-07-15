<?php
declare( strict_types=1 );
namespace Stonewright\WpMcp\Abilities\Users;

use Stonewright\WpMcp\Abilities\AbilityKernel;
use Stonewright\WpMcp\Security\Permissions;
/** @stonewright-status stable */
final class UserList extends AbilityKernel {
	public function name(): string {
 return 'stonewright/user-list'; }
	public function label(): string {
 return __( 'User: List', 'stonewright' ); }
	public function description(): string {
 return __( 'Lists WordPress users.', 'stonewright' ); }
	public function category(): string {
 return 'users'; }
	public function input_schema(): array {
		return [
			'type'=>'object',
			'additionalProperties'=>false,
			'properties'=>[
				'search'=>[ 'type'=>'string' ],
				'role'=>[ 'type'=>'string' ],
				'number'=>[ 'type'=>'integer', 'minimum'=>1, 'maximum'=>100, 'default'=>20 ],
			],
		];
	}
	public function output_schema(): array {
 return [ 'additionalProperties' => true, 'type'=>'object', 'properties'=>[ 'items'=>[ 'type'=>'array' ], 'total'=>[ 'type'=>'integer' ] ], 'required'=>[ 'items', 'total' ] ]; }
	public function permission_callback( array $args ): bool|\WP_Error {
 return Permissions::list_users(); }
	public function execute( array $args ): array|\WP_Error {
		return $this->audit($args, static function ( array $args ) {
			$q=[ 'number'=>min( (int) ( $args['number']??20 ), 100) ];
			if ( isset($args['search']) ) {
$q['search']= (string) $args['search'];
            }
			if ( isset($args['role']) ) {
$q['role']= (string) $args['role'];
            }
			$users=get_users($q);
$items=[];
			foreach ( (array) $users as $u ) {
				$items[]=[ 'id'=> (int) $u->ID,'login'=> (string) $u->user_login,'display_name'=> (string) $u->display_name,'roles'=>array_values( (array) $u->roles),'email'=> (string) $u->user_email ];
			}
			return [ 'items'=>$items,'total'=>count($items) ];
		});
	}
}
