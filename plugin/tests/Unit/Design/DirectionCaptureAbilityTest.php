<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\Tests\Unit\Design;

use PHPUnit\Framework\TestCase;
use Stonewright\WpMcp\Abilities\Design\DirectionActivate;
use Stonewright\WpMcp\Abilities\Design\DirectionCapture;
use Stonewright\WpMcp\Abilities\Design\DirectionSave;
use Stonewright\WpMcp\Core\AbilityRegistry;
use Stonewright\WpMcp\Design\Direction\DesignDirectionRepository;
use Stonewright\WpMcp\Design\Direction\DesignDirectionService;
use Stonewright\WpMcp\Design\Direction\ElementorDirectionCapture;

/**
 * Security-envelope tests for stonewright/design-direction-capture.
 *
 * Capture reads Elementor and proposes a contract, so the risk is not the
 * mapping - that lives in ElementorDirectionCaptureTest - but what the ability
 * is allowed to do with the result. These tests pin that a preview writes
 * nothing, that persisting requires an explicit opt-in, that a persisted
 * capture lands as a draft and never becomes the active direction, and that the
 * ability carries the write permission and task context token even in preview
 * mode because a single flag flips it into a write.
 *
 * @covers \Stonewright\WpMcp\Abilities\Design\DirectionCapture
 */
final class DirectionCaptureAbilityTest extends TestCase {

	private const ACTIVE_OPTION = 'stonewright_active_design_direction_id';

	private DirectionCaptureRepository $repository;

	private DesignDirectionService $service;

	protected function setUp(): void {
		$GLOBALS['stonewright_test_options']         = [];
		$GLOBALS['stonewright_test_user_caps']       = [];
		$GLOBALS['stonewright_test_user_logged_in']  = true;
		$GLOBALS['stonewright_test_current_user_id'] = 7;
		$GLOBALS['stonewright_test_wpdb_inserts']    = [];

		$this->repository = new DirectionCaptureRepository();
		$this->service    = new DesignDirectionService( $this->repository );
	}

	protected function tearDown(): void {
		$GLOBALS['stonewright_test_options']         = [];
		$GLOBALS['stonewright_test_user_caps']       = [];
		$GLOBALS['stonewright_test_user_logged_in']  = false;
		$GLOBALS['stonewright_test_current_user_id'] = 0;
		$GLOBALS['stonewright_test_wpdb_inserts']    = [];
	}

	// -------------------------------------------------------------------------
	// Registration and gates.
	// -------------------------------------------------------------------------

	public function test_ability_uses_the_documented_slug_and_category(): void {
		$ability = new DirectionCapture( $this->service );

		self::assertSame( 'stonewright/design-direction-capture', $ability->name() );
		self::assertSame( 'design', $ability->category() );
	}

	public function test_registry_publishes_the_capture_ability(): void {
		$names = array_column( AbilityRegistry::all_abilities(), 'name' );

		self::assertContains( 'stonewright/design-direction-capture', $names );
	}

	public function test_registry_requires_the_task_context_token(): void {
		$schema = [];
		foreach ( AbilityRegistry::all_abilities() as $ability ) {
			if ( 'stonewright/design-direction-capture' === $ability['name'] ) {
				$schema = $ability['input_schema'];
			}
		}

		self::assertArrayHasKey( 'stonewright_context_token', $schema['properties'] ?? [] );
		self::assertContains( 'stonewright_context_token', $schema['required'] ?? [] );
	}

	public function test_ability_gates_on_the_design_write_permission(): void {
		$ability = new DirectionCapture( $this->service );

		$GLOBALS['stonewright_test_user_caps'] = [ 'read' => true ];
		self::assertFalse( $ability->permission_callback( [] ) );

		$GLOBALS['stonewright_test_user_caps'] = [
			'manage_options' => true,
			'edit_pages'     => true,
		];
		self::assertTrue( $ability->permission_callback( [] ) );
	}

	// -------------------------------------------------------------------------
	// Preview.
	// -------------------------------------------------------------------------

	public function test_preview_returns_the_draft_contract_without_storing_it(): void {
		$result = ( new DirectionCapture( $this->service ) )->execute( [ 'evidence' => $this->evidence() ] );

		self::assertIsArray( $result );
		self::assertTrue( $result['ok'] );
		self::assertFalse( $result['saved'] );
		self::assertSame( 0, $result['id'] );
		self::assertSame( 'Stone Kit', $result['contract']['identity']['name'] );
		self::assertSame( [], $this->repository->records );
		self::assertSame( [], $this->repository->version_rows );
	}

	public function test_preview_reports_the_contract_hash_it_would_store(): void {
		$capture = ( new DirectionCapture( $this->service ) )->execute( [ 'evidence' => $this->evidence() ] );
		self::assertIsArray( $capture );

		$saved = ( new DirectionCapture( $this->service ) )->execute(
			[
				'evidence' => $this->evidence(),
				'save'     => true,
			]
		);

		self::assertIsArray( $saved );
		self::assertSame( $capture['contract_hash'], $saved['contract_hash'] );
	}

