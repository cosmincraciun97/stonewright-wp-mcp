<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\Tests\Unit\Design;

use PHPUnit\Framework\TestCase;
use Stonewright\WpMcp\Design\Direction\DesignDirectionRepository;
use Stonewright\WpMcp\Design\Direction\DesignDirectionService;

/**
 * Lifecycle tests for the design direction service.
 *
 * The service owns validation, hashing, revision rules, the active pointer,
 * and the audit payload. It is driven here against an in-memory repository so
 * every invariant is asserted against real observable state instead of a mock
 * expectation.
 *
 * @covers \Stonewright\WpMcp\Design\Direction\DesignDirectionService
 */
final class DesignDirectionServiceTest extends TestCase {

	private const ACTIVE_OPTION = 'stonewright_active_design_direction_id';

	private InMemoryDirectionRepository $repository;

	private DesignDirectionService $service;

	protected function setUp(): void {
		$GLOBALS['stonewright_test_options'] = [];
		$GLOBALS['stonewright_test_filters'] = [];

		$this->repository = new InMemoryDirectionRepository();
		$this->service    = new DesignDirectionService( $this->repository );
	}

	protected function tearDown(): void {
		$GLOBALS['stonewright_test_options'] = [];
		$GLOBALS['stonewright_test_filters'] = [];
	}

	public function test_first_save_creates_revision_one(): void {
		$result = $this->service->save( $this->input(), 5 );

		$this->assertIsArray( $result );
		$this->assertSame( 1, $result['revision'] );
		$this->assertSame( 'quarry', $result['slug'] );
		$this->assertTrue( $result['versioned'] );
	}

	public function test_first_save_records_the_first_version(): void {
		$result = $this->service->save( $this->input(), 5 );

		$versions = $this->repository->versions( (int) $result['id'] );

		$this->assertCount( 1, $versions );
		$this->assertSame( 1, $versions[0]['revision'] );
		$this->assertSame( $result['hash_after'], $versions[0]['contract_hash'] );
	}

	public function test_first_save_has_no_previous_hash(): void {
		$result = $this->service->save( $this->input(), 5 );

		$this->assertSame( '', $result['hash_before'] );
		$this->assertNotSame( '', $result['hash_after'] );
		$this->assertSame( 64, strlen( (string) $result['hash_after'] ) );
	}

	public function test_a_content_change_increments_the_revision(): void {
		$this->service->save( $this->input(), 5 );

		$changed                              = $this->input();
		$changed['contract']['identity']['summary'] = 'Sharper edges.';

		$result = $this->service->save( $changed, 5 );

		$this->assertSame( 2, $result['revision'] );
		$this->assertTrue( $result['versioned'] );
		$this->assertCount( 2, $this->repository->versions( (int) $result['id'] ) );
	}

	public function test_a_content_change_reports_both_hashes(): void {
		$first = $this->service->save( $this->input(), 5 );

		$changed                              = $this->input();
		$changed['contract']['identity']['summary'] = 'Sharper edges.';
		$second                               = $this->service->save( $changed, 5 );

		$this->assertSame( $first['hash_after'], $second['hash_before'] );
		$this->assertNotSame( $second['hash_before'], $second['hash_after'] );
	}

	public function test_a_byte_identical_save_creates_no_version(): void {
		$first  = $this->service->save( $this->input(), 5 );
		$second = $this->service->save( $this->input(), 5 );

		$this->assertSame( $first['id'], $second['id'] );
		$this->assertSame( 1, $second['revision'] );
		$this->assertFalse( $second['versioned'] );
		$this->assertCount( 1, $this->repository->versions( (int) $second['id'] ) );
	}

	public function test_a_byte_identical_save_reports_equal_hashes(): void {
		$this->service->save( $this->input(), 5 );
		$second = $this->service->save( $this->input(), 5 );

		$this->assertSame( $second['hash_before'], $second['hash_after'] );
	}

	public function test_key_order_does_not_create_a_revision(): void {
		$this->service->save( $this->input(), 5 );

		$reordered             = $this->input();
		$reordered['contract'] = array_reverse( $reordered['contract'], true );

		$second = $this->service->save( $reordered, 5 );

		$this->assertFalse( $second['versioned'] );
		$this->assertSame( 1, $second['revision'] );
	}

