<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\Tests\Unit\Design;

use PHPUnit\Framework\TestCase;
use Stonewright\WpMcp\Design\Quality\QualityReportStore;

/**
 * Storage tests for the bounded per-post quality report ledger.
 *
 * The ledger exists so a report can be reviewed after the browser session that
 * produced it is gone. That makes two properties load bearing: every entry says
 * exactly which page, which direction revision, and which render it describes,
 * and the ledger can never grow without a bound. Everything else here defends
 * those two properties.
 *
 * @covers \Stonewright\WpMcp\Design\Quality\QualityReportStore
 */
final class QualityReportStoreTest extends TestCase {

	private const POST_ID = 4213;

	private const DIRECTION_ID = 7001;

	private const RENDER_HASH = '4f2d425115260cad7e4182b6c9d23e5f708192a3b4c5d6e7f8091a2b3c4d5e6f';

	private const DIRECTION_HASH = 'a1b2c3d4e5f60718293a4b5c6d7e8f901122334455667788990aabbccddeeff0';

	protected function setUp(): void {
		$GLOBALS['stonewright_test_options']           = [];
		$GLOBALS['stonewright_test_post_meta_calls']   = [];
		$GLOBALS['stonewright_test_posts']             = [
			self::POST_ID => (object) [
				'ID'        => self::POST_ID,
				'post_type' => 'page',
				'meta'      => [],
			],
		];
	}

	protected function tearDown(): void {
		$GLOBALS['stonewright_test_options']         = [];
		$GLOBALS['stonewright_test_post_meta_calls'] = [];
		$GLOBALS['stonewright_test_posts']           = [];
	}

	// -------------------------------------------------------------------------
	// Identity and provenance.
	// -------------------------------------------------------------------------

	public function test_save_returns_a_hex_report_id(): void {
		$id = QualityReportStore::save( self::POST_ID, self::DIRECTION_ID, $this->report() );

		self::assertIsString( $id );
		self::assertMatchesRegularExpression( '/^[0-9a-f]{32}$/', $id );
	}

	public function test_an_entry_names_the_post_direction_render_and_time(): void {
		$id = QualityReportStore::save( self::POST_ID, self::DIRECTION_ID, $this->report() );
		self::assertIsString( $id );

		$entry = QualityReportStore::find( self::POST_ID, $id );

		self::assertIsArray( $entry );
		self::assertSame( $id, $entry['report_id'] );
		self::assertSame( self::POST_ID, $entry['post_id'] );
		self::assertSame( self::DIRECTION_ID, $entry['direction_id'] );
		self::assertSame( 3, $entry['direction_revision'] );
		self::assertSame( self::DIRECTION_HASH, $entry['direction_hash'] );
		self::assertSame( self::RENDER_HASH, $entry['render_hash'] );
		self::assertMatchesRegularExpression( '/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', $entry['created_at'] );
		self::assertSame( 'warn', $entry['status'] );
		self::assertSame( 12, $entry['coverage']['checked'] );
	}

	public function test_report_ids_stay_unique_for_identical_payloads(): void {
		$first  = QualityReportStore::save( self::POST_ID, self::DIRECTION_ID, $this->report() );
		$second = QualityReportStore::save( self::POST_ID, self::DIRECTION_ID, $this->report() );

		self::assertIsString( $first );
		self::assertIsString( $second );
		self::assertNotSame( $first, $second );
	}

	public function test_the_ledger_lives_in_one_documented_post_meta_key(): void {
		QualityReportStore::save( self::POST_ID, self::DIRECTION_ID, $this->report() );

		$keys = array_column( (array) $GLOBALS['stonewright_test_post_meta_calls'], 'meta_key' );

		self::assertSame( [ QualityReportStore::META_KEY ], array_values( array_unique( $keys ) ) );
	}

	// -------------------------------------------------------------------------
	// Refusals. The ledger is written from evaluator output, but a caller can
	// still hand it something that would make an entry unreadable later.
	// -------------------------------------------------------------------------

	/**
	 * @return array<string, array{0: string}>
	 */
	public static function required_key_provider(): array {
		return [
			'status'             => [ 'status' ],
			'coverage'           => [ 'coverage' ],
			'findings'           => [ 'findings' ],
			'direction_revision' => [ 'direction_revision' ],
			'direction_hash'     => [ 'direction_hash' ],
			'render_hash'        => [ 'render_hash' ],
		];
	}

