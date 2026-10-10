<?php
/**
 * The input schema of insert_section accepts detach_patterns as a boolean or a list of pattern ids.
 * SPDX-License-Identifier: GPL-2.0-or-later
 *
 * @package Stonewright
 */

declare( strict_types=1 );

namespace Stonewright\WpMcp\Tests\Unit\SectionReuse;

use PHPUnit\Framework\TestCase;
use Stonewright\WpMcp\Abilities\Gutenberg\BlocksBatchMutate;
use Stonewright\WpMcp\Core\RegisteredAbility;

/**
 * The bootstrap validator accepts every value, so these tests install one that follows WordPress core's
 * rules for the schema keywords the schema uses: a multi-type `type` picks the first listed type the value
 * fits, `oneOf` accepts a value only when exactly one branch matches, and a scalar counts as a list when
 * it splits into one (so `true` and `false` fit a list of integers). The real input path of the ability
 * runs it.
 *
 * @covers \Stonewright\WpMcp\Abilities\Gutenberg\BlocksBatchMutate::input_schema
 */
final class DetachPatternsSchemaTest extends TestCase {

	private const VENDOR_ABILITY = __DIR__ . '/../../../vendor/wordpress/abilities-api/includes/abilities-api/class-wp-ability.php';

	protected function setUp(): void {
		if ( ! class_exists( 'WP_Ability', false ) && is_readable( self::VENDOR_ABILITY ) ) {
			require_once self::VENDOR_ABILITY;
		}
		$GLOBALS['stonewright_test_rest_validator'] = self::validator();
	}

	protected function tearDown(): void {
		unset( $GLOBALS['stonewright_test_rest_validator'] );
	}

	/** @return array<string, array{0:mixed}> */
	public static function accepted_values(): array {
		return [
			'true'          => [ true ],
			'false'         => [ false ],
			'a list of ids' => [ [ 40, 41 ] ],
			'an empty list' => [ [] ],
		];
	}

	/** @dataProvider accepted_values */
	public function test_the_input_validation_accepts_detach_patterns( mixed $value ): void {
		$result = $this->ability()->validate_input( $this->input( $value ) );

		self::assertTrue( $result, $result instanceof \WP_Error ? $result->get_error_message() : '' );
	}

	/** @return array<string, array{0:mixed}> */
	public static function refused_values(): array {
		return [
			'a string'             => [ 'yes' ],
			'a list with a non-id' => [ [ 'abc' ] ],
			'a list with zero'     => [ [ 0 ] ],
			'an object'            => [ [ 'id' => 40 ] ],
		];
	}

	/** @dataProvider refused_values */
	public function test_the_input_validation_still_refuses_other_values( mixed $value ): void {
		self::assertInstanceOf( \WP_Error::class, $this->ability()->validate_input( $this->input( $value ) ) );
	}

	public function test_the_schema_is_a_plain_multi_type_that_every_validator_reads(): void {
		$property = ( new BlocksBatchMutate() )->input_schema()['properties']['operations']['items']['properties']['detach_patterns'];

		self::assertArrayNotHasKey( 'oneOf', $property );
		self::assertArrayNotHasKey( 'anyOf', $property );
		self::assertSame( [ 'boolean', 'array' ], $property['type'] );
		self::assertSame( [ 'type' => 'integer', 'minimum' => 1 ], $property['items'] );
	}

	/** @return array<string, mixed> */
	private function input( mixed $detach ): array {
		return [
			'post_id'    => 701,
			'operations' => [
				[
					'action'          => 'insert_section',
					'op_id'           => 'a',
					'section'         => [ 'schema' => 'SectionPortableV1' ],
					'detach_patterns' => $detach,
				],
			],
		];
	}

	private function ability(): RegisteredAbility {
		$kernel = new BlocksBatchMutate();

		return new RegisteredAbility(
			$kernel->name(),
			[
				'label'               => 'Batch mutate Gutenberg blocks',
				'description'         => 'Schema test.',
				'input_schema'        => $kernel->input_schema(),
				'output_schema'       => [],
				'permission_callback' => static fn(): bool => true,
				'execute_callback'    => static fn(): array => [],
				'meta'                => [],
			]
		);
	}

