<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\Support;

/**
 * Thin logger wrapper around `error_log` with structured payloads.
 *
 * Honors `STONEWRIGHT_DEBUG` (defaults to `WP_DEBUG`).
 */
final class Logger {

	public static function debug( string $event, array $context = [] ): void {
		self::write( 'debug', $event, $context );
	}

	public static function info( string $event, array $context = [] ): void {
		self::write( 'info', $event, $context );
	}

	public static function warning( string $event, array $context = [] ): void {
		self::write( 'warning', $event, $context );
	}

	public static function error( string $event, array $context = [] ): void {
		self::write( 'error', $event, $context );
	}

	private static function write( string $level, string $event, array $context ): void {
		if ( 'debug' === $level && ! self::debug_enabled() ) {
			return;
		}

		$payload = wp_json_encode(
			[
				'ts'      => time(),
				'level'   => $level,
				'event'   => 'stonewright.' . $event,
				'context' => $context,
			]
		);

		if ( false === $payload ) {
			return;
		}

		error_log( $payload ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
	}

	private static function debug_enabled(): bool {
		if ( defined( 'STONEWRIGHT_DEBUG' ) ) {
			return (bool) constant( 'STONEWRIGHT_DEBUG' );
		}
		return defined( 'WP_DEBUG' ) && constant( 'WP_DEBUG' );
	}
}
