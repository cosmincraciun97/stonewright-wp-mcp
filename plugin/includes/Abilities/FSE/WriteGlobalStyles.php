<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\Abilities\FSE;

use Stonewright\WpMcp\Abilities\AbilityKernel;
use Stonewright\WpMcp\Abilities\Common\ConfirmationGuard;
use Stonewright\WpMcp\Security\Backup;
use Stonewright\WpMcp\Security\Permissions;
use Stonewright\WpMcp\ThemeJson\Validator;

/**
 * Ability: stonewright/fse.write_global_styles
 *
 * Security envelope (AGENTS.md rules 3, 5):
 *   1. Validate theme_json via ThemeJson\Validator.
 *   2. Backup the existing wp_global_styles post (AGENTS.md rule 3).
 *   3. Write new theme.json content.
 *   4. Confirmation token required in production-safe mode (rule 5).
 *
 * Permission: can_manage_fse() — manage_options + edit_theme_options.
 *
 * @stonewright-status stable
 */
final class WriteGlobalStyles extends AbilityKernel {

	use ConfirmationGuard;

	public function name(): string {
		return 'stonewright/fse-write-global-styles';
	}

	public function label(): string {
		return __( 'Write global styles', 'stonewright' );
	}

	public function description(): string {
		return __( 'Validates a theme.json payload, backups the existing global styles, then writes the new content to the wp_global_styles post. Requires a confirmation token in production-safe mode.', 'stonewright' );
	}

	public function category(): string {
		return 'fse';
	}

	public function input_schema(): array {
		return [
			'type'                 => 'object',
			'additionalProperties' => false,
			'properties'           => [
				'theme_json'         => [
					'type'        => 'object',
					'description' => 'Full theme.json object to write.',
				],
				'confirmation_token' => [
					'type'        => 'string',
					'description' => 'Required in production-safe mode.',
				],
			],
			'required' => [ 'theme_json' ],
		];
	}

	public function output_schema(): array {
		return [
			'type'       => 'object',
			'properties' => [
				'post_id'     => [ 'type' => 'integer' ],
				'snapshot_id' => [ 'type' => 'string' ],
			],
			'required' => [ 'post_id', 'snapshot_id' ],
		];
	}

	public function permission_callback( array $args ): bool|\WP_Error {
		return Permissions::can_manage_fse();
	}

	public function execute( array $args ): array|\WP_Error {
		return $this->audit(
			$args,
			function ( array $args ): array|\WP_Error {
				$theme_json = $args['theme_json'] ?? null;
				if ( ! is_array( $theme_json ) ) {
					return $this->error( 'missing_theme_json', __( 'The theme_json parameter is required and must be an object.', 'stonewright' ) );
				}

				// ── Confirmation token ───────────────────────────────────────────
				$verify_args = array_filter(
					$args,
					static fn( string $k ) => 'confirmation_token' !== $k,
					ARRAY_FILTER_USE_KEY
				);
				$token_error = $this->confirmation_token_error( $args, $verify_args );
				if ( null !== $token_error ) {
					return $token_error;
				}

				// ── Validate theme.json ──────────────────────────────────────────
				$canonical = Validator::validate( $theme_json );
				if ( is_wp_error( $canonical ) ) {
					return $canonical;
				}

				if ( ! class_exists( \WP_Theme_JSON_Resolver::class ) ) {
					return $this->error( 'theme_json_unavailable', __( 'theme.json resolver is not available.', 'stonewright' ) );
				}

				$post_id = \WP_Theme_JSON_Resolver::get_user_global_styles_post_id();
				if ( ! $post_id ) {
					return $this->error( 'no_user_global_styles', __( 'User global styles post is missing.', 'stonewright' ) );
				}

				// ── Backup BEFORE write (AGENTS.md rule 3) ───────────────────────
				$snapshot_id = Backup::snapshot_post( (int) $post_id );

				// ── Write canonical (filtered) payload ───────────────────────────
				$result = wp_update_post(
					[
						'ID'           => (int) $post_id,
						'post_content' => (string) wp_json_encode( $canonical ),
					],
					true
				);
				if ( is_wp_error( $result ) ) {
					return $result;
				}

				return [
					'post_id'     => (int) $post_id,
					'snapshot_id' => $snapshot_id,
				];
			}
		);
	}

	/**
	 * @return array<int, string>
	 */
	protected function audit_redacted_keys(): array {
		return array_merge( parent::audit_redacted_keys(), [ 'confirmation_token' ] );
	}
}
