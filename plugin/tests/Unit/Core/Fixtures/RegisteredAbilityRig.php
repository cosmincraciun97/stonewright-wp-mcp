<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\Tests\Unit\Core\Fixtures;

use Stonewright\WpMcp\Core\RegisteredAbility;

/**
 * Builds RegisteredAbility objects whose callbacks and lifecycle filters are counted, and
 * gives them a schema validator that refuses, because the bootstrap validator accepts anything.
 */
trait RegisteredAbilityRig {

	/** @var array<string, int> */
	protected array $calls = [];

	/** @var list<array{0:string,1:mixed}> */
	protected array $seen = [];

	protected function install_rig(): void {
		$this->calls = [ 'permission' => 0, 'execute' => 0 ];
		$this->seen  = [];
		$GLOBALS['stonewright_test_filters']        = [];
		$GLOBALS['stonewright_test_actions']        = [];
		$GLOBALS['stonewright_test_rest_validator'] = self::validator();
	}

	protected function remove_rig(): void {
		$GLOBALS['stonewright_test_filters'] = [];
		$GLOBALS['stonewright_test_actions'] = [];
		unset( $GLOBALS['stonewright_test_rest_validator'] );
	}

	/**
	 * @param array<string, mixed> $overrides Constructor arguments that replace the defaults.
	 */
	protected function ability( array $overrides = [] ): RegisteredAbility {
		return new RegisteredAbility(
			'stonewright/test-lifecycle',
			array_merge(
				[
					'label'               => 'Lifecycle test',
					'description'         => 'Lifecycle test ability.',
					'input_schema'        => [
						'type'                 => 'object',
						'additionalProperties' => false,
						'required'             => [ 'value' ],
						'properties'           => [ 'value' => [ 'type' => 'string' ] ],
					],
					'output_schema'       => [
						'type'       => 'object',
						'required'   => [ 'ok' ],
						'properties' => [ 'ok' => [ 'type' => 'boolean' ] ],
					],
					'permission_callback' => function ( array $input ): bool {
						++$this->calls['permission'];
						$this->seen[] = [ 'permission', $input ];
						return true;
					},
					'execute_callback'    => function ( array $input ): array {
						++$this->calls['execute'];
						$this->seen[] = [ 'execute', $input ];
						return [ 'ok' => true ];
					},
					'meta'                => [],
				],
				$overrides
			)
		);
	}

	/**
	 * Installs a filter and counts how often it ran.
	 */
	protected function filter( string $hook, callable $callback ): void {
		$this->calls[ $hook ] = 0;
		$GLOBALS['stonewright_test_filters'][ $hook ] = function ( mixed ...$args ) use ( $hook, $callback ): mixed {
			++$this->calls[ $hook ];
			return $callback( ...$args );
		};
	}

	/**
	 * Counts how often a hook ran without changing its value.
	 */
	protected function count_filter( string $hook ): void {
		$this->filter( $hook, static fn ( mixed $value ): mixed => $value );
	}

	/**
	 * @return callable(mixed, mixed, string): (bool|\WP_Error)
	 */
	protected static function validator(): callable {
		$validate = null;
		$validate = static function ( mixed $value, mixed $schema, string $param ) use ( &$validate ): bool|\WP_Error {
			if ( ! is_array( $schema ) ) {
				return true;
			}
			$type = $schema['type'] ?? null;
			if ( 'object' === $type ) {
				if ( ! is_array( $value ) ) {
					return new \WP_Error( 'rest_invalid_type', $param . ' is not of type object.' );
				}
				foreach ( (array) ( $schema['required'] ?? [] ) as $key ) {
					if ( ! array_key_exists( $key, $value ) ) {
						return new \WP_Error( 'rest_property_required', $key . ' is a required property of ' . $param . '.' );
					}
				}
				foreach ( $value as $key => $item ) {
					if ( ! isset( $schema['properties'][ $key ] ) ) {
						if ( false === ( $schema['additionalProperties'] ?? true ) ) {
							return new \WP_Error( 'rest_additional_properties_forbidden', $key . ' is not a valid property of ' . $param . '.' );
						}
						continue;
					}
					$checked = $validate( $item, $schema['properties'][ $key ], $param . '[' . $key . ']' );
					if ( $checked instanceof \WP_Error ) {
						return $checked;
					}
				}
				return true;
			}
			if ( 'string' === $type && ! is_string( $value ) ) {
				return new \WP_Error( 'rest_invalid_type', $param . ' is not of type string.' );
			}
			if ( 'boolean' === $type && ! is_bool( $value ) ) {
				return new \WP_Error( 'rest_invalid_type', $param . ' is not of type boolean.' );
			}
			return true;
		};

		return $validate;
	}
}
