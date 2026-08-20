<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\Tests\Unit\Admin;

use PHPUnit\Framework\TestCase;
use Stonewright\WpMcp\Admin\AuditLogPage;
use Stonewright\WpMcp\Security\ErrorPatterns;

/**
 * @covers \Stonewright\WpMcp\Admin\AuditLogPage
 * @covers \Stonewright\WpMcp\Security\AuditLog
 */
final class AuditLogPageTest extends TestCase {

	private mixed $original_wpdb;

	protected function setUp(): void {
		$this->original_wpdb = $GLOBALS['wpdb'] ?? null;
		$GLOBALS['stonewright_test_user_caps'] = [ 'manage_options' => true ];
		$GLOBALS['stonewright_test_options']   = [];
		$GLOBALS['stonewright_test_missing_user_ids'] = [ 99 ];
		$_GET = [];
	}

	protected function tearDown(): void {
		if ( null !== $this->original_wpdb ) {
			$GLOBALS['wpdb'] = $this->original_wpdb;
		} else {
			unset( $GLOBALS['wpdb'] );
		}
		$GLOBALS['stonewright_test_user_caps'] = [];
		$GLOBALS['stonewright_test_options']   = [];
		$GLOBALS['stonewright_test_missing_user_ids'] = [];
		$_GET = [];
	}

	public function test_render_outputs_filters_expandable_rows_and_semantic_badges(): void {
		$GLOBALS['wpdb'] = new class() {
			public string $prefix = 'wp_';

			public function prepare( string $query, mixed ...$args ): string {
				return $query;
			}

			public function get_var( string $query = '' ): string|int|null {
				// Count queries and table existence probes.
				return 2;
			}

			/** @return array<int, array<string, mixed>> */
			public function get_results( string $query, string $output = 'OBJECT' ): array {
				if ( str_contains( $query, 'stonewright_oauth_clients' ) ) {
					return [ [ 'client_id' => 'client-abc', 'client_name' => 'Desktop client' ] ];
				}
				return [
					[
						'id'             => '12',
						'ability_name'   => 'stonewright/content-update',
						'user_id'        => '1',
						'result_status'  => 'ok',
						'sanitized_args' => '{"post_id":42}',
						'created_at'     => '2026-07-15 10:00:00',
					],
					[
						'id'             => '11',
						'ability_name'   => 'stonewright/content-delete',
						'user_id'        => '1',
						'result_status'  => 'error',
						'sanitized_args' => '{"post_id":7}',
						'created_at'     => '2026-07-14 09:00:00',
					],
					[
						'id'               => '10',
						'ability_name'     => 'oauth/token',
						'user_id'          => '0',
						'result_status'    => 'auth',
						'sanitized_args'   => '{"client_id":"client-abc","http_status":400}',
						'redacted_details' => '{"http_status":400}',
						'created_at'       => '2026-07-13 08:00:00',
					],
					[
						'id'             => '9',
						'ability_name'   => 'stonewright/test',
						'user_id'        => '99',
						'result_status'  => 'ok',
						'sanitized_args' => '{}',
						'created_at'     => '2026-07-12 08:00:00',
					],
				];
			}
		};

		ob_start();
		AuditLogPage::render();
		$html = (string) ob_get_clean();

		self::assertStringContainsString( 'sw-audit-page', $html );
		self::assertStringContainsString( 'Stonewright mutation', $html );
		self::assertStringContainsString( 'sw-audit-filters', $html );
		self::assertStringContainsString( 'value="blocked"', $html );
		self::assertStringContainsString( 'name="ability"', $html );
		self::assertStringContainsString( 'name="status"', $html );
		self::assertStringContainsString( 'name="verification_status"', $html );
		self::assertStringContainsString( 'name="rollback_status"', $html );
		self::assertStringContainsString( 'name="operation_class"', $html );
		self::assertStringContainsString( 'Incident lifecycle', $html );
		self::assertStringContainsString( 'name="user"', $html );
		self::assertStringContainsString( 'name="from"', $html );
		self::assertStringContainsString( 'name="to"', $html );
		self::assertStringContainsString( 'sw-badge', $html );
		self::assertStringContainsString( 'sw-badge--ok', $html );
		self::assertStringContainsString( 'sw-badge--error', $html );
		self::assertStringContainsString( 'sw-audit-row', $html );
		self::assertStringContainsString( 'sw-audit-table-scroll', $html );
		self::assertStringContainsString( 'data-label="Details"', $html );
		self::assertStringContainsString( 'View redacted details', $html );
		self::assertStringContainsString( 'data-stonewright-copy="sw-audit-details-12"', $html );
		self::assertStringContainsString( '<details', $html );
		self::assertStringContainsString( 'post_id', $html );
		self::assertStringContainsString( 'OAuth: Desktop client', $html );
		self::assertStringContainsString( 'Deleted user', $html );
		self::assertStringNotContainsString( '(unknown)', $html );
		self::assertStringContainsString( 'method="get"', $html );
	}

