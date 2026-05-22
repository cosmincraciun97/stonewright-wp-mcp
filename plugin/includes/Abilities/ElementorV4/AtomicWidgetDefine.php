<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\Abilities\ElementorV4;

use Stonewright\WpMcp\Abilities\AbilityKernel;
use Stonewright\WpMcp\Elementor\V4\AtomicCompiler;
use Stonewright\WpMcp\Sandbox\SandboxFiles;
use Stonewright\WpMcp\Sandbox\StaticGuard;
use Stonewright\WpMcp\Security\AuditLog;
use Stonewright\WpMcp\Security\Permissions;

/**
 * Compile + stage an Elementor V4 atomic widget from a Stonewright spec.
 *
 * Pipeline:
 *  1. Compile spec → PHP source via {@see AtomicCompiler::compile()}.
 *  2. Scan the compiled source with {@see StaticGuard::scan()}. Refuse to
 *     stage if the guard reports any findings.
 *  3. Write the source to a sandbox draft file (`atomic-<slug>.php`).
 *  4. Return the compiled class name, the source, and the staged path.
 *
 * The compiled file is NOT auto-loaded — staging only places it on disk. A
 * separate loader / registration ability is responsible for activation, the
 * same separation used by the V3 widget builder pipeline.
 *
 * @stonewright-status sandboxed
 */
final class AtomicWidgetDefine extends AbilityKernel {

	private const ABILITY = 'stonewright/elementor-v4-atomic-widget-define';

	public function name(): string {
		return self::ABILITY;
	}

	public function label(): string {
		return __( 'Define Elementor V4 atomic widget', 'stonewright' );
	}

	public function description(): string {
		return __( 'Compiles a Stonewright atomic widget spec into a sandboxed Elementor V4 Atomic_Widget_Base subclass and writes the source to the sandbox draft directory.', 'stonewright' );
	}

	public function category(): string {
		return 'elementor';
	}

	public function meta(): array {
		return [ 'experimental' => true ];
	}

	public function input_schema(): array {
		return [
			'type'                 => 'object',
			'additionalProperties' => false,
			'required'             => [ 'slug', 'template' ],
			'properties'           => [
				'slug'     => [
					'type'    => 'string',
					'pattern' => '^[a-z0-9-]+$',
				],
				'title'    => [ 'type' => 'string' ],
				'template' => [ 'type' => 'string' ],
				'props'    => [
					'type'  => 'array',
					'items' => [
						'type'                 => 'object',
						'additionalProperties' => false,
						'required'             => [ 'name', 'type' ],
						'properties'           => [
							'name'    => [
								'type'    => 'string',
								'pattern' => '^[a-z][a-z0-9_]*$',
							],
							'type'    => [
								'type' => 'string',
								'enum' => [ 'string', 'number', 'size', 'color', 'boolean', 'link', 'image' ],
							],
							'default' => [],
						],
					],
				],
			],
		];
	}

	public function output_schema(): array {
		return [
			'type'       => 'object',
			'properties' => [
				'ok'           => [ 'type' => 'boolean' ],
				'class_name'   => [ 'type' => 'string' ],
				'php_source'   => [ 'type' => 'string' ],
				'sandbox_path' => [ 'type' => 'string' ],
			],
			'required'   => [ 'ok', 'class_name', 'php_source', 'sandbox_path' ],
		];
	}

	public function permission_callback( array $args ): bool|\WP_Error {
		// This ability writes PHP into the sandbox draft dir. We require the
		// same conjunction as the V3 widget builder (edit_plugins + manage_options)
		// rather than a bare `edit_themes`, which exists in some custom roles but
		// is not a code-edit gate. The plan asked for edit_themes() but that
		// helper is not present on Permissions; can_manage_sandbox() is the
		// closest authoritative gate available and is what the sibling V3
		// WidgetDefine uses for an identical write.
		return Permissions::can_manage_sandbox();
	}

	protected function audit_redacted_keys(): array {
		// Templates may carry copy or markup the operator wants out of audit logs.
		return array_merge( parent::audit_redacted_keys(), [ 'template' ] );
	}

	public function execute( array $args ): array|\WP_Error {
		return $this->audit(
			$args,
			function ( array $a ): array|\WP_Error {
				$slug = (string) ( $a['slug'] ?? '' );
				if ( ! preg_match( '/^[a-z0-9-]+$/', $slug ) ) {
					return $this->error(
						'invalid_slug',
						__( 'slug must match [a-z0-9-]+.', 'stonewright' )
					);
				}

				try {
					$source = AtomicCompiler::compile( $a );
				} catch ( \InvalidArgumentException $e ) {
					return $this->error( 'compile_failed', $e->getMessage() );
				}

				// Defense in depth — the compiler is the source of truth for
				// what we emit, but every generated file still has to pass the
				// static guard before staging. If the guard finds anything the
				// caller's spec is implicated, so surface findings to operators.
				$findings = StaticGuard::scan( $source );
				if ( ! empty( $findings ) ) {
					AuditLog::record(
						self::ABILITY,
						[ 'slug' => $slug, 'static_guard' => 'rejected' ],
						'error'
					);
					return new \WP_Error(
						'stonewright_static_guard_rejected',
						'StaticGuard rejected the compiled atomic widget source.',
						[ 'findings' => $findings ]
					);
				}

				$class_name = AtomicCompiler::slug_to_class( $slug );
				$filename   = 'atomic-' . $slug . '.php';

				$written = SandboxFiles::write( $filename, $source );
				if ( is_wp_error( $written ) ) {
					return $written;
				}

				$sandbox_path = SandboxFiles::draft_dir() . '/' . $filename;

				return $this->ok( [
					'class_name'   => $class_name,
					'php_source'   => $source,
					'sandbox_path' => $sandbox_path,
				] );
			}
		);
	}
}
