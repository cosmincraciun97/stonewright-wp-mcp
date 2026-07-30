<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\Sandbox;

use Stonewright\WpMcp\Security\AuditLog;

/**
 * Handles fatal errors originating from sandbox mu-plugins files.
 *
 * If a PHP fatal occurs inside an active sandbox file, the file is renamed
 * to add a .crashed suffix (preventing it from loading on the next request)
 * and an admin notice is displayed.
 */
final class CrashRecovery {

	/**
	 * Registers the shutdown handler. Call once at plugin boot.
	 */
	public static function register(): void {
		register_shutdown_function( [ self::class, 'handle_shutdown' ] );
	}

	/**
	 * Shutdown callback: checks for fatal errors and disables the offending file.
	 */
	public static function handle_shutdown(): void {
		$error = error_get_last();

		if ( null === $error ) {
			return;
		}

		// Only handle fatal-class errors.
		$fatal_types = [
			E_ERROR,
			E_PARSE,
			E_CORE_ERROR,
			E_COMPILE_ERROR,
			E_USER_ERROR,
		];

		if ( ! in_array( $error['type'], $fatal_types, true ) ) {
			return;
		}

		$error_file     = $error['file'] ?? '';
		$mu_dir         = SandboxFiles::mu_dir();
		$sandbox_prefix = $mu_dir . '/' . SandboxFiles::active_prefix();

		// Only act if the error originated from a live sandbox mu-plugin file
		// (must start with the sandbox prefix AND end in .php — never touch
		// already-renamed .crashed or .disabled files).
		if (
			'' === $error_file
			|| ! str_starts_with( $error_file, $sandbox_prefix )
			|| ! str_ends_with( $error_file, '.php' )
		) {
			return;
		}

		// Rename to .crashed so it won't load on next request.
		$crashed_path = $error_file . '.crashed';

		if ( file_exists( $error_file ) && ! file_exists( $crashed_path ) ) {
			@rename( $error_file, $crashed_path ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
		}

		// Record in audit log. We're inside a shutdown function so DB may still
		// be available; use @ to suppress any further errors here.
		$basename = basename( $error_file );
		@AuditLog::record( // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
			'sandbox.crash_disabled',
			[
				'name'    => $basename,
				'message' => $error['message'] ?? '',
				'line'    => $error['line'] ?? 0,
			]
		);
	}

	/**
	 * Displays an admin notice listing any .crashed sandbox files.
	 *
	 * Hook this onto 'admin_notices'.
	 */
	public static function admin_notice(): void {
		$mu_dir  = SandboxFiles::mu_dir();
		$prefix  = SandboxFiles::active_prefix();
		$pattern = $mu_dir . '/' . $prefix . '*.php.crashed';
		$files   = glob( $pattern );

		if ( empty( $files ) ) {
			return;
		}

		$names = array_map( 'basename', $files );
		$list  = implode( ', ', array_map( 'esc_html', $names ) );

		echo '<div class="notice notice-error"><p><strong>Stonewright Sandbox:</strong> ';
		echo 'The following sandbox file(s) caused a fatal error and have been automatically disabled: ';
		echo $list; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- $names already escaped via esc_html above.
		echo '. Please review and fix them before re-activating.</p></div>';
	}
}
