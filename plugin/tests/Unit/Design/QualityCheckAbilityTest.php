<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\Tests\Unit\Design;

use PHPUnit\Framework\TestCase;
use Stonewright\WpMcp\Abilities\Design\QualityCheck;
use Stonewright\WpMcp\Abilities\System\ToolProfile;
use Stonewright\WpMcp\Core\AbilityRegistry;
use Stonewright\WpMcp\Design\Direction\DesignDirectionService;
use Stonewright\WpMcp\Design\Quality\QualityReportStore;

/**
 * Contract tests for stonewright/design-quality-check.
 *
 * The ability has two halves with different risk. Evaluating supplied evidence
 * touches nothing, so it gates on the read permission. Persisting a report
 * writes post meta, so it gates on the design write permission, is audited, and
 * refuses to store a report it cannot tie to a page and a direction revision.
 * Every test here pins one of those two halves.
 *
 * @covers \Stonewright\WpMcp\Abilities\Design\QualityCheck
 */
final class QualityCheckAbilityTest extends TestCase {

	private const POST_ID = 4213;

	private DirectionAbilityRepository $repository;

	private DesignDirectionService $service;

	private int $direction_id = 0;

	protected function setUp(): void {
		$GLOBALS['stonewright_test_options']         = [];
		$GLOBALS['stonewright_test_user_caps']       = [
			'read'          => true,
			'manage_options' => true,
			'edit_pages'    => true,
		];
		$GLOBALS['stonewright_test_user_logged_in']  = true;
		$GLOBALS['stonewright_test_current_user_id'] = 7;
		$GLOBALS['stonewright_test_wpdb_inserts']    = [];
		$GLOBALS['stonewright_test_post_meta_calls'] = [];
		$GLOBALS['stonewright_test_posts']           = [
			self::POST_ID => (object) [
				'ID'        => self::POST_ID,
				'post_type' => 'page',
				'meta'      => [],
			],
		];

		$this->repository = new DirectionAbilityRepository();
		$this->service    = new DesignDirectionService( $this->repository );

		$saved = $this->service->save( $this->direction_input(), 7 );
		self::assertIsArray( $saved );
		$this->direction_id = (int) $saved['id'];
		update_option( 'stonewright_active_design_direction_id', $this->direction_id );
	}

	protected function tearDown(): void {
		$GLOBALS['stonewright_test_options']         = [];
		$GLOBALS['stonewright_test_user_caps']       = [];
		$GLOBALS['stonewright_test_user_logged_in']  = false;
		$GLOBALS['stonewright_test_current_user_id'] = 0;
		$GLOBALS['stonewright_test_wpdb_inserts']    = [];
		$GLOBALS['stonewright_test_post_meta_calls'] = [];
		$GLOBALS['stonewright_test_posts']           = [];
	}

	// -------------------------------------------------------------------------
	// Surface.
	// -------------------------------------------------------------------------

	public function test_the_ability_uses_the_documented_slug(): void {
		self::assertSame( 'stonewright/design-quality-check', $this->ability()->name() );
		self::assertSame( 'design', $this->ability()->category() );
	}

	public function test_the_registry_publishes_the_ability(): void {
		self::assertContains(
			'stonewright/design-quality-check',
			array_column( AbilityRegistry::all_abilities(), 'name' )
		);
	}

	public function test_the_registry_forces_the_task_context_token(): void {
		$schema = [];
		foreach ( AbilityRegistry::all_abilities() as $ability ) {
			if ( 'stonewright/design-quality-check' === $ability['name'] ) {
				$schema = $ability['input_schema'];
			}
		}

		self::assertArrayHasKey( 'stonewright_context_token', $schema['properties'] ?? [] );
		self::assertContains( 'stonewright_context_token', $schema['required'] ?? [] );
	}

	public function test_the_ability_is_in_the_design_profile_but_not_in_startup(): void {
		self::assertContains( 'stonewright/design-quality-check', ToolProfile::profile_tools( 'elementor-design' ) );
		self::assertNotContains( 'stonewright/design-quality-check', ToolProfile::profile_tools( 'startup' ) );
	}

