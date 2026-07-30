<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\Abilities\ElementorV4;

use Stonewright\WpMcp\Abilities\AbilityKernel;
use Stonewright\WpMcp\Elementor\V4\AtomicClassRepositoryAdapter;
use Stonewright\WpMcp\Elementor\V4\V4FeatureGate;
use Stonewright\WpMcp\Security\Backup;
use Stonewright\WpMcp\Security\Permissions;

/** @stonewright-status experimental */
final class UpdateClass extends AbilityKernel {
	public function name(): string {
 return 'stonewright/elementor-v4-update-class'; }
	public function label(): string {
 return __( 'Update Elementor V4 class', 'stonewright' ); }
	public function description(): string {
 return __( 'Replaces a validated Atomic global class through Elementor runtime storage and verifies readback.', 'stonewright' ); }
	public function category(): string {
 return 'elementor'; }
	public function meta(): array {
 return [ 'experimental' => true ]; }
	public function input_schema(): array {
 return [ 'type' => 'object', 'additionalProperties' => false, 'required' => [ 'id', 'label', 'variants' ], 'properties' => [ 'id' => [ 'type' => 'string', 'minLength' => 1 ], 'label' => [ 'type' => 'string', 'minLength' => 1 ], 'variants' => [ 'type' => 'array', 'items' => [ 'type' => 'object' ] ] ] ]; }
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
			$adapter = AtomicClassRepositoryAdapter::runtime();
			if ( is_wp_error( $adapter ) ) {
return $adapter; }
			$id = (string) $args['id'];
			$item = [ 'id' => $id, 'label' => (string) $args['label'], 'type' => 'class', 'variants' => (array) $args['variants'] ];
			$snapshot_id = Backup::snapshot_post( $kit_id );
			$readback = $adapter->update( $id, $item );
			return is_wp_error( $readback ) ? $readback : [ 'item' => $readback, 'snapshot_id' => $snapshot_id ];
		} );
	}
}
