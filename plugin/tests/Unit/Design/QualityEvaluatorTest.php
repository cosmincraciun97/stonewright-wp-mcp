<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\Tests\Unit\Design;

use PHPUnit\Framework\TestCase;
use Stonewright\WpMcp\Design\Direction\DirectionContract;
use Stonewright\WpMcp\Design\Quality\QualityEvaluator;
use Stonewright\WpMcp\Design\Quality\QualityRule;
use Stonewright\WpMcp\Design\Quality\QualityRuleRegistry;

/**
 * Rule tests for rendered design quality evaluation.
 *
 * The evaluator replaces a fabricated "beauty score" with coverage plus
 * evidence, so its value depends entirely on being honest about three different
 * outcomes:
 *
 * - A measurable defect is a finding with the numbers that produced it.
 * - Guidance a direction expresses but cannot enforce is a warning, never a
 *   hard failure.
 * - Evidence that was never captured is `not_checked`. It is counted, reported,
 *   and never silently treated as a pass. This is the property that stops a
 *   thin browser session from looking like a clean page.
 *
 * Waivers suppress a rule the direction has consciously accepted, but the
 * finding stays visible with its reason so a waiver cannot hide a defect.
 *
 * @covers \Stonewright\WpMcp\Design\Quality\QualityEvaluator
 * @covers \Stonewright\WpMcp\Design\Quality\QualityRule
 * @covers \Stonewright\WpMcp\Design\Quality\QualityRuleRegistry
 */
final class QualityEvaluatorTest extends TestCase {

	// -------------------------------------------------------------------------
	// Registry.
	// -------------------------------------------------------------------------

	public function test_the_registry_exposes_unique_well_formed_rules(): void {
		$rules = QualityRuleRegistry::rules();
		self::assertNotEmpty( $rules );

		$ids = [];
		foreach ( $rules as $rule ) {
			self::assertInstanceOf( QualityRule::class, $rule );
			self::assertMatchesRegularExpression( '/^[a-z0-9]+(\.[a-z0-9_]+)+$/', $rule->id() );
			self::assertContains( $rule->severity(), QualityRule::SEVERITIES );
			self::assertContains( $rule->scope(), QualityRule::SCOPES );
			self::assertNotSame( '', $rule->summary() );
			$ids[] = $rule->id();
		}

		self::assertSame( array_values( array_unique( $ids ) ), $ids );
	}

	public function test_objective_rules_are_separate_from_direction_guidance(): void {
		$objective = [];
		$guidance  = [];
		foreach ( QualityRuleRegistry::rules() as $rule ) {
			if ( $rule->is_guidance() ) {
				$guidance[] = $rule->id();
				continue;
			}
			$objective[] = $rule->id();
		}

		self::assertNotEmpty( $objective );
		self::assertNotEmpty( $guidance );
		self::assertSame( [], array_intersect( $objective, $guidance ) );

		foreach ( QualityRuleRegistry::rules() as $rule ) {
			if ( $rule->is_guidance() ) {
				self::assertSame( 'warning', $rule->severity(), $rule->id() . ' guidance must not hard fail.' );
			}
		}
	}

	// -------------------------------------------------------------------------
	// Overall verdict.
	// -------------------------------------------------------------------------

	public function test_clean_evidence_passes_with_full_coverage(): void {
		$report = $this->evaluate( 'pass' );

		self::assertSame( '1.0', $report['schema_version'] );
		self::assertSame( 'pass', $report['status'] );
		self::assertSame( [], $report['findings'] );
		self::assertGreaterThan( 0, $report['coverage']['checked'] );
		self::assertSame( 0, $report['coverage']['not_checked'] );
	}

	public function test_measurable_defects_fail(): void {
		$report = $this->evaluate( 'fail' );

		self::assertSame( 'fail', $report['status'] );
		self::assertContains( 'contrast.text', $this->rule_ids( $report ) );
	}

	public function test_guidance_deviation_only_warns(): void {
		$report = $this->evaluate( 'warning' );

		self::assertSame( 'warn', $report['status'] );
		self::assertContains( 'token.typography', $this->rule_ids( $report ) );
		self::assertContains( 'token.spacing', $this->rule_ids( $report ) );
		self::assertContains( 'state.hover_defined', $this->rule_ids( $report ) );

		foreach ( $report['findings'] as $finding ) {
			self::assertNotSame( 'error', $finding['severity'] );
		}
	}

