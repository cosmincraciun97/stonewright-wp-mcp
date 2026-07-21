<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\Abilities\Themes;

use Stonewright\WpMcp\Abilities\AbilityKernel;
use Stonewright\WpMcp\Abilities\Common\ConfirmationGuard;
use Stonewright\WpMcp\Security\Permissions;

/**
 * Patch allowlisted theme files with backup + optional production confirmation.
 *
 * Modes: append | insert_after_marker | replace_between_markers | replace_all.
 *
 * @stonewright-status stable
 */
final class ThemeFilePatch extends AbilityKernel {
	use ConfirmationGuard;

	public function name(): string {
		return 'stonewright/theme-file-patch';
	}

	public function label(): string {
		return __( 'Theme: patch allowlisted file', 'stonewright' );
	}

	public function description(): string {
		return __( 'Safely patch child-theme CSS/JS/PHP within an allowlist. Supports append, insert_after_marker, and replace_between_markers. Backs up the file before write and requires confirmation in production-safe mode.', 'stonewright' );
	}

	public function category(): string {
		return 'themes';
	}

	public function input_schema(): array {
		return [
			'type'                 => 'object',
			'additionalProperties' => false,
			'required'             => [ 'path', 'mode', 'content' ],
			'properties'           => [
				'path'               => [
					'type'        => 'string',
					'minLength'   => 1,
					'description' => 'Theme-relative path, e.g. style.css.',
				],
				'theme'              => [
					'type'    => 'string',
					'enum'    => [ 'stylesheet', 'template' ],
					'default' => 'stylesheet',
				],
				'mode'               => [
					'type' => 'string',
					'enum' => [ 'append', 'insert_after_marker', 'replace_between_markers', 'replace_all' ],
				],
				'content'            => [
					'type'        => 'string',
					'description' => 'Text to append/insert, or the replacement body between markers.',
				],
				'marker'             => [
					'type'        => 'string',
					'description' => 'Required for insert_after_marker.',
				],
				'start_marker'       => [
					'type'        => 'string',
					'description' => 'Required for replace_between_markers (inclusive start).',
				],
				'end_marker'         => [
					'type'        => 'string',
					'description' => 'Required for replace_between_markers (inclusive end).',
				],
				'create_if_missing'  => [
					'type'    => 'boolean',
					'default' => false,
				],
				'dry_run'            => [
					'type'    => 'boolean',
					'default' => false,
				],
				'confirmation_token' => [
					'type'        => 'string',
					'description' => 'Required in production-safe mode.',
				],
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
		return Permissions::edit_theme_options() || Permissions::edit_css();
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

				$resolved = ThemeFilePaths::resolve(
					(string) ( $runtime_args['path'] ?? '' ),
					(string) ( $runtime_args['theme'] ?? 'stylesheet' )
				);
				if ( $resolved instanceof \WP_Error ) {
					return $resolved;
				}

				$mode    = (string) ( $runtime_args['mode'] ?? '' );
				$content = (string) ( $runtime_args['content'] ?? '' );
				$dry_run = (bool) ( $runtime_args['dry_run'] ?? false );
				$create  = (bool) ( $runtime_args['create_if_missing'] ?? false );
				$exists  = is_file( $resolved['absolute'] );

				if ( ! $exists && ! $create ) {
					return $this->error(
						'theme_file_not_found',
						__( 'Theme file not found. Set create_if_missing:true to create allowlisted files.', 'stonewright' ),
						[ 'status' => 404, 'path' => $resolved['relative'] ]
					);
				}

				$before = $exists ? (string) file_get_contents( $resolved['absolute'] ) : '';
				$after  = self::apply_patch( $before, $mode, $content, $runtime_args );
				if ( $after instanceof \WP_Error ) {
					return $after;
				}

				$before_hash = hash( 'sha256', $before );
				$after_hash  = hash( 'sha256', $after );
				$changed     = $before_hash !== $after_hash;

				if ( $dry_run || ! $changed ) {
					return [
						'ok'            => true,
						'dry_run'       => $dry_run,
						'changed'       => $changed,
						'path'          => $resolved['relative'],
						'absolute_path' => $resolved['absolute'],
						'mode'          => $mode,
						'before_bytes'  => strlen( $before ),
						'after_bytes'   => strlen( $after ),
						'before_sha256' => $before_hash,
						'after_sha256'  => $after_hash,
						'backup_path'   => null,
						'preview'       => self::preview_tail( $after ),
					];
				}

				$backup = self::backup_file( $resolved['absolute'], $before, $exists );
				if ( $backup instanceof \WP_Error ) {
					return $backup;
				}

				$dir = dirname( $resolved['absolute'] );
				if ( ! is_dir( $dir ) && ! wp_mkdir_p( $dir ) ) {
					return $this->error(
						'theme_file_mkdir_failed',
						__( 'Could not create theme directory for the target path.', 'stonewright' ),
						[ 'status' => 500, 'path' => $resolved['relative'] ]
					);
				}

				$written = file_put_contents( $resolved['absolute'], $after );
				if ( false === $written ) {
					return $this->error(
						'theme_file_write_failed',
						__( 'Failed to write theme file.', 'stonewright' ),
						[ 'status' => 500, 'path' => $resolved['relative'] ]
					);
				}

				return [
					'ok'            => true,
					'dry_run'       => false,
					'changed'       => true,
					'path'          => $resolved['relative'],
					'absolute_path' => $resolved['absolute'],
					'mode'          => $mode,
					'before_bytes'  => strlen( $before ),
					'after_bytes'   => strlen( $after ),
					'before_sha256' => $before_hash,
					'after_sha256'  => $after_hash,
					'backup_path'   => $backup,
					'preview'       => self::preview_tail( $after ),
				];
			}
		);
	}