	/**
	 * @dataProvider required_key_provider
	 */
	public function test_save_refuses_a_report_missing_a_required_key( string $key ): void {
		$report = $this->report();
		unset( $report[ $key ] );

		$result = QualityReportStore::save( self::POST_ID, self::DIRECTION_ID, $report );

		self::assertInstanceOf( \WP_Error::class, $result );
		self::assertSame( QualityReportStore::ERROR_CODE, $result->get_error_code() );
	}

	public function test_save_refuses_an_unknown_report_key_instead_of_stripping_it(): void {
		$report              = $this->report();
		$report['screenshot'] = 'data:image/png;base64,iVBORw0KGgo=';

		$result = QualityReportStore::save( self::POST_ID, self::DIRECTION_ID, $report );

		self::assertInstanceOf( \WP_Error::class, $result );
		self::assertSame( QualityReportStore::ERROR_CODE, $result->get_error_code() );
		self::assertStringContainsString( 'screenshot', $result->get_error_message() );
	}

	public function test_save_refuses_a_finding_carrying_markup_or_a_screenshot(): void {
		$report                            = $this->report();
		$report['findings'][0]['outer_html'] = '<h1 class="hero">Quarry</h1>';

		$result = QualityReportStore::save( self::POST_ID, self::DIRECTION_ID, $report );

		self::assertInstanceOf( \WP_Error::class, $result );
		self::assertSame( QualityReportStore::ERROR_CODE, $result->get_error_code() );
		self::assertStringContainsString( 'outer_html', $result->get_error_message() );
	}

	public function test_save_refuses_an_unsupported_status(): void {
		$report           = $this->report();
		$report['status'] = 'beautiful';

		$result = QualityReportStore::save( self::POST_ID, self::DIRECTION_ID, $report );

		self::assertInstanceOf( \WP_Error::class, $result );
		self::assertSame( QualityReportStore::ERROR_CODE, $result->get_error_code() );
	}

	public function test_save_refuses_a_hash_that_is_not_a_sha256(): void {
		$report                = $this->report();
		$report['render_hash'] = 'not-a-hash';

		$result = QualityReportStore::save( self::POST_ID, self::DIRECTION_ID, $report );

		self::assertInstanceOf( \WP_Error::class, $result );
		self::assertSame( QualityReportStore::ERROR_CODE, $result->get_error_code() );
	}

	public function test_save_refuses_a_post_that_does_not_exist(): void {
		$result = QualityReportStore::save( 999999, self::DIRECTION_ID, $this->report() );

		self::assertInstanceOf( \WP_Error::class, $result );
		self::assertSame( QualityReportStore::ERROR_CODE, $result->get_error_code() );
	}

	public function test_save_refuses_a_non_positive_direction_id(): void {
		$result = QualityReportStore::save( self::POST_ID, 0, $this->report() );

		self::assertInstanceOf( \WP_Error::class, $result );
		self::assertSame( QualityReportStore::ERROR_CODE, $result->get_error_code() );
	}

	// -------------------------------------------------------------------------
	// Bounds. Post meta is a single serialized row, so an unbounded ledger is a
	// slow page load and eventually a broken one.
	// -------------------------------------------------------------------------

	public function test_stored_findings_are_capped_and_the_drop_is_reported(): void {
		$report             = $this->report();
		$report['findings'] = array_fill( 0, QualityReportStore::MAX_FINDINGS + 40, $report['findings'][0] );

		$id = QualityReportStore::save( self::POST_ID, self::DIRECTION_ID, $report );
		self::assertIsString( $id );

		$entry = QualityReportStore::find( self::POST_ID, $id );

		self::assertIsArray( $entry );
		self::assertCount( QualityReportStore::MAX_FINDINGS, $entry['findings'] );
		self::assertSame( 40, $entry['truncated_findings'] );
	}

	public function test_an_incoming_truncation_count_is_carried_forward(): void {
		$report                       = $this->report();
		$report['truncated_findings'] = 7;

		$id = QualityReportStore::save( self::POST_ID, self::DIRECTION_ID, $report );
		self::assertIsString( $id );

		$entry = QualityReportStore::find( self::POST_ID, $id );

		self::assertIsArray( $entry );
		self::assertSame( 7, $entry['truncated_findings'] );
	}

