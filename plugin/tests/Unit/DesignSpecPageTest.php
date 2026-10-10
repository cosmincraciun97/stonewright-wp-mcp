<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Stonewright\WpMcp\Abilities\Design\ValidateSpec;
use Stonewright\WpMcp\DesignSpec\Validator;

/**
 * `page` is optional in a design spec, and a validation failure names the failing path and
 * the shape the validator expected.
 *
 * @covers \Stonewright\WpMcp\DesignSpec\Validator
 * @covers \Stonewright\WpMcp\Abilities\Design\ValidateSpec
 */
final class DesignSpecPageTest extends TestCase {

	protected function setUp(): void {
		$GLOBALS['stonewright_test_user_caps'] = [ 'edit_posts' => true, 'read' => true ];
	}

	protected function tearDown(): void {
		$GLOBALS['stonewright_test_user_caps'] = [];
	}

	/**
	 * @return array<string, mixed>
	 */
	private static function spec_without_page( string $version = '1.0.0' ): array {
		return [
			'version'  => $version,
			'sections' => [
				[
					'id'     => 's1',
					'blocks' => [ [ 'type' => 'heading', 'text' => 'Quarterly results', 'level' => 2 ] ],
				],
			],
		];
	}

	/**
	 * @return array<string, array{0: string}>
	 */
	public static function versions(): array {
		return [
			'1.0.0' => [ '1.0.0' ],
			'2.0.0' => [ '2.0.0' ],
		];
	}

	/**
	 * @dataProvider versions
	 */
	public function test_a_spec_without_page_is_valid( string $version ): void {
		$result = Validator::validate( self::spec_without_page( $version ) );

		self::assertIsArray( $result, $result instanceof \WP_Error ? $result->get_error_message() : '' );
		self::assertArrayNotHasKey( 'page', $result );
	}

	/**
	 * @dataProvider versions
	 */
	public function test_an_empty_page_is_valid( string $version ): void {
		$spec         = self::spec_without_page( $version );
		$spec['page'] = [];

		self::assertIsArray( Validator::validate( $spec ) );
	}

	public function test_a_page_without_title_keeps_its_other_fields(): void {
		$spec         = self::spec_without_page();
		$spec['page'] = [ 'slug' => 'results', 'status' => 'draft' ];

		$result = Validator::validate( $spec );

		self::assertIsArray( $result );
		self::assertSame( [ 'slug' => 'results', 'status' => 'draft' ], $result['page'] );
	}

	public function test_a_page_that_is_not_an_object_names_the_path_and_the_expected_shape(): void {
		$spec         = self::spec_without_page();
		$spec['page'] = 'Quarterly results';

		$result = Validator::validate( $spec );

		self::assertInstanceOf( \WP_Error::class, $result );
		$errors = $result->get_error_data()['errors'];
		$error  = $errors[0];

		self::assertSame( [ 'page' ], $error['path'] );
		self::assertSame( 'page', $error['path_string'] );
		self::assertSame( 'string', $error['received_type'] );
		self::assertStringNotContainsString( '{', $error['message'], 'Message placeholders are filled in.' );
		self::assertStringContainsString( 'string', $error['message'] );
		self::assertStringContainsString( 'object', $error['message'] );
		self::assertNotSame( [], $error['allowed_shapes'] );
		self::assertNotSame( [], $error['nearest_valid_example'] );
		self::assertStringContainsString( 'page', $error['repair_hint'] );
		self::assertStringContainsString( 'optional', $error['repair_hint'] );
	}

	public function test_the_wp_error_message_names_the_failing_paths(): void {
		$spec         = self::spec_without_page();
		$spec['page'] = 'Quarterly results';

		$result = Validator::validate( $spec );

		self::assertInstanceOf( \WP_Error::class, $result );
		self::assertStringStartsWith( 'Design spec failed validation', $result->get_error_message() );
		self::assertStringContainsString( 'page', $result->get_error_message() );
		self::assertStringContainsString( 'object', $result->get_error_message() );
		self::assertStringNotContainsString( '{', $result->get_error_message() );
	}

	public function test_the_wp_error_message_stays_short_when_many_things_fail(): void {
		$spec = [
			'version'  => '1.0.0',
			'sections' => array_fill( 0, 40, [ 'id' => 'x', 'layout' => [ 'columns' => 2 ], 'blocks' => [ [ 'type' => 'heading', 'text' => 'Heading' ] ] ] ),
		];

		$result = Validator::validate( $spec );

		self::assertInstanceOf( \WP_Error::class, $result );
		self::assertLessThanOrEqual( 700, strlen( $result->get_error_message() ) );
		self::assertGreaterThan( 5, count( $result->get_error_data()['errors'] ) );
	}

	public function test_validate_spec_reports_a_spec_without_page_as_valid(): void {
		$result = ( new ValidateSpec() )->execute( [ 'spec' => self::spec_without_page() ] );

		self::assertIsArray( $result );
		self::assertTrue( $result['valid'] );
		self::assertSame( [], $result['errors'] );
	}

	public function test_the_schemas_agree_with_the_documented_required_fields(): void {
		$docs = (string) file_get_contents( dirname( __DIR__, 3 ) . '/docs/design-spec.md' );
		self::assertMatchesRegularExpression( '/### Required fields\s+\|[^\n]+\n\|[^\n]+\n\| `version`[^\n]+\n\| `sections`[^\n]+\n\n/', $docs );

		foreach ( [ 'stonewright.schema.json', 'stonewright.schema.v2.json' ] as $file ) {
			$schema = json_decode( (string) file_get_contents( dirname( __DIR__, 2 ) . '/schemas/' . $file ), true, 512, JSON_THROW_ON_ERROR );
			self::assertSame( [ 'version', 'sections' ], $schema['required'], $file );
			self::assertArrayNotHasKey( 'required', $schema['properties']['page'], $file . ' page.required' );
		}
	}
}
