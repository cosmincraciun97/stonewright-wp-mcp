<?php
declare( strict_types=1 );
namespace Stonewright\WpMcp\Abilities\Search;

use Stonewright\WpMcp\Abilities\AbilityKernel;
use Stonewright\WpMcp\Security\Permissions;
/** @stonewright-status stable */
final class OembedResolve extends AbilityKernel {
	public function name(): string {
 return 'stonewright/oembed-resolve'; }
	public function label(): string {
 return __( 'oEmbed: Resolve', 'stonewright' ); }
	public function description(): string {
 return __( 'Resolves oEmbed HTML for a URL.', 'stonewright' ); }
	public function category(): string {
 return 'search'; }
	public function input_schema(): array {
 return [ 'type'=>'object', 'additionalProperties'=>false, 'properties'=>[ 'url'=>[ 'type'=>'string' ] ], 'required'=>[ 'url' ] ]; }
	public function output_schema(): array {
 return [ 'type'=>'object', 'additionalProperties'=>true ]; }
	public function permission_callback( array $args ): bool|\WP_Error {
 return Permissions::read(); }
	public function execute( array $args ): array|\WP_Error {
		return $this->audit($args, static function ( array $args ) {
			$url= (string) $args['url'];
			$html=wp_oembed_get($url);
			if ( false===$html ) {
return [ 'supported'=>false,'url'=>$url ];
            }
			return [ 'supported'=>true,'url'=>$url,'html'=> (string) $html ];
		});
	}
}
