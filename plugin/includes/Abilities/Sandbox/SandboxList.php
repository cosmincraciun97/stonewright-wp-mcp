<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\Abilities\Sandbox;

use Stonewright\WpMcp\Abilities\AbilityKernel;
use Stonewright\WpMcp\Sandbox\SandboxFiles;
use Stonewright\WpMcp\Security\Permissions;

/**
 * Contract decision: keep output_schema aligned to the handler response shape.
 *
 * @stonewright-status stable
 */
final class SandboxList extends AbilityKernel {

	public function name(): string {
		return 'stonewright/sandbox-list';
	}

	public function label(): string {
		return __( 'List sandbox files', 'stonewright' );
	}

	public function description(): string {
		return __( 'Returns all sandbox draft files with status, size, and modified timestamp.', 'stonewright' );
	}

	public function category(): string {
		return 'sandbox';
	}

	public function input_schema(): array {
		return [
			'type'                 => 'object',
			'additionalProperties' => false,
			'properties'           => [],
		];
	}

	public function output_schema(): array {
		return [
			'type'       => 'object',
			'properties' => [
				'ok'    => [ 'type' => 'boolean' ],
				'files' => [ 'type' => 'array' ],
				'count' => [ 'type' => 'integer' ],
			],
			'required'   => [ 'ok', 'files', 'count' ],
		];
	}

	public function permission_callback( array $args ): bool|\WP_Error {
		return Permissions::can_manage_sandbox();
	}

	public function execute( array $args ): array|\WP_Error {
		return $this->audit(
			$args,
			function ( array $a ): array {
				unset( $a ); // no fields read; audit wrapper handles logging.
				$rows = SandboxFiles::list_files();
				$rows = array_map( [ self::class, 'sanitize_listing_row' ], $rows );
				return $this->ok( [ 'files' => $rows, 'count' => count( $rows ) ] );
			}
		);
	}

	/**
	 * Strip the absolute filesystem path from each listing row, replacing it
	 * with a relative path rooted at wp-content/. We never expose server
	 * filesystem layout to API clients.
	 *
	 * @param array<string, mixed> $row
	 * @return array<string, mixed>
	 */
	private static function sanitize_listing_row( array $row ): array {
		if ( isset( $row['path'] ) && is_string( $row['path'] ) ) {
			$row['path'] = self::relativize_path( $row['path'] );
		}
		return $row;
	}

	private static function relativize_path( string $path ): string {
		// Prefer a path rooted at wp-content/.
		$marker = '/wp-content/';
		$pos    = strrpos( $path, $marker );
		if ( false !== $pos ) {
			return 'wp-content/' . ltrim( substr( $path, $pos + strlen( $marker ) ), '/' );
		}

		// Fall back to the basename — never leak above wp-content.
		return basename( $path );
	}
}