	// -------------------------------------------------------------------------
	// Permissions. Read-only evaluation and persistence are gated differently.
	// -------------------------------------------------------------------------

	public function test_evaluation_gates_on_the_design_read_permission(): void {
		$GLOBALS['stonewright_test_user_caps'] = [ 'read' => true ];
		self::assertFalse( $this->ability()->permission_callback( [] ) );

		$GLOBALS['stonewright_test_user_caps'] = [ 'manage_options' => true ];
		self::assertTrue( $this->ability()->permission_callback( [] ) );
		self::assertTrue( $this->ability()->permission_callback( [ 'persist' => false ] ) );
	}

	public function test_persistence_gates_on_the_design_write_permission(): void {
		$GLOBALS['stonewright_test_user_caps'] = [ 'manage_options' => true ];
		self::assertFalse( $this->ability()->permission_callback( [ 'persist' => true ] ) );

		$GLOBALS['stonewright_test_user_caps'] = [
			'manage_options' => true,
			'edit_pages'     => true,
		];
		self::assertTrue( $this->ability()->permission_callback( [ 'persist' => true ] ) );
	}

	// -------------------------------------------------------------------------
	// Evaluation.
	// -------------------------------------------------------------------------

	public function test_evaluation_reports_coverage_and_writes_nothing(): void {
		$result = $this->ability()->execute( [ 'evidence' => $this->evidence( 'pass' ) ] );

		self::assertIsArray( $result );
		self::assertTrue( $result['ok'] );
		self::assertSame( 'pass', $result['status'] );
		self::assertGreaterThan( 0, $result['coverage']['checked'] );
		self::assertSame( [], $result['findings'] );
		self::assertSame( 0, $result['findings_total'] );
		self::assertFalse( $result['persisted'] );
		self::assertSame( '', $result['report_id'] );
		self::assertSame( [], $GLOBALS['stonewright_test_post_meta_calls'] );
		self::assertSame( [], $GLOBALS['stonewright_test_wpdb_inserts'] );
	}

	public function test_evaluation_reports_the_failing_rules(): void {
		$result = $this->ability()->execute( [ 'evidence' => $this->evidence( 'fail' ) ] );

		self::assertIsArray( $result );
		self::assertSame( 'fail', $result['status'] );
		self::assertContains( 'contrast.text', array_column( $result['findings'], 'rule_id' ) );
		self::assertContains( 'overflow.horizontal', array_column( $result['findings'], 'rule_id' ) );
		self::assertSame( count( $result['findings'] ), $result['findings_total'] );
	}

	public function test_motion_and_ui_evidence_participate_in_the_real_verdict(): void {
		$result = $this->ability()->execute(
			[
				'evidence'        => $this->evidence( 'pass' ),
				'motion_evidence' => [
					'js_disabled_invisible_targets' => [ 'hero-copy' ],
					'reduced_motion_respected' => false,
				],
				'ui_evidence' => [
					'touch_target.min_px' => 20,
				],
			]
		);

		self::assertIsArray( $result );
		self::assertSame( 'fail', $result['status'] );
		self::assertContains( 'motion.content_invisible_without_js', array_column( $result['findings'], 'rule_id' ) );
		self::assertContains( 'ui.touch_target_below_wcag_minimum', array_column( $result['findings'], 'rule_id' ) );
		self::assertSame( 'fail', $result['motion_report']['verdict'] );
		self::assertArrayHasKey( 'motion', $result['coverage'] );
		self::assertArrayHasKey( 'ui', $result['coverage'] );
	}

	public function test_evaluation_names_the_direction_it_measured_against(): void {
		$result = $this->ability()->execute( [ 'evidence' => $this->evidence( 'warning' ) ] );

		self::assertIsArray( $result );
		self::assertSame( 'warn', $result['status'] );
		self::assertSame( $this->direction_id, $result['direction_id'] );
		self::assertSame( 1, $result['direction_revision'] );
		self::assertMatchesRegularExpression( '/^[0-9a-f]{64}$/', $result['direction_hash'] );
	}

