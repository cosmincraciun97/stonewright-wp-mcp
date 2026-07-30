<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\Tests\Unit\Admin;

use PHPUnit\Framework\TestCase;
use Stonewright\WpMcp\Abilities\AbilityKernel;
use Stonewright\WpMcp\Abilities\Design\DirectionList;
use Stonewright\WpMcp\Admin\DesignStudioRestApi;
use Stonewright\WpMcp\Design\Direction\DesignDirectionRepository;
use Stonewright\WpMcp\Design\Direction\DesignDirectionService;

/**
 * Security and delegation contract for the Design Studio REST controller.
 *
 * The controller is deliberately a routing table plus one generic dispatcher:
 * every endpoint hands its arguments to the typed design ability that already
 * owns the rule, so the admin UI cannot reach a code path the MCP surface does
 * not have. These tests pin that boundary — capability, nonce, id shape, error
 * envelope, and the absence of storage calls in the controller itself.
 *
 * @covers \Stonewright\WpMcp\Admin\DesignStudioRestApi
 */
final class DesignStudioRestApiTest extends TestCase {

	private DesignStudioFakeDirectionRepository $repository;

	private DesignDirectionService $service;

	protected function setUp(): void {
		$GLOBALS['stonewright_test_rest_routes']     = [];
		$GLOBALS['stonewright_test_options']         = [];
		$GLOBALS['stonewright_test_user_caps']       = [
			'read'           => true,
			'manage_options' => true,
			'edit_pages'     => true,
		];
		$GLOBALS['stonewright_test_user_logged_in']  = true;
		$GLOBALS['stonewright_test_current_user_id'] = 7;
		$GLOBALS['stonewright_test_posts']           = [];
		$GLOBALS['stonewright_test_wpdb_inserts']    = [];
		$GLOBALS['stonewright_test_nonce_invalid']   = false;

		$this->repository = new DesignStudioFakeDirectionRepository();
		$this->service    = new DesignDirectionService( $this->repository );

		DesignStudioRestApi::reset_for_tests();
		DesignStudioRestApi::set_service_for_tests( $this->service );
	}

	protected function tearDown(): void {
		DesignStudioRestApi::reset_for_tests();

		$GLOBALS['stonewright_test_rest_routes']     = [];
		$GLOBALS['stonewright_test_options']         = [];
		$GLOBALS['stonewright_test_user_caps']       = [];
		$GLOBALS['stonewright_test_user_logged_in']  = false;
		$GLOBALS['stonewright_test_current_user_id'] = 0;
		$GLOBALS['stonewright_test_posts']           = [];
		$GLOBALS['stonewright_test_wpdb_inserts']    = [];
		$GLOBALS['stonewright_test_nonce_invalid']   = false;
	}

	// -------------------------------------------------------------------------
	// Registration.
	// -------------------------------------------------------------------------

	public function test_register_publishes_every_documented_route(): void {
		DesignStudioRestApi::register();

		$paths = [];
		foreach ( $GLOBALS['stonewright_test_rest_routes'] as $route ) {
			self::assertSame( 'stonewright/v1', $route['namespace'] );
			$paths[] = $route['route'];
		}

		self::assertContains( '/design-studio/directions', $paths );
		self::assertContains( '/design-studio/directions/(?P<id>\d+)', $paths );
		self::assertContains( '/design-studio/directions/(?P<id>\d+)/activate', $paths );
		self::assertContains( '/design-studio/directions/(?P<id>\d+)/restore', $paths );
		self::assertContains( '/design-studio/directions/(?P<id>\d+)/sync-plan', $paths );
		self::assertContains( '/design-studio/directions/(?P<id>\d+)/sync-apply', $paths );
		self::assertContains( '/design-studio/quality', $paths );
	}

	public function test_register_is_idempotent(): void {
		DesignStudioRestApi::register();
		$first = count( $GLOBALS['stonewright_test_rest_routes'] );

		DesignStudioRestApi::register();

		self::assertCount( $first, $GLOBALS['stonewright_test_rest_routes'] );
	}

