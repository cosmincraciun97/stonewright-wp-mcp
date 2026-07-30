<?php
declare( strict_types=1 );
namespace Stonewright\WpMcp\Abilities\Revisions;

use Stonewright\WpMcp\Abilities\AbilityKernel;
use Stonewright\WpMcp\Security\Permissions;
/** @stonewright-status stable */
final class PostRevisionList extends AbilityKernel {
	public function name(): string {
 return 'stonewright/post-revision-list'; }
	public function label(): string {
 return __( 'Revision: List', 'stonewright' ); }
	public function description(): string {
 return __( 'Lists revisions for a post.', 'stonewright' ); }
	public function category(): string {
 return 'revisions'; }
	public function input_schema(): array {
 return [ 'type'=>'object', 'additionalProperties'=>false, 'properties'=>[ 'post_id'=>[ 'type'=>'integer', 'minimum'=>1 ] ], 'required'=>[ 'post_id' ] ]; }
	public function output_schema(): array {
 return [ 'additionalProperties' => true, 'type'=>'object', 'properties'=>[ 'items'=>[ 'type'=>'array' ] ], 'required'=>[ 'items' ] ]; }
	public function permission_callback( array $args ): bool|\WP_Error {
		$id= (int) ( $args['post_id']??0 );
return $id>0 ? Permissions::edit_post($id) : false;
	}
	public function execute( array $args ): array|\WP_Error {
		return $this->audit($args, static function ( array $args ) {
			$revs=wp_get_post_revisions( (int) $args['post_id']);
$items=[];
			foreach ( (array) $revs as $rev ) {
				$items[]=[ 'id'=> (int) $rev->ID,'title'=> (string) $rev->post_title,'modified'=> (string) $rev->post_modified ];
			}
			return [ 'items'=>$items,'total'=>count($items) ];
		});
	}
}
