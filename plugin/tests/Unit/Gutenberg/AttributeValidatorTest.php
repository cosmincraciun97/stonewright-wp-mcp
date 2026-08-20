<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\Tests\Unit\Gutenberg;

use PHPUnit\Framework\TestCase;
use Stonewright\WpMcp\Gutenberg\AttributeValidator;

/**
 * @covers \Stonewright\WpMcp\Gutenberg\AttributeValidator
 */
final class AttributeValidatorTest extends TestCase {

	/** @var array<string, mixed> */
	private array $schema = [
		'title' => [
			'type'     => 'string',
			'required' => true,
		],
		'tone'  => [
			'type' => 'string',
			'enum' => [ 'light', 'dark' ],
		],
		'level' => [
			'type' => 'integer',
		],
	];

	public function test_accepts_attributes_that_match_injected_schema(): void {
		$result = AttributeValidator::validate(
			'vendor/card',
			[
				'title' => 'Stone',
				'tone'  => 'dark',
				'level' => 2,
			],
			$this->schema
		);

		self::assertTrue( $result );
	}

	public function test_rejects_unknown_attributes_with_offending_keys(): void {
		$result = AttributeValidator::validate(
			'vendor/card',
			[
				'title'     => 'Stone',
				'undeclared' => true,
				'alsoBad'    => 1,
			],
			$this->schema
		);

		self::assertInstanceOf( \WP_Error::class, $result );
		self::assertSame( 'stonewright_unknown_block_attributes', $result->get_error_code() );
		$data = (array) $result->get_error_data();
		self::assertSame( [ 'undeclared', 'alsoBad' ], $data['offending_keys'] );
		self::assertSame( 'vendor/card', $data['block_name'] );
	}

	public function test_rejects_enum_and_type_mismatches(): void {
		$enum = AttributeValidator::validate(
			'vendor/card',
			[ 'title' => 'Stone', 'tone' => 'neon' ],
			$this->schema
		);
		self::assertInstanceOf( \WP_Error::class, $enum );
		self::assertSame( 'stonewright_invalid_block_attributes', $enum->get_error_code() );

		$type = AttributeValidator::validate(
			'vendor/card',
			[ 'title' => 'Stone', 'level' => 'two' ],
			$this->schema
		);
		self::assertInstanceOf( \WP_Error::class, $type );
		self::assertSame( 'stonewright_invalid_block_attributes', $type->get_error_code() );
	}

	public function test_rejects_missing_required_attributes(): void {
		$result = AttributeValidator::validate( 'vendor/card', [ 'tone' => 'dark' ], $this->schema );

		self::assertInstanceOf( \WP_Error::class, $result );
		self::assertSame( 'stonewright_invalid_block_attributes', $result->get_error_code() );
		self::assertContains( 'title', (array) $result->get_error_data()['offending_keys'] );
	}

	public function test_reads_registered_block_schema_when_none_is_injected(): void {
		$GLOBALS['stonewright_test_registered_blocks'] = [
			'qa/card' => (object) [
				'attributes' => [
					'title' => [ 'type' => 'string' ],
				],
			],
		];

		$ok = AttributeValidator::validate( 'qa/card', [ 'title' => 'ok' ] );
		self::assertTrue( $ok );

		$unknown = AttributeValidator::validate( 'qa/card', [ 'nope' => true ] );
		self::assertInstanceOf( \WP_Error::class, $unknown );
		self::assertSame( [ 'nope' ], $unknown->get_error_data()['offending_keys'] );

		unset( $GLOBALS['stonewright_test_registered_blocks'] );
	}
}
