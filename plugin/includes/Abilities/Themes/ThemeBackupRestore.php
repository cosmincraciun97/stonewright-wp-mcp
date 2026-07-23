<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\Abilities\Themes;

use Stonewright\WpMcp\Abilities\AbilityKernel;
use Stonewright\WpMcp\Abilities\Common\ConfirmationGuard;
use Stonewright\WpMcp\Security\Permissions;
use Stonewright\WpMcp\Security\ThemeWriteTransaction;

/**
 * Restore an exact Stonewright-owned theme backup.
 *
 * @stonewright-status stable
 */
final class ThemeBackupRestore extends AbilityKernel {
	use ConfirmationGuard;

	public function name(): string {
		return 'stonewright/theme-backup-restore';
	}

	public function label(): string {
		return __( 'Theme: restore Stonewright backup', 'stonewright' );
	}

	public function description(): string {
		return __( 'Restores a Stonewright-owned opaque backup reference through atomic write, readback, fresh bootstrap smoke, and rollback gates. Requires production-safe confirmation when applicable.', 'stonewright' );
	}

	public function category(): string {
		return 'themes';
	}

	public function input_schema(): array {
		return [
			'type'                 => 'object',
			'additionalProperties' => false,
			'required'             => [ 'backup_ref' ],
			'properties'           => [
				'backup_ref' => [ 'type' => 'string', 'minLength' => 1 ],
				'theme'      => [ 'type' => 'string', 'enum' => [ 'stylesheet', 'template' ], 'default' => 'stylesheet' ],
				'smoke_url'  => [ 'type' => 'string' ],
				'confirmation_token' => [ 'type' => 'string' ],
			],
		];
	}

	public function output_schema(): array {
		return [
			'type'                 => 'object',
			'additionalProperties' => true,
		];
	}

	public function permission_callback( array $args ): bool|\WP_Error {
		unset( $args );
		return Permissions::manage_options() && Permissions::edit_theme_options();
	}

	public function execute( array $args ): array|\WP_Error {
		return $this->audit(
			$args,
			function ( array $runtime_args ): array|\WP_Error {
				$verify = $runtime_args;
				unset( $verify['confirmation_token'] );
				$token_error = $this->confirmation_token_error( $runtime_args, $verify );
				if ( $token_error instanceof \WP_Error ) {
					return $token_error;
				}

				$backup_ref = (string) ( $runtime_args['backup_ref'] ?? '' );
				$metadata   = ThemeWriteTransaction::backup_metadata( $backup_ref );
				if ( $metadata instanceof \WP_Error ) {
					return $metadata;
				}
				$resolved = ThemeFilePaths::resolve(
					(string) $metadata['relative'],
					(string) ( $runtime_args['theme'] ?? 'stylesheet' )
				);
				if ( $resolved instanceof \WP_Error ) {
					return $resolved;
				}
				$result = ThemeWriteTransaction::restore_owned_backup(
					$backup_ref,
					(string) $resolved['absolute'],
					isset( $runtime_args['smoke_url'] ) ? (string) $runtime_args['smoke_url'] : null
				);
				if ( $result instanceof \WP_Error ) {
					return $result;
				}
				return array_merge(
					$result,
					[
						'backup_ref'     => $backup_ref,
						'operation_class'=> 'theme_backup_restore',
						'resource_type'  => 'theme_file',
						'resource_ref'   => (string) $metadata['relative'],
					]
				);
			}
		);
	}

	/** @return array<int, string> */
	protected function audit_redacted_keys(): array {
		return array_merge( parent::audit_redacted_keys(), [ 'backup_ref' ] );
	}
}