	/**
	 * @param array<string, mixed> $args
	 * @return string|\WP_Error
	 */
	private static function apply_patch( string $before, string $mode, string $content, array $args ) {
		return match ( $mode ) {
			'append' => self::ends_with_newline( $before ) || '' === $before
				? $before . $content
				: $before . "\n" . $content,
			'insert_after_marker' => self::insert_after_marker( $before, (string) ( $args['marker'] ?? '' ), $content ),
			'replace_between_markers' => self::replace_between(
				$before,
				(string) ( $args['start_marker'] ?? '' ),
				(string) ( $args['end_marker'] ?? '' ),
				$content
			),
			'replace_all' => $content,
			default => new \WP_Error(
				'stonewright_theme_file_mode_invalid',
				__( 'Unsupported patch mode.', 'stonewright' ),
				[ 'status' => 400, 'mode' => $mode ]
			),
		};
	}

	/** @return string|\WP_Error */
	private static function insert_after_marker( string $before, string $marker, string $content ) {
		if ( '' === $marker ) {
			return new \WP_Error(
				'stonewright_theme_file_marker_required',
				__( 'marker is required for insert_after_marker.', 'stonewright' ),
				[ 'status' => 400 ]
			);
		}
		$pos = strpos( $before, $marker );
		if ( false === $pos ) {
			return new \WP_Error(
				'stonewright_theme_file_marker_missing',
				__( 'Marker not found in theme file.', 'stonewright' ),
				[
					'status'       => 400,
					'marker'       => $marker,
					'error_code'   => 'marker_missing',
				]
			);
		}
		$insert_at = $pos + strlen( $marker );
		return substr( $before, 0, $insert_at ) . "\n" . $content . substr( $before, $insert_at );
	}

	/** @return string|\WP_Error */
	private static function replace_between( string $before, string $start, string $end, string $content ) {
		if ( '' === $start || '' === $end ) {
			return new \WP_Error(
				'stonewright_theme_file_markers_required',
				__( 'start_marker and end_marker are required for replace_between_markers.', 'stonewright' ),
				[ 'status' => 400 ]
			);
		}
		$start_pos = strpos( $before, $start );
		if ( false === $start_pos ) {
			return new \WP_Error(
				'stonewright_theme_file_marker_missing',
				__( 'start_marker not found in theme file.', 'stonewright' ),
				[ 'status' => 400, 'marker' => $start, 'error_code' => 'start_marker_missing' ]
			);
		}
		$end_pos = strpos( $before, $end, $start_pos + strlen( $start ) );
		if ( false === $end_pos ) {
			return new \WP_Error(
				'stonewright_theme_file_marker_missing',
				__( 'end_marker not found after start_marker.', 'stonewright' ),
				[ 'status' => 400, 'marker' => $end, 'error_code' => 'end_marker_missing' ]
			);
		}
		$end_pos += strlen( $end );
		return substr( $before, 0, $start_pos ) . $start . "\n" . $content . "\n" . $end . substr( $before, $end_pos );
	}

	private static function ends_with_newline( string $text ): bool {
		return '' !== $text && ( "\n" === substr( $text, -1 ) || "\r" === substr( $text, -1 ) );
	}

	private static function preview_tail( string $text, int $max = 400 ): string {
		if ( strlen( $text ) <= $max ) {
			return $text;
		}
		return '…' . substr( $text, -$max );
	}

	/** @return string|\WP_Error|null */
	private static function backup_file( string $absolute, string $before, bool $exists ) {
		if ( ! $exists || '' === $before ) {
			return null;
		}
		$upload = wp_upload_dir();
		if ( ! empty( $upload['error'] ) ) {
			return new \WP_Error(
				'stonewright_theme_file_backup_failed',
				__( 'Could not resolve uploads directory for theme backup.', 'stonewright' ),
				[ 'status' => 500 ]
			);
		}
		$dir = trailingslashit( (string) $upload['basedir'] ) . 'stonewright-theme-backups';
		if ( ! is_dir( $dir ) && ! wp_mkdir_p( $dir ) ) {
			return new \WP_Error(
				'stonewright_theme_file_backup_failed',
				__( 'Could not create theme backup directory.', 'stonewright' ),
				[ 'status' => 500 ]
			);
		}
		$basename = basename( $absolute );
		$target   = $dir . '/' . gmdate( 'Ymd-His' ) . '-' . hash( 'sha256', $absolute ) . '-' . $basename;
		if ( false === file_put_contents( $target, $before ) ) {
			return new \WP_Error(
				'stonewright_theme_file_backup_failed',
				__( 'Could not write theme backup file.', 'stonewright' ),
				[ 'status' => 500 ]
			);
		}
		return $target;
	}

	/** @return array<int, string> */
	protected function audit_redacted_keys(): array {
		return array_merge( parent::audit_redacted_keys(), [ 'content' ] );
	}
}
