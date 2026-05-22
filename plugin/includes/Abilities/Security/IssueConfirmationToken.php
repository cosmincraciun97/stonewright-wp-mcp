<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\Abilities\Security;

use Stonewright\WpMcp\Abilities\AbilityKernel;
use Stonewright\WpMcp\Security\ConfirmationToken;
use Stonewright\WpMcp\Security\Permissions;

/**
 * Issues a short-lived confirmation token for a destructive ability call.
 * Pass the returned token as confirmation_token to the target ability.
 * Contract decision: keep output_schema aligned to the handler response shape.
 *
 * @stonewright-status stable
 */
final class IssueConfirmationToken extends AbilityKernel {

	public function name(): string {
		return 'stonewright/security-issue-confirmation-token';
	}

	public function label(): string {
		return __( 'Issue confirmation token', 'stonewright' );
	}

	public function description(): string {
		return __( 'Issues a short-lived token required by destructive abilities when stonewright_mode is production-safe.', 'stonewright' );
	}

	public function category(): string {
		return 'security';
	}

	public function input_schema(): array {
		return [
			'type'                 => 'object',
			'additionalProperties' => false,
			'properties'           => [
				'ability'     => [ 'type' => 'string' ],
				'args'        => [ 'type' => 'object' ],
				'ttl_seconds' => [ 'type' => 'integer', 'minimum' => 1, 'maximum' => 3600, 'default' => 300 ],
			],
			'required'             => [ 'ability' ],
		];
	}

	public function output_schema(): array {
		return [
			'type'       => 'object',
			'properties' => [
				'token'      => [ 'type' => 'string' ],
				'expires_at' => [ 'type' => 'string' ],
			],
			'required'   => [ 'token', 'expires_at' ],
		];
	}

	public function permission_callback( array $args ): bool|\WP_Error {
		return Permissions::manage_options();
	}

	public function execute( array $args ): array|\WP_Error {
		$ability    = (string) $args['ability'];
		$inner_args = is_array( $args['args'] ?? null ) ? $args['args'] : [];
		$ttl        = isset( $args['ttl_seconds'] ) ? (int) $args['ttl_seconds'] : 300;

		$token      = ConfirmationToken::issue( $ability, $inner_args, $ttl );
		$expires_at = gmdate( 'c', time() + max( 60, min( 3600, $ttl ) ) );

		return [
			'token'      => $token,
			'expires_at' => $expires_at,
		];
	}
}
