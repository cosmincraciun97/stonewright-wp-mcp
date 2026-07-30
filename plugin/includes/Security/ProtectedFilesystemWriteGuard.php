<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\Security;

/**
 * Permanently blocks php-execute from mutating WordPress code files.
 *
 * Full runtime PHP remains available for WordPress API inspection and non-file
 * work. Code-file writes must use typed file abilities (theme-file-patch) with
 * validation, backup, atomic replace, smoke, audit, and rollback.
 *
 * Cannot be bypassed by confirmation tokens or custom-code grants.
 */
final class ProtectedFilesystemWriteGuard {

	/**
	 * Filesystem mutation function names blocked in php-execute.
	 *
	 * @var list<string>
	 */
	private const MUTATION_FUNCTIONS = [
		'file_put_contents',
		'fwrite',
		'fputs',
		'ftruncate',
		'copy',
		'rename',
		'unlink',
		'move_uploaded_file',
		'rmdir',
		'mkdir',
		'link',
		'symlink',
		'touch',
		'chown',
		'chmod',
		'chgrp',
		'tempnam',
	];

	/**
	 * Execution primitives that can trivially reconstruct or invoke a blocked
	 * filesystem mutation and therefore cannot run inside php-execute.
	 *
	 * @var list<string>
	 */
	private const INDIRECT_EXECUTION_FUNCTIONS = [
		'eval',
		'assert',
		'exec',
		'shell_exec',
		'system',
		'passthru',
		'proc_open',
		'popen',
	];

	/**
	 * Internal gates that php-execute must never call to mint its own authority
	 * or enter a code-file transaction outside the typed ability boundary.
	 *
	 * @var list<string>
	 */
	private const PRIVILEGED_WRITE_CLASSES = [
		'CustomCodeGrant',
		'ThemeWriteTransaction',
		'ThemeFilePatch',
		'ThemeBackupRestore',
		'ConfirmationToken',
	];

	/**
	 * Static inspection before eval. Fail closed when mutation APIs appear and
	 * the target cannot be proven safe (outside WP code roots).
	 */
	public static function inspect( string $code ): bool|\WP_Error {
		if ( ! self::mentions_mutation_api( $code ) ) {
			return true;
		}

		// Any filesystem mutation from php-execute is blocked for code safety.
		// Typed abilities own theme/plugin/file writes with full transactions.
		return self::blocked_error(
			'Filesystem mutation APIs are not allowed in php-execute.',
			[
				'attempted_operation' => self::detect_operations( $code ),
				'target_class'        => self::classify_literal_targets( $code ),
			]
		);
	}

	/**
	 * @return list<string>
	 */
	public static function detect_operations( string $code ): array {
		$found = [];
		$lower = strtolower( $code );
		foreach ( self::MUTATION_FUNCTIONS as $fn ) {
			if ( preg_match( '/\b' . preg_quote( $fn, '/' ) . '\s*\(/i', $code ) ) {
				$found[] = $fn;
			}
		}
		if ( preg_match( '/\bfopen\s*\([^)]*[\'"](?:w|a|x|c|\+)/i', $code ) ) {
			$found[] = 'fopen_write';
		}
		if ( preg_match( '/\$wp_filesystem\s*->\s*(?:put_contents|copy|move|delete|rmdir|mkdir|chmod|chown|touch)\s*\(/i', $code ) ) {
			$found[] = 'wp_filesystem_write';
		}
		if ( preg_match( '/\bWP_Filesystem\s*\(/i', $code ) && preg_match( '/put_contents|->copy\s*\(|->move\s*\(|->delete\s*\(/i', $code ) ) {
			$found[] = 'WP_Filesystem';
		}
		if ( preg_match( '/\b(?:call_user_func(?:_array)?|forward_static_call(?:_array)?)\s*\(\s*[\'"](?:file_put_contents|fwrite|copy|rename|unlink)/i', $code ) ) {
			$found[] = 'callable_indirection';
		}
		if ( preg_match( '/Reflection(?:Method|Function)\s*\(/i', $code ) && ( str_contains( $lower, 'file_put' ) || str_contains( $lower, 'fwrite' ) || str_contains( $lower, 'unlink' ) ) ) {
			$found[] = 'reflection_indirection';
		}
		foreach ( self::INDIRECT_EXECUTION_FUNCTIONS as $fn ) {
			if ( 'eval' === $fn ) {
				if ( preg_match( '/\beval\s*\(/i', $code ) ) {
					$found[] = 'dynamic_code_execution';
				}
				continue;
			}
			if ( preg_match( '/\b' . preg_quote( $fn, '/' ) . '\s*\(/i', $code ) ) {
				$found[] = 'indirect_execution:' . $fn;
			}
		}
		if ( preg_match( '/\b(?:include|include_once|require|require_once)\b/i', $code ) ) {
			$found[] = 'dynamic_include';
		}
		if ( preg_match( '/(?<!->)(?<!::)\$[A-Za-z_][A-Za-z0-9_]*\s*\(/', $code ) ) {
			$found[] = 'variable_function_call';
		}
		if ( preg_match( '/\b(?:SplFileObject|ZipArchive|PharData?)\b/i', $code ) ) {
			$found[] = 'filesystem_object';
		}
		if ( preg_match( '/(?:->|::)\s*\{?\s*\$[A-Za-z_][A-Za-z0-9_]*\s*\}?\s*\(/', $code ) ) {
			$found[] = 'dynamic_method_call';
		}
		if ( preg_match( '/\$[A-Za-z_][A-Za-z0-9_]*\s*::\s*[A-Za-z_][A-Za-z0-9_]*\s*\(/', $code ) ) {
			$found[] = 'dynamic_static_class_call';
		}
		foreach ( self::PRIVILEGED_WRITE_CLASSES as $class ) {
			if ( preg_match( '/\b' . preg_quote( $class, '/' ) . '\b/i', $code ) ) {
				$found[] = 'privileged_write_bypass:' . $class;
			}
		}
		if ( preg_match( '/\b(?:AbilityRegistry|rest_do_request|WP_REST_Request)\b/i', $code ) ) {
			$found[] = 'privileged_dispatch_bypass';
		}
		return array_values( array_unique( $found ) );
	}

