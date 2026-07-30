<?php
declare( strict_types=1 );
namespace Stonewright\WpMcp\Abilities\Themes;

use Stonewright\WpMcp\Abilities\AbilityKernel;
use Stonewright\WpMcp\Security\Permissions;
/** @stonewright-status stable */
final class ThemeList extends AbilityKernel {
	public function name(): string {
 return 'stonewright/theme-list'; }
	public function label(): string {
 return __( 'Theme: List', 'stonewright' ); }
	public function description(): string {
 return __( 'Lists installed themes.', 'stonewright' ); }
	public function category(): string {
 return 'themes'; }
	public function input_schema(): array {
 return [ 'type'=>'object', 'additionalProperties'=>false, 'properties'=>[] ]; }
	public function output_schema(): array {
 return [ 'additionalProperties' => true, 'type'=>'object', 'properties'=>[ 'items'=>[ 'type'=>'array' ] ], 'required'=>[ 'items' ] ]; }
	public function permission_callback( array $args ): bool|\WP_Error {
 return Permissions::switch_themes() || Permissions::edit_theme_options(); }
	public function execute( array $args ): array|\WP_Error {
		return $this->audit($args, static function ( array $args ) {
			$themes = wp_get_themes();
$active = get_stylesheet();
$items=[];
			foreach ( $themes as $stylesheet=>$theme ) {
				$items[]=[ 'stylesheet'=> (string) $stylesheet,'name'=> (string) $theme->get('Name'),'status'=>$stylesheet===$active?'active':'inactive','version'=> (string) $theme->get('Version') ];
			}
			return [ 'items'=>$items,'total'=>count($items) ];
		});
	}
}
