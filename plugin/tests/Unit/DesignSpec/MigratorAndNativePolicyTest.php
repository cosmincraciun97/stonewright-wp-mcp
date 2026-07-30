<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\Tests\Unit\DesignSpec;

use PHPUnit\Framework\TestCase;
use Stonewright\WpMcp\DesignSpec\Migrator;
use Stonewright\WpMcp\DesignSpec\Validator;

/**
 * @covers \Stonewright\WpMcp\DesignSpec\Migrator
 * @covers \Stonewright\WpMcp\DesignSpec\Validator
 */
final class MigratorAndNativePolicyTest extends TestCase {

	/**
	 * @return array<string, mixed>
	 */
	private static function v1_fixture(): array {
		return [
			'version'  => '1.0.0',
			'page'     => [ 'title' => 'Agency Landing' ],
			'tokens'   => [
				'colors' => [
					'primary' => '#312E81',
					'text'    => '#111827',
				],
			],
			'sections' => [
				[
					'id'     => 'hero',
					'blocks' => [
						[ 'type' => 'heading', 'level' => 1, 'text' => 'Signal & Form' ],
						[ 'type' => 'paragraph', 'text' => 'We design systems that ship.' ],
						[ 'type' => 'button', 'text' => 'Book a call', 'url' => 'https://example.com' ],
					],
				],
			],
		];
	}

	public function test_v1_to_v2_preserves_renderable_structure(): void {
		$v1 = self::v1_fixture();
		$v2 = Migrator::v1_to_v2( $v1 );

		self::assertSame( '2.0.0', $v2['version'] );
		self::assertSame( $v1['page']['title'], $v2['page']['title'] );
		self::assertCount( count( $v1['sections'] ), $v2['sections'] );
		self::assertSame( 'heading', $v2['sections'][0]['blocks'][0]['type'] );
		self::assertArrayHasKey( 'content_facts', $v2 );
		self::assertArrayHasKey( 'design_system', $v2 );
		self::assertArrayHasKey( 'native_policy', $v2 );
		self::assertArrayHasKey( 'verification_policy', $v2 );
		self::assertSame( '#312E81', $v2['design_system']['colors']['primary'] );
	}

	public function test_migrated_v1_fixture_still_validates(): void {
		$v2     = Migrator::v1_to_v2( self::v1_fixture() );
		$result = Validator::validate( $v2 );

		self::assertIsArray(
			$result,
			'Expected array, got WP_Error: ' . ( $result instanceof \WP_Error ? $result->get_error_message() . ' ' . wp_json_encode( $result->get_error_data() ) : '' )
		);
		self::assertSame( '2.0.0', $result['version'] );
	}

	public function test_strict_native_policy_rejects_html_widget(): void {
		$spec = Migrator::v1_to_v2( self::v1_fixture() );
		$spec['native_policy']['strict'] = true;
		$spec['sections'][0]['blocks'][] = [
			'type' => 'html',
			'text' => '<div class="custom">x</div>',
		];

		$result = Validator::validate( $spec );
		self::assertInstanceOf( \WP_Error::class, $result );
		self::assertSame( 'stonewright_spec_invalid', $result->get_error_code() );

		$data   = $result->get_error_data();
		$errors = is_array( $data ) ? ( $data['errors'] ?? [] ) : [];
		$codes  = array_column( is_array( $errors ) ? $errors : [], 'keyword' );
		self::assertContains( 'native_policy_html_widget', $codes );
	}

	public function test_strict_native_policy_requires_native_gap_for_custom_css(): void {
		$spec = Migrator::v1_to_v2( self::v1_fixture() );
		$spec['native_policy']['strict'] = true;
		$spec['sections'][0]['blocks'][1]['custom_css'] = '.x { color: red; }';

		$result = Validator::validate( $spec );
		self::assertInstanceOf( \WP_Error::class, $result );
		self::assertSame( 'stonewright_spec_invalid', $result->get_error_code() );

		$data   = $result->get_error_data();
		$errors = is_array( $data ) ? ( $data['errors'] ?? [] ) : [];
		$codes  = array_column( is_array( $errors ) ? $errors : [], 'keyword' );
		self::assertContains( 'native_policy_custom_css', $codes );
	}

	public function test_native_gap_allows_custom_css_under_strict_policy(): void {
		$spec = Migrator::v1_to_v2( self::v1_fixture() );
		$spec['native_policy']['strict'] = true;
		$spec['sections'][0]['blocks'][1]['custom_css'] = '.hero-kicker { letter-spacing: 0.08em; }';
		$spec['sections'][0]['blocks'][1]['native_gap'] = [
			'reason'                   => 'Elementor paragraph has no letter-spacing control at this site version.',
			'scoped_selectors'         => [ '.hero-kicker' ],
			'attempted_native_controls'=> [ 'typography_letter_spacing' ],
		];

		$result = Validator::validate( $spec );
		self::assertIsArray(
			$result,
			'Expected array, got WP_Error: ' . ( $result instanceof \WP_Error ? $result->get_error_message() . ' ' . wp_json_encode( $result->get_error_data() ) : '' )
		);
	}
}