	public function test_preview_carries_issues_conflicts_and_unmapped_evidence(): void {
		$evidence                       = $this->evidence();
		$evidence['kit_title']          = '';
		$evidence['colors'][]           = [
			'title' => 'Primary',
			'color' => '#FF0000',
		];
		$evidence['layout']['nonsense'] = 'x';

		$result = ( new DirectionCapture( $this->service ) )->execute( [ 'evidence' => $evidence ] );

		self::assertIsArray( $result );
		self::assertNotSame( [], $result['issues'] );
		self::assertContains( 'tokens.colors.primary', $result['conflicts'] );
		self::assertContains( 'layout.nonsense', $result['unmapped'] );
	}

	// -------------------------------------------------------------------------
	// Persisting.
	// -------------------------------------------------------------------------

	public function test_save_true_stores_a_draft_and_returns_the_receipt(): void {
		$result = ( new DirectionCapture( $this->service ) )->execute(
			[
				'evidence' => $this->evidence(),
				'save'     => true,
			]
		);

		self::assertIsArray( $result );
		self::assertTrue( $result['saved'] );
		self::assertGreaterThan( 0, $result['id'] );
		self::assertSame( 'draft', $result['status'] );
		self::assertSame( 1, $result['revision'] );
		self::assertTrue( $result['effect_verified'] );

		$stored = $this->repository->get( (int) $result['id'] );
		self::assertIsArray( $stored );
		self::assertSame( 'capture', $stored['source_type'] );
		self::assertSame( $result['contract_hash'], $stored['contract_hash'] );
	}

	public function test_stored_capture_records_the_kit_as_its_source_reference(): void {
		$result = ( new DirectionCapture( $this->service ) )->execute(
			[
				'evidence' => $this->evidence(),
				'save'     => true,
			]
		);

		self::assertIsArray( $result );
		$stored = $this->repository->get( (int) $result['id'] );
		self::assertIsArray( $stored );
		self::assertSame( [ 'kit' => 'kit:12' ], $stored['source_refs'] );
	}

	public function test_a_capture_never_becomes_the_active_direction(): void {
		( new DirectionCapture( $this->service ) )->execute(
			[
				'evidence' => $this->evidence(),
				'save'     => true,
			]
		);

		self::assertSame( 0, (int) get_option( self::ACTIVE_OPTION, 0 ) );
	}

	public function test_a_stored_capture_is_never_marked_ready(): void {
		$result = ( new DirectionCapture( $this->service ) )->execute(
			[
				'evidence' => $this->evidence(),
				'save'     => true,
			]
		);

		self::assertIsArray( $result );
		$stored = $this->repository->get( (int) $result['id'] );
		self::assertIsArray( $stored );
		self::assertFalse( $stored['contract']['readiness']['ready'] );
	}

	public function test_explicit_slug_is_used_when_supplied(): void {
		$result = ( new DirectionCapture( $this->service ) )->execute(
			[
				'evidence' => $this->evidence(),
				'save'     => true,
				'slug'     => 'Captured Stone',
			]
		);

		self::assertIsArray( $result );
		self::assertSame( 'captured-stone', $result['slug'] );
	}

	public function test_saving_records_an_audit_event(): void {
		( new DirectionCapture( $this->service ) )->execute(
			[
				'evidence' => $this->evidence(),
				'save'     => true,
			]
		);

		self::assertContains(
			'stonewright/design-direction-capture',
			array_column( $this->audit_rows(), 'ability_name' )
		);
	}

	// -------------------------------------------------------------------------
	// Rejections.
	// -------------------------------------------------------------------------

	public function test_missing_evidence_is_rejected(): void {
		$result = ( new DirectionCapture( $this->service ) )->execute( [] );

		self::assertInstanceOf( \WP_Error::class, $result );
		self::assertSame( ElementorDirectionCapture::ERROR_CODE, $result->get_error_code() );
	}

	public function test_invalid_evidence_is_rejected_before_any_write(): void {
		$result = ( new DirectionCapture( $this->service ) )->execute(
			[
				'evidence' => [ 'kit_id' => 0 ],
				'save'     => true,
			]
		);

		self::assertInstanceOf( \WP_Error::class, $result );
		self::assertSame( ElementorDirectionCapture::ERROR_CODE, $result->get_error_code() );
		self::assertSame( [], $this->repository->records );
	}

	public function test_capture_contract_saves_and_activates_with_zero_edits(): void {
		$GLOBALS['stonewright_test_user_caps'] = [
			'manage_options' => true,
			'edit_pages'     => true,
		];

		$capture = ( new DirectionCapture( $this->service ) )->execute(
			[
				'evidence' => $this->evidence(),
				'save'     => true,
			]
		);
		self::assertIsArray( $capture );
		self::assertTrue( $capture['saved'] );

		$payload = json_decode( (string) wp_json_encode( $capture['contract'] ), true );
		self::assertIsArray( $payload );

		$saved = ( new DirectionSave( $this->service ) )->execute(
			[
				'contract' => $payload,
				'slug'     => (string) $capture['slug'],
				'status'   => 'ready',
			]
		);
		self::assertIsArray( $saved, $saved instanceof \WP_Error ? $saved->get_error_message() : '' );
		self::assertSame( 'ready', $saved['status'] );

		$activated = ( new DirectionActivate( $this->service ) )->execute( [ 'id' => (int) $saved['id'] ] );
		self::assertIsArray( $activated, $activated instanceof \WP_Error ? $activated->get_error_message() : '' );
		self::assertTrue( $activated['ok'] );
		self::assertSame( (int) $saved['id'], (int) get_option( self::ACTIVE_OPTION, 0 ) );
	}