	/**
	 * Best-effort classification of string-literal path targets for audit meta.
	 *
	 * @return list<string>
	 */
	public static function classify_literal_targets( string $code ): array {
		$classes = [];
		if ( preg_match_all( '/[\'"]([^\'"]+\.(?:php|css|js|inc))[\'"]/i', $code, $m ) ) {
			foreach ( $m[1] as $path ) {
				$classes[] = self::logical_target_class( $path );
			}
		}
		if ( preg_match( '/functions\.php|get_stylesheet_directory|get_template_directory|WP_PLUGIN_DIR|WPMU_PLUGIN_DIR|ABSPATH/i', $code ) ) {
			$classes[] = 'wordpress_code_root';
		}
		return array_values( array_unique( $classes ?: [ 'unclassified_path' ] ) );
	}

	public static function logical_target_class( string $path ): string {
		$norm = strtolower( str_replace( '\\', '/', $path ) );
		if ( str_contains( $norm, 'functions.php' ) || str_contains( $norm, '/themes/' ) || str_contains( $norm, 'stylesheet' ) ) {
			return 'theme_php';
		}
		if ( str_contains( $norm, '/plugins/' ) || str_contains( $norm, 'wp-content/plugins' ) ) {
			return 'plugin_php';
		}
		if ( str_contains( $norm, 'mu-plugins' ) ) {
			return 'mu_plugin_php';
		}
		if ( str_ends_with( $norm, '.php' ) ) {
			return 'php_file';
		}
		if ( str_ends_with( $norm, '.css' ) || str_ends_with( $norm, '.js' ) ) {
			return 'front_asset';
		}
		return 'other_path';
	}

	private static function mentions_mutation_api( string $code ): bool {
		return [] !== self::detect_operations( $code );
	}

	/**
	 * @param array<string, mixed> $extra
	 */
	private static function blocked_error( string $detail, array $extra = [] ): \WP_Error {
		return new \WP_Error(
			'stonewright_php_code_file_write_blocked',
			__( 'php-execute cannot write theme, plugin, mu-plugin, core, or other WordPress code files. Use stonewright/theme-file-patch (or another typed file ability) after dry_run, operator custom-code approval, full-file validation, backup, atomic write, readback, and smoke checks. Never retry the same file write through php-execute.', 'stonewright' ),
			array_merge(
				[
					'status'                   => 400,
					'retryable'                => false,
					'do_not_retry_php_execute' => true,
					'error_code'               => 'php_code_file_write_blocked',
					'cause'                    => $detail,
					'repair'                   => 'Use stonewright/theme-file-patch with dry_run:true first. Obtain a custom-code grant from wp-admin when required. Do not retry via php-execute.',
					'recommended_tools'        => [
						'stonewright/theme-file-patch',
						'stonewright/theme-file-read',
					],
					'next_call'                => [
						'ability' => 'stonewright/theme-file-patch',
						'mode'    => 'dry_run',
						'rule'    => 'Never write code files through php-execute; typed theme-file-patch owns validation + rollback.',
					],
				],
				$extra
			)
		);
	}
}
