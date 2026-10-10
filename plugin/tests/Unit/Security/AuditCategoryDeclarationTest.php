<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\Tests\Unit\Security;

use PHPUnit\Framework\TestCase;
use Stonewright\WpMcp\Abilities\AbilityKernel;
use Stonewright\WpMcp\Abilities\Search\SearchQuery;
use Stonewright\WpMcp\Core\RestRoutes;
use Stonewright\WpMcp\Security\AuditEvent;
use Stonewright\WpMcp\Security\AuditLog;
use Stonewright\WpMcp\Security\IncidentStore;

/**
 * Audit categories follow what an ability declares it does and what the
 * recorder reported, never words that happen to appear in an ability name.
 *
 * @covers \Stonewright\WpMcp\Security\AuditEvent
 * @covers \Stonewright\WpMcp\Abilities\AbilityKernel
 */
final class AuditCategoryDeclarationTest extends TestCase {

	protected function setUp(): void {
		$GLOBALS['stonewright_test_user_caps']       = [ 'read' => true, 'edit_posts' => true ];
		$GLOBALS['stonewright_test_current_user_id'] = 5;
		$GLOBALS['stonewright_test_wpdb_inserts']    = [];
		$GLOBALS['stonewright_test_options']         = [ 'stonewright_mode' => 'development' ];
		$GLOBALS['stonewright_test_search_posts']    = [];
		AuditLog::reset_request_state();
		IncidentStore::reset_for_tests();
	}

	protected function tearDown(): void {
		$GLOBALS['stonewright_test_user_caps']    = [];
		$GLOBALS['stonewright_test_wpdb_inserts'] = [];
		$GLOBALS['stonewright_test_options']      = [];
		unset( $GLOBALS['stonewright_test_search_posts'] );
		AuditLog::reset_request_state();
		IncidentStore::reset_for_tests();
	}

	public function test_read_only_search_is_recorded_as_a_read(): void {
		$result = ( new SearchQuery() )->execute( [ 'search' => 'synthetic' ] );

		self::assertIsArray( $result );
		$rows = $this->rows_for( 'stonewright/search-query' );
		self::assertCount( 1, $rows );
		self::assertSame( AuditEvent::CATEGORY_READ, $rows[0]['category'] );
		self::assertSame( 'READ', $rows[0]['operation_class'] );
		self::assertSame( AuditEvent::OUTCOME_SUCCESS, $rows[0]['outcome'] );
	}

	public function test_kernel_declaration_decides_read_or_write_for_any_ability_name(): void {
		$read = $this->kernel( 'stonewright/blocks-example-inspect-tree', 'read' );
		$read->execute( [] );
		$write = $this->kernel( 'stonewright/blocks-example-arrange', 'write' );
		$write->execute( [] );

		self::assertSame( AuditEvent::CATEGORY_READ, $this->rows_for( 'stonewright/blocks-example-inspect-tree' )[0]['category'] );
		self::assertSame( AuditEvent::CATEGORY_WRITE, $this->rows_for( 'stonewright/blocks-example-arrange' )[0]['category'] );
	}

	public function test_block_abilities_are_not_misread_as_lock_errors(): void {
		$success = AuditEvent::normalize( 'stonewright/blocks-insert', [ '_meta' => [ 'operation_kind' => 'write' ] ], 'ok' );
		$failure = AuditEvent::normalize(
			'stonewright/blocks-batch-mutate',
			[ '_meta' => [ 'operation_kind' => 'write', 'error_code' => 'stonewright_block_markup_mismatch' ] ],
			'error'
		);
		$undeclared = AuditEvent::normalize(
			'stonewright/blocks-finalize-batch',
			[ '_meta' => [ 'error_code' => 'stonewright_finalizer_rejected' ] ],
			'error'
		);

		self::assertSame( AuditEvent::CATEGORY_WRITE, $success['category'] );
		self::assertSame( AuditEvent::CATEGORY_WRITE, $failure['category'] );
		self::assertSame( AuditEvent::OUTCOME_FAILED, $failure['outcome'] );
		self::assertFalse( $failure['retryable'] );
		self::assertNotSame( AuditEvent::CATEGORY_TRANSIENT, $undeclared['category'] );
		self::assertSame( AuditEvent::OUTCOME_FAILED, $undeclared['outcome'] );
	}

