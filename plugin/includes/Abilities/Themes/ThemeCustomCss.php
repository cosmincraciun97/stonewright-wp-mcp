<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\Abilities\Themes;

use Stonewright\WpMcp\Abilities\AbilityKernel;
use Stonewright\WpMcp\Abilities\Common\ConfirmationGuard;
use Stonewright\WpMcp\Security\Backup;
use Stonewright\WpMcp\Security\Permissions;

/**
 * Gets or updates theme custom CSS with backup before write.
 *
 * @stonewright-status stable
 */
final class ThemeCustomCss extends AbilityKernel {
	use ConfirmationGuard;

	public function name(): string {
		return 'stonewright/theme-custom-css';
	}

	public function label(): string {
		return __( 'Theme: Custom CSS', 'stonewright' );
	}

	public function description(): string {
		return __( 'Gets or updates theme custom CSS with backup before write.', 'stonewright' );
	}

	public function category(): string {
		return 'themes';
	}

	public function input_schema(): array {
		return [
			'type'                 => 'object',
			'additionalProperties' => false,
			'properties'           => [
				'action'             => [ 'type' => 'string', 'enum' => [ 'get', 'update' ] ],
				'css'                => [ 'type' => 'string' ],
				'confirmation_token' => [ 'type' => 'string' ],
			],
			'required'             => [ 'action' ],
		];
	}

	public function output_schema(): array {
		return [
			'type'                 => 'object',
			'additionalProperties' => true,
		];
	}

	public function permission_callback( array $args ): bool|\WP_Error {
		return Permissions::edit_css();
	}

	public function execute( array $args ): array|\WP_Error {
		return $this->audit(
			$args,
			function ( array $args ) {
				if ( 'get' === (string) $args['action'] ) {
					return [
						'css'        => (string) wp_get_custom_css(),
						'stylesheet' => get_stylesheet(),
					];
				}
				$verify = $args;
				unset( $verify['confirmation_token'] );
				$token_error = $this->confirmation_token_error( $args, $verify );
				if ( null !== $token_error ) {
					return $token_error;
				}
				$post = wp_get_custom_css_post();
				if ( $post instanceof \WP_Post && $post->ID > 0 ) {
					Backup::snapshot_post( (int) $post->ID );
				}
				$result = wp_update_custom_css_post( (string) ( $args['css'] ?? '' ) );
				if ( is_wp_error( $result ) ) {
					return $result;
				}
				return [
					'ok'  => true,
					'css' => (string) wp_get_custom_css(),
				];
			}
		);
	}
}
