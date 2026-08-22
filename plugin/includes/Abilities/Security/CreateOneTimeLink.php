<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\Abilities\Security;

use Stonewright\WpMcp\Abilities\AbilityKernel;
use Stonewright\WpMcp\Abilities\Common\ConfirmationGuard;
use Stonewright\WpMcp\Security\OneTimeLink;
use Stonewright\WpMcp\Security\Permissions;

/**
 * Creates a one-time admin access link for browser automation.
 *
 * @stonewright-status stable
 */
final class CreateOneTimeLink extends AbilityKernel {
	use ConfirmationGuard;

	public function name(): string {
		return 'stonewright/security-create-one-time-link';
	}

	public function label(): string {
		return __( 'Create one-time admin access link', 'stonewright' );
	}

	public function description(): string {
		return __( 'Generates a short-lived, single-use admin login URL for browser automation tools. The link logs in the current admin user and expires after ttl_seconds (default 300). Requires confirmation in production-safe mode.', 'stonewright' );
	}

	public function category(): string {
		return 'security';
	}

	public function input_schema(): array {
		return [
			'type'                 => 'object',
			'additionalProperties' => false,
			'properties'           => [
				'ttl_seconds'        => [
					'type'        => 'integer',
					'description' => 'How long the link is valid (seconds). Default: 300. Max: 3600.',
					'default'     => 300,
					'minimum'     => 30,
					'maximum'     => 3600,
				],
				'user_agent'         => [
					'type'        => 'string',
					'description' => 'Browser User-Agent that will redeem the link. Pass the automation browser UA; defaults to the MCP client UA, which Playwright cannot match.',
					'maxLength'   => 512,
				],
				'confirmation_token' => [
					'type'        => 'string',
					'description' => 'Required in production-safe mode because a one-time admin login link is a privileged credential.',
				],
			],
		];
	}

	public function output_schema(): array {
		return [
			'type'       => 'object',
			'properties' => [
				'url'        => [ 'type' => 'string', 'description' => 'The one-time admin access URL.' ],
				'expires_in' => [ 'type' => 'integer', 'description' => 'Seconds until the link expires.' ],
			],
			'required'   => [ 'url', 'expires_in' ],
		];
	}

	public function permission_callback( array $args ): bool|\WP_Error {
		return Permissions::manage_options();
	}

	public function execute( array $args ): array|\WP_Error {
		return $this->audit(
			$args,
			function ( array $runtime_args ): array|\WP_Error {
				$verify_args = array_filter(
					$runtime_args,
					static fn( string $key ): bool => 'confirmation_token' !== $key,
					ARRAY_FILTER_USE_KEY
				);
				$token_error = $this->confirmation_token_error( $runtime_args, $verify_args );
				if ( $token_error instanceof \WP_Error ) {
					return $token_error;
				}

				$ttl     = min( 3600, max( 30, (int) ( $runtime_args['ttl_seconds'] ?? 300 ) ) );
				$user_id = get_current_user_id();

				if ( 0 === $user_id ) {
					return $this->error( 'no_user', __( 'No authenticated user — cannot create a one-time link.', 'stonewright' ) );
				}

				$context = [];
				$user_agent = sanitize_text_field( (string) ( $runtime_args['user_agent'] ?? '' ) );
				if ( '' !== $user_agent ) {
					$context['user_agent'] = $user_agent;
				}

				$url = OneTimeLink::create( $user_id, $ttl, $context );
				if ( $url instanceof \WP_Error ) {
					return $url;
				}

				return [
					'url'        => $url,
					'expires_in' => $ttl,
				];
			}
		);
	}
}