	public function test_save_rejects_an_invalid_contract(): void {
		$input                                = $this->input();
		$input['contract']['dials']['variance'] = 400;

		$result = $this->service->save( $input, 5 );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'stonewright_direction_invalid', $result->get_error_code() );
		$this->assertSame( [], $this->repository->records );
	}

	public function test_save_rejects_an_unsupported_source_type(): void {
		$input                = $this->input();
		$input['source_type'] = 'ftp';

		$result = $this->service->save( $input, 5 );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'stonewright_direction_invalid', $result->get_error_code() );
	}

	public function test_save_derives_the_slug_from_the_identity_name(): void {
		$input = $this->input();
		unset( $input['slug'] );

		$result = $this->service->save( $input, 5 );

		$this->assertSame( 'quarry', $result['slug'] );
	}

	public function test_save_rejects_ready_status_when_the_contract_has_issues(): void {
		$input                                 = $this->input();
		$input['status']                       = 'ready';
		$input['contract']['readiness']['ready'] = false;

		$result = $this->service->save( $input, 5 );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'stonewright_direction_not_ready', $result->get_error_code() );
	}

	public function test_save_marks_ready_when_status_is_ready_and_issues_are_empty(): void {
		$input = $this->input();
		$input['status'] = 'ready';
		$input['contract']['readiness'] = [
			'ready'      => false,
			'sync_ready' => false,
			'issues'     => [],
		];

		$result = $this->service->save( $input, 5 );

		$this->assertIsArray( $result );
		$this->assertSame( 'ready', $result['status'] );
		$stored = $this->repository->get( (int) $result['id'] );
		$this->assertTrue( $stored['contract']['readiness']['ready'] );
	}

	public function test_save_accepts_ready_status_when_the_contract_is_ready(): void {
		$result = $this->service->save( $this->ready_input(), 5 );

		$this->assertSame( 'ready', $result['status'] );
	}

	public function test_save_rejects_an_unknown_status(): void {
		$input           = $this->input();
		$input['status'] = 'published';

		$result = $this->service->save( $input, 5 );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'stonewright_direction_invalid', $result->get_error_code() );
	}

	public function test_save_defaults_to_draft(): void {
		$input = $this->input();
		unset( $input['status'] );

		$result = $this->service->save( $input, 5 );

		$this->assertSame( 'draft', $result['status'] );
	}

	public function test_save_carries_an_audit_payload(): void {
		$result = $this->service->save( $this->input(), 5 );

		$this->assertSame( 'design_direction.save', $result['audit']['action'] );
		$this->assertSame( 5, $result['audit']['actor_id'] );
		$this->assertSame( $result['id'], $result['audit']['direction_id'] );
		$this->assertSame( $result['hash_before'], $result['audit']['hash_before'] );
		$this->assertSame( $result['hash_after'], $result['audit']['hash_after'] );
	}

	public function test_save_rolls_back_a_failed_write(): void {
		$this->repository->fail_save = true;

		$result = $this->service->save( $this->input(), 5 );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( [ 'begin', 'rollback' ], $this->repository->transaction_calls );
	}

	public function test_save_rolls_back_a_failed_version_write(): void {
		$this->repository->fail_add_version = true;

		$result = $this->service->save( $this->input(), 5 );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( [ 'begin', 'rollback' ], $this->repository->transaction_calls );
	}

	public function test_a_successful_save_commits(): void {
		$this->service->save( $this->input(), 5 );

		$this->assertSame( [ 'begin', 'commit' ], $this->repository->transaction_calls );
	}

	public function test_activation_requires_a_ready_contract(): void {
		$saved = $this->service->save( $this->input(), 5 );

		$result = $this->service->activate( (int) $saved['id'], 5 );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'stonewright_direction_not_ready', $result->get_error_code() );
		$this->assertNull( $this->service->active() );
	}

	public function test_activation_sets_the_active_pointer(): void {
		$saved = $this->service->save( $this->ready_input(), 5 );

		$result = $this->service->activate( (int) $saved['id'], 5 );

		$this->assertIsArray( $result );
		$this->assertSame( $saved['id'], (int) get_option( self::ACTIVE_OPTION ) );
		$this->assertSame( $saved['id'], $this->service->active()['id'] );
	}

	public function test_only_one_direction_is_active(): void {
		$first  = $this->service->save( $this->ready_input(), 5 );
		$second = $this->service->save( $this->ready_input( 'granite' ), 5 );

		$this->service->activate( (int) $first['id'], 5 );
		$this->service->activate( (int) $second['id'], 5 );

		$this->assertSame( $second['id'], $this->service->active()['id'] );
		$this->assertSame( (string) $second['id'], (string) get_option( self::ACTIVE_OPTION ) );
	}

	public function test_activation_reports_both_hashes(): void {
		$saved = $this->service->save( $this->ready_input(), 5 );

		$result = $this->service->activate( (int) $saved['id'], 5 );

		$this->assertSame( $saved['hash_after'], $result['hash_before'] );
		$this->assertSame( $saved['hash_after'], $result['hash_after'] );
		$this->assertSame( 'design_direction.activate', $result['audit']['action'] );
	}

	public function test_activation_of_a_missing_record_fails(): void {
		$result = $this->service->activate( 4242, 5 );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'stonewright_direction_not_found', $result->get_error_code() );
	}

	public function test_activation_restores_the_previous_pointer_on_failure(): void {
		$first  = $this->service->save( $this->ready_input(), 5 );
		$second = $this->service->save( $this->ready_input( 'granite' ), 5 );
		$this->service->activate( (int) $first['id'], 5 );

		$this->repository->fail_save = true;
		$result                      = $this->service->activate( (int) $second['id'], 5 );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( $first['id'], (int) get_option( self::ACTIVE_OPTION ) );
	}

	public function test_active_returns_null_without_a_pointer(): void {
		$this->assertNull( $this->service->active() );
	}

	public function test_active_returns_null_for_a_stale_pointer(): void {
		update_option( self::ACTIVE_OPTION, 999 );

		$this->assertNull( $this->service->active() );
	}

	public function test_deactivate_clears_the_active_pointer(): void {
		$saved = $this->service->save( $this->ready_input(), 5 );
		$this->assertIsArray( $saved );
		$this->service->activate( (int) $saved['id'], 5 );

		$result = $this->service->deactivate( 5 );

		$this->assertIsArray( $result );
		$this->assertSame( 0, (int) get_option( self::ACTIVE_OPTION, 0 ) );
		$this->assertSame( 0, $result['id'] );
		$this->assertSame( (int) $saved['id'], $result['previous_active_id'] );
		$this->assertSame( 'inactive', $result['status'] );
		$this->assertSame( hash( 'sha256', (string) (int) $saved['id'] ), $result['hash_before'] );
		$this->assertSame( hash( 'sha256', '0' ), $result['hash_after'] );
		$this->assertNull( $this->service->active() );
		$this->assertSame( 'design_direction.deactivate', $result['audit']['action'] );
	}

	public function test_deactivate_restores_the_previous_pointer_on_failed_readback(): void {
		$saved = $this->service->save( $this->ready_input(), 5 );
		$this->assertIsArray( $saved );
		$this->service->activate( (int) $saved['id'], 5 );

		add_filter(
			'pre_update_option',
			static function ( mixed $value, mixed $option, mixed $old ): mixed {
				if ( self::ACTIVE_OPTION === $option && 0 === (int) $value ) {
					return $old;
				}

				return $value;
			}
		);

		$result = $this->service->deactivate( 5 );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'stonewright_direction_verification_failed', $result->get_error_code() );
		$this->assertSame( (int) $saved['id'], (int) get_option( self::ACTIVE_OPTION, 0 ) );
	}

	public function test_archive_refuses_the_active_record(): void {
		$saved = $this->service->save( $this->ready_input(), 5 );
		$this->service->activate( (int) $saved['id'], 5 );

		$result = $this->service->archive( (int) $saved['id'], 5 );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'stonewright_direction_active', $result->get_error_code() );
		$this->assertSame( 'ready', $this->repository->records[ (int) $saved['id'] ]['status'] );
	}

	public function test_archive_archives_an_inactive_record(): void {
		$saved = $this->service->save( $this->input(), 5 );

		$result = $this->service->archive( (int) $saved['id'], 5 );

		$this->assertIsArray( $result );
		$this->assertSame( 'archived', $this->repository->records[ (int) $saved['id'] ]['status'] );
		$this->assertSame( 'design_direction.archive', $result['audit']['action'] );
	}

	public function test_archive_of_a_missing_record_fails(): void {
		$result = $this->service->archive( 4242, 5 );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'stonewright_direction_not_found', $result->get_error_code() );
	}

	public function test_restore_creates_a_new_revision(): void {
		$first                                = $this->service->save( $this->input(), 5 );
		$changed                              = $this->input();
		$changed['contract']['identity']['summary'] = 'Sharper edges.';
		$this->service->save( $changed, 5 );

		$result = $this->service->restore( (int) $first['id'], 1, 5 );

		$this->assertIsArray( $result );
		$this->assertSame( 3, $result['revision'] );
		$this->assertSame( $first['hash_after'], $result['hash_after'] );
	}

	public function test_restore_does_not_rewrite_history(): void {
		$first                                = $this->service->save( $this->input(), 5 );
		$changed                              = $this->input();
		$changed['contract']['identity']['summary'] = 'Sharper edges.';
		$second                               = $this->service->save( $changed, 5 );

		$this->service->restore( (int) $first['id'], 1, 5 );

		$versions = $this->repository->versions( (int) $first['id'] );
		$this->assertSame( [ 3, 2, 1 ], array_column( $versions, 'revision' ) );
		$this->assertSame( $second['hash_after'], $versions[1]['contract_hash'] );
		$this->assertSame( $first['hash_after'], $versions[2]['contract_hash'] );
	}

	public function test_restore_reports_the_superseded_hash(): void {
		$first                                = $this->service->save( $this->input(), 5 );
		$changed                              = $this->input();
		$changed['contract']['identity']['summary'] = 'Sharper edges.';
		$second                               = $this->service->save( $changed, 5 );

		$result = $this->service->restore( (int) $first['id'], 1, 5 );

		$this->assertSame( $second['hash_after'], $result['hash_before'] );
		$this->assertSame( 'design_direction.restore', $result['audit']['action'] );
		$this->assertSame( 1, $result['audit']['restored_revision'] );
	}

	public function test_restore_rejects_an_unknown_revision(): void {
		$saved = $this->service->save( $this->input(), 5 );

		$result = $this->service->restore( (int) $saved['id'], 9, 5 );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'stonewright_direction_not_found', $result->get_error_code() );
	}

	public function test_restore_of_a_missing_record_fails(): void {
		$result = $this->service->restore( 4242, 1, 5 );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'stonewright_direction_not_found', $result->get_error_code() );
	}

	public function test_restore_of_the_current_contract_creates_no_revision(): void {
		$saved = $this->service->save( $this->input(), 5 );

		$result = $this->service->restore( (int) $saved['id'], 1, 5 );

		$this->assertFalse( $result['versioned'] );
		$this->assertSame( 1, $result['revision'] );
		$this->assertCount( 1, $this->repository->versions( (int) $saved['id'] ) );
	}

	public function test_restore_rejects_a_version_whose_contract_no_longer_validates(): void {
		$saved = $this->service->save( $this->input(), 5 );

		$this->repository->corrupt_version( (int) $saved['id'], 1 );

		$result = $this->service->restore( (int) $saved['id'], 1, 5 );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'stonewright_direction_invalid', $result->get_error_code() );
	}

	public function test_restore_rolls_back_a_failed_write(): void {
		$first                                = $this->service->save( $this->input(), 5 );
		$changed                              = $this->input();
		$changed['contract']['identity']['summary'] = 'Sharper edges.';
		$this->service->save( $changed, 5 );

		$this->repository->fail_save = true;
		$result                      = $this->service->restore( (int) $first['id'], 1, 5 );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'rollback', end( $this->repository->transaction_calls ) );
	}

	/**
	 * A draft save payload.
	 *
	 * @param string $name Direction identity name.
	 * @return array<string,mixed>
	 */
	private function input( string $name = 'Quarry' ): array {
		return [
			'slug'        => sanitize_title( $name ),
			'status'      => 'draft',
			'source_type' => 'manual',
			'source_refs' => [ 'brief' => 'brief:12' ],
			'contract'    => $this->contract( $name ),
		];
	}

	/**
	 * A save payload whose contract is ready for activation.
	 *
	 * @param string $name Direction identity name.
	 * @return array<string,mixed>
	 */
	private function ready_input( string $name = 'Quarry' ): array {
		$input                                   = $this->input( $name );
		$input['status']                         = 'ready';
		$input['contract']['readiness']          = [
			'ready'      => true,
			'sync_ready' => false,
			'issues'     => [],
		];

		return $input;
	}

	/**
	 * A valid contract.
	 *
	 * @param string $name Direction identity name.
	 * @return array<string,mixed>
	 */
	private function contract( string $name ): array {
		return [
			'schema_version' => '1.0',
			'identity'       => [
				'name'    => $name,
				'summary' => 'Stone and precision.',
			],
			'tokens'         => [
				'colors'     => [ 'brand' => '#1f2933' ],
				'typography' => [
					'body' => [
						'family' => 'Inter',
						'size'   => '1rem',
					],
				],
				'spacing'    => [ 'md' => '1rem' ],
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
			'provenance'     => [
				'tokens.colors.brand' => [
					'source'    => 'brief',
					'reference' => 'brief:12',
				],
			],
			'waivers'        => [],
			'readiness'      => [
				'ready'      => false,
				'sync_ready' => false,
				'issues'     => [ 'Component coverage incomplete.' ],
			],
		];
	}
}

