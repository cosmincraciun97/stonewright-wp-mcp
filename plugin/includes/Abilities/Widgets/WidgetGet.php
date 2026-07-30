<?php
declare( strict_types=1 );
namespace Stonewright\WpMcp\Abilities\Widgets;

use Stonewright\WpMcp\Abilities\AbilityKernel;
use Stonewright\WpMcp\Security\Permissions;
/** @stonewright-status stable */
final class WidgetGet extends AbilityKernel {
	public function name(): string {
 return 'stonewright/widget-get'; }
	public function label(): string {
 return __( 'Widget: Get', 'stonewright' ); }
	public function description(): string {
 return __( 'Gets one sidebar assignment list.', 'stonewright' ); }
	public function category(): string {
 return 'widgets'; }
	public function input_schema(): array {
 return [ 'type'=>'object', 'additionalProperties'=>false, 'properties'=>[ 'sidebar_id'=>[ 'type'=>'string' ] ], 'required'=>[ 'sidebar_id' ] ]; }
	public function output_schema(): array {
 return [ 'type'=>'object', 'additionalProperties'=>true ]; }
	public function permission_callback( array $args ): bool|\WP_Error {
 return Permissions::edit_theme_options(); }
	public function execute( array $args ): array|\WP_Error {
		return $this->audit($args, static function ( array $args ) {
			$sidebars=wp_get_sidebars_widgets();
$id= (string) $args['sidebar_id'];
			if ( !isset($sidebars[ $id ]) ) {
return new \WP_Error('stonewright_sidebar_not_found', 'Sidebar not found.');
            }
			return [ 'id'=>$id,'widgets'=>array_values(array_map('strval', (array) $sidebars[ $id ])) ];
		});
	}
}