	public function test_the_route_table_covers_both_a_read_and_a_write_for_directions(): void {
		$methods = [];
		foreach ( DesignStudioRestApi::routes() as $route ) {
			$methods[] = $route['methods'];
		}

		self::assertContains( 'GET', $methods );
		self::assertContains( 'POST', $methods );
	}

	// -------------------------------------------------------------------------
	// Capability and nonce.
	// -------------------------------------------------------------------------

	public function test_every_route_requires_manage_options(): void {
		$GLOBALS['stonewright_test_user_caps'] = [];

		foreach ( array_keys( DesignStudioRestApi::routes() ) as $route_id ) {
			$request = new \WP_REST_Request( 'POST', '/design-studio' );
			$request->set_header( 'x-wp-nonce', 'valid' );

			$allowed = DesignStudioRestApi::check_permission( $route_id, $request );

			self::assertInstanceOf( \WP_Error::class, $allowed, $route_id );
			self::assertSame( 403, $allowed->get_error_data()['status'] ?? 0, $route_id );
		}
	}

	public function test_mutating_routes_require_a_rest_nonce(): void {
		foreach ( DesignStudioRestApi::routes() as $route_id => $route ) {
			if ( 'GET' === $route['methods'] ) {
				continue;
			}

			$request = new \WP_REST_Request( 'POST', '/design-studio' );

			$allowed = DesignStudioRestApi::check_permission( $route_id, $request );

			self::assertInstanceOf( \WP_Error::class, $allowed, $route_id );
			self::assertSame( 'stonewright_design_studio_invalid_nonce', $allowed->get_error_code(), $route_id );
			self::assertSame( 403, $allowed->get_error_data()['status'] ?? 0, $route_id );
		}
	}

	public function test_mutating_routes_reject_a_stale_nonce(): void {
		$GLOBALS['stonewright_test_nonce_invalid'] = true;

		$request = new \WP_REST_Request( 'POST', '/design-studio' );
		$request->set_header( 'x-wp-nonce', 'stale' );

		$allowed = DesignStudioRestApi::check_permission( 'directions.save', $request );

		self::assertInstanceOf( \WP_Error::class, $allowed );
		self::assertSame( 'stonewright_design_studio_invalid_nonce', $allowed->get_error_code() );
	}

	public function test_read_routes_do_not_require_a_nonce(): void {
		$request = new \WP_REST_Request( 'GET', '/design-studio/directions' );

		self::assertTrue( DesignStudioRestApi::check_permission( 'directions.list', $request ) );
	}

	public function test_mutating_routes_pass_with_a_valid_nonce(): void {
		$request = new \WP_REST_Request( 'POST', '/design-studio/directions' );
		$request->set_header( 'x-wp-nonce', 'valid' );

		self::assertTrue( DesignStudioRestApi::check_permission( 'directions.save', $request ) );
	}

	public function test_an_unknown_route_id_is_refused(): void {
		$request = new \WP_REST_Request( 'GET', '/design-studio' );

		$allowed = DesignStudioRestApi::check_permission( 'directions.nope', $request );

		self::assertInstanceOf( \WP_Error::class, $allowed );
		self::assertSame( 404, $allowed->get_error_data()['status'] ?? 0 );
	}

	// -------------------------------------------------------------------------
	// Identifiers.
	// -------------------------------------------------------------------------

	/**
	 * @dataProvider provide_non_positive_ids
	 */
	public function test_direction_ids_must_be_positive_integers( mixed $id ): void {
		$request = new \WP_REST_Request( 'GET', '/design-studio/directions/x', [ 'id' => $id ] );

		$response = DesignStudioRestApi::handle( 'directions.get', $request );

		self::assertInstanceOf( \WP_Error::class, $response );
		self::assertSame( 'stonewright_design_studio_invalid_id', $response->get_error_code() );
		self::assertSame( 400, $response->get_error_data()['status'] ?? 0 );
	}

	/**
	 * @return array<string, array{0: mixed}>
	 */
	public static function provide_non_positive_ids(): array {
		return [
			'zero'     => [ 0 ],
			'negative' => [ -3 ],
			'blank'    => [ '' ],
			'words'    => [ 'seven' ],
			'missing'  => [ null ],
		];
	}

