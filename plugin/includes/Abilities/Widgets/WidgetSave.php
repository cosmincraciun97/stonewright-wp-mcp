<?php
declare( strict_types=1 );
namespace Stonewright\WpMcp\Abilities\Widgets;

use Stonewright\WpMcp\Abilities\AbilityKernel;
use Stonewright\WpMcp\Security\Permissions;
/** @stonewright-status stable */
final class WidgetSave extends AbilityKernel {
	public function name(): string {
 return 'stonewright/widget-save'; }
	public function label(): string {
 return __( 'Widget: Save sidebar', 'stonewright' ); }
	public function description(): string {
 return __( 'Replaces the widget id list for a sidebar.', 'stonewright' ); }
	public function category(): string {
 return 'widgets'; }
	public function input_schema(): array {
		return [
			'type'=>'object',
			'additionalProperties'=>false,
			'properties'=>[
				'sidebar_id'=>[ 'type'=>'string' ],
				'widgets'=>[ 'type'=>'array', 'items'=>[ 'type'=>'string' ] ],
			],
			'required'=>[ 'sidebar_id', 'widgets' ],
		];
	}
	public function output_schema(): array {
 return [ 'additionalProperties' => true, 'type'=>'object', 'properties'=>[ 'ok'=>[ 'type'=>'boolean' ] ], 'required'=>[ 'ok' ] ]; }
	public function permission_callback( array $args ): bool|\WP_Error {
 return Permissions::edit_theme_options(); }
	public function execute( array $args ): array|\WP_Error {
		return $this->audit($args, static function ( array $args ) {
			$sidebars=wp_get_sidebars_widgets();
			$sidebars[ (string) $args['sidebar_id'] ]=array_values(array_map('strval', (array) $args['widgets']));
			wp_set_sidebars_widgets($sidebars);
			return [ 'ok'=>true,'sidebar_id'=> (string) $args['sidebar_id'] ];
		});
	}
}
