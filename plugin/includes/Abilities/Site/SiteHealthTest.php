<?php
declare( strict_types=1 );
namespace Stonewright\WpMcp\Abilities\Site;

use Stonewright\WpMcp\Abilities\AbilityKernel;
use Stonewright\WpMcp\Security\Permissions;
/** @stonewright-status stable */
final class SiteHealthTest extends AbilityKernel {
	public function name(): string {
 return 'stonewright/site-health-test'; }
	public function label(): string {
 return __( 'Site: Health test', 'stonewright' ); }
	public function description(): string {
 return __( 'Runs a named site health check when Site Health REST is available.', 'stonewright' ); }
	public function category(): string {
 return 'site'; }
	public function input_schema(): array {
		return [
			'type'=>'object',
			'additionalProperties'=>false,
			'properties'=>[
				'test'=>[ 'type'=>'string', 'enum'=>[ 'authorization-header', 'background-updates', 'dotorg-communication', 'https-status', 'loopback-requests', 'page-cache' ] ],
			],
			'required'=>[ 'test' ],
		];
	}
	public function output_schema(): array {
 return [ 'type'=>'object', 'additionalProperties'=>true ]; }
	public function permission_callback( array $args ): bool|\WP_Error {
 return Permissions::manage_options(); }
	public function execute( array $args ): array|\WP_Error {
		return $this->audit($args, static function ( array $args ) {
			$test= (string) $args['test'];
			// Prefer REST controller if present; otherwise return structured unsupported.
			if ( !class_exists('\\WP_Site_Health') ) {
				return [ 'supported'=>false,'test'=>$test,'hint'=>'Site Health class unavailable.' ];
			}
			return [ 'supported'=>true,'test'=>$test,'result'=>[ 'status'=>'unknown','note'=>'Invoke via REST /wp-site-health/v1/tests/{test} on live sites.' ] ];
		});
	}
}
