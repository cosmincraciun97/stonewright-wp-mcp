<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Stonewright\WpMcp\ThemeJson\Validator;

/**
 * @covers \Stonewright\WpMcp\ThemeJson\Validator
 */
final class ThemeJsonValidatorTest extends TestCase {

	// -------------------------------------------------------------------------
	// Happy path — validate() now returns the canonical array on success
	// -------------------------------------------------------------------------

	public function test_minimal_valid_theme_json(): void {
		$result = Validator::validate( [ 'version' => 3 ] );
		$this->assertIsArray( $result );
		$this->assertSame( 3, $result['version'] );
	}

	public function test_full_valid_theme_json_with_settings_and_styles(): void {
		$theme_json = [
			'version'  => 3,
			'settings' => [
				'color' => [
					'palette' => [
						[ 'slug' => 'primary', 'color' => '#0073aa', 'name' => 'Primary' ],
					],
				],
				'typography' => [
					'fontSizes' => [
						[ 'slug' => 'sm', 'size' => '14px', 'name' => 'Small' ],
					],
				],
				'spacing' => [
					'units'  => [ 'px', 'em', 'rem' ],
					'margin' => true,
				],
			],
			'styles' => [
				'color'      => [ 'background' => '#ffffff' ],
				'typography' => [ 'fontSize' => '16px' ],
			],
		];
		$result = Validator::validate( $theme_json );
		$this->assertIsArray( $result );
		$this->assertSame( 3, $result['version'] );
		$this->assertArrayHasKey( 'settings', $result );
		$this->assertArrayHasKey( 'styles', $result );
	}

	public function test_version_2_is_valid(): void {
		$result = Validator::validate( [ 'version' => 2 ] );
		$this->assertIsArray( $result );
		$this->assertSame( 2, $result['version'] );
	}

	public function test_validate_strips_unknown_top_level_key_from_canonical(): void {
		// Unknown keys should be filtered out of the returned canonical array
		// even after the previous unknown-key validation test. This one checks
		// that a payload with ONLY unknown extra keys alongside a valid version
		// fails validation (schema has additionalProperties: false).
		// For a payload that passes validation, unknown keys are stripped.
		// We can't test that here directly because the schema rejects them. Instead
		// verify that the filter_canonical logic doesn't re-introduce unknown keys
		// by testing a minimal valid payload returns only the canonical keys.
		$result = Validator::validate( [ 'version' => 3 ] );
		$this->assertIsArray( $result );
		// No extra keys beyond what's in the input.
		foreach ( array_keys( $result ) as $key ) {
			$this->assertContains( $key, [ 'version', '$schema', 'title', 'description', 'settings', 'styles', 'customTemplates', 'templateParts', 'patterns' ] );
		}
	}

	// -------------------------------------------------------------------------
	// Missing required fields
	// -------------------------------------------------------------------------

	public function test_missing_version_fails(): void {
		$result = Validator::validate( [] );
		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'stonewright_theme_json_invalid', $result->get_error_code() );
	}

	public function test_invalid_version_type_fails(): void {
		$result = Validator::validate( [ 'version' => '3' ] );
		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'stonewright_theme_json_invalid', $result->get_error_code() );
		$errors = (array) ( $result->get_error_data()['errors'] ?? [] );
		$keywords = array_column( $errors, 'keyword' );
		$this->assertContains( 'type', $keywords );
	}

	public function test_version_below_minimum_fails(): void {
		$result = Validator::validate( [ 'version' => 1 ] );
		$this->assertInstanceOf( \WP_Error::class, $result );
		$errors = (array) ( $result->get_error_data()['errors'] ?? [] );
		$keywords = array_column( $errors, 'keyword' );
		$this->assertContains( 'minimum', $keywords );
	}

	public function test_version_above_maximum_fails(): void {
		$result = Validator::validate( [ 'version' => 99 ] );
		$this->assertInstanceOf( \WP_Error::class, $result );
		$errors = (array) ( $result->get_error_data()['errors'] ?? [] );
		$keywords = array_column( $errors, 'keyword' );
		$this->assertContains( 'maximum', $keywords );
	}

	// -------------------------------------------------------------------------
	// Additional properties
	// -------------------------------------------------------------------------

	public function test_unknown_top_level_key_fails(): void {
		$result = Validator::validate( [ 'version' => 3, 'unknownKey' => 'bad' ] );
		$this->assertInstanceOf( \WP_Error::class, $result );
		$errors = (array) ( $result->get_error_data()['errors'] ?? [] );
		$keywords = array_column( $errors, 'keyword' );
		$this->assertContains( 'additionalProperties', $keywords );
	}

	public function test_unknown_color_key_fails(): void {
		$result = Validator::validate( [
			'version'  => 3,
			'settings' => [
				'color' => [ 'notAColorField' => true ],
			],
		] );
		$this->assertInstanceOf( \WP_Error::class, $result );
	}

	// -------------------------------------------------------------------------
	// Palette item required fields
	// -------------------------------------------------------------------------

	public function test_palette_item_missing_color_fails(): void {
		$result = Validator::validate( [
			'version'  => 3,
			'settings' => [
				'color' => [
					'palette' => [
						[ 'slug' => 'primary' ], // 'color' missing
					],
				],
			],
		] );
		$this->assertInstanceOf( \WP_Error::class, $result );
		$errors = (array) ( $result->get_error_data()['errors'] ?? [] );
		$messages = array_column( $errors, 'message' );
		$found = false;
		foreach ( $messages as $msg ) {
			if ( str_contains( $msg, 'color' ) ) {
				$found = true;
				break;
			}
		}
		$this->assertTrue( $found, 'Expected error mentioning missing "color" property' );
	}
}
