<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\Abilities\ElementorV4;

use Stonewright\WpMcp\Abilities\AbilityKernel;
use Stonewright\WpMcp\Elementor\V4\AtomicVariableRepositoryAdapter;
use Stonewright\WpMcp\Elementor\V4\V4FeatureGate;
use Stonewright\WpMcp\Security\Permissions;

/** @stonewright-status experimental */
final class ListVariables extends AbilityKernel {
	public function name(): string {
 return 'stonewright/elementor-v4-list-variables'; }
	public function label(): string {
 return __( 'List Elementor V4 variables', 'stonewright' ); }
	public function description(): string {
 return __( 'Lists variables through Elementor Variables_Service.', 'stonewright' ); }
	public function category(): string {
 return 'elementor'; }
	public function meta(): array {
 return [ 'experimental' => true ]; }
	public function output_schema(): array {
 return [ 'type' => 'object', 'properties' => [ 'items' => [ 'type' => 'object' ], 'source' => [ 'type' => 'string' ] ] ]; }
	public function permission_callback( array $args ): bool|\WP_Error {
 $gate = V4FeatureGate::check();
return is_wp_error( $gate ) ? $gate : Permissions::edit_posts(); }
	public function execute( array $args ): array|\WP_Error {
		return $this->audit( $args, function (): array|\WP_Error {
			$adapter = AtomicVariableRepositoryAdapter::runtime();
			if ( is_wp_error( $adapter ) ) {
return $adapter; }
			$items = $adapter->all();
			return is_wp_error( $items ) ? $items : [ 'items' => $items, 'source' => 'elementor_variables_service' ];
		} );
	}
}
