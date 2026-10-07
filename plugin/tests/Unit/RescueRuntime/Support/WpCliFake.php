<?php
declare( strict_types=1 );

/**
 * A stand-in for the WP_CLI class, for tests that run in their own process after defining WP_CLI.
 */
final class WP_CLI {

	/** @var array<string, mixed> */
	public static array $commands = [];

	/** @param array<string, mixed> $args */
	public static function add_command( string $name, mixed $callable, array $args = [] ): bool {
		self::$commands[ $name ] = $callable;
		return true;
	}

	public static function get_root_command(): object {
		return new class() {

			/** @param array<int, string> $args */
			public function find_subcommand( array &$args ): object|false {
				$name = array_shift( $args );
				return isset( WP_CLI::$commands[ $name ] ) ? new \stdClass() : false;
			}
		};
	}
}
