<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\Abilities\Expertise;

use Stonewright\WpMcp\Abilities\AbilityKernel;
use Stonewright\WpMcp\Expertise\ExpertiseRegistry;
use Stonewright\WpMcp\Security\Permissions;

/** @stonewright-status experimental */
final class ExpertiseList extends AbilityKernel {

	public function name(): string {
 return 'stonewright/expertise-list'; }
	public function label(): string {
 return __( 'List expertise packs', 'stonewright' ); }
	public function description(): string {
 return __( 'Lists compact expertise pack lifecycle, version, scorecard, and parity metadata for maintainers.', 'stonewright' ); }
	public function category(): string {
 return 'expertise'; }
	public function input_schema(): array {
 return [ 'type' => 'object', 'additionalProperties' => false, 'properties' => [ 'status' => [ 'type' => 'string' ], 'domain' => [ 'type' => 'string' ] ] ]; }
	public function output_schema(): array {
 return [ 'type' => 'object', 'required' => [ 'ok', 'packs', 'count' ], 'properties' => [ 'ok' => [ 'type' => 'boolean' ], 'packs' => [ 'type' => 'array' ], 'count' => [ 'type' => 'integer' ] ] ]; }
	public function permission_callback( array $args ): bool|\WP_Error {
 return Permissions::manage_options(); }

	public function execute( array $args ): array {
		$status = sanitize_key( (string) ( $args['status'] ?? '' ) );
		$domain = sanitize_key( (string) ( $args['domain'] ?? '' ) );
		$rows   = [];
		foreach ( ExpertiseRegistry::all() as $pack ) {
			if ( ( '' !== $status && $status !== (string) $pack['status'] ) || ( '' !== $domain && $domain !== (string) $pack['domain'] ) ) {
continue; }
			$rows[] = array_intersect_key( $pack, array_flip( [ 'id', 'domain', 'capability', 'version', 'status', 'tier', 'trigger', 'hash', 'last_verified_at' ] ) );
		}
		return [ 'ok' => true, 'packs' => $rows, 'count' => count( $rows ) ];
	}
}