	public function test_the_ledger_keeps_only_the_newest_reports(): void {
		$ids = [];
		for ( $i = 0; $i < QualityReportStore::MAX_REPORTS + 5; $i++ ) {
			$report           = $this->report();
			$report['status'] = 0 === $i % 2 ? 'pass' : 'warn';
			$id               = QualityReportStore::save( self::POST_ID, self::DIRECTION_ID, $report );
			self::assertIsString( $id );
			$ids[] = $id;
		}

		$latest = QualityReportStore::latest( self::POST_ID, QualityReportStore::MAX_REPORTS );

		self::assertCount( QualityReportStore::MAX_REPORTS, $latest );
		self::assertSame( array_pop( $ids ), $latest[0]['report_id'] );
		self::assertNull( QualityReportStore::find( self::POST_ID, $ids[0] ) );
	}

	public function test_latest_is_newest_first_and_honours_the_limit(): void {
		$first  = QualityReportStore::save( self::POST_ID, self::DIRECTION_ID, $this->report() );
		$second = QualityReportStore::save( self::POST_ID, self::DIRECTION_ID, $this->report() );
		$third  = QualityReportStore::save( self::POST_ID, self::DIRECTION_ID, $this->report() );

		self::assertSame(
			[ $third, $second, $first ],
			array_column( QualityReportStore::latest( self::POST_ID ), 'report_id' )
		);
		self::assertSame(
			[ $third, $second ],
			array_column( QualityReportStore::latest( self::POST_ID, 2 ), 'report_id' )
		);
	}

	public function test_latest_clamps_a_hostile_limit(): void {
		QualityReportStore::save( self::POST_ID, self::DIRECTION_ID, $this->report() );

		self::assertCount( 1, QualityReportStore::latest( self::POST_ID, 5000 ) );
		self::assertSame( [], QualityReportStore::latest( self::POST_ID, 0 ) );
	}

	public function test_an_unknown_post_or_report_reads_as_empty(): void {
		self::assertSame( [], QualityReportStore::latest( 999999 ) );
		self::assertNull( QualityReportStore::find( 999999, str_repeat( 'a', 32 ) ) );
		self::assertNull( QualityReportStore::find( self::POST_ID, str_repeat( 'a', 32 ) ) );
	}

	public function test_a_corrupt_ledger_reads_as_empty_instead_of_throwing(): void {
		$GLOBALS['stonewright_test_posts'][ self::POST_ID ]->meta = [
			QualityReportStore::META_KEY => 'not-a-list',
		];

		self::assertSame( [], QualityReportStore::latest( self::POST_ID ) );
	}

	public function test_a_report_from_another_post_is_not_readable_through_this_post(): void {
		$GLOBALS['stonewright_test_posts'][4214] = (object) [
			'ID'        => 4214,
			'post_type' => 'page',
			'meta'      => [],
		];

		$other = QualityReportStore::save( 4214, self::DIRECTION_ID, $this->report() );
		self::assertIsString( $other );

		self::assertNull( QualityReportStore::find( self::POST_ID, $other ) );
		self::assertIsArray( QualityReportStore::find( 4214, $other ) );
	}

	// -------------------------------------------------------------------------
	// Fixtures.
	// -------------------------------------------------------------------------

	/**
	 * An evaluator report envelope as the ability composes it.
	 *
	 * @return array<string, mixed>
	 */
	private function report(): array {
		return [
			'schema_version'     => '1.0',
			'status'             => 'warn',
			'coverage'           => [
				'checked'           => 12,
				'not_checked'       => 2,
				'not_checked_rules' => [ 'contrast.focus' ],
			],
			'findings'           => [
				[
					'rule_id'       => 'token.typography',
					'severity'      => 'warning',
					'viewport'      => 'desktop',
					'element_ref'   => 'hero-title',
					'evidence'      => [
						'actual'   => 57,
						'required' => 56,
						'property' => 'font.size_px',
					],
					'repair_hint'   => 'Use the direction type scale.',
					'waived'        => false,
					'waiver_reason' => '',
				],
			],
			'truncated_findings' => 0,
			'direction_revision' => 3,
			'direction_hash'     => self::DIRECTION_HASH,
			'render_hash'        => self::RENDER_HASH,
		];
	}
}
