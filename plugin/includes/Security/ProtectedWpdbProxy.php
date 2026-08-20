<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\Security;

/**
 * Forwards $wpdb access while intercepting write verbs for php-execute.
 *
 * @phpstan-ignore-next-line
 */
final class ProtectedWpdbProxy {

	public function __construct(
		private object $inner,
		private bool $read_only
	) {}

	public function __get( string $name ): mixed {
		return $this->inner->{$name} ?? null;
	}

	public function __set( string $name, mixed $value ): void {
		$this->inner->{$name} = $value;
	}

	public function __isset( string $name ): bool {
		return isset( $this->inner->{$name} );
	}

	/** @param array<int, mixed> $arguments */
	public function __call( string $name, array $arguments ): mixed {
		ProtectedWpdbWriteGuard::assert_allowed( $name, $arguments, $this->read_only );
		return $this->inner->{$name}( ...$arguments );
	}
}