	public function test_absent_evidence_is_not_checked_and_never_a_pass(): void {
		$report = $this->evaluate( 'incomplete' );

		self::assertSame( 'not_checked', $report['status'] );
		self::assertSame( [], $report['findings'] );
		self::assertSame( 0, $report['coverage']['checked'] );
		self::assertGreaterThan( 0, $report['coverage']['not_checked'] );
		self::assertContains( 'contrast.text', $report['coverage']['not_checked_rules'] );
	}

	// -------------------------------------------------------------------------
	// Individual rules.
	// -------------------------------------------------------------------------

	public function test_text_contrast_reports_the_measured_and_required_ratio(): void {
		$finding = $this->finding( $this->evaluate( 'fail' ), 'contrast.text' );

		self::assertSame( 'error', $finding['severity'] );
		self::assertSame( 'desktop', $finding['viewport'] );
		self::assertSame( 'legal-note', $finding['element_ref'] );
		self::assertSame( 4.5, $finding['evidence']['required'] );
		self::assertLessThan( 4.5, $finding['evidence']['actual'] );
		self::assertNotSame( '', $finding['repair_hint'] );
	}

	public function test_large_text_uses_the_large_text_contrast_threshold(): void {
		// 3.36:1 — below the 4.5:1 body-text threshold, above the 3:1 large-text one.
		$evidence = $this->fixture( 'fail' );
		$evidence['viewports'][0]['elements'][0]['text_color'] = '#8c8c8c';

		$at_body_size = $this->evaluate_evidence( $evidence );
		self::assertSame( 'legal-note', $this->finding( $at_body_size, 'contrast.text' )['element_ref'] );

		$evidence['viewports'][0]['elements'][0]['font']['size_px'] = 32;
		$at_display_size = $this->evaluate_evidence( $evidence );
		self::assertNotContains( 'contrast.text', $this->rule_ids( $at_display_size ) );

		// Bold text reaches the large threshold earlier than regular text does.
		$evidence['viewports'][0]['elements'][0]['font']['size_px'] = 19;
		$evidence['viewports'][0]['elements'][0]['font']['weight']  = 400;
		self::assertContains( 'contrast.text', $this->rule_ids( $this->evaluate_evidence( $evidence ) ) );

		$evidence['viewports'][0]['elements'][0]['font']['weight'] = 700;
		self::assertNotContains( 'contrast.text', $this->rule_ids( $this->evaluate_evidence( $evidence ) ) );
	}

	public function test_focus_contrast_measures_the_outline_against_its_backdrop(): void {
		$evidence = $this->fixture( 'pass' );
		$evidence['viewports'][0]['elements'][1]['states']['focus']['outline_color'] = '#0c2140';

		$finding = $this->finding( $this->evaluate_evidence( $evidence ), 'contrast.focus' );
		self::assertSame( 'error', $finding['severity'] );
		self::assertSame( 3.0, $finding['evidence']['required'] );
	}

	public function test_horizontal_overflow_is_reported_per_viewport(): void {
		$finding = $this->finding( $this->evaluate( 'fail' ), 'overflow.horizontal' );

		self::assertSame( 'error', $finding['severity'] );
		self::assertSame( 'desktop', $finding['viewport'] );
		self::assertSame( '', $finding['element_ref'] );
		self::assertSame( 1560, $finding['evidence']['actual'] );
		self::assertSame( 1440, $finding['evidence']['required'] );
	}

	public function test_a_sub_minimum_touch_target_fails(): void {
		$finding = $this->finding( $this->evaluate( 'fail' ), 'target.size' );

		self::assertSame( 'tiny-cta', $finding['element_ref'] );
		self::assertSame( QualityEvaluator::MIN_TARGET_PX, $finding['evidence']['required'] );
		self::assertSame( 20, $finding['evidence']['actual'] );
	}

	public function test_clipped_text_fails(): void {
		$finding = $this->finding( $this->evaluate( 'fail' ), 'text.clipped' );

		self::assertSame( 'clipped-heading', $finding['element_ref'] );
		self::assertSame( 900, $finding['evidence']['actual'] );
		self::assertSame( 600, $finding['evidence']['required'] );
	}

	public function test_an_interactive_element_without_a_captured_focus_state_fails(): void {
		$finding = $this->finding( $this->evaluate( 'fail' ), 'state.focus_visible' );

		self::assertSame( 'error', $finding['severity'] );
		self::assertSame( 'tiny-cta', $finding['element_ref'] );
	}

