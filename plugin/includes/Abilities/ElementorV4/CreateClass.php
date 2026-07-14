<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\Abilities\ElementorV4;

use Stonewright\WpMcp\Abilities\AbilityKernel;
use Stonewright\WpMcp\Elementor\V4\AtomicClassRepositoryAdapter;
use Stonewright\WpMcp\Elementor\V4\V4FeatureGate;
use Stonewright\WpMcp\Security\Backup;
use Stonewright\WpMcp\Security\Permissions;

/** @stonewright-status experimental */
final class CreateClass extends AbilityKernel {
	public function name(): string {
 return 'stonewright/elementor-v4-create-class'; }
	public function label(): string {
 return __( 'Create Elementor V4 class', 'stonewright' ); }
	public function description(): string {
 return __( 'Creates a validated Atomic global class using Elementor runtime storage and readback.', 'stonewright' ); }
	public function category(): string {
 return 'elementor'; }
	public function meta(): array {
 return [ 'experimental' => true ]; }
	public function input_schema(): array {
		return [
			'type' => 'object',
			'additionalProperties' => false,
			'required' => [ 'label', 'variants' ],
			'properties' => [
				'label' => [ 'type' => 'string', 'minLength' => 1 ],
				'variants' => [ 'type' => 'array', 'items' => [ 'type' => 'object' ] ],
			],
		];
	}
	public function output_schema(): array {
 return [ 'type' => 'object', 'properties' => [ 'item' => [ 'type' => 'object' ], 'snapshot_id' => [ 'type' => 'string' ] ] ]; }
	public function permission_callback( array $args ): bool|\WP_Error {
		$gate = V4FeatureGate::check( true );
		return is_wp_error( $gate ) ? $gate : Permissions::edit_theme_options();
	}
	public function execute( array $args ): array|\WP_Error {
		return $this->audit( $args, function ( array $args ): array|\WP_Error {
			$kit_id = V4FeatureGate::active_kit_id();
			if ( 0 === $kit_id ) {
return $this->error( 'no_kit', 'No active Elementor kit found.' ); }
			$adapter = AtomicClassRepositoryAdapter::runtime();
			if ( is_wp_error( $adapter ) ) {
return $adapter; }
			$id = 'sw-' . wp_generate_uuid4();
			$item = [ 'id' => $id, 'label' => (string) $args['label'], 'type' => 'class', 'variants' => (array) $args['variants'] ];
			$snapshot_id = Backup::snapshot_post( $kit_id );
			$readback = $adapter->create( $item );
			return is_wp_error( $readback ) ? $readback : [ 'item' => $readback, 'snapshot_id' => $snapshot_id ];
		} );
	}
}
