<?php
declare( strict_types=1 );
namespace Stonewright\WpMcp\Abilities\Revisions;

use Stonewright\WpMcp\Abilities\AbilityKernel;
use Stonewright\WpMcp\Security\Permissions;
/** @stonewright-status stable */
final class PostRevisionGet extends AbilityKernel {
	public function name(): string {
 return 'stonewright/post-revision-get'; }
	public function label(): string {
 return __( 'Revision: Get', 'stonewright' ); }
	public function description(): string {
 return __( 'Gets a single revision including content.', 'stonewright' ); }
	public function category(): string {
 return 'revisions'; }
	public function input_schema(): array {
 return [ 'type'=>'object', 'additionalProperties'=>false, 'properties'=>[ 'revision_id'=>[ 'type'=>'integer', 'minimum'=>1 ] ], 'required'=>[ 'revision_id' ] ]; }
	public function output_schema(): array {
 return [ 'type'=>'object', 'additionalProperties'=>true ]; }
	public function permission_callback( array $args ): bool|\WP_Error {
		$rev=get_post( (int) ( $args['revision_id']??0 ));
		if ( !$rev ) {
return false;
        }
		$parent= (int) $rev->post_parent;
return $parent>0 ? Permissions::edit_post($parent) : Permissions::edit_posts();
	}
	public function execute( array $args ): array|\WP_Error {
		return $this->audit($args, static function ( array $args ) {
			$rev=get_post( (int) $args['revision_id']);
			if ( !$rev || 'revision'!==$rev->post_type ) {
return new \WP_Error('stonewright_revision_not_found', 'Revision not found.');
            }
			return [ 'id'=> (int) $rev->ID,'parent'=> (int) $rev->post_parent,'title'=> (string) $rev->post_title,'content'=> (string) $rev->post_content,'modified'=> (string) $rev->post_modified ];
		});
	}
}