	public function test_a_positive_id_reaches_the_ability(): void {
		$this->seed_direction( 4, 'quarry' );

		$request  = new \WP_REST_Request( 'GET', '/design-studio/directions/4', [ 'id' => 4 ] );
		$response = DesignStudioRestApi::handle( 'directions.get', $request );

		self::assertInstanceOf( \WP_REST_Response::class, $response );
		self::assertSame( 4, $response->get_data()['direction']['id'] ?? 0 );
	}

	// -------------------------------------------------------------------------
	// Delegation.
	// -------------------------------------------------------------------------

	public function test_the_list_route_returns_exactly_what_the_ability_returns(): void {
		$this->seed_direction( 1, 'quarry' );
		$this->seed_direction( 2, 'basalt' );

		$response = DesignStudioRestApi::handle( 'directions.list', new \WP_REST_Request( 'GET', '/design-studio/directions' ) );
		$expected = ( new DirectionList( $this->service ) )->execute( [] );

		self::assertInstanceOf( \WP_REST_Response::class, $response );
		self::assertSame( $expected, $response->get_data() );
	}

	public function test_query_parameters_outside_the_ability_schema_are_dropped(): void {
		$this->seed_direction( 1, 'quarry' );

		$request = new \WP_REST_Request(
			'GET',
			'/design-studio/directions',
			[
				'status'     => 'draft',
				'not_a_prop' => 'ignored',
			]
		);

		$response = DesignStudioRestApi::handle( 'directions.list', $request );

		self::assertInstanceOf( \WP_REST_Response::class, $response );
		self::assertSame( 1, $response->get_data()['count'] ?? -1 );
	}

	public function test_an_invalid_payload_returns_the_ability_error_with_a_4xx_status(): void {
		$request = new \WP_REST_Request( 'GET', '/design-studio/directions', [ 'status' => 'nonsense' ] );

		$response = DesignStudioRestApi::handle( 'directions.list', $request );

		self::assertInstanceOf( \WP_Error::class, $response );
		self::assertSame( 'stonewright_direction_invalid', $response->get_error_code() );
		self::assertSame( 400, $response->get_error_data()['status'] ?? 0 );
	}

	public function test_a_save_without_a_contract_is_refused_before_storage(): void {
		$request = new \WP_REST_Request( 'POST', '/design-studio/directions' );

		$response = DesignStudioRestApi::handle( 'directions.save', $request );

		self::assertInstanceOf( \WP_Error::class, $response );
		self::assertSame( 'stonewright_direction_invalid', $response->get_error_code() );
		self::assertSame( [], $this->repository->records );
	}

	public function test_the_handler_re_checks_the_ability_permission(): void {
		$GLOBALS['stonewright_test_user_caps'] = [
			'read'           => true,
			'manage_options' => true,
		];

		$request  = new \WP_REST_Request( 'POST', '/design-studio/directions/1/activate', [ 'id' => 1 ] );
		$response = DesignStudioRestApi::handle( 'directions.activate', $request );

		self::assertInstanceOf( \WP_Error::class, $response );
		self::assertSame( 403, $response->get_error_data()['status'] ?? 0 );
	}

	// -------------------------------------------------------------------------
	// Quality reports.
	// -------------------------------------------------------------------------

	public function test_quality_requires_a_positive_post_id(): void {
		$response = DesignStudioRestApi::handle( 'quality.list', new \WP_REST_Request( 'GET', '/design-studio/quality' ) );

		self::assertInstanceOf( \WP_Error::class, $response );
		self::assertSame( 'stonewright_design_studio_invalid_id', $response->get_error_code() );
	}

	public function test_quality_returns_the_stored_reports_newest_first(): void {
		$GLOBALS['stonewright_test_posts'][12] = (object) [
			'ID'   => 12,
			'meta' => [
				'_stonewright_quality_reports' => [
					[
						'report_id' => 'r2',
						'status'    => 'warn',
					],
					[
						'report_id' => 'r1',
						'status'    => 'pass',
					],
				],
			],
		];

		$request  = new \WP_REST_Request( 'GET', '/design-studio/quality', [ 'post_id' => 12 ] );
		$response = DesignStudioRestApi::handle( 'quality.list', $request );

		self::assertInstanceOf( \WP_REST_Response::class, $response );

		$data = $response->get_data();
		self::assertSame( 12, $data['post_id'] );
		self::assertSame( 2, $data['count'] );
		self::assertSame( 'r2', $data['reports'][0]['report_id'] );
	}

