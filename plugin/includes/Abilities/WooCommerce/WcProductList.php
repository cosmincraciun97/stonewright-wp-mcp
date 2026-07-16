<?php
declare( strict_types=1 );
namespace Stonewright\WpMcp\Abilities\WooCommerce;

use Stonewright\WpMcp\Abilities\AbilityKernel;
use Stonewright\WpMcp\Security\Permissions;
/** @stonewright-status stable */
final class WcProductList extends AbilityKernel {
	public function name(): string {
 return 'stonewright/wc-product-list'; }
	public function label(): string {
 return __( 'WooCommerce: Products', 'stonewright' ); }
	public function description(): string {
 return __( 'Lists WooCommerce products when WooCommerce is active.', 'stonewright' ); }
	public function category(): string {
 return 'woocommerce'; }
	public function input_schema(): array {
		return [
			'type'=>'object',
			'additionalProperties'=>false,
			'properties'=>[
				'search'=>[ 'type'=>'string' ],
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
			if ( !class_exists('\\WooCommerce') && !function_exists('wc_get_products') ) {
				return [ 'supported'=>false,'hint'=>'WooCommerce is not active on this site.' ];
			}
			$q=[ 'limit'=>min( (int) ( $args['per_page']??20 ), 50),'return'=>'objects' ];
			if ( isset($args['status']) ) {
$q['status']= (string) $args['status'];
            }
			if ( isset($args['search']) ) {
$q['s']= (string) $args['search'];
            }
			$products=function_exists('wc_get_products')?wc_get_products($q):[];
			$items=[];
			foreach ( (array) $products as $p ) {
				$items[]=[ 'id'=> (int) $p->get_id(),'name'=> (string) $p->get_name(),'sku'=> (string) $p->get_sku(),'price'=> (string) $p->get_price(),'status'=> (string) $p->get_status(),'stock_status'=> (string) $p->get_stock_status() ];
			}
			return [ 'supported'=>true,'items'=>$items,'total'=>count($items) ];
		});
	}
}
