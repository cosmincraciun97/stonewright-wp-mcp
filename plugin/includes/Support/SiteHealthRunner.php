<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\Support;

/**
 * Runs WordPress Site Health tests on the server.
 *
 * WordPress lists a direct test as `[ 'label' => ..., 'test' => 'name' ]` and runs it through
 * the `get_test_<name>()` method of its Site Health object. Tests added by other code may hand
 * over a callable instead. This class follows the same two rules.
 */
final class SiteHealthRunner {

	private const DESCRIPTION_MAX = 500;

	/**
	 * Files the Site Health tests rely on when WordPress has not loaded the admin yet.
	 *
	 * @var list<string>
	 */
	private const ADMIN_INCLUDES = [ 'update.php', 'plugin.php', 'misc.php', 'file.php' ];

	/**
	 * Loads the admin includes that Site Health tests call into.
	 */
	public static function load_admin_includes(): void {
		if ( ! defined( 'ABSPATH' ) ) {
			return;
		}

		foreach ( self::ADMIN_INCLUDES as $file ) {
			$path = ABSPATH . 'wp-admin/includes/' . $file;
			if ( is_file( $path ) ) {
				require_once $path;
			}
		}
	}

	/**
	 * Runs every direct test of the Site Health object.
	 *
	 * @param object $health WP_Site_Health instance.
	 * @return list<array{name: string, status: string, label: string}>
	 */
	public static function run_direct_tests( object $health ): array {
		$tests  = method_exists( $health, 'get_tests' ) ? $health->get_tests() : [];
		$direct = is_array( $tests ) && is_array( $tests['direct'] ?? null ) ? $tests['direct'] : [];
		$rows   = [];

		foreach ( $direct as $name => $definition ) {
			if ( ! is_array( $definition ) ) {
				continue;
			}
			$callable = self::callable_for( $health, $definition['test'] ?? null );
			if ( null === $callable ) {
				continue;
			}

			try {
				$result = call_user_func( $callable );
			} catch ( \Throwable $error ) {
				unset( $error );
				$rows[] = [
					'name'   => (string) $name,
					'status' => 'error',
					'label'  => (string) ( $definition['label'] ?? $name ),
				];
				continue;
			}
			if ( ! is_array( $result ) ) {
				continue;
			}

			$rows[] = [
				'name'   => (string) $name,
				'status' => (string) ( $result['status'] ?? 'unknown' ),
				'label'  => (string) ( $result['label'] ?? '' ),
			];
		}

		return $rows;
	}

	/**
	 * Runs one test by its Site Health name, for example `loopback-requests`.
	 *
	 * @param object $health WP_Site_Health instance.
	 * @return array{status: string, label: string, description: string, badge: string}|null Null when this WordPress has no such test.
	 */
	public static function run_named_test( object $health, string $test ): ?array {
		$method = 'get_test_' . str_replace( '-', '_', $test );
		if ( 1 !== preg_match( '/^get_test_[a-z0-9_]+$/', $method ) || ! method_exists( $health, $method ) || ! is_callable( [ $health, $method ] ) ) {
			return null;
		}

		try {
			$result = call_user_func( [ $health, $method ] );
		} catch ( \Throwable $error ) {
			return [
				'status'      => 'error',
				'label'       => $test,
				'description' => mb_substr( $error->getMessage(), 0, self::DESCRIPTION_MAX ),
				'badge'       => '',
			];
		}
		if ( ! is_array( $result ) ) {
			return null;
		}

		$badge       = $result['badge'] ?? null;
		$description = trim( (string) preg_replace( '/\s+/', ' ', wp_strip_all_tags( (string) ( $result['description'] ?? '' ) ) ) );

		return [
			'status'      => (string) ( $result['status'] ?? 'unknown' ),
			'label'       => (string) ( $result['label'] ?? '' ),
			'description' => mb_substr( $description, 0, self::DESCRIPTION_MAX ),
			'badge'       => is_array( $badge ) ? (string) ( $badge['label'] ?? '' ) : '',
		];
	}

	private static function callable_for( object $health, mixed $test ): ?callable {
		if ( is_string( $test ) && '' !== $test ) {
			$method = 'get_test_' . $test;
			if ( method_exists( $health, $method ) && is_callable( [ $health, $method ] ) ) {
				return [ $health, $method ];
			}
		}

		return is_callable( $test ) ? $test : null;
	}
}
