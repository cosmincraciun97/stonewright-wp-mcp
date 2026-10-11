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
 return __( 'Promotes, stales, or retires a site expertise pack through score, runtime, permission, audit, and confirmation gates. A promotion to verified or stable also needs an administrator to approve that pack in wp-admin; without that approval it returns an approval link and stops.', 'stonewright' ); }
	public function category(): string {
 return 'expertise'; }
	public function input_schema(): array {
		return [ 'type' => 'object', 'additionalProperties' => false, 'required' => [ 'id', 'target' ], 'properties' => [ 'id' => [ 'type' => 'string' ], 'target' => [ 'type' => 'string', 'enum' => [ 'candidate', 'verified', 'stable', 'stale', 'retired' ] ], 'maintainer_approved' => [ 'type' => 'boolean', 'default' => false, 'description' => 'Ignored. Promotion needs an administrator approval in wp-admin.' ], 'approval_note' => [ 'type' => 'string', 'description' => 'Ignored. The approving administrator writes the note in wp-admin.' ], 'confirmation_token' => [ 'type' => 'string' ] ] ];
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
				return ExpertisePromotion::promote( (string) ( $input['id'] ?? '' ), $target );
			}
		);
	}
}