	public function test_evaluation_resolves_an_explicit_direction_slug(): void {
		$result = $this->ability()->execute(
			[
				'evidence' => $this->evidence( 'pass' ),
				'slug'     => 'quarry',
			]
		);

		self::assertIsArray( $result );
		self::assertSame( $this->direction_id, $result['direction_id'] );
	}

	public function test_without_a_direction_the_token_rules_report_as_not_checked(): void {
		update_option( 'stonewright_active_design_direction_id', 0 );

		$result = $this->ability()->execute( [ 'evidence' => $this->evidence( 'warning' ) ] );

		self::assertIsArray( $result );
		self::assertSame( 0, $result['direction_id'] );
		self::assertSame( '', $result['direction_hash'] );
		self::assertContains( 'token.typography', $result['coverage']['not_checked_rules'] );
		self::assertContains( 'token.spacing', $result['coverage']['not_checked_rules'] );
	}

	public function test_invalid_evidence_is_refused_by_the_validator(): void {
		$evidence                        = $this->evidence( 'pass' );
		$evidence['viewports'][0]['dom'] = '<html></html>';

		$result = $this->ability()->execute( [ 'evidence' => $evidence ] );

		self::assertInstanceOf( \WP_Error::class, $result );
		self::assertSame( 'stonewright_quality_evidence_invalid', $result->get_error_code() );
	}

	public function test_returned_findings_are_capped_but_the_total_is_honest(): void {
		$evidence = $this->evidence( 'fail' );
		$element  = $evidence['viewports'][0]['elements'][1];
		for ( $i = 0; $i < 30; $i++ ) {
			$element['ref']                        = 'legal-note-' . $i;
			$evidence['viewports'][0]['elements'][] = $element;
		}

		$result = $this->ability()->execute( [ 'evidence' => $evidence ] );

		self::assertIsArray( $result );
		self::assertCount( QualityCheck::RETURNED_FINDINGS, $result['findings'] );
		self::assertGreaterThan( QualityCheck::RETURNED_FINDINGS, $result['findings_total'] );
	}

	// -------------------------------------------------------------------------
	// Persistence.
	// -------------------------------------------------------------------------

	public function test_persisting_stores_a_retrievable_report_and_audits_the_write(): void {
		$result = $this->ability()->execute(
			[
				'evidence' => $this->evidence( 'warning' ),
				'persist'  => true,
			]
		);

		self::assertIsArray( $result );
		self::assertTrue( $result['persisted'] );
		self::assertMatchesRegularExpression( '/^[0-9a-f]{32}$/', $result['report_id'] );
		self::assertSame( self::POST_ID, $result['post_id'] );

		$entry = QualityReportStore::find( self::POST_ID, $result['report_id'] );
		self::assertIsArray( $entry );
		self::assertSame( 'warn', $entry['status'] );
		self::assertSame( $result['direction_hash'], $entry['direction_hash'] );
		self::assertSame( $result['render_hash'], $entry['render_hash'] );

		self::assertContains(
			'stonewright/design-quality-check',
			array_column( $this->audit_rows(), 'ability_name' )
		);
	}

	public function test_persisting_uses_the_post_id_from_the_arguments_over_the_evidence(): void {
		$GLOBALS['stonewright_test_posts'][4214] = (object) [
			'ID'        => 4214,
			'post_type' => 'page',
			'meta'      => [],
		];

		$result = $this->ability()->execute(
			[
				'evidence' => $this->evidence( 'pass' ),
				'post_id'  => 4214,
				'persist'  => true,
			]
		);

		self::assertIsArray( $result );
		self::assertSame( 4214, $result['post_id'] );
		self::assertIsArray( QualityReportStore::find( 4214, $result['report_id'] ) );
		self::assertSame( [], QualityReportStore::latest( self::POST_ID ) );
	}

	public function test_persisting_without_a_post_is_refused(): void {
		$evidence = $this->evidence( 'pass' );
		unset( $evidence['target']['post_id'] );

		$result = $this->ability()->execute(
			[
				'evidence' => $evidence,
				'persist'  => true,
			]
		);

		self::assertInstanceOf( \WP_Error::class, $result );
		self::assertSame( 'stonewright_quality_report_invalid', $result->get_error_code() );
	}

