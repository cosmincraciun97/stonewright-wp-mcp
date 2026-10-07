<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\Tests\Unit\Admin;

use PHPUnit\Framework\TestCase;
use Stonewright\WpMcp\Admin\AuditLogPage;
use Stonewright\WpMcp\Security\AuditEvent;
use Stonewright\WpMcp\Security\ErrorPatterns;
use Stonewright\WpMcp\Security\IncidentStore;

/**
 * @covers \Stonewright\WpMcp\Admin\AuditLogPage
 * @covers \Stonewright\WpMcp\Security\AuditLog
 */
final class AuditLogPageTest extends TestCase {

	private mixed $original_wpdb;

	protected function setUp(): void {
		$this->original_wpdb = $GLOBALS['wpdb'] ?? null;
		$GLOBALS['stonewright_test_user_caps'] = [ 'manage_options' => true ];
		$GLOBALS['stonewright_test_options']   = [
			'stonewright_mode' => 'development',
		];
		$GLOBALS['stonewright_test_missing_user_ids'] = [ 99 ];
		$GLOBALS['stonewright_test_current_user_id']  = 7;
		$GLOBALS['stonewright_test_nonce_invalid']    = false;
		$_GET  = [];
		$_POST = [];
		IncidentStore::reset_for_tests();
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
		$GLOBALS['stonewright_test_current_user_id']  = 0;
		$GLOBALS['stonewright_test_nonce_invalid']    = false;
		$_GET  = [];
		$_POST = [];
	}