	// -------------------------------------------------------------------------
	// No business logic in the controller.
	// -------------------------------------------------------------------------

	public function test_every_route_delegates_to_a_typed_design_ability_or_a_store(): void {
		foreach ( DesignStudioRestApi::routes() as $route_id => $route ) {
			if ( null === $route['ability'] ) {
				continue;
			}

			self::assertTrue( class_exists( $route['ability'] ), $route_id );
			self::assertTrue( is_subclass_of( $route['ability'], AbilityKernel::class ), $route_id );
			self::assertStringStartsWith( 'Stonewright\\WpMcp\\Abilities\\Design\\', $route['ability'], $route_id );
		}
	}

	public function test_the_controller_holds_no_storage_or_option_writes(): void {
		$source = (string) file_get_contents( dirname( __DIR__, 3 ) . '/includes/Admin/DesignStudioRestApi.php' );

		foreach ( [ '$wpdb', 'update_option(', 'update_post_meta(', 'wp_update_post(', 'wp_insert_post(', 'delete_option(' ] as $forbidden ) {
			self::assertStringNotContainsString( $forbidden, $source, $forbidden );
		}
	}

	// -------------------------------------------------------------------------
	// Helpers.
	// -------------------------------------------------------------------------

	private function seed_direction( int $id, string $slug ): void {
		$this->repository->records[ $id ] = [
			'id'            => $id,
			'slug'          => $slug,
			'status'        => 'draft',
			'revision'      => 1,
			'contract'      => [
				'identity'  => [ 'name' => ucfirst( $slug ) ],
				'readiness' => [
					'ready'      => false,
					'sync_ready' => false,
					'issues'     => [],
				],
			],
			'contract_hash' => str_repeat( (string) $id, 8 ),
			'source_type'   => 'manual',
			'source_refs'   => [],
			'created_at'    => '2026-07-25 09:00:00',
			'updated_at'    => '2026-07-25 09:00:00',
		];
	}
}

/**
 * In-memory direction store so controller tests never touch SQL.
 */
final class DesignStudioFakeDirectionRepository extends DesignDirectionRepository {

	/** @var array<int, array<string, mixed>> */
	public array $records = [];

	/** @var list<array<string, mixed>> */
	public array $version_rows = [];

	private int $next_id = 1;

	public function __construct() {
	}

	/**
	 * @param array<string, mixed> $filters Optional status filter.
	 * @return list<array<string, mixed>>
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
	 * @return array<string, mixed>|null
	 */
	public function get( int $id ): ?array {
		return $this->records[ $id ] ?? null;
	}

	/**
	 * @return array<string, mixed>|null
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
	 * @param array<string, mixed> $record Direction row.
	 * @return int|\WP_Error
	 */
	public function save( array $record ) {
		$id = isset( $record['id'] ) ? (int) $record['id'] : $this->next_id++;

		$record['id']         = $id;
		$record['created_at'] = (string) ( $record['created_at'] ?? '2026-07-25 09:00:00' );
		$record['updated_at'] = '2026-07-25 09:00:00';
		$this->records[ $id ] = $record;

		return $id;
	}

	/**
	 * @param array<string, mixed> $snapshot Version row.
	 * @return int|\WP_Error
	 */
	public function add_version( array $snapshot ) {
		$snapshot['id']       = count( $this->version_rows ) + 1;
		$this->version_rows[] = $snapshot;

		return (int) $snapshot['id'];
	}

	/**
	 * @return list<array<string, mixed>>
	 */
	public function versions( int $id ): array {
		return array_values(
			array_filter(
				$this->version_rows,
				static fn( array $row ): bool => (int) $row['direction_id'] === $id
			)
		);
	}

	/**
	 * @return array<string, mixed>|null
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
