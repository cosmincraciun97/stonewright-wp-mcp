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
 return [ 'type' => 'object', 'additionalProperties' => false, 'required' => [ 'id' ], 'properties' => [ 'id' => [ 'type' => 'string' ], 'persist' => [ 'type' => 'boolean', 'default' => true ] ] ]; }
	public function output_schema(): array {
 return [ 'type' => 'object', 'additionalProperties' => true ]; }
	public function permission_callback( array $args ): bool|\WP_Error {
 return Permissions::manage_options(); }
	public function execute( array $args ): array|\WP_Error {
 return ExpertiseEvaluator::evaluate( (string) ( $args['id'] ?? '' ), null, (bool) ( $args['persist'] ?? true ) ); }
}
