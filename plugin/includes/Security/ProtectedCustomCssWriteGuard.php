<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\Security;

/**
 * Prevents php-execute from writing Customizer or Elementor custom CSS.
 *
 * The gated path is stonewright/theme-custom-css (dry-run + custom_code_grant).
 */
final class ProtectedCustomCssWriteGuard {

	/**
	 * Static inspection of a PHP body before evaluation.
	 */
	public static function inspect( string $code ): bool|\WP_Error {
		if ( preg_match( '/wp_update_custom_css_post\s*\(/i', $code ) ) {
			return self::blocked_error( 'wp_update_custom_css_post' );
		}
		$writes = (bool) preg_match(
			'/\b(?:update_option|add_option|update_post_meta|add_post_meta|update_metadata|add_metadata)\s*\(/i',
			$code
		);
		if ( $writes && preg_match( '/custom_css/i', $code ) ) {
			return self::blocked_error( 'custom_css_option_or_meta_write' );
		}
		return true;
	}

	public static function blocked_error( string $cause = 'custom_css_write' ): \WP_Error {
		return new \WP_Error(
			'stonewright_php_custom_css_write_blocked',
			__( 'Custom CSS writes are blocked in php-execute. Use stonewright/theme-custom-css so dry-run, human approval, backup, and readback gates run.', 'stonewright' ),
			[
				'status'                   => 400,
				'retryable'                => false,
				'do_not_retry_php_execute' => true,
				'cause'                    => $cause,
				'gated_tool'               => 'stonewright/theme-custom-css',
				'gated_mcp_tool'           => 'stonewright-theme-custom-css',
				'error_code'               => 'php_custom_css_write_blocked',
				'repair'                   => 'Run stonewright/theme-custom-css with dry_run:true, show the user approval_url, exact path, byte counts, and change summary, then stop for a human-issued custom_code_grant.',
				'recommended_tools'        => [ 'stonewright/theme-custom-css' ],
				'next_call'                => [
					'ability' => 'stonewright/theme-custom-css',
					'mode'    => 'dry_run',
					'rule'    => 'Do not retry this write through php-execute or raw option/meta updates.',
				],
			]
		);
	}

	public static function blocked_exception( string $cause = 'custom_css_write' ): GuardedRuntimeWriteException {
		$error = self::blocked_error( $cause );
		return new GuardedRuntimeWriteException(
			(string) $error->get_error_code(),
			$error->get_error_message(),
			is_array( $error->get_error_data() ) ? $error->get_error_data() : []
		);
	}

	/**
	 * @return array<string, callable>
	 */
	public static function install(): array {
		$callbacks = [];
		$css_data = static function ( mixed $data, mixed $args = null ) {
			unset( $data, $args );
			throw self::blocked_exception( 'update_custom_css_data' );
		};
		// Runtime enforcement: Customizer CSS updates that pass through this filter are blocked.
		// @phpstan-ignore-next-line argument.type (throws; never returns a value)
		add_filter( 'update_custom_css_data', $css_data, PHP_INT_MIN, 2 );
		$callbacks['update_custom_css_data'] = $css_data;

		$pre_option = static function ( mixed $value, mixed $option = null, mixed $old_value = null ): mixed {
			unset( $old_value );
			$name = strtolower( (string) $option );
			if ( str_contains( $name, 'custom_css' ) ) {
				throw self::blocked_exception( 'option:' . $name );
			}
			return $value;
		};
		add_filter( 'pre_update_option', $pre_option, PHP_INT_MIN, 3 );
		$callbacks['pre_update_option'] = $pre_option;

		return $callbacks;
	}

	/** @param array<string, callable> $callbacks */
	public static function uninstall( array $callbacks ): void {
		foreach ( $callbacks as $hook => $callback ) {
			remove_filter( $hook, $callback, PHP_INT_MIN );
		}
	}
}