	public function test_redacted_exports_allowlist_fields_and_fail_closed_on_secret_like_details(): void {
		$row = [
			'id'                  => 9,
			'event_id'            => '00000000-0000-4000-8000-000000000009',
			'created_at'          => '2026-07-15 10:00:00',
			'ability_name'        => 'stonewright/content-update',
			'result_status'       => 'error',
			'category'            => 'WRITE',
			'outcome'             => 'FAILED',
			'root_error_code'     => 'stonewright_write_failed',
			'redacted_details'    => '{"authorization":"Bearer sentinel-private-example-token"}',
			'sanitized_args'      => '{"must_not_export":"private body"}',
		];
		$json = AuditLogPage::build_export( [ $row ], 'json' );
		self::assertIsString( $json );
		self::assertStringContainsString( '[redacted]', $json );
		self::assertStringNotContainsString( 'sentinel-private-example-token', $json );
		self::assertStringNotContainsString( 'must_not_export', $json );

		$csv = AuditLogPage::build_export( [ $row ], 'csv' );
		self::assertIsString( $csv );
		self::assertStringContainsString( 'ability_name', $csv );
		self::assertStringNotContainsString( 'sentinel-private-example-token', $csv );

		$row['ability_name'] = '=HYPERLINK("https://client.example","open")';
		$csv_formula = AuditLogPage::build_export( [ $row ], 'csv' );
		self::assertIsString( $csv_formula );
		self::assertStringContainsString( "'=HYPERLINK", $csv_formula );

		$row['redacted_details'] = '{"note":"Bearer still-secret-value"}';
		$blocked = AuditLogPage::build_export( [ $row ], 'json' );
		self::assertInstanceOf( \WP_Error::class, $blocked );
		self::assertSame( 'stonewright_audit_export_sensitive_content_blocked', $blocked->get_error_code() );
	}

	public function test_render_empty_state(): void {
		$GLOBALS['wpdb'] = new class() {
			public string $prefix = 'wp_';

			public function prepare( string $query, mixed ...$args ): string {
				return $query;
			}

			/** @return array<int, array<string, mixed>> */
			public function get_results( string $query, string $output = 'OBJECT' ): array {
				return [];
			}

			public function get_var( string $query = '' ): string|int|null {
				return 0;
			}
		};

		ob_start();
		AuditLogPage::render();
		$html = (string) ob_get_clean();

		self::assertStringContainsString( 'sw-empty-state', $html );
		self::assertStringContainsString( 'No audit entries', $html );
	}

	public function test_error_row_expand_shows_code_message_target_mode_and_repair(): void {
		$GLOBALS['wpdb'] = new class() {
			public string $prefix = 'wp_';

			public function prepare( string $query, mixed ...$args ): string {
				return $query;
			}

			public function get_var( string $query = '' ): string|int|null {
				return 1;
			}

			/** @return array<int, array<string, mixed>> */
			public function get_results( string $query, string $output = 'OBJECT' ): array {
				if ( str_contains( $query, 'stonewright_oauth_clients' ) ) {
					return [];
				}
				return [
					[
						'id'               => '20',
						'ability_name'     => 'stonewright/design-validate-spec',
						'user_id'          => '1',
						'result_status'    => 'error',
						'error_code'       => 'stonewright_spec_invalid',
						'root_error_code'  => 'stonewright_spec_invalid',
						'resource_ref'     => '88',
						'mode'             => 'development',
						'remediation_code' => 'stonewright_spec_invalid',
						'redacted_details' => wp_json_encode(
							[
								'error_code'          => 'stonewright_spec_invalid',
								'error_message'       => 'Spec failed at tokens.color',
								'target_id'           => '88',
								'verification_status' => 'failed',
								'remediation_code'    => 'stonewright_spec_invalid',
							]
						),
						'sanitized_args'   => '{"password":"[redacted]"}',
						'created_at'       => '2026-07-16 10:00:00',
					],
				];
			}
		};

		ob_start();
		AuditLogPage::render();
		$html = (string) ob_get_clean();

		self::assertStringContainsString( 'error_code', $html );
		self::assertStringContainsString( 'stonewright_spec_invalid', $html );
		self::assertStringContainsString( 'error_message', $html );
		self::assertStringContainsString( 'Spec failed at tokens.color', $html );
		self::assertStringContainsString( '&quot;target&quot;', $html );
		self::assertStringContainsString( '88', $html );
		self::assertStringContainsString( '&quot;mode&quot;', $html );
		self::assertStringContainsString( 'development', $html );
		self::assertStringContainsString( '&quot;remediation&quot;', $html );
		self::assertStringContainsString( 'Validate the design spec', $html );
		self::assertStringNotContainsString( 'sentinel-private', $html );
	}

