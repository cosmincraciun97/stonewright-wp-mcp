<?php
declare( strict_types=1 );
namespace Stonewright\WpMcp\Abilities\WooCommerce;

use Stonewright\WpMcp\Abilities\AbilityKernel;
use Stonewright\WpMcp\Security\Permissions;
/** @stonewright-status stable */
final class WcOrderList extends AbilityKernel {
	public function name(): string {
 return 'stonewright/wc-order-list'; }
	public function label(): string {
 return __( 'WooCommerce: Orders', 'stonewright' ); }
	public function description(): string {
 return __( 'Lists WooCommerce orders when WooCommerce is active.', 'stonewright' ); }
	public function category(): string {
 return 'woocommerce'; }
	public function input_schema(): array {
		return [
			'type'=>'object',
			'additionalProperties'=>false,
			'properties'=>[
				'status'=>[ 'type'=>'string' ],
				'per_page'=>[ 'type'=>'integer', 'minimum'=>1, 'maximum'=>50, 'default'=>20 ],
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
			$q=[ 'limit'=>min( (int) ( $args['per_page']??20 ), 50) ];
			if ( isset($args['status']) ) {
$q['status']= (string) $args['status'];
            }
			$orders=wc_get_orders($q);
$items=[];
			foreach ( (array) $orders as $o ) {
				$items[]=[ 'id'=> (int) $o->get_id(),'status'=> (string) $o->get_status(),'total'=> (string) $o->get_total(),'currency'=> (string) $o->get_currency(),'date_created'=> (string) $o->get_date_created(),'customer_id'=> (int) $o->get_customer_id() ];
			}
			return [ 'supported'=>true,'items'=>$items,'total'=>count($items) ];
		});
	}
}
