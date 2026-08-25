<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\Security;

/**
 * Forwards $wpdb access while intercepting write verbs for php-execute.
 *
 * Extends wpdb so strict third-party type checks keep working. Does not call
 * the parent constructor, so php-execute never opens a second connection.
 */
#[\AllowDynamicProperties]
final class ProtectedWpdbProxy extends \wpdb {

	private const PROXY_INTERNALS = [ 'inner', 'read_only' ];

	public function __construct(
		private \wpdb $inner,
		private bool $read_only
	) {
		$this->synchronize_from_inner();
	}

	/**
	 * Single write-policy choke point. Inherited helpers flow through here.
	 *
	 * @param string $query Database query.
	 * @return bool|int|mixed Query result from the inner wpdb.
	 */
	public function query( $query ) {
		ProtectedWpdbWriteGuard::assert_allowed( 'query', [ $query ], $this->read_only );
		$this->synchronize_to_inner();
		$result = $this->inner->query( $query );
		$this->synchronize_from_inner();
		return $result;
	}

	/**
	 * Copy mutable result and connection state back onto the original handle.
	 */
	public function flush_to_inner(): void {
		$this->synchronize_to_inner();
	}

	public function __get( $name ) {
		$name = (string) $name;
		if ( $this->is_proxy_internal( $name ) ) {
			return null;
		}

		$vars = get_object_vars( $this );
		if ( array_key_exists( $name, $vars ) ) {
			return $vars[ $name ];
		}

		return $this->inner->{$name} ?? null;
	}

	public function __set( $name, $value ) {
		$name = (string) $name;
		if ( $this->is_proxy_internal( $name ) ) {
			return;
		}
		$this->{$name} = $value;
	}

	public function __isset( $name ) {
		$name = (string) $name;
		if ( $this->is_proxy_internal( $name ) ) {
			return false;
		}

		return array_key_exists( $name, get_object_vars( $this ) ) || isset( $this->inner->{$name} );
	}

	/**
	 * Forward helpers that exist on the inner handle but not on this class.
	 * Needed when a test stub's insert/update/get_* bypass query().
	 *
	 * @param array<int, mixed> $arguments
	 */
	public function __call( string $name, array $arguments ): mixed {
		ProtectedWpdbWriteGuard::assert_allowed( $name, $arguments, $this->read_only );
		$this->synchronize_to_inner();
		$result = $this->inner->{$name}( ...$arguments );
		$this->synchronize_from_inner();
		return $result;
	}

	private function synchronize_from_inner(): void {
		$this->copy_wpdb_state( $this->inner, $this );
	}

	private function synchronize_to_inner(): void {
		$this->copy_wpdb_state( $this, $this->inner );
	}

	private function copy_wpdb_state( object $from, object $to ): void {
		$copied = [];
		foreach ( $this->wpdb_hierarchy() as $class ) {
			foreach ( $class->getProperties() as $property ) {
				if ( $property->isStatic() ) {
					continue;
				}
				$name = $property->getName();
				if ( isset( $copied[ $name ] ) || $this->is_proxy_internal( $name ) ) {
					continue;
				}
				$copied[ $name ] = true;
				if ( ! $this->property_initialized( $property, $from ) ) {
					continue;
				}
				$value = $property->getValue( $from );
				try {
					$property->setValue( $to, $value );
				} catch ( \Throwable $exception ) {
					unset( $exception );
					$to->{$name} = $value;
				}
			}
		}

		foreach ( get_object_vars( $from ) as $name => $value ) {
			if ( isset( $copied[ $name ] ) || $this->is_proxy_internal( $name ) ) {
				continue;
			}
			$to->{$name} = $value;
			$copied[ $name ] = true;
		}
	}

	/**
	 * Bound to wpdb and its parents; never copies Stonewright proxy internals.
	 *
	 * @return list<\ReflectionClass<object>>
	 */
	private function wpdb_hierarchy(): array {
		$classes = [];
		$class   = new \ReflectionClass( \wpdb::class );
		while ( $class instanceof \ReflectionClass ) {
			$classes[] = $class;
			$parent    = $class->getParentClass();
			$class     = $parent instanceof \ReflectionClass ? $parent : null;
		}
		return $classes;
	}

	private function property_initialized( \ReflectionProperty $property, object $object ): bool {
		try {
			return $property->isInitialized( $object );
		} catch ( \Throwable $exception ) {
			unset( $exception );
			return false;
		}
	}

	private function is_proxy_internal( string $name ): bool {
		return in_array( $name, self::PROXY_INTERNALS, true );
	}
}
