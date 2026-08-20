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

	protected function tearDown(): void {
		unset( $GLOBALS['stonewright_test_registered_blocks'] );
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
	}

	public function test_finalizer_context_warns_on_unknown_keys_when_schema_is_likely_partial(): void {
		$GLOBALS['stonewright_test_registered_blocks'] = [
			'kadence/row' => (object) [
				'attributes' => [
					'uniqueID' => [ 'type' => 'string' ],
				],
			],
		];

		$result = AttributeValidator::validate(
			'kadence/row',
			[
				'uniqueID'         => 'row-1',
				'kadenceDynamic'   => [ 'enable' => true ],
			],
			null,
			'finalizer'
		);

		self::assertIsArray( $result );
		self::assertContains( 'likely_partial_schema', $this->warning_codes( $result ) );
		self::assertNotInstanceOf( \WP_Error::class, $result );
	}

	public function test_server_context_still_rejects_unknown_keys_on_partial_schemas(): void {
		$GLOBALS['stonewright_test_registered_blocks'] = [
			'kadence/row' => (object) [
				'attributes' => [
					'uniqueID' => [ 'type' => 'string' ],
				],
			],
		];

		$result = AttributeValidator::validate(
			'kadence/row',
			[
				'uniqueID'       => 'row-1',
				'kadenceDynamic' => [ 'enable' => true ],
			],
			null,
			'server'
		);

		self::assertInstanceOf( \WP_Error::class, $result );
		self::assertSame( 'stonewright_unknown_block_attributes', $result->get_error_code() );
		self::assertSame( [ 'kadenceDynamic' ], $result->get_error_data()['offending_keys'] );
	}

	public function test_unregistered_block_is_a_hard_error_in_both_contexts(): void {
		$GLOBALS['stonewright_test_registered_blocks'] = [];

		$finalizer = AttributeValidator::validate( 'missing/block', [ 'foo' => true ], null, 'finalizer' );
		$server    = AttributeValidator::validate( 'missing/block', [ 'foo' => true ], null, 'server' );

		self::assertInstanceOf( \WP_Error::class, $finalizer );
		self::assertInstanceOf( \WP_Error::class, $server );
		self::assertSame( 'stonewright_block_not_registered', $finalizer->get_error_code() );
		self::assertSame( 'stonewright_block_not_registered', $server->get_error_code() );
	}

	public function test_thin_js_bundle_blocks_are_likely_partial_and_known_namespaces_are(): void {
		$GLOBALS['stonewright_test_registered_blocks'] = [
			'thin/widget' => (object) [
				'attributes'    => [
					'text' => [ 'type' => 'string' ],
				],
				'editor_script' => 'thin-widget-editor',
			],
			'thin-handles/widget' => (object) [
				'attributes'            => [
					'text' => [ 'type' => 'string' ],
				],
				'editor_script_handles' => [ 'thin-handles-editor' ],
			],
			'fat/widget'  => (object) [
				'attributes'    => [
					'one'   => [ 'type' => 'string' ],
					'two'   => [ 'type' => 'string' ],
					'three' => [ 'type' => 'string' ],
				],
				'editor_script' => 'fat-widget-editor',
			],
			'kadence/row' => (object) [
				'attributes' => [
					'uniqueID' => [ 'type' => 'string' ],
				],
			],
			'generateblocks/container' => (object) [
				'attributes' => [
					'uniqueId' => [ 'type' => 'string' ],
				],
			],
			'uagb/info-box' => (object) [
				'attributes' => [
					'block_id' => [ 'type' => 'string' ],
				],
			],
		];

		self::assertTrue( AttributeValidator::is_schema_likely_partial( 'thin/widget' ) );
		self::assertTrue( AttributeValidator::is_schema_likely_partial( 'thin-handles/widget' ) );
		self::assertFalse( AttributeValidator::is_schema_likely_partial( 'fat/widget' ) );
		self::assertTrue( AttributeValidator::is_schema_likely_partial( 'kadence/row' ) );
		self::assertTrue( AttributeValidator::is_schema_likely_partial( 'generateblocks/container' ) );
		self::assertTrue( AttributeValidator::is_schema_likely_partial( 'uagb/info-box' ) );
	}

	public function test_finalizer_warns_for_thin_js_bundle_unknown_keys(): void {
		$GLOBALS['stonewright_test_registered_blocks'] = [
			'thin/widget' => (object) [
				'attributes'    => [
					'text' => [ 'type' => 'string' ],
				],
				'editor_script' => 'thin-widget-editor',
			],
		];

		$result = AttributeValidator::validate(
			'thin/widget',
			[
				'text'     => 'hi',
				'uniqueID' => 'abc',
			],
			null,
			'finalizer'
		);

		self::assertIsArray( $result );
		self::assertContains( 'likely_partial_schema', $this->warning_codes( $result ) );
	}

	public function test_finalizer_context_still_rejects_unknown_keys_on_complete_schemas(): void {
		$GLOBALS['stonewright_test_registered_blocks'] = [
			'vendor/card' => (object) [
				'attributes'    => [
					'title' => [ 'type' => 'string' ],
					'tone'  => [ 'type' => 'string' ],
					'level' => [ 'type' => 'integer' ],
				],
				'editor_script' => 'vendor-card-editor',
			],
		];

		$result = AttributeValidator::validate(
			'vendor/card',
			[
				'title'      => 'Stone',
				'undeclared' => true,
			],
			null,
			'finalizer'
		);

		self::assertInstanceOf( \WP_Error::class, $result );
		self::assertSame( 'stonewright_unknown_block_attributes', $result->get_error_code() );
	}

	/**
	 * @param array<string, mixed> $result
	 * @return list<string>
	 */
	private function warning_codes( array $result ): array {
		$warnings = $result['warnings'] ?? [];
		if ( ! is_array( $warnings ) ) {
			return [];
		}
		$codes = [];
		foreach ( $warnings as $warning ) {
			if ( is_string( $warning ) ) {
				$codes[] = $warning;
				continue;
			}
			if ( is_array( $warning ) && isset( $warning['code'] ) && is_string( $warning['code'] ) ) {
				$codes[] = $warning['code'];
			}
		}
		return $codes;
	}
}
