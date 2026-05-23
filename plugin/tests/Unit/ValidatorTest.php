<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Stonewright\WpMcp\DesignSpec\Validator;

/**
 * @covers \Stonewright\WpMcp\DesignSpec\Validator
 */
final class ValidatorTest extends TestCase {

	private static function minimal_valid_spec(): array {
		return [
			'page'     => [ 'title' => 'Test Page' ],
			'sections' => [
				[
					'id'     => 'sec1',
					'blocks' => [
						[ 'type' => 'paragraph', 'text' => 'Hello world' ],
					],
				],
			],
		];
	}

	public function test_validate_returns_normalized_spec_when_valid(): void {
		$result = Validator::validate( self::minimal_valid_spec() );

		$this->assertIsArray( $result, 'Expected array, got WP_Error: ' . ( $result instanceof \WP_Error ? $result->get_error_message() : '' ) );
		$this->assertArrayHasKey( 'sections', $result );
		$this->assertNotEmpty( $result['sections'][0]['blocks'] );
	}

	public function test_validate_returns_wp_error_when_page_title_missing(): void {
		$result = Validator::validate( [] );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'stonewright_spec_invalid', $result->get_error_code() );
	}

	public function test_validate_returns_wp_error_when_no_sections(): void {
		$result = Validator::validate( [
			'page'     => [ 'title' => 'Page with no sections' ],
			'sections' => [],
		] );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'stonewright_spec_invalid', $result->get_error_code() );
	}

	public function test_validate_wp_error_does_not_contain_spec_in_data(): void {
		// Regression: Validator must never embed the raw spec in error data (information disclosure).
		$result = Validator::validate( [] );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$data = $result->get_error_data();
		$this->assertIsArray( $data, 'Error data should be an array.' );
		$this->assertArrayNotHasKey( 'spec', $data, 'Error data must not contain spec (information disclosure).' );
		$this->assertArrayHasKey( 'errors', $data, 'Error data must still contain validation errors.' );
	}

	public function test_normalize_assigns_default_version_and_section_ids(): void {
		$normalized = Validator::normalize( [
			'page'     => [ 'title' => 'Bare' ],
			'sections' => [
				[ 'blocks' => [ [ 'type' => 'paragraph', 'text' => 'Hi' ] ] ],
			],
		] );

		$this->assertSame( '1.0.0', $normalized['version'] );
		$this->assertSame( 'section_0', $normalized['sections'][0]['id'] );
	}

	public function test_validate_succeeds_with_form_block(): void {
		$spec = [
			'version' => '1.0.0',
			'page'     => [ 'title' => 'Form Page' ],
			'sections' => [
				[
					'id'     => 'sec_form',
					'blocks' => [
						[
							'type' => 'form',
							'id' => 'form_block_1',
							'form_name' => 'Newsletter',
							'button_text' => 'Subscribe',
							'fields' => [
								[ 'type' => 'text', 'label' => 'Name', 'placeholder' => 'Enter name', 'required' => true ]
							],
							'submit_actions' => [ 'email' ]
						]
					]
				]
			]
		];

		$result = Validator::validate( $spec );
		$this->assertIsArray( $result, 'Expected form block spec to validate successfully' );
	}
}