/**
 * In-memory repository double.
 *
 * Holds records and versions in arrays so the service's lifecycle rules are
 * observable without a database.
 */
final class InMemoryDirectionRepository extends DesignDirectionRepository {

	/** @var array<int,array<string,mixed>> */
	public array $records = [];

	/** @var list<array<string,mixed>> */
	public array $version_rows = [];

	/** @var list<string> */
	public array $transaction_calls = [];

	public bool $fail_save = false;

	public bool $fail_add_version = false;

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
		if ( $this->fail_save ) {
			return new \WP_Error( 'stonewright_direction_write_failed', 'Write failed.' );
		}

		$id = isset( $record['id'] ) ? (int) $record['id'] : $this->next_id++;

		$record['id']                 = $id;
		$record['created_at']       ??= '2026-07-24 09:00:00';
		$record['updated_at']         = '2026-07-24 09:00:00';
		$this->records[ $id ]         = $record;

		return $id;
	}

	/**
	 * @param array<string,mixed> $snapshot
	 * @return int|\WP_Error
	 */
	public function add_version( array $snapshot ) {
		if ( $this->fail_add_version ) {
			return new \WP_Error( 'stonewright_direction_write_failed', 'Write failed.' );
		}

		$snapshot['id']       = $this->next_version_id++;
		$snapshot['created_at'] = '2026-07-24 09:00:00';
		$this->version_rows[] = $snapshot;

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
		if ( $this->fail_save ) {
			return new \WP_Error( 'stonewright_direction_write_failed', 'Write failed.' );
		}

		if ( ! isset( $this->records[ $id ] ) ) {
			return new \WP_Error( 'stonewright_direction_not_found', 'Missing record.' );
		}

		$this->records[ $id ]['status'] = 'archived';

		return true;
	}

	public function begin_transaction(): void {
		$this->transaction_calls[] = 'begin';
	}

	public function commit_transaction(): void {
		$this->transaction_calls[] = 'commit';
	}

	public function rollback_transaction(): void {
		$this->transaction_calls[] = 'rollback';
	}

	/**
	 * Replaces a stored version's contract with one that cannot validate.
	 */
	public function corrupt_version( int $id, int $revision ): void {
		foreach ( $this->version_rows as $index => $row ) {
			if ( (int) $row['direction_id'] === $id && (int) $row['revision'] === $revision ) {
				$this->version_rows[ $index ]['contract']['dials']['variance'] = 900;
			}
		}
	}
}
