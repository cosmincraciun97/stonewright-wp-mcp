<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\Security;

/**
 * Prevents runtime PHP snippets from bypassing typed Elementor write gates.
 */
final class ProtectedElementorWriteGuard {
	private const META_KEYS = [
		'_elementor_data',
		'_elementor_page_settings',
		'_elementor_edit_mode',
		'_elementor_version',
	];

	public static function inspect( string $code ): bool|\WP_Error {
		$lower = strtolower( $code );
		$mentions_protected_meta = false;
		foreach ( self::META_KEYS as $key ) {
			if ( str_contains( $lower, $key ) ) {
				$mentions_protected_meta = true;
				break;
			}
		}

		$direct_helper = preg_match( '/(?:ElementorData|ElementorWriter)\s*::\s*write\s*\(/i', $code );
		$raw_mutator   = preg_match( '/\b(?:update_post_meta|add_post_meta|delete_post_meta|update_metadata|add_metadata|delete_metadata)\s*\(/i', $code );
		$direct_sql    = str_contains( $lower, '$wpdb' );

		if ( 1 === $direct_helper || ( $mentions_protected_meta && ( 1 === $raw_mutator || $direct_sql ) ) ) {
			return new \WP_Error(
				'stonewright_php_elementor_raw_write_blocked',
				__( 'Raw Elementor document writes are blocked in php-execute. Use typed Elementor abilities so schema validation, backup, architecture checks, readback, and audit gates run.', 'stonewright' ),
				[
					'status'            => 400,
					'retryable'         => false,
					'do_not_retry_php_execute' => true,
					'cause'             => 'php-execute cannot mutate protected Elementor document metadata.',
					'repair'            => 'Return to the typed Elementor ability that failed, follow its schema_request exactly, and rerun one consolidated dry-run. Do not retry this write through php-execute or WP-CLI.',
					'protected_meta'    => self::META_KEYS,
					'recommended_tools' => [
						'stonewright/elementor-v3-build-page-from-spec',
						'stonewright/elementor-v3-batch-mutate',
						'stonewright/elementor-v4-read-atomic-tree',
					],
					'next_call'         => [
						'ability' => 'stonewright/elementor-v3-batch-mutate',
						'mode'    => 'dry_run',
						'rule'    => 'Use the schema_requests returned by the failed typed batch; do not guess rejected controls.',
					],
				]
			);
		}

		return true;
	}

	/** @return array<string, callable> */
	public static function install(): array {
		$callbacks = [];
		foreach ( [ 'update_post_metadata', 'add_post_metadata', 'delete_post_metadata' ] as $hook ) {
			$callback = static function ( mixed $check, mixed $object_id, mixed $meta_key, mixed $meta_value = null, mixed $previous = null ): mixed {
				if ( in_array( (string) $meta_key, self::META_KEYS, true ) ) {
					throw new \RuntimeException( 'Raw Elementor metadata mutation blocked; use a typed Stonewright Elementor ability.' );
				}
				return $check;
			};
			add_filter( $hook, $callback, PHP_INT_MIN, 5 );
			$callbacks[ $hook ] = $callback;
		}

		return $callbacks;
	}

	/** @param array<string, callable> $callbacks */
	public static function uninstall( array $callbacks ): void {
		foreach ( $callbacks as $hook => $callback ) {
			remove_filter( $hook, $callback, PHP_INT_MIN );
		}
	}
}
