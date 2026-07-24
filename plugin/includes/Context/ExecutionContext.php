<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\Context;

/**
 * Request-scoped verified metadata for internal post-execution services.
 */
final class ExecutionContext {
	private static string $task_hash = '';

	public static function set_task_hash( string $task_hash ): void {
		self::$task_hash = preg_match( '/^[a-f0-9]{64}$/', $task_hash ) ? $task_hash : '';
	}

	public static function task_hash(): string {
		return self::$task_hash;
	}

	public static function clear(): void {
		self::$task_hash = '';
	}
}
