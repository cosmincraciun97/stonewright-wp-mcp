<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\Abilities\ElementorWidget;

use Stonewright\WpMcp\Abilities\AbilityKernel;
use Stonewright\WpMcp\Abilities\Sandbox\SandboxGuards;
use Stonewright\WpMcp\Elementor\WidgetBuilder\Compiler;
use Stonewright\WpMcp\Sandbox\SandboxFiles;
use Stonewright\WpMcp\Sandbox\StaticGuard;
use Stonewright\WpMcp\Security\AuditLog;
use Stonewright\WpMcp\Security\Permissions;

/**
 * Compile + stage an Elementor widget from the Stonewright DSL.
 *
 * Pipeline:
 *  1. Validate input.
 *  2. Compile DSL → PHP via Compiler.
 *  3. Run generated PHP through StaticGuard::scan(). Reject on findings.
 *  4. Write to sandbox draft dir as widget-<slug>.pending.php.
 *  5. Return result.
 *
 * The file is NOT loaded until stonewright/elementor.widget_register is called.
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
final class WidgetDefine extends AbilityKernel {

	use SandboxGuards;

	private const ABILITY = 'stonewright/elementor-widget-define';

	public function name(): string {
		return self::ABILITY;
	}

	public function label(): string {
		return __( 'Define Elementor widget', 'stonewright' );
	}

	public function description(): string {
		return __( 'Compiles a DSL template into a sandboxed Elementor widget PHP file. Requires StaticGuard to pass. File is staged as .pending.php — call widget_register to activate.', 'stonewright' );
	}

	public function category(): string {
		return 'elementor-widget';
	}

	public function input_schema(): array {
		return [
			'type'                 => 'object',
			'additionalProperties' => false,
			'required'             => [ 'widget_slug', 'label', 'category', 'controls', 'template', 'render_strategy' ],
			'properties'           => [
				'widget_slug'        => [
					'type'    => 'string',
					'pattern' => '^[a-z][a-z0-9_-]{2,40}$',
				],
				'label'              => [
					'type'      => 'string',
					'minLength' => 3,
					'maxLength' => 80,
				],
				'category'           => [
					'type' => 'string',
					'enum' => [ 'basic', 'pro-elements', 'general', 'stonewright' ],
				],
				'controls'           => [
					'type'     => 'array',
					'minItems' => 1,
					'items'    => [
						'type'                 => 'object',
						'additionalProperties' => false,
						'required'             => [ 'id', 'label', 'type' ],
						'properties'           => [
							'id'      => [
								'type'    => 'string',
								'pattern' => '^[a-z][a-z0-9_]{0,40}$',
							],
							'label'   => [ 'type' => 'string' ],
							'type'    => [
								'type' => 'string',
								'enum' => [ 'text', 'textarea', 'number', 'color', 'select', 'url', 'image', 'switcher' ],
							],
							'default' => [],
							'options' => [ 'type' => 'object' ],
						],
					],
				],
				'template'           => [ 'type' => 'string' ],
				'render_strategy'    => [
					'type' => 'string',
					'enum' => [ 'twig', 'block-binding' ],
				],
				'confirmation_token' => [ 'type' => 'string' ],
			],
		];
	}

	public function output_schema(): array {
		return [
			'type'       => 'object',
			'required'   => [ 'ok', 'sandbox_file', 'widget_slug', 'static_guard' ],
			'properties' => [
				'ok'           => [ 'type' => 'boolean' ],
				'sandbox_file' => [ 'type' => 'string' ],
				'preview_url'  => [ 'type' => 'string' ],
				'widget_slug'  => [ 'type' => 'string' ],
				'static_guard' => [
					'type'       => 'object',
					'properties' => [
						'passed'   => [ 'type' => 'boolean' ],
						'findings' => [ 'type' => 'array', 'items' => [ 'type' => 'string' ] ],
					],
				],
			],
		];
	}

	public function permission_callback( array $args ): bool|\WP_Error {
		return Permissions::can_manage_sandbox();
	}

	protected function audit_redacted_keys(): array {
		return array_merge( parent::audit_redacted_keys(), [ 'template' ] );
	}

	public function execute( array $args ): array|\WP_Error {
		return $this->audit(
			$args,
			function ( array $a ): array|\WP_Error {
				// 1. DISALLOW_FILE_MODS check.
				$file_mods_error = $this->file_mods_disabled_error();
				if ( null !== $file_mods_error ) {
					return $file_mods_error;
				}

				// 2. Production-safe confirmation token.
				// C2 — Include 'template' in verify_args so token is bound to the
				// exact template the user confirmed. Without this, an attacker with
				// edit_plugins + a valid token for a benign template could substitute
				// a malicious template and bypass the confirmation check.
				$token_error = $this->production_safe_token_error(
					$a,
					[
						'widget_slug'     => $a['widget_slug'],
						'label'           => $a['label'],
						'category'        => $a['category'],
						'controls'        => $a['controls'],
						'template'        => $a['template'],
						'render_strategy' => $a['render_strategy'],
					]
				);
				if ( null !== $token_error ) {
					return $token_error;
				}

				$slug     = (string) $a['widget_slug'];
				$label    = (string) $a['label'];
				$category = (string) $a['category'];
				$controls = (array) $a['controls'];
				$template = (string) $a['template'];
				$strategy = (string) $a['render_strategy'];

				// 3. Compile DSL → PHP.
				$source = Compiler::compile( $slug, $label, $category, $controls, $template, $strategy );
				if ( is_wp_error( $source ) ) {
					return $source;
				}

				// 4. StaticGuard scan (defense-in-depth, layer 2).
				$findings = StaticGuard::scan( $source );
				if ( ! empty( $findings ) ) {
					AuditLog::record(
						self::ABILITY,
						[ 'widget_slug' => $slug, 'static_guard' => 'rejected' ],
						'error'
					);
					return new \WP_Error(
						'stonewright_static_guard_rejected',
						'StaticGuard rejected the compiled widget source. This is a compiler bug — please report it.',
						[ 'findings' => $findings ]
					);
				}

				// 5. Write pending file to sandbox draft dir.
				$filename = 'widget-' . $slug . '.pending.php';
				$path     = self::write_pending( $filename, $source );
				if ( is_wp_error( $path ) ) {
					return $path;
				}

				return $this->ok( [
					'sandbox_file' => $path,
					'preview_url'  => admin_url( 'admin.php?page=stonewright-sandbox&widget=' . rawurlencode( $slug ) ),
					'widget_slug'  => $slug,
					'static_guard' => [
						'passed'   => true,
						'findings' => [],
					],
				] );
			}
		);
	}

	// -------------------------------------------------------------------------
	// Helpers
	// -------------------------------------------------------------------------

	/**
	 * Write source to `<draft_dir>/widget-<slug>.pending.php`.
	 * Uses raw file_put_contents because SandboxFiles::write() enforces a
	 * strict name regex that does not allow the .pending.php extension.
	 *
	 * @return string|\WP_Error Absolute path on success.
	 */
	private static function write_pending( string $filename, string $source ): string|\WP_Error {
		// Ensure draft dir exists.
		$dir = SandboxFiles::draft_dir();

		// Guard: filename must be safe (no path separators, known pattern).
		if ( $filename !== basename( $filename ) ) {
			return new \WP_Error( 'stonewright_widget_invalid_name', 'Path traversal detected in widget filename.' );
		}
		if ( ! preg_match( '/^widget-[a-z][a-z0-9_-]{2,40}\.pending\.php$/', $filename ) ) {
			return new \WP_Error(
				'stonewright_widget_invalid_name',
				'Widget filename does not match expected pattern.'
			);
		}

		$path   = $dir . '/' . $filename;
		$result = file_put_contents( $path, $source ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
		if ( false === $result ) {
			return new \WP_Error(
				'stonewright_widget_write_error',
				sprintf( 'Could not write widget pending file: %s', $filename )
			);
		}

		AuditLog::record( self::ABILITY, [ 'widget_slug' => $filename, 'action' => 'write_pending' ] );
		return $path;
	}
}
