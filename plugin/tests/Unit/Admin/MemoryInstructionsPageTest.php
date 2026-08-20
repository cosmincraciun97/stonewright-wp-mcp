<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\Tests\Unit\Admin;

use PHPUnit\Framework\TestCase;
use Stonewright\WpMcp\Admin\MemoryInstructionsPage;
use Stonewright\WpMcp\Security\IncidentStore;

/**
 * @covers \Stonewright\WpMcp\Admin\MemoryInstructionsPage
 */
final class MemoryInstructionsPageTest extends TestCase {

	private mixed $original_wpdb;

	protected function setUp(): void {
		$this->original_wpdb = $GLOBALS['wpdb'] ?? null;
		$GLOBALS['stonewright_test_user_caps']['manage_options'] = true;
		$GLOBALS['stonewright_test_options'] = [];
		$_GET = [];
		IncidentStore::reset_for_tests();
	}

	protected function tearDown(): void {
		if ( null !== $this->original_wpdb ) {
			$GLOBALS['wpdb'] = $this->original_wpdb;
		} else {
			unset( $GLOBALS['wpdb'] );
		}
		$GLOBALS['stonewright_test_user_caps'] = [];
		IncidentStore::reset_for_tests();
		$GLOBALS['stonewright_test_options'] = [];
		$_GET  = [];
		$_POST = [];
	}

	public function test_incident_lifecycle_tab_does_not_render_ordinary_memory_table(): void {
		$GLOBALS['wpdb'] = $this->make_wpdb_with_rows( [], true );
		$GLOBALS['stonewright_test_options'][ IncidentStore::OPTION_KEY ] = [
			str_repeat( 'a', 64 ) => [
				'incident_id'      => str_repeat( 'a', 64 ),
				'state'            => 'open',
				'ability_name'     => 'stonewright/example-write',
				'category'         => 'WRITE',
				'severity'         => 'error',
				'root_error_code'  => 'stonewright_write_failed',
				'normalized_path'  => 'settings/title',
				'occurrence_count' => 2,
				'first_seen'       => '2026-01-01 00:00:00',
				'last_seen'        => '2026-01-02 00:00:00',
			],
		];
		$_GET['type'] = 'incidents';

		ob_start();
		MemoryInstructionsPage::render();
		$html = (string) ob_get_clean();

		self::assertStringContainsString( 'stonewright-incident-' . str_repeat( 'a', 64 ), $html );
		self::assertStringContainsString( 'View audit events', $html );
		self::assertStringContainsString( 'Open (1)', $html );
		self::assertStringNotContainsString( 'No memory entries.', $html );
	}

	public function test_legacy_unresolved_incidents_url_shows_first_class_unresolved_rows(): void {
		$GLOBALS['wpdb'] = $this->make_wpdb_with_rows( [], true );
		$GLOBALS['stonewright_test_options'][ IncidentStore::OPTION_KEY ] = [
			str_repeat( 'b', 64 ) => [
				'incident_id' => str_repeat( 'b', 64 ), 'state' => 'observing', 'ability_name' => 'stonewright/example-write',
				'category' => 'WRITE', 'severity' => 'error', 'root_error_code' => 'stonewright_write_failed',
				'normalized_path' => 'settings/title', 'occurrence_count' => 1, 'first_seen' => '2026-01-01 00:00:00', 'last_seen' => '2026-01-02 00:00:00',
			],
			str_repeat( 'c', 64 ) => [
				'incident_id' => str_repeat( 'c', 64 ), 'state' => 'resolved', 'ability_name' => 'stonewright/example-write',
				'category' => 'WRITE', 'severity' => 'error', 'root_error_code' => 'stonewright_write_failed',
				'normalized_path' => 'settings/title', 'occurrence_count' => 2, 'first_seen' => '2026-01-01 00:00:00', 'last_seen' => '2026-01-02 00:00:00',
			],
		];
		$_GET['type'] = 'unresolved_incidents';

		ob_start();
		MemoryInstructionsPage::render();
		$html = (string) ob_get_clean();

		self::assertStringContainsString( 'stonewright-incident-' . str_repeat( 'b', 64 ), $html );
		self::assertStringNotContainsString( 'stonewright-incident-' . str_repeat( 'c', 64 ), $html );
		self::assertStringNotContainsString( 'No memory entries.', $html );
	}

