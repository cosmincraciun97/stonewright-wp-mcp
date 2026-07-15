<?php
declare( strict_types=1 );
namespace Stonewright\WpMcp\Abilities\PluginsManage;

use Stonewright\WpMcp\Abilities\AbilityKernel;
use Stonewright\WpMcp\Abilities\Common\ConfirmationGuard;
use Stonewright\WpMcp\Security\Permissions;
/** @stonewright-status stable */
final class PluginDeactivate extends AbilityKernel {
	use ConfirmationGuard;

	public function name(): string {
 return 'stonewright/plugin-deactivate'; }
	public function label(): string {
 return __( 'Plugin: Deactivate', 'stonewright' ); }
	public function description(): string {
 return __( 'Deactivates a plugin. Cannot deactivate Stonewright itself.', 'stonewright' ); }
	public function category(): string {
 return 'plugins'; }
	public function input_schema(): array {
		return [
			'type'=>'object',
			'additionalProperties'=>false,
			'properties'=>[
				'plugin'=>[ 'type'=>'string' ],
				'confirmation_token'=>[ 'type'=>'string' ],
			],
			'required'=>[ 'plugin' ],
		];
	}
	public function output_schema(): array {
 return [ 'additionalProperties' => true, 'type'=>'object', 'properties'=>[ 'plugin'=>[ 'type'=>'string' ], 'active'=>[ 'type'=>'boolean' ] ], 'required'=>[ 'plugin', 'active' ] ]; }
	public function permission_callback( array $args ): bool|\WP_Error {
 return Permissions::activate_plugins(); }
	public function execute( array $args ): array|\WP_Error {
		return $this->audit($args, function ( array $args ) {
			$verify=$args;
unset($verify['confirmation_token']);
			$err=$this->confirmation_token_error($args, $verify);
if ( null!==$err ) {
return $err;
            }
			$plugin= (string) $args['plugin'];
			if ( str_contains($plugin, 'stonewright') ) {
				return new \WP_Error('stonewright_self_protection', 'Cannot deactivate Stonewright from itself.');
			}
			deactivate_plugins($plugin);
			return [ 'plugin'=>$plugin,'active'=>is_plugin_active($plugin) ];
		});
	}
}
