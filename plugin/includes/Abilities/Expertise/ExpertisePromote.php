<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\Abilities\Expertise;

use Stonewright\WpMcp\Abilities\AbilityKernel;
use Stonewright\WpMcp\Abilities\Common\ConfirmationGuard;
use Stonewright\WpMcp\Expertise\ExpertisePromotion;
use Stonewright\WpMcp\Security\Permissions;

/** @stonewright-status experimental */
final class ExpertisePromote extends AbilityKernel {

	use ConfirmationGuard;

	public function name(): string {
 return 'stonewright/expertise-promote'; }
	public function label(): string {
 return __( 'Promote expertise pack', 'stonewright' ); }
	public function description(): string {
 return __( 'Promotes, stales, or retires a site expertise pack through score, runtime, permission, audit, and confirmation gates.', 'stonewright' ); }
	public function category(): string {
 return 'expertise'; }
	public function input_schema(): array {
		return [ 'type' => 'object', 'additionalProperties' => false, 'required' => [ 'id', 'target' ], 'properties' => [ 'id' => [ 'type' => 'string' ], 'target' => [ 'type' => 'string', 'enum' => [ 'candidate', 'verified', 'stable', 'stale', 'retired' ] ], 'maintainer_approved' => [ 'type' => 'boolean', 'default' => false ], 'approval_note' => [ 'type' => 'string' ], 'confirmation_token' => [ 'type' => 'string' ] ] ];
	}
	public function output_schema(): array {
 return [ 'type' => 'object', 'additionalProperties' => true ]; }
	public function permission_callback( array $args ): bool|\WP_Error {
 return Permissions::manage_options(); }

	public function execute( array $args ): array|\WP_Error {
		$verify_args = array_filter( $args, static fn( string $key ): bool => 'confirmation_token' !== $key, ARRAY_FILTER_USE_KEY );
		$error       = $this->confirmation_token_error( $args, $verify_args );
		if ( $error instanceof \WP_Error ) {
return $error; }
		return $this->audit(
			$args,
			static function ( array $input ): array|\WP_Error {
				$target = (string) ( $input['target'] ?? '' );
				if ( in_array( $target, [ 'stale', 'retired' ], true ) ) {
return ExpertisePromotion::set_terminal_status( (string) ( $input['id'] ?? '' ), $target ); }
				return ExpertisePromotion::promote( (string) ( $input['id'] ?? '' ), $target, (bool) ( $input['maintainer_approved'] ?? false ), (string) ( $input['approval_note'] ?? '' ) );
			}
		);
	}
}