	public function test_oversized_evidence_is_rejected_before_mapping(): void {
		$evidence              = $this->evidence();
		$evidence['kit_title'] = str_repeat( 'a', 300000 );

		$result = ( new DirectionCapture( $this->service ) )->execute( [ 'evidence' => $evidence ] );

		self::assertInstanceOf( \WP_Error::class, $result );
		self::assertSame( 'stonewright_direction_payload_too_large', $result->get_error_code() );
	}

	// -------------------------------------------------------------------------
	// Helpers.
	// -------------------------------------------------------------------------

	/**
	 * @return array<string,mixed>
	 */
	private function evidence(): array {
		return [
			'kit_id'      => 12,
			'kit_title'   => 'Stone Kit',
			'colors'      => [
				[
					'title' => 'Primary',
					'color' => '#1B1B1B',
				],
			],
			'typography'  => [
				[
					'title'       => 'Heading',
					'font_family' => 'Fraunces',
					'font_size'   => '48px',
				],
			],
			'layout'      => [ 'container_width' => '1200px' ],
			'breakpoints' => [ 'mobile' => 767 ],
			'buttons'     => [ 'border_radius' => '4px' ],
		];
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
}

/**
 * In-memory repository double for the capture ability tests.
 *
 * Each direction test file carries its own double so the files stay
 * independent: none of them depends on another file having been loaded first.
 */
final class DirectionCaptureRepository extends DesignDirectionRepository {

	/** @var array<int,array<string,mixed>> */
	public array $records = [];

	/** @var list<array<string,mixed>> */
	public array $version_rows = [];

	private int $next_id = 1;

	private int $next_version_id = 1;

	public function __construct() {
	}

	/**
	 * @param array<string,mixed> $filters
	 * @return list<array<string,mixed>>
	 */
	public function list( array $filters = [] ): array {
		$records = array_values( $this->records );

		if ( isset( $filters['status'] ) ) {
			$records = array_values(
				array_filter(
					$records,
					static fn( array $record ): bool => $record['status'] === $filters['status']
				)
			);
		}

		return $records;
	}

	/**
	 * @return array<string,mixed>|null
	 */
	public function get( int $id ): ?array {
		return $this->records[ $id ] ?? null;
	}

	/**
	 * @return array<string,mixed>|null
	 */
	public function find_by_slug( string $slug ): ?array {
		foreach ( $this->records as $record ) {
			if ( $record['slug'] === $slug ) {
				return $record;
			}
		}

		return null;
	}

	/**
	 * @param array<string,mixed> $record
	 * @return int|\WP_Error
	 */
	public function save( array $record ) {
		$id = isset( $record['id'] ) ? (int) $record['id'] : $this->next_id++;

		$record['id']           = $id;
		$record['created_at'] ??= '2026-07-24 09:00:00';
		$record['updated_at']   = '2026-07-24 09:00:00';
		$this->records[ $id ]   = $record;

		return $id;
	}

	/**
	 * @param array<string,mixed> $snapshot
	 * @return int|\WP_Error
	 */
	public function add_version( array $snapshot ) {
		$snapshot['id']         = $this->next_version_id++;
		$snapshot['created_at'] = '2026-07-24 09:00:00';
		$this->version_rows[]   = $snapshot;

		return (int) $snapshot['id'];
	}

	/**
	 * @return list<array<string,mixed>>
	 */
	public function versions( int $id ): array {
		$rows = array_values(
			array_filter(
				$this->version_rows,
				static fn( array $row ): bool => (int) $row['direction_id'] === $id
			)
		);

		usort( $rows, static fn( array $a, array $b ): int => (int) $b['revision'] <=> (int) $a['revision'] );

		return $rows;
	}

	/**
	 * @return array<string,mixed>|null
	 */
	public function version( int $id, int $revision ): ?array {
		foreach ( $this->version_rows as $row ) {
			if ( (int) $row['direction_id'] === $id && (int) $row['revision'] === $revision ) {
				return $row;
			}
		}

		return null;
	}

	/**
	 * @return true|\WP_Error
	 */
	public function archive( int $id ) {
		if ( ! isset( $this->records[ $id ] ) ) {
			return new \WP_Error( 'stonewright_direction_write_failed', 'Missing record.' );
		}

		$this->records[ $id ]['status'] = 'archived';

		return true;
	}

	public function begin_transaction(): void {
	}

	public function commit_transaction(): void {
	}

	public function rollback_transaction(): void {
	}
}