	/** @return list<string> The values a text splits into when WordPress reads it as a list. */
	private static function split_list( string $text ): array {
		return '' === $text ? [] : (array) preg_split( '/[\s,]+/', $text, -1, PREG_SPLIT_NO_EMPTY );
	}

	/** @return callable(mixed, mixed, string): (bool|\WP_Error) */
	private static function validator(): callable {
		$fits = [
			'array'   => static fn( mixed $value ): bool => is_array( $value ) ? array_is_list( $value ) : is_scalar( $value ),
			'object'  => static fn( mixed $value ): bool => is_array( $value ) && ( [] === $value || ! array_is_list( $value ) ),
			'integer' => static fn( mixed $value ): bool => is_numeric( $value ) && (float) (int) $value === (float) $value,
			'number'  => 'is_numeric',
			'boolean' => static fn( mixed $value ): bool => is_bool( $value ) || ( is_string( $value ) && in_array( strtolower( $value ), [ 'true', 'false' ], true ) ) || ( is_int( $value ) && in_array( $value, [ 0, 1 ], true ) ),
			'string'  => 'is_string',
			'null'    => 'is_null',
		];
		$validate = null;
		$validate = static function ( mixed $value, mixed $schema, string $param ) use ( &$validate, $fits ): bool|\WP_Error {
			if ( ! is_array( $schema ) ) {
				return true;
			}
			if ( isset( $schema['oneOf'] ) ) {
				$matches = 0;
				foreach ( $schema['oneOf'] as $branch ) {
					if ( ! isset( $branch['type'] ) && isset( $schema['type'] ) ) {
						$branch['type'] = $schema['type'];
					}
					if ( ! $validate( $value, $branch, $param ) instanceof \WP_Error ) {
						++$matches;
					}
				}
				if ( 0 === $matches ) {
					return new \WP_Error( 'rest_no_matching_schema', $param . ' does not match any of the expected formats.' );
				}
				if ( $matches > 1 ) {
					return new \WP_Error( 'rest_one_of_multiple_matches', $param . ' matches more than one of the expected formats.' );
				}
				if ( ! isset( $schema['type'] ) ) {
					return true;
				}
			}
			$type = $schema['type'] ?? null;
			if ( is_array( $type ) ) {
				$best = '';
				foreach ( $type as $candidate ) {
					if ( isset( $fits[ $candidate ] ) && $fits[ $candidate ]( $value ) ) {
						$best = $candidate;
						break;
					}
				}
				if ( '' === $best ) {
					return new \WP_Error( 'rest_invalid_type', $param . ' is not of type ' . implode( ',', $type ) . '.' );
				}
				$type = $best;
			}
			if ( null === $type ) {
				return true;
			}
			if ( isset( $fits[ $type ] ) && ! $fits[ $type ]( $value ) ) {
				return new \WP_Error( 'rest_invalid_type', $param . ' is not of type ' . $type . '.' );
			}
			if ( 'integer' === $type && isset( $schema['minimum'] ) && (int) $value < $schema['minimum'] ) {
				return new \WP_Error( 'rest_out_of_bounds', $param . ' must be greater than or equal to ' . $schema['minimum'] . '.' );
			}
			if ( 'array' === $type && isset( $schema['items'] ) ) {
				$items = is_scalar( $value ) ? self::split_list( (string) $value ) : $value;
				foreach ( (array) $items as $index => $item ) {
					$checked = $validate( $item, $schema['items'], $param . '[' . $index . ']' );
					if ( $checked instanceof \WP_Error ) {
						return $checked;
					}
				}
			}
			if ( 'object' === $type && is_array( $value ) ) {
				foreach ( $value as $key => $item ) {
					if ( isset( $schema['properties'][ $key ] ) ) {
						$checked = $validate( $item, $schema['properties'][ $key ], $param . '[' . $key . ']' );
						if ( $checked instanceof \WP_Error ) {
							return $checked;
						}
					}
				}
			}

			return true;
		};

		return $validate;
	}
}
