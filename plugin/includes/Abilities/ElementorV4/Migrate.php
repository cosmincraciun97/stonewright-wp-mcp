<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\Abilities\ElementorV4;

use Stonewright\WpMcp\Abilities\AbilityKernel;
use Stonewright\WpMcp\Elementor\V4\MigrationPlanner;
use Stonewright\WpMcp\Elementor\V4\V4FeatureGate;
use Stonewright\WpMcp\Security\Backup;
use Stonewright\WpMcp\Security\Permissions;
use Stonewright\WpMcp\Support\ElementorData;

/** @stonewright-status experimental */
final class Migrate extends AbilityKernel {
	public function name(): string {
 return 'stonewright/elementor-v4-migrate'; }
	public function label(): string {
 return __( 'Plan or apply Elementor V3 to V4 migration', 'stonewright' ); }
	public function description(): string {
 return __( 'Inventories a page, returns an explicit loss report, and applies only a zero-loss V4 migration after approval.', 'stonewright' ); }
	public function category(): string {
 return 'elementor'; }
	public function meta(): array {
 return [ 'experimental' => true, 'destructive' => true ]; }
	public function input_schema(): array {
		return [
			'type'                 => 'object',
			'additionalProperties' => false,
			'required'             => [ 'post_id' ],
			'properties'           => [
				'post_id'           => [ 'type' => 'integer', 'minimum' => 1 ],
				'apply'             => [ 'type' => 'boolean', 'default' => false ],
				'confirm_migration' => [ 'type' => 'boolean' ],
			],
		];
	}
	public function output_schema(): array {
		return [
			'type' => 'object',
			'properties' => [
				'applied' => [ 'type' => 'boolean' ],
				'write_ready' => [ 'type' => 'boolean' ],
				'converted_tree' => [ 'type' => 'array' ],
				'loss_report' => [ 'type' => 'array' ],
				'counts' => [ 'type' => 'object' ],
				'snapshot_id' => [ 'type' => 'string' ],
				'readback_hash' => [ 'type' => 'string' ],
				'implicit_conversion' => [ 'type' => 'boolean' ],
				'schema_fingerprint' => [ 'type' => 'string' ],
			],
		];
	}
	public function permission_callback( array $args ): bool|\WP_Error {
		$gate = V4FeatureGate::check( (bool) ( $args['apply'] ?? false ) );
		return is_wp_error( $gate ) ? $gate : Permissions::edit_post( (int) ( $args['post_id'] ?? 0 ) );
	}
	public function execute( array $args ): array|\WP_Error {
		return $this->audit( $args, function ( array $args ): array|\WP_Error {
			$post_id = (int) $args['post_id'];
			if ( ! get_post( $post_id ) ) {
return $this->error( 'not_found', 'Post not found.' ); }
			$plan = MigrationPlanner::plan( ElementorData::read( $post_id ) );
			$plan['applied'] = false;
$plan['snapshot_id'] = '';
$plan['readback_hash'] = '';
			if ( ! (bool) ( $args['apply'] ?? false ) ) {
return $plan; }
			if ( true !== ( $args['confirm_migration'] ?? false ) ) {
return $this->error( 'stonewright_v4_migration_confirmation_required', 'Set confirm_migration=true after reviewing the loss report.' ); }
			if ( ! $plan['write_ready'] ) {
return $this->error( 'stonewright_v4_migration_has_loss', 'Migration is blocked because at least one element lacks a lossless V4 mapping.', [ 'loss_report' => $plan['loss_report'] ] ); }
			$snapshot_id = Backup::snapshot_post( $post_id );
			$tree = (array) $plan['converted_tree'];
			if ( ! ElementorData::write( $post_id, $tree ) ) {
Backup::restore( $post_id, $snapshot_id );
return $this->error( 'stonewright_v4_migration_write_failed', 'Migration write failed and the snapshot was restored.' ); }
			$readback = ElementorData::read( $post_id );
			$expected_hash = self::hash( $tree );
$readback_hash = self::hash( $readback );
			if ( $expected_hash !== $readback_hash ) {
Backup::restore( $post_id, $snapshot_id );
return $this->error( 'stonewright_v4_migration_readback_failed', 'Migration readback differed and the snapshot was restored.' ); }
			$plan['applied'] = true;
$plan['snapshot_id'] = $snapshot_id;
$plan['readback_hash'] = $readback_hash;
			return $plan;
		} );
	}
	/** @param array<int, mixed> $tree */
	private static function hash( array $tree ): string {
 $json = wp_json_encode( $tree, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
return hash( 'sha256', false === $json ? '' : $json ); }
}