	public function test_an_element_with_no_captured_states_is_not_checked(): void {
		$report = $this->evaluate( 'incomplete' );

		self::assertNotContains( 'state.focus_visible', $this->rule_ids( $report ) );
		self::assertContains( 'state.focus_visible', $report['coverage']['not_checked_rules'] );
	}

	public function test_spacing_deviation_reports_the_nearest_token(): void {
		$finding = $this->finding( $this->evaluate( 'warning' ), 'token.spacing' );

		self::assertSame( 'warning', $finding['severity'] );
		self::assertSame( 100, $finding['evidence']['actual'] );
		self::assertSame( 96, $finding['evidence']['required'] );
	}

	public function test_typography_deviation_reports_the_nearest_token(): void {
		$finding = $this->finding( $this->evaluate( 'warning' ), 'token.typography' );

		self::assertSame( 57, $finding['evidence']['actual'] );
		self::assertSame( 56, $finding['evidence']['required'] );
	}

	public function test_a_direction_without_tokens_cannot_produce_token_findings(): void {
		$direction = DirectionContract::defaults();
		$report    = QualityEvaluator::evaluate(
			$this->validated( 'warning' ),
			$direction
		);

		self::assertIsArray( $report );
		self::assertNotContains( 'token.spacing', $this->rule_ids( $report ) );
		self::assertContains( 'token.spacing', $report['coverage']['not_checked_rules'] );
	}

	// -------------------------------------------------------------------------
	// Waivers.
	// -------------------------------------------------------------------------

	public function test_a_waiver_downgrades_the_finding_and_keeps_its_reason(): void {
		$direction              = $this->direction();
		$direction['waivers'][] = [
			'rule_id' => 'contrast.text',
			'reason'  => 'Legal note is decorative in this direction.',
		];

		$report  = QualityEvaluator::evaluate( $this->validated( 'fail' ), $direction );
		self::assertIsArray( $report );
		$finding = $this->finding( $report, 'contrast.text' );

		self::assertSame( 'info', $finding['severity'] );
		self::assertTrue( $finding['waived'] );
		self::assertSame( 'Legal note is decorative in this direction.', $finding['waiver_reason'] );
	}

	public function test_a_waiver_applies_only_to_its_exact_rule_id(): void {
		$direction              = $this->direction();
		$direction['waivers'][] = [
			'rule_id' => 'contrast',
			'reason'  => 'Too broad to match anything.',
		];

		$report = QualityEvaluator::evaluate( $this->validated( 'fail' ), $direction );
		self::assertIsArray( $report );
		self::assertSame( 'error', $this->finding( $report, 'contrast.text' )['severity'] );
	}

	public function test_waiving_every_failure_leaves_a_warning_free_pass(): void {
		$direction = $this->direction();
		foreach ( [ 'contrast.text', 'overflow.horizontal', 'target.size', 'text.clipped', 'state.focus_visible', 'state.hover_defined', 'token.spacing', 'token.typography' ] as $rule_id ) {
			$direction['waivers'][] = [
				'rule_id' => $rule_id,
				'reason'  => 'Accepted for this direction.',
			];
		}

		$report = QualityEvaluator::evaluate( $this->validated( 'fail' ), $direction );
		self::assertIsArray( $report );
		self::assertSame( 'pass', $report['status'] );
		self::assertNotSame( [], $report['findings'] );
	}

	// -------------------------------------------------------------------------
	// Ordering and bounds.
	// -------------------------------------------------------------------------

	public function test_findings_are_ordered_by_severity_then_viewport_then_rule(): void {
		$evidence = $this->fixture( 'fail' );
		// Add a narrower viewport that also fails, so viewport ordering is observable.
		$evidence['viewports'][] = [
			'id'           => 'mobile',
			'width'        => 390,
			'height'       => 844,
			'scroll_width' => 520,
			'elements'     => [
				[
					'ref'              => 'legal-note',
					'kind'             => 'text',
					'box'              => [
						'width'  => 358,
						'height' => 40,
					],
					'text_color'       => '#9a9a9a',
					'background_color' => '#ffffff',
					'font'             => [
						'size_px' => 16,
						'weight'  => 400,
					],
				],
			],
		];

		$report = $this->evaluate_evidence( $evidence );

		$severity_rank = [
			'error'   => 0,
			'warning' => 1,
			'info'    => 2,
		];
		$viewport_rank = [
			'desktop' => 0,
			'tablet'  => 1,
			'mobile'  => 2,
		];

		$previous = null;
		foreach ( $report['findings'] as $finding ) {
			$key = [
				$severity_rank[ $finding['severity'] ],
				$viewport_rank[ $finding['viewport'] ],
				$finding['rule_id'],
				$finding['element_ref'],
			];
			if ( null !== $previous ) {
				self::assertLessThanOrEqual( 0, $this->compare( $previous, $key ), 'Findings must be ordered.' );
			}
			$previous = $key;
		}
	}

