<?php
declare( strict_types=1 );
namespace Stonewright\WpMcp\Abilities\Users;

use Stonewright\WpMcp\Abilities\AbilityKernel;
use Stonewright\WpMcp\Abilities\Common\ConfirmationGuard;
use Stonewright\WpMcp\Security\Permissions;
/** @stonewright-status stable */
final class UserAppPasswords extends AbilityKernel {
	use ConfirmationGuard;

	public function name(): string {
 return 'stonewright/user-app-passwords'; }
	public function label(): string {
 return __( 'User: Application passwords', 'stonewright' ); }
	public function description(): string {
 return __( 'List, create, or revoke application passwords for a user.', 'stonewright' ); }
	public function category(): string {
 return 'users'; }
	public function input_schema(): array {
		return [
			'type'=>'object',
			'additionalProperties'=>false,
			'properties'=>[
				'action'=>[ 'type'=>'string', 'enum'=>[ 'list', 'create', 'revoke' ] ],
				'user_id'=>[ 'type'=>'integer', 'minimum'=>1 ],
				'name'=>[ 'type'=>'string' ],
				'uuid'=>[ 'type'=>'string' ],
				'confirmation_token'=>[ 'type'=>'string' ],
			],
			'required'=>[ 'action', 'user_id' ],
		];
	}
	public function output_schema(): array {
 return [ 'type'=>'object', 'additionalProperties'=>true ]; }
	public function permission_callback( array $args ): bool|\WP_Error {
		$user_id = (int) ( $args['user_id'] ?? 0 );
		if ( $user_id <= 0 ) {
			return false;
		}
		// Require edit_user on the target account (covers multisite/custom roles),
		// not only manage_options for arbitrary user_ids.
		if ( ! current_user_can( 'edit_user', $user_id ) ) {
			return false;
		}
		$action = (string) ( $args['action'] ?? 'list' );
		if ( 'list' === $action ) {
			return true;
		}
		// Create/revoke also need manage_options for site-wide app-password admin.
		return Permissions::manage_options();
	}
	public function execute( array $args ): array|\WP_Error {
		return $this->audit($args, function ( array $args ) {
			$action= (string) $args['action'];
$uid= (int) $args['user_id'];
			if ( in_array($action, [ 'create','revoke' ], true) ) {
				$verify=$args;
unset($verify['confirmation_token']);
				$err=$this->confirmation_token_error($args, $verify);
if ( null!==$err ) {
return $err;
                }
			}
			if ( !class_exists('\\WP_Application_Passwords') ) {
				return new \WP_Error('stonewright_app_passwords_unavailable', 'Application passwords API unavailable.');
			}
			if ( 'list'===$action ) {
				$rows=\WP_Application_Passwords::get_user_application_passwords($uid);
				$items=[];
				foreach ( (array) $rows as $row ) {
					$items[]=[ 'uuid'=> (string) ( $row['uuid']??'' ),'name'=> (string) ( $row['name']??'' ),'created'=> (string) ( $row['created']??'' ),'last_used'=>$row['last_used']??null ];
				}
				return [ 'items'=>$items,'total'=>count($items) ];
			}
			if ( 'create'===$action ) {
				$name=trim( (string) ( $args['name']??'' ));
				if ( ''===$name ) {
return new \WP_Error('stonewright_app_password_name_required', 'name is required.');
                }
				$created=\WP_Application_Passwords::create_new_application_password($uid, [ 'name'=>$name ]);
				if ( is_wp_error($created) ) {
return $created;
                }
				return [ 'uuid'=> (string) ( $created[1]['uuid']??'' ),'name'=>$name,'password'=> (string) ( $created[0]??'' ),'note'=>'Store this now; it cannot be retrieved again.' ];
			}
			$uuid= (string) ( $args['uuid']??'' );
			if ( ''===$uuid ) {
return new \WP_Error('stonewright_app_password_uuid_required', 'uuid is required.');
            }
			$ok=\WP_Application_Passwords::delete_application_password($uid, $uuid);
			if ( is_wp_error($ok) ) {
return $ok;
            }
			return [ 'deleted'=>true,'uuid'=>$uuid ];
		});
	}
}
