<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\Abilities\ElementorWidget;

use Stonewright\WpMcp\Abilities\AbilityKernel;
use Stonewright\WpMcp\Sandbox\SandboxFiles;
use Stonewright\WpMcp\Security\Permissions;

/**
 * List known Elementor widgets managed by Stonewright.
 *
 * Returns entries from:
 *  - stonewright_registered_widgets option (status: 'active').
 *  - Pending .pending.php files in the sandbox draft dir (status: 'sandboxed').
 *
 * @stonewright-status stable
 */
final class WidgetList extends AbilityKernel {

	private const OPTION_KEY = 'stonewright_registered_widgets';

	public function name(): string {
		return 'stonewright/elementor-widget-list';
	}

	public function label(): string {
		return __( 'List Elementor widgets', 'stonewright' );
	}

	public function description(): string {
		return __( 'Enumerates Stonewright-managed Elementor widgets. Active widgets come from the registered-widgets option; sandboxed widgets are .pending.php files in the draft dir.', 'stonewright' );
	}

	public function category(): string {
		return 'elementor-widget';
	}

	public function output_schema(): array {
		return [
			'type'       => 'object',
			'required'   => [ 'ok', 'widgets' ],
			'properties' => [
				'ok'      => [ 'type' => 'boolean' ],
				'widgets' => [
					'type'  => 'array',
					'items' => [
						'type'       => 'object',
						'required'   => [ 'widget_slug', 'status', 'file' ],
						'properties' => [
							'widget_slug' => [ 'type' => 'string' ],
							'status'      => [ 'type' => 'string', 'enum' => [ 'active', 'sandboxed' ] ],
							'file'        => [ 'type' => 'string' ],
							'defined_at'  => [ 'type' => 'integer' ],
						],
					],
				],
			],
		];
	}

	public function permission_callback( array $args ): bool|\WP_Error {
		return Permissions::can_view_sandbox();
	}

	public function execute( array $args ): array|\WP_Error {
		$draft_dir  = SandboxFiles::draft_dir();
		$widgets    = [];
		$seen_slugs = [];

		// 1. Active widgets from registry option.
		$registered = (array) get_option( self::OPTION_KEY, [] );
		foreach ( $registered as $slug ) {
			$slug = (string) $slug;
			if ( '' === $slug ) {
				continue;
			}
			$active_file = $draft_dir . '/widget-' . $slug . '.php';
			$seen_slugs[ $slug ] = true;

			$widgets[] = [
				'widget_slug' => $slug,
				'status'      => 'active',
				'file'        => $active_file,
				'defined_at'  => file_exists( $active_file ) ? (int) filemtime( $active_file ) : 0,
			];
		}

		// 2. Pending files not yet in the registry.
		$pending_files = glob( $draft_dir . '/widget-*.pending.php' );
		if ( false !== $pending_files ) {
			foreach ( $pending_files as $path ) {
				$base = basename( $path ); // widget-foo.pending.php
				if ( ! preg_match( '/^widget-([a-z][a-z0-9_-]{2,40})\.pending\.php$/', $base, $m ) ) {
					continue;
				}
				$slug = $m[1];
				if ( isset( $seen_slugs[ $slug ] ) ) {
					continue;
				}
				$widgets[] = [
					'widget_slug' => $slug,
					'status'      => 'sandboxed',
					'file'        => $path,
					'defined_at'  => (int) filemtime( $path ),
				];
			}
		}

		return $this->ok( [ 'widgets' => $widgets ] );
	}
}