	public function test_the_same_evidence_always_produces_the_same_report(): void {
		self::assertSame( $this->evaluate( 'fail' ), $this->evaluate( 'fail' ) );
	}

	public function test_findings_are_capped(): void {
		$evidence = $this->fixture( 'fail' );
		$element  = $evidence['viewports'][0]['elements'][0];

		// Each copy of the failing note produces a contrast error and a type warning.
		for ( $i = 0; $i < intdiv( QualityEvaluator::MAX_FINDINGS, 2 ) + 10; $i++ ) {
			$element['ref']                         = 'note-' . $i;
			$evidence['viewports'][0]['elements'][] = $element;
		}

		$report = $this->evaluate_evidence( $evidence );
		self::assertCount( QualityEvaluator::MAX_FINDINGS, $report['findings'] );
		self::assertGreaterThan( 0, $report['truncated_findings'] );
	}

	public function test_invalid_evidence_is_refused(): void {
		$error = QualityEvaluator::evaluate( [ 'schema_version' => '1.0' ], $this->direction() );
		self::assertInstanceOf( \WP_Error::class, $error );
	}

	// -------------------------------------------------------------------------
	// Helpers.
	// -------------------------------------------------------------------------

	/**
	 * @param array<int,int|string> $left
	 * @param array<int,int|string> $right
	 */
	private function compare( array $left, array $right ): int {
		foreach ( $left as $index => $value ) {
			$other = $right[ $index ];
			if ( $value === $other ) {
				continue;
			}
			if ( is_int( $value ) && is_int( $other ) ) {
				return $value < $other ? -1 : 1;
			}
			return strcmp( (string) $value, (string) $other );
		}
		return 0;
	}

	/**
	 * @param array<string,mixed> $report
	 * @return list<string>
	 */
	private function rule_ids( array $report ): array {
		/** @var list<array<string,mixed>> $findings */
		$findings = $report['findings'];
		return array_values( array_unique( array_map( static fn ( array $f ): string => (string) $f['rule_id'], $findings ) ) );
	}

	/**
	 * @param array<string,mixed> $report
	 * @return array<string,mixed>
	 */
	private function finding( array $report, string $rule_id ): array {
		/** @var list<array<string,mixed>> $findings */
		$findings = $report['findings'];
		foreach ( $findings as $finding ) {
			if ( $rule_id === $finding['rule_id'] ) {
				return $finding;
			}
		}

		self::fail( 'Expected a ' . $rule_id . ' finding. Got: ' . implode( ', ', $this->rule_ids( $report ) ) );
	}

	/**
	 * @return array<string,mixed>
	 */
	private function evaluate( string $name ): array {
		return $this->evaluate_evidence( $this->fixture( $name ) );
	}

	/**
	 * @param array<string,mixed> $evidence
	 * @return array<string,mixed>
	 */
	private function evaluate_evidence( array $evidence ): array {
		$report = QualityEvaluator::evaluate( $evidence, $this->direction() );
		self::assertIsArray( $report );

		/** @var array<string,mixed> $report */
		return $report;
	}

	/**
	 * @return array<string,mixed>
	 */
	private function validated( string $name ): array {
		return $this->fixture( $name );
	}

	/**
	 * @return array<string,mixed>
	 */
	private function direction(): array {
		$direction                         = DirectionContract::defaults();
		$direction['identity']['name']     = 'Stone and Precision';
		$direction['tokens']['spacing']    = [
			'sm'      => '16px',
			'md'      => '24px',
			'lg'      => '48px',
			'section' => '96px',
		];
		$direction['tokens']['typography'] = [
			'h1'        => [
				'font-family' => 'Inter',
				'font-size'   => '56px',
			],
			'h1-tablet' => [ 'font-size' => '40px' ],
			'h1-mobile' => [ 'font-size' => '32px' ],
			'body'      => [ 'font-size' => '18px' ],
		];

		return $direction;
	}

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
