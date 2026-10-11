<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\Abilities\FSE;

use Stonewright\WpMcp\Abilities\AbilityKernel;
use Stonewright\WpMcp\Abilities\Common\ConfirmationGuard;
use Stonewright\WpMcp\FSE\GlobalStylesWriter;
use Stonewright\WpMcp\Security\Permissions;

/**
 * Compatibility wrapper for stonewright/fse-update-global-styles.
 *
 * Delegates to the same validator + backup + persist envelope as
 * {@see WriteGlobalStyles} / {@see GlobalStylesWriter}. Existing callers keep
 * the merge/replace input shape and the `id` output field.
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
		return __( 'Compatibility wrapper around fse-write-global-styles. Merges or replaces user-level theme.json overrides using the same validator, backup, and confirmation envelope. In merge mode objects merge key by key and a list (such as a color palette) is replaced by the list that was sent. A call that passes neither settings nor styles is refused.', 'stonewright' );
	}

	public function category(): string {
		return 'fse';
	}

	public function input_schema(): array {
		return [
			'type'                 => 'object',
			'additionalProperties' => false,
			'properties'           => [
				'settings'           => [ 'type' => 'object' ],
				'styles'             => [ 'type' => 'object' ],
				'mode'               => [ 'type' => 'string', 'enum' => [ 'merge', 'replace' ], 'default' => 'merge' ],
				'confirmation_token' => [ 'type' => 'string' ],
				'custom_code_grant'  => [
					'type'        => 'string',
					'description' => 'Required when styles.css or per-block css is present. Single-use human-issued grant bound to the CSS candidate hash.',
				],
			],
		];
	}

	public function output_schema(): array {
		return [
			'type'       => 'object',
			'properties' => [
				'id'          => [ 'type' => 'integer' ],
				'post_id'     => [ 'type' => 'integer' ],
				'snapshot_id' => [ 'type' => 'string' ],
			],
		];
	}

	public function permission_callback( array $args ): bool|\WP_Error {
		return Permissions::can_manage_fse();
	}

	public function execute( array $args ): array|\WP_Error {
		return $this->audit(
			$args,
			function ( array $args ) {
				// A call that names nothing to change would only rewrite the record, so it is refused before
				// a token is looked at. In merge mode empty objects change nothing either.
				$has_settings = isset( $args['settings'] ) && is_array( $args['settings'] );
				$has_styles   = isset( $args['styles'] ) && is_array( $args['styles'] );
				$replace      = 'replace' === ( $args['mode'] ?? 'merge' );
				$has_change   = $replace
					? ( $has_settings || $has_styles )
					: ( ( $has_settings && [] !== $args['settings'] ) || ( $has_styles && [] !== $args['styles'] ) );
				if ( ! $has_change ) {
					return $this->error(
						'global_styles_empty',
						__( 'Pass settings and/or styles to change. A call with nothing to change is refused instead of rewriting the global styles.', 'stonewright' ),
						[ 'status' => 400 ]
					);
				}

				$verify_args = array_filter(
					$args,
					static fn( string $k ) => 'confirmation_token' !== $k && 'custom_code_grant' !== $k,
					ARRAY_FILTER_USE_KEY
				);
				$token_error = $this->confirmation_token_error( $args, $verify_args );
				if ( null !== $token_error ) {
					return $token_error;
				}

				$composed = GlobalStylesWriter::compose_from_update_args( $args );
				if ( is_wp_error( $composed ) ) {
					return $composed;
				}

				$written = GlobalStylesWriter::write( $composed, (string) ( $args['custom_code_grant'] ?? '' ) );
				if ( is_wp_error( $written ) ) {
					return $written;
				}

				return [
					'id'          => (int) $written['post_id'],
					'post_id'     => (int) $written['post_id'],
					'snapshot_id' => (string) $written['snapshot_id'],
				];
			}
		);
	}

	/**
	 * @return array<int, string>
	 */
	protected function audit_redacted_keys(): array {
		return array_merge( parent::audit_redacted_keys(), [ 'confirmation_token', 'custom_code_grant' ] );
	}
}
