<?php
/**
 * The input validator leaves the task context token to the context guard: callers pass it to every ability,
 * and an ability that does not need it must not refuse it as an unknown property.
 * SPDX-License-Identifier: GPL-2.0-or-later
 *
 * @package Stonewright
 */

declare( strict_types=1 );

namespace Stonewright\WpMcp\Tests\Unit\Core;

use PHPUnit\Framework\TestCase;
use Stonewright\WpMcp\Abilities\SectionReuse\SectionReuseFind;
use Stonewright\WpMcp\Core\AbilityInputValidator;

/**
 * @covers \Stonewright\WpMcp\Core\AbilityInputValidator
 */
final class AbilityInputValidatorContextTokenTest extends TestCase {

	protected function setUp(): void {
		// Like WordPress: an object schema with additionalProperties false refuses a property it does not list.
		$GLOBALS['stonewright_test_rest_validator'] = static function ( mixed $value, mixed $schema ): bool|\WP_Error {
			if ( is_array( $schema ) && is_array( $value ) && 'object' === ( $schema['type'] ?? null ) && false === ( $schema['additionalProperties'] ?? true ) ) {
				foreach ( array_keys( $value ) as $key ) {
					if ( ! array_key_exists( (string) $key, (array) ( $schema['properties'] ?? [] ) ) ) {
						return new \WP_Error( 'rest_additional_properties_forbidden', sprintf( '%s is not a valid property of Object.', (string) $key ) );
					}
				}
			}
			return true;
		};
	}

	protected function tearDown(): void {
		unset( $GLOBALS['stonewright_test_rest_validator'] );
	}

	public function test_a_context_token_is_not_an_unknown_property_for_an_ability_that_does_not_need_it(): void {
		$result = AbilityInputValidator::validate(
			new SectionReuseFind(),
			[
				'builder'                   => 'elementor-v3',
				'roles'                     => [ 'hero', 'features', 'other' ],
				'target_post_id'            => 53,
				'stonewright_context_token' => 'swctx_example',
			]
		);

		self::assertTrue( $result );
	}

	public function test_other_unknown_properties_are_still_refused(): void {
		$result = AbilityInputValidator::validate(
			new SectionReuseFind(),
			[
				'builder'                   => 'elementor-v3',
				'roles'                     => [ 'hero' ],
				'stonewright_context_token' => 'swctx_example',
				'unknown_field'             => true,
			]
		);

		self::assertInstanceOf( \WP_Error::class, $result );
		self::assertSame( 'ability_invalid_input', $result->get_error_code() );
	}
}
