<?php
declare( strict_types=1 );
namespace Stonewright\WpMcp\Abilities\Widgets;

use Stonewright\WpMcp\Abilities\AbilityKernel;
use Stonewright\WpMcp\Abilities\Common\ConfirmationGuard;
use Stonewright\WpMcp\Security\Permissions;
/** @stonewright-status stable */
final class WidgetDelete extends AbilityKernel {
	use ConfirmationGuard;

	public function name(): string {
 return 'stonewright/widget-delete'; }
	public function label(): string {
 return __( 'Widget: Delete from sidebar', 'stonewright' ); }
	public function description(): string {
 return __( 'Removes a widget id from a sidebar.', 'stonewright' ); }
	public function category(): string {
 return 'widgets'; }
	public function input_schema(): array {
		return [
			'type'=>'object',
			'additionalProperties'=>false,
			'properties'=>[
				'sidebar_id'=>[ 'type'=>'string' ],
				'widget_id'=>[ 'type'=>'string' ],
				'confirmation_token'=>[ 'type'=>'string' ],
			],
			'required'=>[ 'sidebar_id', 'widget_id' ],
		];
	}
	public function output_schema(): array {
 return [ 'additionalProperties' => true, 'type'=>'object', 'properties'=>[ 'ok'=>[ 'type'=>'boolean' ] ], 'required'=>[ 'ok' ] ]; }
	public function permission_callback( array $args ): bool|\WP_Error {
 return Permissions::edit_theme_options(); }
	public function execute( array $args ): array|\WP_Error {
		return $this->audit($args, function ( array $args ) {
			$verify=$args;
unset($verify['confirmation_token']);
			$err=$this->confirmation_token_error($args, $verify);
if ( null!==$err ) {
return $err;
            }
			$sidebars=wp_get_sidebars_widgets();
$sid= (string) $args['sidebar_id'];
$wid= (string) $args['widget_id'];
			$widgets=array_values(array_filter(array_map('strval', (array) ( $sidebars[ $sid ]??[] )), static fn( $w )=>$w!==$wid));
			$sidebars[ $sid ]=$widgets;
wp_set_sidebars_widgets($sidebars);
			return [ 'ok'=>true,'sidebar_id'=>$sid,'widget_id'=>$wid ];
		});
	}
}