	public function test_render_includes_memory_edit_controls_and_bundle_import_export(): void {
		$GLOBALS['wpdb'] = $this->make_wpdb_with_rows(
			[
				[
					'id'          => '9',
					'type'        => 'feedback',
					'scope'       => 'site-a-frontend',
					'memory_key'  => 'no-html-widgets',
					'name'        => 'No Elementor HTML widgets by default',
					'value_json'  => wp_json_encode( 'Use native Elementor widgets first.' ),
					'confidence'  => '1.0000',
					'created_at'  => '2026-05-24 00:00:00',
					'updated_at'  => '2026-05-24 00:00:00',
				],
			],
			true
		);

		ob_start();
		MemoryInstructionsPage::render();
		$html = (string) ob_get_clean();

		self::assertStringContainsString( 'stonewright-memory-edit-9', $html );
		self::assertStringContainsString( 'stonewright_memory_update', $html );
		self::assertStringContainsString( 'Export JSON', $html );
		self::assertStringContainsString( 'Import JSON', $html );
		self::assertStringContainsString( 'Use native Elementor widgets first.', $html );
		self::assertStringContainsString( 'Verified Repairs', $html );
		self::assertStringContainsString( 'Unresolved Incidents', $html );
		self::assertStringContainsString( 'Audit Feedback', $html );
		self::assertStringContainsString( 'plugin-site', $html );
		self::assertStringContainsString( 'Last retrieved:', $html );
		self::assertStringContainsString( 'Direct-local receipts', $html );
		self::assertStringContainsString( 'stonewright_memory_migrate_feedback', $html );
		self::assertStringNotContainsString( 'memory table is missing or outdated', $html );
		self::assertMatchesRegularExpression(
			'/<button\b(?=[^>]*\btype="submit")(?=[^>]*\bdata-confirm="Delete this memory\?")/i',
			$html
		);
	}

	public function test_draft_rows_render_approve_and_discard_actions(): void {
		$GLOBALS['wpdb'] = $this->make_wpdb_with_rows(
			[
				[
					'id'          => '12',
					'type'        => 'reference',
					'scope'       => 'audit',
					'memory_key'  => 'draft-lesson-abc',
					'name'        => 'Draft lesson: spec invalid',
					'value_json'  => wp_json_encode( [ 'proposed_remediation' => 'Validate the spec first.' ] ),
					'confidence'  => '1.0000',
					'status'      => 'draft',
					'created_at'  => '2026-08-21 00:00:00',
					'updated_at'  => '2026-08-21 00:00:00',
				],
			],
			true
		);

		ob_start();
		MemoryInstructionsPage::render();
		$html = (string) ob_get_clean();

		self::assertStringContainsString( 'stonewright_memory_approve_draft', $html );
		self::assertStringContainsString( 'stonewright_memory_discard_draft', $html );
		self::assertStringContainsString( '>Approve<', $html );
		self::assertStringContainsString( '>Discard<', $html );
		self::assertStringContainsString( 'name="id" value="12"', $html );
	}

	public function test_approve_draft_requires_manage_options_and_sets_active(): void {
		$wpdb = $this->make_memory_wpdb(
			[
				[
					'id'          => 12,
					'type'        => 'reference',
					'scope'       => 'audit',
					'memory_key'  => 'draft-lesson-abc',
					'name'        => 'Draft lesson',
					'value_json'  => wp_json_encode( [ 'proposed_remediation' => 'Fix it.' ] ),
					'status'      => 'draft',
					'confidence'  => 1.0,
					'topic'       => 'Draft lesson',
					'created_at'  => '2026-08-21 00:00:00',
					'updated_at'  => '2026-08-21 00:00:00',
				],
			]
		);
		$GLOBALS['wpdb'] = $wpdb;

		$GLOBALS['stonewright_test_user_caps']['manage_options'] = false;
		$_POST = [ 'id' => '12' ];
		$this->expectException( \RuntimeException::class );
		$this->expectExceptionMessageMatches( '/wp_die/' );
		try {
			MemoryInstructionsPage::handle_approve_draft();
		} finally {
			$GLOBALS['stonewright_test_user_caps']['manage_options'] = true;
			$_POST = [];
		}
	}