	public function test_view_occurrences_url_includes_error_code_and_list_filters_by_it(): void {
		$pattern = $this->seed_recurring_pattern();
		$wpdb    = $this->make_audit_wpdb( [], 0 );
		$GLOBALS['wpdb'] = $wpdb;

		ob_start();
		AuditLogPage::render();
		$html = (string) ob_get_clean();

		self::assertStringContainsString( 'View occurrences', $html );
		self::assertStringContainsString( 'error_code=' . rawurlencode( $pattern['error_code'] ), $html );
		self::assertStringContainsString( 'signature=' . rawurlencode( $pattern['signature'] ), $html );

		$_GET = [
			'status'     => 'error',
			'ability'    => $pattern['ability'],
			'error_code' => $pattern['error_code'],
			'signature'  => $pattern['signature'],
		];
		$wpdb->queries = [];
		ob_start();
		AuditLogPage::render();
		$filtered = (string) ob_get_clean();

		$audit_sql = implode( "\n", $wpdb->queries );
		self::assertStringContainsString( 'error_code = %s', $audit_sql );
		self::assertStringContainsString( 'Events for this pattern were pruned by retention — the pattern summary above is the surviving record', $filtered );
		self::assertStringNotContainsString( 'No audit entries match this view.', $filtered );
	}

	public function test_dismiss_recurring_pattern_requires_js_confirm(): void {
		$this->seed_recurring_pattern();
		$GLOBALS['wpdb'] = $this->make_audit_wpdb( [], 0 );

		ob_start();
		AuditLogPage::render();
		$html = (string) ob_get_clean();

		self::assertMatchesRegularExpression(
			'/<button\b(?=[^>]*\btype="submit")(?=[^>]*\bdata-confirm=")/i',
			$html
		);
		self::assertMatchesRegularExpression(
			'/<button[^>]*data-confirm="[^"]+"[^>]*>\s*Dismiss/i',
			$html
		);
	}

	/**
	 * @return array{ability:string,error_code:string,signature:string}
	 */
	private function seed_recurring_pattern(): array {
		$ability = 'stonewright/design-validate-spec';
		$args    = [
			'_meta' => [
				'error_code'    => 'stonewright_spec_invalid',
				'error_message' => 'Spec failed at tokens.color',
			],
		];
		ErrorPatterns::observe( $ability, 'error', $args );
		ErrorPatterns::observe( $ability, 'error', $args );

		return [
			'ability'    => $ability,
			'error_code' => 'stonewright_spec_invalid',
			'signature'  => ErrorPatterns::signature( $ability, $args, 'error' ),
		];
	}

	/**
	 * @param array<int, array<string, mixed>> $rows
	 */
	private function make_audit_wpdb( array $rows, int $count ): object {
		return new class( $rows, $count ) {
			public string $prefix = 'wp_';
			/** @var list<string> */
			public array $queries = [];
			/** @var array<int, array<string, mixed>> */
			private array $rows;
			private int $count;

			public function __construct( array $rows, int $count ) {
				$this->rows  = $rows;
				$this->count = $count;
			}

			public function prepare( string $query, mixed ...$args ): string {
				$this->queries[] = $query;
				return $query;
			}

			public function get_var( string $query = '' ): string|int|null {
				$this->queries[] = $query;
				return $this->count;
			}

			/** @return array<int, array<string, mixed>> */
			public function get_results( string $query, string $output = 'OBJECT' ): array {
				$this->queries[] = $query;
				if ( str_contains( $query, 'stonewright_oauth_clients' ) ) {
					return [];
				}
				return $this->rows;
			}
		};
	}
}
