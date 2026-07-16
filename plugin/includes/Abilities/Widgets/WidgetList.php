<?php
declare( strict_types=1 );
namespace Stonewright\WpMcp\Abilities\Widgets;

use Stonewright\WpMcp\Abilities\AbilityKernel;
use Stonewright\WpMcp\Security\Permissions;
/** @stonewright-status stable */
final class WidgetList extends AbilityKernel {
	public function name(): string {
 return 'stonewright/widget-list'; }
	public function label(): string {
 return __( 'Widget: List', 'stonewright' ); }
	public function description(): string {
 return __( 'Lists sidebars and assigned widget ids.', 'stonewright' ); }
	public function category(): string {
 return 'widgets'; }
	public function input_schema(): array {
 return [ 'type'=>'object', 'additionalProperties'=>false, 'properties'=>[] ]; }
	public function output_schema(): array {
 return [ 'additionalProperties' => true, 'type'=>'object', 'properties'=>[ 'sidebars'=>[ 'type'=>'array' ] ], 'required'=>[ 'sidebars' ] ]; }
	public function permission_callback( array $args ): bool|\WP_Error {
 return Permissions::edit_theme_options(); }
	public function execute( array $args ): array|\WP_Error {
		return $this->audit($args, static function ( array $args ) {
			$sidebars = wp_get_sidebars_widgets();
			$out=[];
			foreach ( (array) $sidebars as $id=>$widgets ) {
				if ( 'wp_inactive_widgets'===$id || 'array_version'===$id ) {
continue;
                }
				$out[]=[ 'id'=> (string) $id,'widgets'=>array_values(array_map('strval', (array) $widgets)) ];
			}
			return [ 'sidebars'=>$out ];
		});
	}
}
