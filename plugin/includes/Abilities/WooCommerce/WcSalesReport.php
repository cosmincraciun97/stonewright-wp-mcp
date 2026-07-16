<?php
declare( strict_types=1 );
namespace Stonewright\WpMcp\Abilities\WooCommerce;

use Stonewright\WpMcp\Abilities\AbilityKernel;
use Stonewright\WpMcp\Security\Permissions;
/** @stonewright-status stable */
final class WcSalesReport extends AbilityKernel {
	public function name(): string {
 return 'stonewright/wc-sales-report'; }
	public function label(): string {
 return __( 'WooCommerce: Sales report', 'stonewright' ); }
	public function description(): string {
 return __( 'Returns a compact sales summary when WooCommerce is active.', 'stonewright' ); }
	public function category(): string {
 return 'woocommerce'; }
	public function input_schema(): array {
		return [
			'type'=>'object',
			'additionalProperties'=>false,
			'properties'=>[
				'period'=>[ 'type'=>'string' ],
			],
		];
	}
	public function output_schema(): array {
 return [ 'type'=>'object', 'additionalProperties'=>true ]; }
	public function permission_callback( array $args ): bool|\WP_Error {
 return Permissions::manage_woocommerce(); }
	public function execute( array $args ): array|\WP_Error {
		return $this->audit($args, static function ( array $args ) {
			if ( !function_exists('wc_get_orders') ) {
				return [ 'supported'=>false,'hint'=>'WooCommerce is not active on this site.' ];
			}
			// Compact summary without depending on internal report classes.
			$orders=wc_get_orders([ 'limit'=>50,'status'=>[ 'completed','processing' ] ]);
			$total=0.0;
$count=0;
			foreach ( (array) $orders as $o ) {
$total+= (float) $o->get_total();
$count++; }
			return [ 'supported'=>true,'order_count'=>$count,'gross_total'=>$total,'period'=> (string) ( $args['period']??'recent' ) ];
		});
	}
}
