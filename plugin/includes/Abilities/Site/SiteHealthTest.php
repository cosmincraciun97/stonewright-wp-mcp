<?php
declare( strict_types=1 );
namespace Stonewright\WpMcp\Abilities\Site;

use Stonewright\WpMcp\Abilities\AbilityKernel;
use Stonewright\WpMcp\Security\Permissions;
use Stonewright\WpMcp\Support\SiteHealthRunner;
/** @stonewright-status stable */
final class SiteHealthTest extends AbilityKernel {
	public function name(): string {
 return 'stonewright/site-health-test'; }
	public function label(): string {
 return __( 'Site: Health test', 'stonewright' ); }
	public function description(): string {
 return __( 'Runs one named WordPress Site Health check on the server and returns its status, label, description and badge.', 'stonewright' ); }
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
		return $this->audit_read($args, static function ( array $args ) {
			$test= (string) $args['test'];
			if ( ! class_exists( '\WP_Site_Health' ) && defined( 'ABSPATH' ) && is_file( ABSPATH . 'wp-admin/includes/class-wp-site-health.php' ) ) {
				require_once ABSPATH . 'wp-admin/includes/class-wp-site-health.php';
			}
			if ( ! class_exists( '\WP_Site_Health' ) ) {
				return [ 'supported'=>false,'test'=>$test,'hint'=>'Site Health class unavailable.' ];
			}
			SiteHealthRunner::load_admin_includes();
			$result = SiteHealthRunner::run_named_test( \WP_Site_Health::get_instance(), $test );
			if ( null === $result ) {
				return [ 'supported'=>false,'test'=>$test,'hint'=>'This WordPress version does not provide the "' . $test . '" Site Health test.' ];
			}
			return [ 'supported'=>true,'test'=>$test,'result'=>$result ];
		});
	}
}
