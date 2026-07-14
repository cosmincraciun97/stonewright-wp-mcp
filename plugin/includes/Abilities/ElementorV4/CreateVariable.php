<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\Abilities\ElementorV4;

use Stonewright\WpMcp\Abilities\AbilityKernel;
use Stonewright\WpMcp\Elementor\V4\AtomicVariableRepositoryAdapter;
use Stonewright\WpMcp\Elementor\V4\V4FeatureGate;
use Stonewright\WpMcp\Security\Backup;
use Stonewright\WpMcp\Security\Permissions;

/** @stonewright-status experimental */
final class CreateVariable extends AbilityKernel {
	public function name(): string {
 return 'stonewright/elementor-v4-create-variable'; }
	public function label(): string {
 return __( 'Create Elementor V4 variable', 'stonewright' ); }
	public function description(): string {
 return __( 'Creates a variable through Elementor Variables_Service and verifies readback.', 'stonewright' ); }
	public function category(): string {
 return 'elementor'; }
	public function meta(): array {
 return [ 'experimental' => true ]; }
	public function input_schema(): array {
 return [ 'type' => 'object', 'additionalProperties' => false, 'required' => [ 'label', 'type', 'value' ], 'properties' => [ 'label' => [ 'type' => 'string', 'minLength' => 1 ], 'type' => [ 'type' => 'string', 'enum' => [ 'global-color-variable', 'global-font-variable', 'global-size-variable', 'global-custom-size-variable' ] ], 'value' => [] ] ]; }
	public function output_schema(): array {
 return [ 'type' => 'object', 'properties' => [ 'item' => [ 'type' => 'object' ], 'snapshot_id' => [ 'type' => 'string' ] ] ]; }
	public function permission_callback( array $args ): bool|\WP_Error {
 $gate = V4FeatureGate::check( true );
return is_wp_error( $gate ) ? $gate : Permissions::edit_theme_options(); }
	public function execute( array $args ): array|\WP_Error {
		return $this->audit( $args, function ( array $args ): array|\WP_Error {
			$kit_id = V4FeatureGate::active_kit_id();
			if ( 0 === $kit_id ) {
return $this->error( 'no_kit', 'No active Elementor kit found.' ); }
			$adapter = AtomicVariableRepositoryAdapter::runtime();
			if ( is_wp_error( $adapter ) ) {
return $adapter; }
			$snapshot_id = Backup::snapshot_post( $kit_id );
			$item = $adapter->create( [ 'label' => (string) $args['label'], 'type' => (string) $args['type'], 'value' => $args['value'] ] );
			return is_wp_error( $item ) ? $item : [ 'item' => $item, 'snapshot_id' => $snapshot_id ];
		} );
	}
}
