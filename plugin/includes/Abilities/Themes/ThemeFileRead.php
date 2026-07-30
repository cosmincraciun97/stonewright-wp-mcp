<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\Abilities\Themes;

use Stonewright\WpMcp\Abilities\AbilityKernel;
use Stonewright\WpMcp\Security\Permissions;

/**
 * Read allowlisted files from the active child (or parent) theme.
 *
 * @stonewright-status stable
 */
final class ThemeFileRead extends AbilityKernel {

	public function name(): string {
		return 'stonewright/theme-file-read';
	}

	public function label(): string {
		return __( 'Theme: read allowlisted file', 'stonewright' );
	}

	public function description(): string {
		return __( 'Reads a file from the active theme within a strict allowlist (style.css, functions.php, scripts, inc/*). Prefer this over php-execute for theme CSS/JS inspection.', 'stonewright' );
	}

	public function category(): string {
		return 'themes';
	}

	public function input_schema(): array {
		return [
			'type'                 => 'object',
			'additionalProperties' => false,
			'required'             => [ 'path' ],
			'properties'           => [
				'path' => [
					'type'        => 'string',
					'minLength'   => 1,
					'description' => 'Theme-relative path, e.g. style.css or inc/custom.css.',
				],
				'theme' => [
					'type'        => 'string',
					'enum'        => [ 'stylesheet', 'template' ],
					'default'     => 'stylesheet',
					'description' => 'stylesheet = child/active theme; template = parent theme.',
				],
				'max_bytes' => [
					'type'        => 'integer',
					'default'     => 262144,
					'minimum'     => 1024,
					'maximum'     => 1048576,
				],
			],
		];
	}

	public function output_schema(): array {
		return [
			'type'       => 'object',
			'properties' => [
				'ok'            => [ 'type' => 'boolean' ],
				'path'          => [ 'type' => 'string' ],
				'theme'         => [ 'type' => 'string' ],
				'stylesheet'    => [ 'type' => 'string' ],
				'content'       => [ 'type' => 'string' ],
				'bytes'         => [ 'type' => 'integer' ],
				'sha256'        => [ 'type' => 'string' ],
				'truncated'    => [ 'type' => 'boolean' ],
				'max_bytes'     => [ 'type' => 'integer' ],
			],
			'required'   => [ 'ok', 'path', 'content', 'bytes', 'sha256' ],
		];
	}

	public function permission_callback( array $args ): bool|\WP_Error {
		$path = (string) ( $args['path'] ?? '' );
		// PHP sources require an admin/code-edit gate; CSS/JS may use edit_css.
		if ( ThemeFilePaths::is_php_path( $path ) ) {
			return Permissions::manage_options() || Permissions::edit_theme_options();
		}
		return Permissions::edit_theme_options() || Permissions::edit_css();
	}

	public function execute( array $args ): array|\WP_Error {
		$resolved = ThemeFilePaths::resolve(
			(string) ( $args['path'] ?? '' ),
			(string) ( $args['theme'] ?? 'stylesheet' )
		);
		if ( $resolved instanceof \WP_Error ) {
			return $resolved;
		}

		if ( ! is_readable( $resolved['absolute'] ) ) {
			return $this->error(
				'theme_file_not_found',
				__( 'Theme file not found or not readable.', 'stonewright' ),
				[
					'status' => 404,
					'path'   => $resolved['relative'],
				]
			);
		}

		$max     = max( 1024, min( 1048576, (int) ( $args['max_bytes'] ?? 262144 ) ) );
		$raw     = (string) file_get_contents( $resolved['absolute'] );
		$bytes   = strlen( $raw );
		$trunc   = $bytes > $max;
		$content = $trunc ? substr( $raw, 0, $max ) : $raw;

		return [
			'ok'            => true,
			'path'          => $resolved['relative'],
			'theme'         => $resolved['theme_key'],
			'stylesheet'    => $resolved['stylesheet'],
			'content'       => $content,
			'bytes'         => $bytes,
			'sha256'        => hash( 'sha256', $raw ),
			'truncated'    => $trunc,
			'max_bytes'     => $max,
		];
	}
}
