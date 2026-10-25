<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\Abilities\ElementorWidget;

use Stonewright\WpMcp\Abilities\AbilityKernel;
use Stonewright\WpMcp\Abilities\Sandbox\SandboxGuards;
use Stonewright\WpMcp\Elementor\WidgetBuilder\RegisteredWidgets;
use Stonewright\WpMcp\Sandbox\SandboxFiles;
use Stonewright\WpMcp\Sandbox\StaticGuard;
use Stonewright\WpMcp\Security\Permissions;

/**
 * Activate a previously-staged Elementor widget pending file.
 *
 * Pipeline:
 *  1. Locate widget-<slug>.pending.php (stored as .pending.draft) in the sandbox draft dir.
 *  2. Re-run StaticGuard (defense-in-depth — file may have been tampered with).
 *  3. Write widget-<slug>.php (stored as .draft) through SandboxFiles::write(), which
 *     keeps a backup of the active file it replaces, records the write in the change
 *     ledger and applies the size cap; then remove the pending file.
 *  4. Append slug to stonewright_registered_widgets option (autoloaded).
 *  5. Return result.
 *
 * FILE STORAGE NOTE:
 * Widget files live in SandboxFiles::draft_dir(), NOT promoted to mu_dir via
 * SandboxFiles::activate(). Loading is handled by the dedicated widget loader
 * hook (Elementor\WidgetBuilder\Loader), which loads, on the
 * elementor/widgets/register action, the active widget file of every slug on
 * the registered-widgets list and registers it with Elementor. This keeps
 * widget PHP out of mu-plugins and prevents auto-loading of un-reviewed code.
 *
 * @stonewright-status sandboxed
 */
final class WidgetRegister extends AbilityKernel {

	use SandboxGuards;

	private const ABILITY = 'stonewright/elementor-widget-register';

	public function name(): string {
		return self::ABILITY;
	}

	public function label(): string {
		return __( 'Register Elementor widget', 'stonewright' );
	}

	public function description(): string {
		return __( 'Activates a staged widget pending file. Re-runs StaticGuard, writes the active widget-<slug>.draft file through the sandbox writer (backup of the file it replaces and a change-ledger row) and removes the pending file, then adds the slug to the registered-widgets list. Only widgets on that list are loaded into Elementor. Requires confirmation_token in production-safe mode.', 'stonewright' );
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

				$pending_name = 'widget-' . $slug . '.pending.php';
				$active_name  = 'widget-' . $slug . '.php';
				$pending_path = SandboxFiles::stored_path( $pending_name );
				$active_path  = SandboxFiles::stored_path( $active_name );

				// 3. Ensure pending file exists.
				if ( ! file_exists( $pending_path ) ) {
					return new \WP_Error(
						'stonewright_widget_pending_not_found',
						sprintf( 'No pending file found for widget "%s". Run stonewright/elementor-widget-define first.', $slug )
					);
				}

				// 4. Read source.
				$source = SandboxFiles::read( $pending_name );
				if ( is_wp_error( $source ) ) {
					return new \WP_Error(
						'stonewright_widget_read_error',
						sprintf( 'Could not read pending file for widget "%s".', $slug )
					);
				}

				// 5. Re-run StaticGuard (defense-in-depth).
				$findings = StaticGuard::scan( $source );
				if ( ! empty( $findings ) ) {
					// The audit wrapper records this rejection once, with its error code.
					return new \WP_Error(
						'stonewright_static_guard_rejected',
						'StaticGuard rejected the widget source during registration. File may have been tampered with.',
						[ 'findings' => $findings ]
					);
				}

				// 6. Determine previous status.
				$previous_status = file_exists( $active_path ) ? 'active' : 'none';

				// 7. Write the active file through the sandbox writer (backup, ledger row, size cap), then drop the pending file.
				$written = SandboxFiles::write( $active_name, $source );
				if ( is_wp_error( $written ) ) {
					return $written;
				}
				SandboxFiles::delete( $pending_name );

				// 8. Update registry option (not autoloaded).
				RegisteredWidgets::add( $slug );

				return $this->ok( [
					'widget_slug'     => $slug,
					'active_file'     => $active_path,
					'previous_status' => $previous_status,
				] );
			}
		);
	}
}
