<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\Security;

/**
 * Prevents runtime PHP snippets from bypassing typed Elementor write gates.
 *
 * Read paths against protected Elementor meta (get_post_meta, SELECT, json_decode)
 * remain allowed. Only raw write/delete/update paths are blocked.
 */
final class ProtectedElementorWriteGuard {
	private const META_KEYS = [
		'_elementor_data',
		'_elementor_page_settings',
		'_elementor_edit_mode',
		'_elementor_version',
	];

	/**
	 * Static inspection of a PHP body before evaluation.
	 *
	 * @param bool $read_only When true, also reject obvious WordPress mutation APIs.
	 */
	public static function inspect( string $code, bool $read_only = false ): bool|\WP_Error {
		$lower = strtolower( $code );
		$mentions_protected_meta = false;
		foreach ( self::META_KEYS as $key ) {
			if ( str_contains( $lower, $key ) ) {
				$mentions_protected_meta = true;
				break;
			}
		}

		$direct_helper = preg_match( '/(?:ElementorData|ElementorWriter|DocumentIntegrityGate)\s*::\s*(?:write|assert_write_allowed)\s*\(/i', $code );
		$raw_mutator   = preg_match( '/\b(?:update_post_meta|add_post_meta|delete_post_meta|update_metadata|add_metadata|delete_metadata)\s*\(/i', $code );
		// Only treat $wpdb write verbs as mutation when protected meta is mentioned.
		$wpdb_write    = (bool) preg_match( '/\$wpdb\s*->\s*(?:update|insert|replace|delete|query)\s*\(/i', $code );
		$wpdb_select   = (bool) preg_match( '/\$wpdb\s*->\s*(?:get_var|get_row|get_col|get_results|prepare)\s*\(/i', $code );
		// Common indirection / shell / file bypasses used to mutate Elementor docs.
		$indirect = (bool) preg_match(
			'/\b(?:call_user_func(?:_array)?|forward_static_call(?:_array)?|invokeArgs|Reflection(?:Method|Function))\s*\(/i',
			$code
		);
		$file_write = (bool) preg_match( '/\b(?:file_put_contents|fwrite|fputs)\s*\(/i', $code );
		$wp_cli_meta = (bool) preg_match( '/\b(?:WP_CLI|wp_cli)\b/i', $code ) && $mentions_protected_meta;

		if (
			1 === $direct_helper
			|| ( $mentions_protected_meta && ( 1 === $raw_mutator || $wpdb_write || $indirect || $file_write || $wp_cli_meta ) )
		) {
			$error = self::blocked_error();
			$data  = $error->get_error_data();
			$data  = is_array( $data ) ? $data : [];
			$data['wpdb_select_ok'] = $wpdb_select;
			$error->add_data( $data, $error->get_error_code() );
			return $error;
		}

		if ( $read_only ) {
			$mutation = preg_match(
				'/\b(?:update_option|delete_option|add_option|update_post_meta|add_post_meta|delete_post_meta|update_metadata|delete_metadata|wp_insert_post|wp_update_post|wp_delete_post|wp_update_attachment_metadata|file_put_contents|fwrite|unlink|rename|move_uploaded_file)\s*\(/i',
				$code
			);
			if ( 1 === $mutation || $wpdb_write ) {
				return self::read_only_error();
			}
		}

		return true;
	}

	/** @return list<string> */
	public static function protected_meta_keys(): array {
		return self::META_KEYS;
	}

	public static function is_protected_meta_key( string $meta_key ): bool {
		return in_array( $meta_key, self::META_KEYS, true );
	}

	public static function blocked_error(): \WP_Error {
		return new \WP_Error(
			'stonewright_php_elementor_raw_write_blocked',
			__( 'Raw Elementor document writes are blocked in php-execute. Use typed Elementor abilities so schema validation, backup, architecture checks, readback, and audit gates run.', 'stonewright' ),
			[
				'status'                   => 400,
				'retryable'                => false,
				'do_not_retry_php_execute' => true,
				'cause'                    => 'php-execute cannot mutate protected Elementor document metadata.',
				'repair'                   => 'Return to the typed Elementor ability that failed, follow its schema_request exactly, and rerun one consolidated dry-run. Do not retry this write through php-execute or WP-CLI.',
				'protected_meta'           => self::META_KEYS,
				'error_code'               => 'php_elementor_raw_write_blocked',
				'recommended_tools'        => [
					'stonewright/elementor-v3-build-page-from-spec',
					'stonewright/elementor-v3-batch-mutate',
					'stonewright/elementor-v4-read-atomic-tree',
				],
				'next_call'                => [
					'ability' => 'stonewright/elementor-v3-batch-mutate',
					'mode'    => 'dry_run',
					'rule'    => 'Use the schema_requests returned by the failed typed batch; do not guess rejected controls.',
				],
				'allowed_reads'            => [
					'get_post_meta',
					'get_post_meta(..., true)',
					'$wpdb->get_var / get_results with SELECT',
					'json_decode on _elementor_data',
				],
			]
		);
	}

	public static function blocked_exception(): GuardedRuntimeWriteException {
		$error = self::blocked_error();
		return new GuardedRuntimeWriteException(
			(string) $error->get_error_code(),
			$error->get_error_message(),
			is_array( $error->get_error_data() ) ? $error->get_error_data() : []
		);
	}

	public static function read_only_error(): \WP_Error {
		return new \WP_Error(
			'stonewright_php_read_only_violation',
			__( 'php-execute was called with read_only:true but the code mutated WordPress state. Remove writes or set read_only:false.', 'stonewright' ),
			[
				'status'     => 400,
				'retryable'  => true,
				'error_code' => 'php_read_only_violation',
				'fix'        => [ 'remove_mutations', 'set_read_only_false' ],
			]
		);
	}

	public static function read_only_exception(): GuardedRuntimeWriteException {
		$error = self::read_only_error();
		return new GuardedRuntimeWriteException(
			(string) $error->get_error_code(),
			$error->get_error_message(),
			is_array( $error->get_error_data() ) ? $error->get_error_data() : []
		);
	}

	/**
	 * Install runtime filters that throw if protected meta is mutated.
	 * When $read_only is true, also block generic option/meta mutations.
	 *
	 * @return array<string, callable>
	 */
	public static function install( bool $read_only = false ): array {
		$callbacks = [];
		foreach ( [ 'update_post_metadata', 'add_post_metadata', 'delete_post_metadata' ] as $hook ) {
			$callback = static function ( mixed $check, mixed $object_id, mixed $meta_key, mixed $meta_value = null, mixed $previous = null ) use ( $read_only ): mixed {
				unset( $object_id, $meta_value, $previous );
				if ( self::is_protected_meta_key( (string) $meta_key ) ) {
					throw self::blocked_exception();
				}
				if ( $read_only ) {
					throw self::read_only_exception();
				}
				return $check;
			};
			add_filter( $hook, $callback, PHP_INT_MIN, 5 );
			$callbacks[ $hook ] = $callback;
		}

		if ( $read_only ) {
			$pre_option = static function ( mixed $value, mixed $option = null, mixed $old_value = null ) {
				unset( $value, $option, $old_value );
				throw self::read_only_exception();
			};
			// Runtime enforcement: option updates that pass through pre_update_option are blocked.
			// @phpstan-ignore-next-line argument.type (throws; never returns a value)
			add_filter( 'pre_update_option', $pre_option, PHP_INT_MIN, 3 );
			$callbacks['pre_update_option'] = $pre_option;
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
