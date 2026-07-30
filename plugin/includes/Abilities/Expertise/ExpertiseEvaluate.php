<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\Abilities\Expertise;

use Stonewright\WpMcp\Abilities\AbilityKernel;
use Stonewright\WpMcp\Expertise\ExpertiseEvaluator;
use Stonewright\WpMcp\Security\Permissions;

/** @stonewright-status experimental */
final class ExpertiseEvaluate extends AbilityKernel {

	public function name(): string {
 return 'stonewright/expertise-evaluate'; }
	public function label(): string {
 return __( 'Evaluate expertise pack', 'stonewright' ); }
	public function description(): string {
 return __( 'Runs the reproducible expertise eval corpus and returns score, critical failures, token/tool metrics, editability, semantics, and rollback.', 'stonewright' ); }
	public function category(): string {
 return 'expertise'; }
	public function input_schema(): array {
		return [
			'type'                 => 'object',
			'additionalProperties' => false,
			'required'             => [ 'id' ],
			'properties'           => [
				'id'       => [ 'type' => 'string' ],
				'persist'  => [ 'type' => 'boolean', 'default' => true ],
				'evidence' => [
					'type'                 => 'object',
					'additionalProperties' => false,
					'required'             => [ 'task_id', 'fixture_id', 'schema_hash', 'editor_verified', 'frontend_verified', 'readback_verified' ],
					'properties'           => [
						'task_id'           => [ 'type' => 'string' ],
						'fixture_id'        => [ 'type' => 'string' ],
						'schema_hash'       => [ 'type' => 'string', 'pattern' => '^[a-f0-9]{64}$' ],
						'editor_verified'   => [ 'type' => 'boolean' ],
						'frontend_verified' => [ 'type' => 'boolean' ],
						'readback_verified' => [ 'type' => 'boolean' ],
					],
				],
			],
		];
	}
	public function output_schema(): array {
 return [ 'type' => 'object', 'additionalProperties' => true ]; }
	public function permission_callback( array $args ): bool|\WP_Error {
 return Permissions::manage_options(); }
	public function execute( array $args ): array|\WP_Error {
		$runtime = \Stonewright\WpMcp\Expertise\RuntimeContext::capture();
		if ( is_array( $args['evidence'] ?? null ) ) {
			$runtime['verification_evidence'] = $args['evidence'];
		}
		return ExpertiseEvaluator::evaluate( (string) ( $args['id'] ?? '' ), $runtime, (bool) ( $args['persist'] ?? true ) );
	}
}
