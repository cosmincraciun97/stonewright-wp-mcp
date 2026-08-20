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
		return __( 'Compatibility wrapper around fse-write-global-styles. Merges or replaces user-level theme.json overrides using the same validator, backup, and confirmation envelope.', 'stonewright' );
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
				$verify_args = array_filter(
					$args,
					static fn( string $k ) => 'confirmation_token' !== $k,
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

				$written = GlobalStylesWriter::write( $composed );
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
		return array_merge( parent::audit_redacted_keys(), [ 'confirmation_token' ] );
	}
}