	public function test_real_lock_and_busy_codes_stay_transient(): void {
		foreach ( [ 'stonewright_elementor_lock_lost', 'stonewright_post_locked', 'stonewright_css_lease_busy', 'stonewright_tree_conflict' ] as $code ) {
			$event = AuditEvent::normalize(
				'stonewright/blocks-batch-mutate',
				[ '_meta' => [ 'operation_kind' => 'write', 'root_error_code' => $code ] ],
				'error'
			);
			self::assertSame( AuditEvent::CATEGORY_TRANSIENT, $event['category'], $code );
			self::assertSame( AuditEvent::OUTCOME_RETRYABLE, $event['outcome'], $code );
		}
	}

	public function test_ability_names_never_pick_an_error_class(): void {
		$cases = [
			'stonewright/example-capability-report' => AuditEvent::CATEGORY_PERMISSION,
			'stonewright/example-confirmation-list' => AuditEvent::CATEGORY_SAFETY,
			'stonewright/post-revision-restore'     => AuditEvent::CATEGORY_ROLLBACK,
			'stonewright/example-schema-catalog'    => AuditEvent::CATEGORY_VALIDATION,
			'stonewright/example-mail-settings'     => AuditEvent::CATEGORY_EXTERNAL,
		];
		foreach ( $cases as $ability => $wrong_category ) {
			$event = AuditEvent::normalize( $ability, [], 'ok' );
			self::assertNotSame( $wrong_category, $event['category'], $ability );
			self::assertSame( AuditEvent::OUTCOME_SUCCESS, $event['outcome'], $ability );
		}
	}

	public function test_one_rest_read_call_writes_exactly_one_row(): void {
		AuditLog::begin_request();
		$result = ( new SearchQuery() )->execute( [ 'search' => 'synthetic' ] );
		RestRoutes::audit_post_dispatch(
			rest_ensure_response( [ 'name' => 'stonewright/search-query', 'result' => $result ] ),
			null,
			new \WP_REST_Request( 'POST', '/stonewright/v1/abilities/run', [ 'name' => 'stonewright/search-query' ] )
		);

		$audit_rows = array_values(
			array_filter(
				$GLOBALS['stonewright_test_wpdb_inserts'],
				static fn ( array $insert ): bool => str_contains( (string) $insert['table'], 'stonewright_audit_log' )
			)
		);
		self::assertCount( 1, $audit_rows );
		self::assertSame( 'stonewright/search-query', $audit_rows[0]['data']['ability_name'] );
	}

	private function kernel( string $name, string $kind ): AbilityKernel {
		return new class( $name, $kind ) extends AbilityKernel {
			public function __construct( private string $ability_name, private string $kind ) {
			}

			public function name(): string {
				return $this->ability_name;
			}

			public function label(): string {
				return 'Synthetic';
			}

			public function description(): string {
				return 'Synthetic declaration fixture.';
			}

			public function category(): string {
				return 'test';
			}

			public function execute( array $args ): array|\WP_Error {
				$callback = static fn (): array => [ 'ok' => true ];
				return 'read' === $this->kind ? $this->audit_read( $args, $callback ) : $this->audit_write( $args, $callback );
			}
		};
	}

	/** @return list<array<string, mixed>> */
	private function rows_for( string $ability ): array {
		$rows = [];
		foreach ( $GLOBALS['stonewright_test_wpdb_inserts'] as $insert ) {
			if ( str_contains( (string) $insert['table'], 'stonewright_audit_log' ) && $ability === ( $insert['data']['ability_name'] ?? '' ) ) {
				$rows[] = $insert['data'];
			}
		}
		return $rows;
	}
}