	public function test_approve_and_discard_transition_draft_status(): void {
		$wpdb = $this->make_memory_wpdb(
			[
				[
					'id'          => 12,
					'type'        => 'reference',
					'scope'       => 'audit',
					'memory_key'  => 'draft-lesson-abc',
					'name'        => 'Draft lesson',
					'value_json'  => wp_json_encode( [ 'proposed_remediation' => 'Fix it.' ] ),
					'status'      => 'draft',
					'confidence'  => 1.0,
					'topic'       => 'Draft lesson',
					'created_at'  => '2026-08-21 00:00:00',
					'updated_at'  => '2026-08-21 00:00:00',
				],
				[
					'id'          => 13,
					'type'        => 'reference',
					'scope'       => 'audit',
					'memory_key'  => 'draft-lesson-def',
					'name'        => 'Other draft',
					'value_json'  => wp_json_encode( [ 'proposed_remediation' => 'Other.' ] ),
					'status'      => 'draft',
					'confidence'  => 1.0,
					'topic'       => 'Other draft',
					'created_at'  => '2026-08-21 00:00:00',
					'updated_at'  => '2026-08-21 00:00:00',
				],
			]
		);
		$GLOBALS['wpdb'] = $wpdb;

		self::assertTrue( MemoryInstructionsPage::apply_draft_review( 12, 'approve' ) );
		self::assertTrue( MemoryInstructionsPage::apply_draft_review( 13, 'discard' ) );

		$approved = \Stonewright\WpMcp\Memory\Memory::get_by_id( 12 );
		$discarded = \Stonewright\WpMcp\Memory\Memory::get_by_id( 13 );
		self::assertIsArray( $approved );
		self::assertIsArray( $discarded );
		self::assertSame( 'active', $approved['status'] );
		self::assertSame( 'rejected', $discarded['status'] );
	}

	public function test_render_surfaces_schema_health_notice_when_table_broken(): void {
		$GLOBALS['wpdb'] = $this->make_wpdb_with_rows( [], false );

		ob_start();
		MemoryInstructionsPage::render();
		$html = (string) ob_get_clean();

		self::assertStringContainsString( 'notice notice-error', $html );
		self::assertStringContainsString( 'memory table is missing or outdated', $html );
	}

	/**
	 * @param array<int, array<string, mixed>> $rows
	 */
	private function make_wpdb_with_rows( array $rows, bool $schema_ok ): object {
		return new class( $rows, $schema_ok ) {
			public string $prefix = 'wp_';
			/** @var array<int, array<string, mixed>> */
			private array $rows;
			private bool $schema_ok;

			/** @param array<int, array<string, mixed>> $rows */
			public function __construct( array $rows, bool $schema_ok ) {
				$this->rows      = $rows;
				$this->schema_ok = $schema_ok;
			}

			public function prepare( string $query, mixed ...$args ): string {
				return $query;
			}

			/** @return array<int, array<string, mixed>> */
			public function get_results( string $query, string $output = 'OBJECT' ): array {
				return $this->rows;
			}

			/** @return array<int, string> */
			public function get_col( string $query, int $x = 0 ): array {
				if ( ! $this->schema_ok ) {
					return [];
				}
				return [
					'id',
					'scope',
					'type',
					'name',
					'memory_key',
					'value_json',
					'confidence',
					'topic',
					'version_fingerprint',
					'expires_at',
					'status',
					'precedence',
					'created_by',
					'created_at',
					'updated_at',
					'last_retrieved_at',
				];
			}
		};
	}

	/**
	 * @param array<int, array<string, mixed>> $rows
	 */
	private function make_memory_wpdb( array $rows ): object {
		return new class( $rows ) {
			public string $prefix     = 'wp_';
			public int $insert_id     = 0;
			public string $last_error = '';
			/** @var array<int, array<string, mixed>> */
			public array $rows = [];
			/** @var array<int, mixed> */
			public array $last_prepare_args = [];

			/** @param array<int, array<string, mixed>> $rows */
			public function __construct( array $rows ) {
				$this->rows = $rows;
			}

			public function prepare( string $query, mixed ...$args ): string {
				$this->last_prepare_args = $args;
				return $query;
			}

			public function get_row( string $query, string $output = 'OBJECT' ): ?array {
				$id = (int) ( $this->last_prepare_args[0] ?? 0 );
				foreach ( $this->rows as $row ) {
					if ( (int) $row['id'] === $id ) {
						return $row;
					}
				}
				return null;
			}

			public function get_var( string $query ): mixed {
				return null;
			}

			/** @param array<string, mixed> $data @param array<string, mixed> $where */
			public function update( string $table, array $data, array $where, array $format = [], array $where_format = [] ): int {
				$id = (int) ( $where['id'] ?? 0 );
				foreach ( $this->rows as $i => $row ) {
					if ( (int) $row['id'] === $id ) {
						$this->rows[ $i ] = array_merge( $row, $data, [ 'updated_at' => gmdate( 'Y-m-d H:i:s' ) ] );
						return 1;
					}
				}
				return 0;
			}

			/** @return array<int, string> */
			public function get_col( string $query, int $x = 0 ): array {
				return [
					'id', 'scope', 'type', 'name', 'memory_key', 'value_json', 'confidence',
					'topic', 'version_fingerprint', 'expires_at', 'status', 'precedence',
					'created_by', 'created_at', 'updated_at', 'last_retrieved_at',
				];
			}
		};
	}
}