	public function test_persisting_without_a_direction_is_refused(): void {
		update_option( 'stonewright_active_design_direction_id', 0 );

		$result = $this->ability()->execute(
			[
				'evidence' => $this->evidence( 'pass' ),
				'persist'  => true,
			]
		);

		self::assertInstanceOf( \WP_Error::class, $result );
		self::assertSame( 'stonewright_direction_not_found', $result->get_error_code() );
	}

	public function test_a_refused_persist_is_audited_as_an_error(): void {
		update_option( 'stonewright_active_design_direction_id', 0 );

		$this->ability()->execute(
			[
				'evidence' => $this->evidence( 'pass' ),
				'persist'  => true,
			]
		);

		$rows = array_values(
			array_filter(
				$this->audit_rows(),
				static fn( array $row ): bool => 'stonewright/design-quality-check' === ( $row['ability_name'] ?? '' )
			)
		);

		self::assertNotSame( [], $rows );
		self::assertSame( 'error', $rows[0]['result_status'] );
	}

	// -------------------------------------------------------------------------
	// Fixtures.
	// -------------------------------------------------------------------------

	private function ability(): QualityCheck {
		return new QualityCheck( $this->service );
	}

	/**
	 * Audit-log rows captured by the test wpdb double.
	 *
	 * @return list<array<string,mixed>>
	 */
	private function audit_rows(): array {
		$rows = [];

		foreach ( (array) ( $GLOBALS['stonewright_test_wpdb_inserts'] ?? [] ) as $insert ) {
			$data = is_array( $insert['data'] ?? null ) ? $insert['data'] : $insert;
			if ( isset( $data['ability_name'] ) ) {
				$rows[] = $data;
			}
		}

		return $rows;
	}

	/**
	 * @return array<string, mixed>
	 */
	private function evidence( string $name ): array {
		$path = dirname( __DIR__, 2 ) . '/fixtures/design-quality/' . $name . '.json';
		self::assertFileExists( $path );

		$decoded = json_decode( (string) file_get_contents( $path ), true );
		self::assertIsArray( $decoded );

		$decoded['target']['post_id'] = self::POST_ID;

		return $decoded;
	}

	/**
	 * A ready direction whose token scale matches the quality fixtures.
	 *
	 * @return array<string, mixed>
	 */
	private function direction_input(): array {
		return [
			'slug'        => 'quarry',
			'status'      => 'ready',
			'source_type' => 'manual',
			'source_refs' => [],
			'contract'    => [
				'schema_version' => '1.0',
				'identity'       => [
					'name'    => 'Quarry',
					'summary' => 'Stone and precision.',
				],
				'tokens'         => [
					'colors'     => [ 'brand' => '#0b1f3a' ],
					'typography' => [
						'h1'        => [
							'font-family' => 'Inter',
							'font-size'   => '56px',
						],
						'h1-tablet' => [ 'font-size' => '40px' ],
						'h1-mobile' => [ 'font-size' => '32px' ],
						'body'      => [ 'font-size' => '18px' ],
					],
					'spacing'    => [
						'sm'      => '16px',
						'md'      => '24px',
						'lg'      => '48px',
						'section' => '96px',
					],
					'radii'      => [ 'sm' => '2px' ],
					'elevation'  => [ 'low' => '0 1px 2px rgba(0,0,0,0.12)' ],
					'motion'     => [ 'fast' => 120 ],
				],
				'components'     => [],
				'dials'          => [
					'variance' => 20,
					'density'  => 40,
					'motion'   => 10,
				],
				'guidance'       => [
					'do'    => [ 'Keep surfaces quiet.' ],
					'avoid' => [ 'Decorative gradients.' ],
				],
				'provenance'     => [],
				'waivers'        => [],
				'readiness'      => [
					'ready'      => true,
					'sync_ready' => true,
					'issues'     => [],
				],
			],
		];
	}
}
