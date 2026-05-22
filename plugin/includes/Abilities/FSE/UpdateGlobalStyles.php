<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\Abilities\FSE;

use Stonewright\WpMcp\Abilities\AbilityKernel;
use Stonewright\WpMcp\Abilities\Common\ConfirmationGuard;
use Stonewright\WpMcp\Security\Backup;
use Stonewright\WpMcp\Security\Permissions;

/**
 * Contract decision: keep output_schema aligned to the handler response shape.
 *
 * @stonewright-status stable
 */
final class UpdateGlobalStyles extends AbilityKernel {

	use ConfirmationGuard;

	public function name(): string {
		return 'stonewright/fse-update-global-styles';
	}

	public function label(): string {
		return __( 'Update global styles', 'stonewright' );
	}

	public function description(): string {
		return __( 'Updates the user-level theme.json overrides (wp_global_styles post). Replaces or merges.', 'stonewright' );
	}

	public function category(): string {
		return 'fse';
	}

	public function input_schema(): array {
		return [
			'type'                 => 'object',
			'additionalProperties' => false,
			'properties'           => [
				'settings' => [ 'type' => 'object' ],
				'styles'   => [ 'type' => 'object' ],
				'mode'               => [ 'type' => 'string', 'enum' => [ 'merge', 'replace' ], 'default' => 'merge' ],
				'confirmation_token' => [ 'type' => 'string' ],
			],
		];
	}

	public function output_schema(): array {
		return [
			'type'       => 'object',
			'properties' => [
				'id' => [ 'type' => 'integer' ],
			],
		];
	}

	public function permission_callback( array $args ): bool|\WP_Error {
		return Permissions::edit_theme_options();
	}

	public function execute( array $args ): array|\WP_Error {
		return $this->audit(
			$args,
			function ( array $args ) {
				// Build the verify_args as the args the ability signs over (no confirmation_token).
				$verify_args = array_filter(
					$args,
					static fn( string $k ) => 'confirmation_token' !== $k,
					ARRAY_FILTER_USE_KEY
				);

				$token_error = $this->confirmation_token_error( $args, $verify_args );
				if ( null !== $token_error ) {
					return $token_error;
				}

				if ( ! class_exists( \WP_Theme_JSON_Resolver::class ) ) {
					return $this->error( 'theme_json_unavailable', __( 'theme.json resolver is not available.', 'stonewright' ) );
				}

				$user_cpt_id = \WP_Theme_JSON_Resolver::get_user_global_styles_post_id();
				if ( ! $user_cpt_id ) {
					return $this->error( 'no_user_global_styles', __( 'User global styles post is missing.', 'stonewright' ) );
				}

				$current = get_post( $user_cpt_id );
				$raw     = $current ? json_decode( (string) $current->post_content, true ) : [];
				if ( ! is_array( $raw ) ) {
					$raw = [];
				}

				$mode = isset( $args['mode'] ) ? (string) $args['mode'] : 'merge';

				$next = $raw;
				if ( 'replace' === $mode ) {
					$next = [
						'version'  => 3,
						'settings' => isset( $args['settings'] ) && is_array( $args['settings'] ) ? $args['settings'] : ( $raw['settings'] ?? [] ),
						'styles'   => isset( $args['styles'] ) && is_array( $args['styles'] ) ? $args['styles'] : ( $raw['styles'] ?? [] ),
					];
				} else {
					$next['version'] = $next['version'] ?? 3;
					if ( isset( $args['settings'] ) && is_array( $args['settings'] ) ) {
						$next['settings'] = $this->merge( (array) ( $raw['settings'] ?? [] ), $args['settings'] );
					}
					if ( isset( $args['styles'] ) && is_array( $args['styles'] ) ) {
						$next['styles'] = $this->merge( (array) ( $raw['styles'] ?? [] ), $args['styles'] );
					}
				}

				Backup::snapshot_post( (int) $user_cpt_id );

				$result = wp_update_post(
					[
						'ID'           => (int) $user_cpt_id,
						'post_content' => wp_json_encode( $next ),
					],
					true
				);
				if ( is_wp_error( $result ) ) {
					return $result;
				}

				return [ 'id' => (int) $user_cpt_id ];
			}
		);
	}

	private function merge( array $base, array $overlay ): array {
		foreach ( $overlay as $key => $value ) {
			if ( is_array( $value ) && isset( $base[ $key ] ) && is_array( $base[ $key ] ) ) {
				$base[ $key ] = $this->merge( $base[ $key ], $value );
			} else {
				$base[ $key ] = $value;
			}
		}
		return $base;
	}
}
