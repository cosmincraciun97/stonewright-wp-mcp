<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\Tests\Unit\Security;

use PHPUnit\Framework\TestCase;
use Stonewright\WpMcp\Admin\MemoryInstructionsPage;
use Stonewright\WpMcp\Security\ErrorPatterns;

/**
 * Proposed lessons influence agents only after an administrator approves them:
 * approval records who and when, and a one-time repair returns lessons that
 * became active without approval to draft.
 *
 * @covers \Stonewright\WpMcp\Admin\MemoryInstructionsPage
 * @covers \Stonewright\WpMcp\Security\ErrorPatterns
 */
final class DraftLessonApprovalTest extends TestCase {

	private mixed $saved_wpdb = null;

	protected function setUp(): void {
		$this->saved_wpdb                            = $GLOBALS['wpdb'] ?? null;
		$GLOBALS['stonewright_test_options']         = [];
		$GLOBALS['stonewright_test_current_user_id'] = 7;
	}

	protected function tearDown(): void {
		$GLOBALS['wpdb']                             = $this->saved_wpdb;
		$GLOBALS['stonewright_test_options']         = [];
		$GLOBALS['stonewright_test_current_user_id'] = 0;
	}

	public function test_approval_records_who_approved_and_when(): void {
		$store           = $this->store( [ $this->row( 11, 'draft-lesson-a', 'draft', [ 'source' => 'error-pattern-draft' ] ) ] );
		$GLOBALS['wpdb'] = $store;

		self::assertTrue( MemoryInstructionsPage::apply_draft_review( 11, 'approve' ) );

		self::assertCount( 1, $store->updates );
		$data = $store->updates[0];
		self::assertSame( 'active', $data['status'] );
		$value = json_decode( (string) $data['value_json'], true );
		self::assertSame( 7, $value['approval']['approved_by'] ?? null );
		self::assertMatchesRegularExpression( '/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', (string) ( $value['approval']['approved_at'] ?? '' ) );
		self::assertSame( 'error-pattern-draft', $value['source'] );
	}

	public function test_lessons_active_without_approval_return_to_draft_once(): void {
		$store           = $this->store(
			[
				$this->row( 21, 'draft-lesson-unapproved', 'active', [ 'source' => 'error-pattern-draft' ] ),
				$this->row( 22, 'draft-lesson-approved', 'active', [ 'source' => 'error-pattern-draft', 'approval' => [ 'approved_by' => 1, 'approved_at' => '2026-10-01 10:00:00' ] ] ),
				$this->row( 23, 'site-reference', 'active', [ 'source' => 'user' ] ),
				$this->row( 24, 'draft-lesson-still-draft', 'draft', [ 'source' => 'error-pattern-draft' ] ),
			]
		);
		$GLOBALS['wpdb'] = $store;

		$result = ErrorPatterns::return_unapproved_draft_lessons();

		self::assertSame( 1, $result['returned'] );
		self::assertSame( [ 21 ], array_keys( $store->updates_by_id ) );
		self::assertSame( 'draft', $store->updates_by_id[21]['status'] );

		$again = ErrorPatterns::return_unapproved_draft_lessons();
		self::assertTrue( $again['already_done'] );
	}

	/** @param array<string, mixed> $value @return array<string, mixed> */
	private function row( int $id, string $key, string $status, array $value ): array {
		return [
			'id'                  => $id,
			'type'                => 'reference',
			'scope'               => 'site-reference' === $key ? 'site' : 'audit',
			'memory_key'          => $key,
			'name'                => 'Synthetic ' . $key,
			'value_json'          => (string) json_encode( $value ),
			'confidence'          => '1.0000',
			'topic'               => '',
			'version_fingerprint' => '',
			'expires_at'          => null,
			'status'              => $status,
			'precedence'          => 0,
			'created_at'          => '2026-10-01 09:00:00',
			'updated_at'          => '2026-10-01 09:00:00',
			'last_retrieved_at'   => null,
		];
	}

	/** @param list<array<string, mixed>> $rows */
	private function store( array $rows ): object {
		return new class( $rows ) {
			public string $prefix = 'wp_';
			public string $last_error = '';
			/** @var list<array<string, mixed>> */
			public array $updates = [];
			/** @var array<int, array<string, mixed>> */
			public array $updates_by_id = [];
			private bool $listed = false;

			/** @param list<array<string, mixed>> $rows */
			public function __construct( private array $rows ) {}

			public function prepare( string $query, mixed ...$args ): string {
				return $query . ' -- ' . implode( ',', array_map( 'strval', $args ) );
			}

			/** @return list<array<string, mixed>> */
			public function get_results( string $query, mixed $output = null ): array {
				if ( $this->listed ) {
					return [];
				}
				$this->listed = true;
				return $this->rows;
			}

			/** @return array<string, mixed>|null */
			public function get_row( string $query, mixed $output = null ): ?array {
				$id = (int) substr( $query, (int) strrpos( $query, ' -- ' ) + 4 );
				foreach ( $this->rows as $row ) {
					if ( (int) $row['id'] === $id ) {
						return $row;
					}
				}
				return null;
			}

			/** @param array<string, mixed> $data @param array<string, mixed> $where */
			public function update( string $table, array $data, array $where, mixed $format = null, mixed $where_format = null ): int {
				$this->updates[]                             = $data;
				$this->updates_by_id[ (int) $where['id'] ] = $data;
				return 1;
			}
		};
	}
}
