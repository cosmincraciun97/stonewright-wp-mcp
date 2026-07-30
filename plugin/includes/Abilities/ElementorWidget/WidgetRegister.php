<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\Abilities\ElementorWidget;

use Stonewright\WpMcp\Abilities\AbilityKernel;
use Stonewright\WpMcp\Abilities\Sandbox\SandboxGuards;
use Stonewright\WpMcp\Sandbox\SandboxFiles;
use Stonewright\WpMcp\Sandbox\StaticGuard;
use Stonewright\WpMcp\Security\AuditLog;
use Stonewright\WpMcp\Security\Permissions;

/**
 * Activate a previously-staged Elementor widget pending file.
 *
 * Pipeline:
 *  1. Locate widget-<slug>.pending.php in sandbox draft dir.
 *  2. Re-run StaticGuard (defense-in-depth — file may have been tampered with).
 *  3. Rename widget-<slug>.pending.php → widget-<slug>.php in the same dir.
 *  4. Append slug to stonewright_registered_widgets option.
 *  5. Return result.
 *
 * FILE STORAGE NOTE:
 * Widget files live in SandboxFiles::draft_dir(), NOT promoted to mu_dir via
 * SandboxFiles::activate(). Loading is handled by the dedicated widget loader
 * hook (Elementor\WidgetBuilder\Loader), which scans draft_dir for files
 * matching widget-*.php (non-pending) on the elementor/widgets/register action
 * and registers them with Elementor. This keeps widget PHP out of mu-plugins
 * and prevents auto-loading of un-reviewed code.
 *
 * @stonewright-status sandboxed
 */
final class WidgetRegister extends AbilityKernel {

	use SandboxGuards;

	private const ABILITY      = 'stonewright/elementor-widget-register';
	private const OPTION_KEY   = 'stonewright_registered_widgets';

	public function name(): string {
		return self::ABILITY;
	}

	public function label(): string {
		return __( 'Register Elementor widget', 'stonewright' );
	}

	public function description(): string {
		return __( 'Activates a staged widget pending file. Re-runs StaticGuard before renaming. Appends slug to the registered-widgets option. Requires confirmation_token in production-safe mode.', 'stonewright' );
	}

	public function category(): string {
		return 'elementor-widget';
	}

	public function input_schema(): array {
		return [
			'type'                 => 'object',
			'additionalProperties' => false,
			'required'             => [ 'widget_slug' ],
			'properties'           => [
				'widget_slug'        => [
					'type'    => 'string',
					'pattern' => '^[a-z][a-z0-9_-]{2,40}$',
				],
				'confirmation_token' => [ 'type' => 'string' ],
			],
		];
	}

	public function output_schema(): array {
		return [
			'type'       => 'object',
			'required'   => [ 'ok', 'widget_slug', 'active_file', 'previous_status' ],
			'properties' => [
				'ok'              => [ 'type' => 'boolean' ],
				'widget_slug'     => [ 'type' => 'string' ],
				'active_file'     => [ 'type' => 'string' ],
				'previous_status' => [ 'type' => 'string' ],
			],
		];
	}

	public function permission_callback( array $args ): bool|\WP_Error {
		return Permissions::can_manage_sandbox();
	}

	public function execute( array $args ): array|\WP_Error {
		return $this->audit(
			$args,
			function ( array $a ): array|\WP_Error {
				// 1. DISALLOW_FILE_MODS.
				$file_mods_error = $this->file_mods_disabled_error();
				if ( null !== $file_mods_error ) {
					return $file_mods_error;
				}

				$slug = (string) $a['widget_slug'];

				// 2. Production-safe confirmation token.
				$token_error = $this->production_safe_token_error(
					$a,
					[ 'widget_slug' => $slug ]
				);
				if ( null !== $token_error ) {
					return $token_error;
				}

				$draft_dir    = SandboxFiles::draft_dir();
				$pending_name = 'widget-' . $slug . '.pending.php';
				$active_name  = 'widget-' . $slug . '.php';
				$pending_path = $draft_dir . '/' . $pending_name;
				$active_path  = $draft_dir . '/' . $active_name;

				// 3. Ensure pending file exists.
				if ( ! file_exists( $pending_path ) ) {
					return new \WP_Error(
						'stonewright_widget_pending_not_found',
						sprintf( 'No pending file found for widget "%s". Run widget_define first.', $slug )
					);
				}

				// 4. Read source.
				$source = file_get_contents( $pending_path ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
				if ( false === $source ) {
					return new \WP_Error(
						'stonewright_widget_read_error',
						sprintf( 'Could not read pending file for widget "%s".', $slug )
					);
				}

				// 5. Re-run StaticGuard (defense-in-depth).
				$findings = StaticGuard::scan( $source );
				if ( ! empty( $findings ) ) {
					AuditLog::record(
						self::ABILITY,
						[ 'widget_slug' => $slug, 'static_guard' => 'rejected' ],
						'error'
					);
					return new \WP_Error(
						'stonewright_static_guard_rejected',
						'StaticGuard rejected the widget source during registration. File may have been tampered with.',
						[ 'findings' => $findings ]
					);
				}

				// 6. Determine previous status.
				$previous_status = file_exists( $active_path ) ? 'active' : 'none';

				// 7. Rename pending → active.
				if ( ! rename( $pending_path, $active_path ) ) { // phpcs:ignore WordPress.WP.AlternativeFunctions.rename_rename
					return new \WP_Error(
						'stonewright_widget_rename_error',
						sprintf( 'Could not rename pending file for widget "%s".', $slug )
					);
				}

				// 8. Update registry option.
				$registered = (array) get_option( self::OPTION_KEY, [] );
				if ( ! in_array( $slug, $registered, true ) ) {
					$registered[] = $slug;
					update_option( self::OPTION_KEY, array_values( $registered ), false );
				}

				AuditLog::record(
					self::ABILITY,
					[ 'widget_slug' => $slug, 'action' => 'registered' ]
				);

				return $this->ok( [
					'widget_slug'     => $slug,
					'active_file'     => $active_path,
					'previous_status' => $previous_status,
				] );
			}
		);
	}
}