	public function test_render_outputs_filters_expandable_rows_and_semantic_badges(): void {
		$GLOBALS['wpdb'] = new class() {
			public $prefix = 'wp_';

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
		self::assertStringContainsString( 'data-sw-shell', $html );
		self::assertStringContainsString( '<h1 class="sw-ui-page-title">Audit log</h1>', $html );
		self::assertSame( 1, substr_count( $html, '<h1' ), 'One h1, printed by the shell.' );
		self::assertStringContainsString( 'name="ability"', $html );
		self::assertStringContainsString( 'name="status"', $html );
		self::assertStringContainsString( 'value="blocked"', $html );
		self::assertStringContainsString( 'name="verification_status"', $html );
		self::assertStringContainsString( 'name="rollback_status"', $html );
		self::assertStringContainsString( 'name="operation_class"', $html );
		self::assertStringContainsString( 'name="user"', $html );
		self::assertStringContainsString( 'name="from"', $html );
		self::assertStringContainsString( 'name="to"', $html );
		self::assertMatchesRegularExpression( '/<section class="sw-ui-card sw-incident-summary"[^>]*>.*?<h2[^>]*>Incident lifecycle<\/h2>/s', $html, 'The incident band is a card with a heading.' );
		self::assertStringContainsString( 'More filters', $html );
		self::assertStringContainsString( 'sw-ui-toolbar', $html );
		self::assertStringContainsString( 'sw-ui-table', $html );
		self::assertStringContainsString( 'sw-ui-badge--ok', $html );
		self::assertStringContainsString( 'sw-ui-badge--danger', $html );
		self::assertStringNotContainsString( 'sw-badge', $html, 'No older badge classes.' );
		self::assertStringNotContainsString( 'sw-btn', $html, 'No older button classes.' );
		self::assertStringContainsString( 'Delete all logs', $html );
		self::assertStringContainsString( 'OAuth: Desktop client', $html );
		self::assertStringContainsString( 'Deleted user', $html );
		self::assertStringNotContainsString( '(unknown)', $html );
		self::assertStringContainsString( 'method="get"', $html );
		self::assertStringContainsString( '<caption class="sw-ui-visually-hidden">Audit log entries</caption>', $html );
		self::assertMatchesRegularExpression( '/<time datetime="2026-07-15T10:00:00Z" title="2026-07-15 10:00:00 UTC">/', $html, 'Times are <time> elements with UTC in the title.' );

		// Row details open in one drawer; each row has a named button and a panel.
		self::assertSame( 4, substr_count( $html, 'data-sw-audit-open=' ) );
		self::assertMatchesRegularExpression( '/<button[^>]*data-sw-audit-open="sw-audit-row-12"[^>]*>Details<span class="sw-ui-visually-hidden"> of event 12, stonewright\/content-update<\/span>/', $html );
		self::assertStringContainsString( 'id="sw-audit-row-12"', $html );
		self::assertStringContainsString( '<dialog id="sw-audit-drawer" class="sw-ui-dialog sw-ui-drawer"', $html );
		self::assertStringContainsString( 'data-sw-ui-light-dismiss', $html );
		self::assertStringContainsString( 'post_id', $html );
		self::assertStringContainsString( 'data-sw-ui-copy="#sw-audit-payload-12"', $html );
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

		$row['ability_name']     = 'Bearer still-secret-value';
		$row['redacted_details'] = '{}';
		$blocked = AuditLogPage::build_export( [ $row ], 'json' );
		self::assertInstanceOf( \WP_Error::class, $blocked );
		self::assertSame( 'stonewright_audit_export_sensitive_content_blocked', $blocked->get_error_code() );
	}

	public function test_render_empty_state(): void {
		$GLOBALS['wpdb'] = new class() {
			public $prefix = 'wp_';

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

		self::assertStringContainsString( 'sw-ui-empty', $html );
		self::assertStringContainsString( 'sw-ui-empty__title', $html );
		self::assertStringContainsString( 'No audit entries have been recorded', $html );
		self::assertStringContainsString( 'Run a Stonewright mutation', $html );
		self::assertStringNotContainsString( 'sw-empty-state', $html );
	}

	public function test_error_row_expand_shows_code_message_target_mode_and_repair(): void {
		$GLOBALS['wpdb'] = new class() {
			public $prefix = 'wp_';

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
		self::assertStringContainsString( 'sw-ui-kv', $html, 'The drawer reads as facts.' );
		self::assertStringContainsString( 'Redacted details', $html );
		self::assertStringContainsString( 'Repair', $html );
		self::assertStringContainsString( 'Spec failed at tokens.color', $html );
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
		self::assertDoesNotMatchRegularExpression( '/Errors <span class="sw-ui-count sw-ui-num">0</', $filtered, 'A pattern view hides the views that count nothing.' );
		self::assertMatchesRegularExpression( '/All <span class="sw-ui-count sw-ui-num">0</', $filtered );
	}

	public function test_dismissing_a_recurring_pattern_asks_in_a_dialog_and_still_posts_without_script(): void {
		$pattern         = $this->seed_recurring_pattern();
		$GLOBALS['wpdb'] = $this->make_audit_wpdb( [], 0 );

		ob_start();
		AuditLogPage::render();
		$html = (string) ob_get_clean();

		self::assertStringNotContainsString( 'data-confirm=', $html, 'No browser confirm().' );
		self::assertMatchesRegularExpression( '/<dialog[^>]*class="sw-ui-dialog"[^>]*aria-labelledby="(sw-audit-dismiss-[a-f0-9]+)-title"/', $html );
		self::assertStringContainsString( 'Dismiss this recurring error pattern?', $html );
		self::assertStringContainsString( 'name="action" value="stonewright_dismiss_error_pattern"', $html );
		self::assertStringContainsString( 'name="signature" value="' . $pattern['signature'] . '"', $html );
		self::assertMatchesRegularExpression( '/<button[^>]*type="submit"[^>]*form="sw-audit-dismiss-form-[a-f0-9]+"[^>]*data-sw-ui-dialog-open="#sw-audit-dismiss-[a-f0-9]+"/', $html, 'Without script the row button posts the form that sits in the dialog.' );
		self::assertMatchesRegularExpression( '/>Dismiss<span class="sw-ui-visually-hidden"> pattern for stonewright\/design-validate-spec<\/span>/', $html, 'Each button names its pattern.' );
		self::assertMatchesRegularExpression( '/>View occurrences<span class="sw-ui-visually-hidden"> of stonewright\/design-validate-spec<\/span>/', $html );
	}

	public function test_purge_requires_manage_options(): void {
		$GLOBALS['stonewright_test_user_caps']['manage_options'] = false;
		$GLOBALS['wpdb'] = $this->make_purge_wpdb( 3 );
		$_POST = [
			'_stonewright_nonce' => 'test-nonce-stonewright_audit_purge',
			'confirm_phrase'     => 'DELETE',
		];

		$this->expectException( \RuntimeException::class );
		$this->expectExceptionMessageMatches( '/wp_die/' );
		AuditLogPage::process_purge_request();
	}

	public function test_purge_requires_valid_nonce(): void {
		$GLOBALS['stonewright_test_nonce_invalid'] = true;
		$GLOBALS['wpdb'] = $this->make_purge_wpdb( 3 );
		$_POST = [
			'_stonewright_nonce' => 'forged',
			'confirm_phrase'     => 'DELETE',
		];

		$this->expectException( \RuntimeException::class );
		$this->expectExceptionMessageMatches( '/wp_die/' );
		AuditLogPage::process_purge_request();
	}

	public function test_purge_requires_typed_delete_confirmation(): void {
		$GLOBALS['wpdb'] = $this->make_purge_wpdb( 3 );
		$_POST = [
			'_stonewright_nonce' => 'test-nonce-stonewright_audit_purge',
			'confirm_phrase'     => 'delete',
		];

		$this->expectException( \RuntimeException::class );
		$this->expectExceptionMessageMatches( '/wp_die/' );
		AuditLogPage::process_purge_request();
	}

	public function test_purge_deletes_the_incidents_that_pointed_into_the_log_and_says_how_many(): void {
		$wpdb            = $this->make_purge_wpdb( 4 );
		$GLOBALS['wpdb'] = $wpdb;
		foreach ( [ 'stonewright_skill_write_conflict', 'stonewright_spec_invalid' ] as $code ) {
			IncidentStore::observe( AuditEvent::normalize( 'stonewright/skills-save', [ '_meta' => [ 'error_code' => $code, 'resource_type' => 'skill' ] ], 'error' ) );
		}
		self::assertSame( 2, array_sum( IncidentStore::counts() ) );
		$_POST = [
			'_stonewright_nonce' => 'test-nonce-stonewright_audit_purge',
			'confirm_phrase'     => 'DELETE',
		];

		AuditLogPage::process_purge_request();

		self::assertSame( [ 'open' => 0, 'observing' => 0, 'resolved' => 0, 'suppressed' => 0 ], IncidentStore::counts() );
		$args = json_decode( (string) ( $wpdb->inserts[0]['sanitized_args'] ?? '' ), true );
		self::assertSame( 2, (int) ( $args['incidents'] ?? -1 ), 'The receipt records how many incidents went with the log.' );
		self::assertSame( 2, AuditLogPage::last_purged_incidents() );
	}

	public function test_purge_wipes_events_and_patterns_then_records_one_receipt(): void {
		$this->seed_recurring_pattern();
		self::assertNotEmpty( ErrorPatterns::recurring() );

		$wpdb = $this->make_purge_wpdb( 4 );
		$GLOBALS['wpdb'] = $wpdb;
		$_POST = [
			'_stonewright_nonce' => 'test-nonce-stonewright_audit_purge',
			'confirm_phrase'     => 'DELETE',
		];

		$count = AuditLogPage::process_purge_request();

		self::assertSame( 4, $count );
		self::assertSame( 1, $wpdb->event_count, 'Exactly one receipt row remains after purge.' );
		self::assertCount( 1, $wpdb->inserts );
		self::assertSame( 'audit_log_purged', (string) ( $wpdb->inserts[0]['ability_name'] ?? '' ) );
		$args = json_decode( (string) ( $wpdb->inserts[0]['sanitized_args'] ?? '' ), true );
		self::assertIsArray( $args );
		self::assertSame( 7, (int) ( $args['actor'] ?? 0 ) );
		self::assertSame( 4, (int) ( $args['count'] ?? 0 ) );
		self::assertSame( [], ErrorPatterns::recurring() );
		self::assertFalse( get_option( ErrorPatterns::OPTION_KEY, false ) );
		self::assertSame( 'development', get_option( 'stonewright_mode' ) );
		self::assertNotEmpty(
			array_filter(
				$wpdb->queries,
				static fn( string $sql ): bool => str_contains( $sql, 'DELETE FROM' ) && str_contains( $sql, 'stonewright_audit_log' )
			)
		);
	}

	public function test_render_includes_delete_all_logs_inline_confirm_and_updated_append_copy(): void {
		$GLOBALS['wpdb'] = $this->make_audit_wpdb( [], 0 );

		ob_start();
		AuditLogPage::render();
		$html = (string) ob_get_clean();

		self::assertStringContainsString( 'Delete all logs', $html );
		self::assertStringContainsString( 'sw-ui-btn--danger', $html );
		self::assertSame( 1, substr_count( $html, 'sw-ui-btn--primary' ), 'One primary action on the page: Filter.' );
		self::assertMatchesRegularExpression( '/sw-ui-btn--danger"[^>]*data-sw-ui-dialog-open="#sw-audit-purge-dialog"|sw-ui-btn sw-ui-btn--danger sw-ui-btn--sm"[^>]*data-sw-ui-dialog-open/', $html, 'Delete is a danger button, never primary.' );
		self::assertMatchesRegularExpression( '/<button[^>]*data-sw-ui-dialog-open="#sw-audit-purge-dialog"[^>]*>Delete all logs/', $html );
		self::assertMatchesRegularExpression( '/<dialog id="sw-audit-purge-dialog" class="sw-ui-dialog"[^>]*aria-labelledby="sw-audit-purge-title"/', $html );
		self::assertStringContainsString( 'Type DELETE to confirm', $html );
		self::assertStringContainsString( 'data-sw-ui-confirm-phrase="DELETE"', $html );
		self::assertMatchesRegularExpression( '/<button[^>]*disabled[^>]*data-sw-ui-confirm-submit|<button[^>]*data-sw-ui-confirm-submit[^>]*disabled/', $html, 'The delete button starts disabled.' );
		self::assertMatchesRegularExpression( '/<button[^>]*autofocus[^>]*>Cancel/', $html, 'The safe action takes focus.' );
		self::assertStringContainsString( 'incidents', strtolower( strip_tags( substr( $html, (int) strpos( $html, 'sw-audit-purge-dialog' ), 1800 ) ) ), 'The confirmation says incidents go too.' );
		self::assertStringContainsString( 'Records of what agents and Stonewright did', $html );
		self::assertStringNotContainsString( 'window.confirm', (string) file_get_contents( dirname( __DIR__, 3 ) . '/assets/admin/pages/audit.js' ) );
		self::assertFileDoesNotExist( dirname( __DIR__, 3 ) . '/assets/admin/audit.js' );
		self::assertFileDoesNotExist( dirname( __DIR__, 3 ) . '/assets/admin/audit.css' );
	}

	public function test_render_after_purge_shows_inline_flash_not_wp_notice(): void {
		$GLOBALS['wpdb'] = $this->make_audit_wpdb(
			[
				[
					'id'               => '1',
					'ability_name'     => 'audit_log_purged',
					'user_id'          => '7',
					'result_status'    => 'ok',
					'redacted_details' => wp_json_encode( [ 'actor' => 7, 'count' => 4 ] ),
					'created_at'       => '2026-08-21 08:00:00',
				],
			],
			1
		);
		$_GET['purged']    = '4';
		$_GET['incidents'] = '3';

		ob_start();
		AuditLogPage::render();
		$html = (string) ob_get_clean();
		unset( $_GET['purged'], $_GET['incidents'] );

		self::assertStringContainsString( 'sw-ui-notice--ok', $html );
		self::assertStringContainsString( 'role="status"', $html );
		self::assertStringContainsString( 'Deleted 4 audit events, 3 incidents and all pattern summaries. One audit_log_purged receipt remains.', $html );
		self::assertStringNotContainsString( 'notice notice-success is-dismissible', $html );
		self::assertStringContainsString( 'audit_log_purged', $html );
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
			public $prefix = 'wp_';
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

	private function make_purge_wpdb( int $event_count ): object {
		return new class( $event_count ) {
			public $prefix = 'wp_';
			public int $event_count;
			/** @var list<string> */
			public array $queries = [];
			/** @var list<array<string, mixed>> */
			public array $inserts = [];

			public function __construct( int $event_count ) {
				$this->event_count = $event_count;
			}

			public function prepare( string $query, mixed ...$args ): string {
				$this->queries[] = $query;
				return $query;
			}

			public function get_var( string $query = '' ): string|int|null {
				$this->queries[] = $query;
				return $this->event_count;
			}

			public function query( string $query ): int|false {
				$this->queries[] = $query;
				if ( str_contains( $query, 'DELETE FROM' ) && str_contains( $query, 'stonewright_audit_log' ) ) {
					$deleted           = $this->event_count;
					$this->event_count = 0;
					return $deleted;
				}
				return 0;
			}

			/**
			 * @param array<string, mixed> $data
			 * @param array<int, mixed>    $format
			 */
			public function insert( string $table, array $data, array $format = [] ): int {
				unset( $format );
				$this->inserts[] = $data;
				if ( str_contains( $table, 'stonewright_audit_log' ) ) {
					++$this->event_count;
				}
				return 1;
			}

			/** @return array<int, array<string, mixed>> */
			public function get_results( string $query, string $output = 'OBJECT' ): array {
				unset( $query, $output );
				return [];
			}
		};
	}
}
