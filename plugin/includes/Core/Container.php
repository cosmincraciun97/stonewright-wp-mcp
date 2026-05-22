<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\Core;

/**
 * Tiny container for sharing services between Stonewright components.
 */
final class Container {

	/**
	 * @var array<string, callable|object>
	 */
	private array $bindings = [];

	/**
	 * @var array<string, object>
	 */
	private array $instances = [];

	public function singleton( string $id, callable $factory ): void {
		$this->bindings[ $id ] = $factory;
	}

	public function instance( string $id, object $object ): void {
		$this->instances[ $id ] = $object;
	}

	public function has( string $id ): bool {
		return isset( $this->bindings[ $id ] ) || isset( $this->instances[ $id ] );
	}

	public function get( string $id ): object {
		if ( isset( $this->instances[ $id ] ) ) {
			return $this->instances[ $id ];
		}

		if ( isset( $this->bindings[ $id ] ) ) {
			$instance = ( $this->bindings[ $id ] )( $this );
			if ( ! is_object( $instance ) ) {
				throw new \RuntimeException( sprintf( 'Binding for "%s" must return an object.', $id ) );
			}
			$this->instances[ $id ] = $instance;
			return $instance;
		}

		throw new \RuntimeException( sprintf( 'No binding registered for "%s".', $id ) );
	}
}
