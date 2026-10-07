<?php
declare( strict_types=1 );

/**
 * Test double for the Abilities API registry.
 *
 * Every Stonewright ability counts as registered unless a test lists the registered names
 * in `$GLOBALS['stonewright_test_registered_abilities']`.
 */

if ( ! function_exists( 'wp_get_abilities' ) ) {
	/**
	 * @return list<object>
	 */
	function wp_get_abilities(): array {
		$names = $GLOBALS['stonewright_test_registered_abilities'] ?? null;
		if ( ! is_array( $names ) ) {
			$names = [];
			foreach ( \Stonewright\WpMcp\Core\AbilityRegistry::list() as $class ) {
				$names[] = ( new $class() )->name();
			}
		}

		return array_map(
			static fn( string $name ): object => new class( $name ) {
				public function __construct( private string $name ) {}

				public function get_name(): string {
					return $this->name;
				}
			},
			$names
		);
	}
}
