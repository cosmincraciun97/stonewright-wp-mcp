<?php
declare( strict_types=1 );
namespace Stonewright\WpMcp\Abilities\Settings;

use Stonewright\WpMcp\Abilities\AbilityKernel;
use Stonewright\WpMcp\Abilities\Common\ConfirmationGuard;
use Stonewright\WpMcp\Security\Permissions;
/** @stonewright-status stable */
final class SettingsUpdate extends AbilityKernel {
	use ConfirmationGuard;

	public function name(): string {
 return 'stonewright/settings-update'; }
	public function label(): string {
 return __( 'Settings: Update', 'stonewright' ); }
	public function description(): string {
 return __( 'Updates allowlisted site settings. Blocks siteurl/home.', 'stonewright' ); }
	public function category(): string {
 return 'settings'; }
	public function input_schema(): array {
		return [
			'type'=>'object',
			'additionalProperties'=>false,
			'properties'=>[
				'settings'=>[ 'type'=>'object', 'additionalProperties'=>true ],
				'confirmation_token'=>[ 'type'=>'string' ],
			],
			'required'=>[ 'settings' ],
		];
	}
	public function output_schema(): array {
 return [ 'additionalProperties' => true, 'type'=>'object', 'properties'=>[ 'updated'=>[ 'type'=>'array' ] ], 'required'=>[ 'updated' ] ]; }
	public function permission_callback( array $args ): bool|\WP_Error {
 return Permissions::manage_options(); }
	public function execute( array $args ): array|\WP_Error {
		return $this->audit($args, function ( array $args ) {
			$verify=$args;
unset($verify['confirmation_token']);
			$err=$this->confirmation_token_error($args, $verify);
if ( null!==$err ) {
return $err;
            }
			$settings=is_array($args['settings']??null)?$args['settings']:[];
			$updated=[];
			foreach ( $settings as $key=>$value ) {
				$key= (string) $key;
				if ( in_array($key, [ 'siteurl','home' ], true) ) {
					return new \WP_Error('stonewright_settings_blocked_key', 'Updating siteurl/home is blocked.', [ 'key'=>$key ]);
				}
				if ( !in_array($key, SettingsGet::ALLOWLIST, true) ) {
continue;
                }
				update_option($key, $value);
				$updated[]=$key;
			}
			return [ 'updated'=>$updated ];
		});
	}
}
