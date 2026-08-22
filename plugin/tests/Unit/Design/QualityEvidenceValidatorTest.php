<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\Tests\Unit\Design;

use PHPUnit\Framework\TestCase;
use Stonewright\WpMcp\Design\Quality\QualityEvidenceValidator;

/**
 * Validation tests for rendered browser evidence.
 *
 * Evidence arrives from a browser session, which means it is the least trusted
 * input in the quality subsystem: it is large, it is shaped by whatever ran in
 * the page, and it is the only thing standing between a rendered page and a
 * pass/fail verdict. These tests pin the properties that make it safe to
 * evaluate:
 *
 * - Allowlist only. Unknown keys are rejected, never stripped, so a caller
 *   cannot smuggle an unbounded DOM dump past the validator by nesting it under
 *   a key the evaluator ignores.
 * - Hard bounds on viewports, elements, and string length, so one page cannot
 *   turn a check into a memory problem.
 * - Colors are normalized to lowercase hex once, here, so no rule has to guess
 *   whether it received `rgb(11, 31, 58)` or `#0B1F3A`.
 * - Absent evidence stays absent. The validator never invents a default, and it
 *   preserves a partially captured `states` object, because "we captured states
 *   and focus was missing" and "we never captured states" must reach the
 *   evaluator as different facts.
 *
 * @covers \Stonewright\WpMcp\Design\Quality\QualityEvidenceValidator
 */
final class QualityEvidenceValidatorTest extends TestCase {

	// -------------------------------------------------------------------------
	// Shape and allowlisting.
	// -------------------------------------------------------------------------

	public function test_it_accepts_bounded_fixture_evidence(): void {
		foreach ( [ 'pass', 'fail', 'warning', 'incomplete' ] as $name ) {
			$result = QualityEvidenceValidator::validate( $this->fixture( $name ) );
			self::assertIsArray( $result, $name . ' fixture must validate.' );
			self::assertSame( QualityEvidenceValidator::SCHEMA_VERSION, $result['schema_version'] );
		}
	}

	public function test_it_rejects_an_unknown_top_level_key(): void {
		$evidence          = $this->fixture( 'pass' );
		$evidence['dom']   = '<html>…</html>';
		$error             = QualityEvidenceValidator::validate( $evidence );

		self::assertInstanceOf( \WP_Error::class, $error );
		self::assertSame( QualityEvidenceValidator::ERROR_CODE, $error->get_error_code() );
		self::assertStringContainsString( 'does not accept', $error->get_error_message() );
		self::assertStringContainsString( 'Accepted keys:', $error->get_error_message() );
		self::assertContains( 'schema_version', $error->get_error_data()['accepted_keys'] );
		self::assertContains( 'target', $error->get_error_data()['accepted_keys'] );
		self::assertContains( 'viewports', $error->get_error_data()['accepted_keys'] );
	}

	public function test_it_rejects_an_unknown_element_key(): void {
		$evidence = $this->fixture( 'pass' );
		$evidence['viewports'][0]['elements'][0]['outer_html'] = '<h1>…</h1>';

		$error = QualityEvidenceValidator::validate( $evidence );
		self::assertInstanceOf( \WP_Error::class, $error );
		self::assertSame( QualityEvidenceValidator::ERROR_CODE, $error->get_error_code() );
	}

	public function test_it_rejects_an_unsupported_schema_version(): void {
		$evidence                   = $this->fixture( 'pass' );
		$evidence['schema_version'] = '2.0';

		$error = QualityEvidenceValidator::validate( $evidence );
		self::assertInstanceOf( \WP_Error::class, $error );
	}

	public function test_it_rejects_an_unknown_viewport_id(): void {
		$evidence                      = $this->fixture( 'pass' );
		$evidence['viewports'][0]['id'] = 'widescreen';

		$error = QualityEvidenceValidator::validate( $evidence );
		self::assertInstanceOf( \WP_Error::class, $error );
	}

	public function test_it_rejects_two_reports_for_the_same_viewport(): void {
		$evidence                = $this->fixture( 'pass' );
		$evidence['viewports'][] = $evidence['viewports'][0];

		$error = QualityEvidenceValidator::validate( $evidence );
		self::assertInstanceOf( \WP_Error::class, $error );
	}

	public function test_it_rejects_an_element_without_a_reference(): void {
		$evidence = $this->fixture( 'pass' );
		unset( $evidence['viewports'][0]['elements'][0]['ref'] );

		$error = QualityEvidenceValidator::validate( $evidence );
		self::assertInstanceOf( \WP_Error::class, $error );
	}

	public function test_it_rejects_an_unknown_element_kind(): void {
		$evidence = $this->fixture( 'pass' );
		$evidence['viewports'][0]['elements'][0]['kind'] = 'widget';

		$error = QualityEvidenceValidator::validate( $evidence );
		self::assertInstanceOf( \WP_Error::class, $error );
	}

	// -------------------------------------------------------------------------
	// Bounds.
	// -------------------------------------------------------------------------

	public function test_it_rejects_more_elements_than_the_cap(): void {
		$evidence = $this->fixture( 'pass' );
		$element  = $evidence['viewports'][0]['elements'][0];

		$evidence['viewports'][0]['elements'] = [];
		for ( $i = 0; $i <= QualityEvidenceValidator::MAX_ELEMENTS; $i++ ) {
			$element['ref']                         = 'ref-' . $i;
			$evidence['viewports'][0]['elements'][] = $element;
		}

		$error = QualityEvidenceValidator::validate( $evidence );
		self::assertInstanceOf( \WP_Error::class, $error );
	}

	public function test_it_rejects_an_over_long_element_reference(): void {
		$evidence = $this->fixture( 'pass' );
		$evidence['viewports'][0]['elements'][0]['ref'] = str_repeat( 'a', QualityEvidenceValidator::MAX_STRING_LENGTH + 1 );

		$error = QualityEvidenceValidator::validate( $evidence );
		self::assertInstanceOf( \WP_Error::class, $error );
	}

	public function test_it_rejects_a_negative_dimension(): void {
		$evidence = $this->fixture( 'pass' );
		$evidence['viewports'][0]['elements'][0]['box']['width'] = -1;

		$error = QualityEvidenceValidator::validate( $evidence );
		self::assertInstanceOf( \WP_Error::class, $error );
	}

	public function test_it_rejects_evidence_larger_than_the_byte_cap(): void {
		$evidence = $this->fixture( 'pass' );
		$evidence['target']['url'] = 'https://example.test/?q=' . str_repeat( 'a', 400 );

		$element = $evidence['viewports'][0]['elements'][0];
		for ( $i = 0; $i < 90; $i++ ) {
			$element['ref']                         = str_repeat( 'r', 1900 ) . $i;
			$evidence['viewports'][0]['elements'][] = $element;
		}

		$error = QualityEvidenceValidator::validate( $evidence );
		self::assertInstanceOf( \WP_Error::class, $error );
	}

	// -------------------------------------------------------------------------
	// Normalization.
	// -------------------------------------------------------------------------

	public function test_it_normalizes_css_color_functions_to_hex(): void {
		$result = QualityEvidenceValidator::validate( $this->fixture( 'pass' ) );
		self::assertIsArray( $result );

		self::assertSame( '#0b1f3a', $result['viewports'][0]['elements'][0]['text_color'] );
		self::assertSame( '#ffffff', $result['viewports'][0]['elements'][0]['background_color'] );
	}

	public function test_it_normalizes_shorthand_hex_and_uppercase(): void {
		$evidence = $this->fixture( 'pass' );
		$evidence['viewports'][0]['elements'][0]['text_color']       = '#FFF';
		$evidence['viewports'][0]['elements'][0]['background_color'] = 'rgba(11, 31, 58, 1)';

		$result = QualityEvidenceValidator::validate( $evidence );
		self::assertIsArray( $result );
		self::assertSame( '#ffffff', $result['viewports'][0]['elements'][0]['text_color'] );
		self::assertSame( '#0b1f3a', $result['viewports'][0]['elements'][0]['background_color'] );
	}

	public function test_it_rejects_a_color_it_cannot_measure(): void {
		$evidence = $this->fixture( 'pass' );
		$evidence['viewports'][0]['elements'][0]['text_color'] = 'var(--brand)';

		$error = QualityEvidenceValidator::validate( $evidence );
		self::assertInstanceOf( \WP_Error::class, $error );
	}

	public function test_it_orders_viewports_from_widest_to_narrowest(): void {
		$evidence              = $this->fixture( 'pass' );
		$evidence['viewports'] = array_reverse( $evidence['viewports'] );

		$result = QualityEvidenceValidator::validate( $evidence );
		self::assertIsArray( $result );
		self::assertSame(
			[ 'desktop', 'tablet', 'mobile' ],
			array_column( $result['viewports'], 'id' )
		);
	}

	// -------------------------------------------------------------------------
	// Absent evidence stays absent.
	// -------------------------------------------------------------------------

	public function test_it_does_not_invent_missing_measurements(): void {
		$result = QualityEvidenceValidator::validate( $this->fixture( 'incomplete' ) );
		self::assertIsArray( $result );

		$viewport = $result['viewports'][0];
		self::assertArrayNotHasKey( 'scroll_width', $viewport );

		$title = $viewport['elements'][0];
		self::assertArrayNotHasKey( 'text_color', $title );
		self::assertArrayNotHasKey( 'font', $title );
		self::assertArrayNotHasKey( 'states', $title );
	}

	public function test_it_preserves_a_partially_captured_state_object(): void {
		$result = QualityEvidenceValidator::validate( $this->fixture( 'fail' ) );
		self::assertIsArray( $result );

		$states = $result['viewports'][0]['elements'][2]['states'];
		self::assertArrayHasKey( 'hover', $states );
		self::assertArrayNotHasKey( 'focus', $states );
	}

	// -------------------------------------------------------------------------
	// Helpers.
	// -------------------------------------------------------------------------

	/**
	 * @return array<string,mixed>
	 */
	private function fixture( string $name ): array {
		$path = dirname( __DIR__, 2 ) . '/fixtures/design-quality/' . $name . '.json';
		self::assertFileExists( $path );

		$decoded = json_decode( (string) file_get_contents( $path ), true );
		self::assertIsArray( $decoded );

		/** @var array<string,mixed> $decoded */
		return $decoded;
	}
}
