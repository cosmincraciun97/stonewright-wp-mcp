<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\Tests\Unit\Security;

use PHPUnit\Framework\TestCase;
use Stonewright\WpMcp\Security\AuditLog;
use Stonewright\WpMcp\Security\ErrorPatterns;

/**
 * @covers \Stonewright\WpMcp\Security\ErrorPatterns
 * @covers \Stonewright\WpMcp\Security\AuditLog
 */
final class ErrorPatternsTest extends TestCase {

	private mixed $original_wpdb;

	protected function setUp(): void {
		$this->original_wpdb = $GLOBALS['wpdb'] ?? null;
		$GLOBALS['stonewright_test_options'] = [];
		$GLOBALS['stonewright_test_current_user_id'] = 1;
	}

	protected function tearDown(): void {
		if ( null !== $this->original_wpdb ) {
			$GLOBALS['wpdb'] = $this->original_wpdb;
		} else {
			unset( $GLOBALS['wpdb'] );
		}
		$GLOBALS['stonewright_test_options'] = [];
	}

	public function test_two_matching_errors_group_as_recurring(): void {
		$args = [
			'_meta' => [
				'error_code'    => 'validation_failed',
				'error_message' => 'Spec rejected: missing sections array for layout.',
			],
		];

		ErrorPatterns::observe( 'stonewright/design-apply', 'error', $args );
		ErrorPatterns::observe( 'stonewright/design-apply', 'error', $args );

		$recurring = ErrorPatterns::recurring();
		self::assertNotEmpty( $recurring );
		self::assertSame( 2, $recurring[0]['count'] );
		self::assertSame( 'stonewright/design-apply', $recurring[0]['ability'] );
		self::assertStringContainsString( 'Spec rejected', $recurring[0]['message'] );
		self::assertNotEmpty( $recurring[0]['last_seen'] );
	}

	public function test_ok_status_is_ignored(): void {
		ErrorPatterns::observe( 'stonewright/memory-save', 'ok', [] );
		self::assertSame( [], ErrorPatterns::recurring() );
	}

	public function test_dismiss_hides_pattern(): void {
		$args = [ '_meta' => [ 'error_code' => 'x', 'error_message' => 'boom once twice' ] ];
		ErrorPatterns::observe( 'stonewright/php-execute', 'error', $args );
		ErrorPatterns::observe( 'stonewright/php-execute', 'error', $args );
		$sig = ErrorPatterns::signature( 'stonewright/php-execute', $args );
		self::assertTrue( ErrorPatterns::dismiss( $sig ) );
		self::assertSame( [], ErrorPatterns::recurring() );
	}

	public function test_learning_write_failure_is_logged_only_on_verified_recipe_promote(): void {
		$log_file = tempnam( sys_get_temp_dir(), 'sw-ep-log-' );
		self::assertNotFalse( $log_file );
		$previous_log = ini_get( 'error_log' );
		ini_set( 'error_log', $log_file );

		$GLOBALS['wpdb'] = new class() {
			public string $prefix     = 'wp_';
			public string $last_error = 'Unknown column topic';
			public int $insert_id    = 0;

			public function get_var( string $query ): mixed {
				return null;
			}

			public function prepare( string $query, mixed ...$args ): string {
				return $query;
			}

			/** @return array<int, string> */
			public function get_col( string $query, int $x = 0 ): array {
				return [];
			}

			/**
			 * @param array<string, mixed> $data
			 * @return false Always fails (broken table fixture).
			 */
			public function insert( string $table, array $data, array $format = [] ): bool {
				return false;
			}
		};

		$args = [
			'error_code' => 'stonewright_demo_failure',
			'message'    => 'Demo failed',
		];
		// Unresolved observe must not attempt a learning write.
		ErrorPatterns::observe( 'stonewright/demo-ability', 'error', $args );
		ErrorPatterns::observe( 'stonewright/demo-ability', 'error', $args );
		$mid = (string) file_get_contents( $log_file );
		self::assertStringNotContainsString( 'error_pattern_learning_write_failed', $mid );

		// Agent-supplied success/recipe data is not canonical repair proof.
		ErrorPatterns::observe_verified_repair(
			'stonewright/demo-ability',
			[
				'effect_verified'     => true,
				'verification_status' => 'verified',
				'repair_recipe'       => 'Fix the fixture, then retry once.',
			]
		);

		ini_set( 'error_log', (string) $previous_log );
		$log = (string) file_get_contents( $log_file );
		@unlink( $log_file );

		self::assertStringNotContainsString( 'error_pattern_learning_write_failed', $log );
		self::assertStringNotContainsString( 'verified-repair-', $log );
	}

	public function test_audit_log_records_error_pattern_throws(): void {
		$log_file = tempnam( sys_get_temp_dir(), 'sw-al-log-' );
		self::assertNotFalse( $log_file );
		$previous_log = ini_get( 'error_log' );
		ini_set( 'error_log', $log_file );

		$GLOBALS['wpdb'] = new class() {
			public string $prefix     = 'wp_';
			public string $last_error = '';
			public int $insert_id    = 1;

			/** @param array<string, mixed> $data */
			public function insert( string $table, array $data, array $format = [] ): int {
				++$this->insert_id;
				return 1;
			}

			public function get_var( string $query ): mixed {
				return null;
			}

			public function prepare( string $query, mixed ...$args ): string {
				return $query;
			}

			/** @return array<int, string> */
			public function get_col( string $query, int $x = 0 ): array {
				return [];
			}
		};

		ErrorPatterns::set_test_before_observe(
			static function () {
				throw new \RuntimeException( 'simulated observe failure via get_var' );
			}
		);

		$args = [ 'error_code' => 'x', 'message' => 'boom' ];
		AuditLog::record( 'stonewright/demo-ability', $args, 'error' );
		ErrorPatterns::set_test_before_observe( null );

		ini_set( 'error_log', (string) $previous_log );
		$log = (string) file_get_contents( $log_file );
		@unlink( $log_file );

		self::assertStringContainsString( 'error_patterns_observe_threw', $log );
		self::assertStringContainsString( 'simulated observe failure', $log );
	}

	/**
	 * Ownership decides the prefix, so normalization needs the origin context.
	 *
	 * `invalid_request` is both an RFC 6749 protocol code and a code Stonewright
	 * abilities emit themselves. Preserving it globally would leave Stonewright's
	 * own failures colliding with every other plugin's; namespacing it globally
	 * would rewrite a protocol constant the client is entitled to read back.
	 *
	 * @dataProvider code_ownership_cases
	 */
	public function test_normalize_code_follows_ownership( string $code, string $ability, string $status, string $expected ): void {
		self::assertSame( $expected, ErrorPatterns::normalize_code( $code, $ability, $status ) );
	}

	/**
	 * @return array<string, array{0: string, 1: string, 2: string, 3: string}>
	 */
	public static function code_ownership_cases(): array {
		return [
			'stonewright ability owns its bare code' => [
				'invalid_request',
				'stonewright/design-apply-to-post',
				'error',
				'stonewright_invalid_request',
			],
			'already namespaced stays unchanged'     => [
				'stonewright_spec_invalid',
				'stonewright/design-validate-spec',
				'error',
				'stonewright_spec_invalid',
			],
			'auth status keeps protocol invalid_request' => [
				'invalid_request',
				'oauth/token',
				'auth',
				'invalid_request',
			],
			'auth status keeps protocol invalid_grant'   => [
				'invalid_grant',
				'oauth/token',
				'auth',
				'invalid_grant',
			],
			'oauth origin keeps protocol code on error status' => [
				'invalid_client',
				'oauth/token',
				'error',
				'invalid_client',
			],
			'auth origin still owns non-protocol codes' => [
				'widget_missing',
				'oauth/token',
				'auth',
				'stonewright_widget_missing',
			],
			'foreign rest_ prefix untouched'         => [
				'rest_no_route',
				'stonewright/content-get-page',
				'error',
				'rest_no_route',
			],
			'foreign oauth_ prefix untouched'        => [
				'oauth_invalid_client',
				'stonewright/ping',
				'error',
				'oauth_invalid_client',
			],
			'foreign http_ prefix untouched'         => [
				'http_request_failed',
				'stonewright/media-upload',
				'error',
				'http_request_failed',
			],
			'empty input stays empty'                => [ '', 'stonewright/ping', 'error', '' ],
			'whitespace-only input stays empty'      => [ "  \t ", 'stonewright/ping', 'error', '' ],
			'unknown-code sentinel is not a code'    => [ 'error', 'stonewright/ping', 'error', 'error' ],
			'mixed case is folded before prefixing'  => [
				'Validation_Failed',
				'stonewright/design-apply-to-post',
				'error',
				'stonewright_validation_failed',
			],
		];
	}

	public function test_observed_patterns_store_the_normalized_code(): void {
		$args = [
			'_meta' => [
				'error_code'    => 'invalid_request',
				'error_message' => 'Missing target id.',
			],
		];

		ErrorPatterns::observe( 'stonewright/design-apply-to-post', 'error', $args );
		ErrorPatterns::observe( 'stonewright/design-apply-to-post', 'error', $args );

		$recurring = ErrorPatterns::recurring();
		self::assertNotEmpty( $recurring );
		self::assertSame( 'stonewright_invalid_request', $recurring[0]['error_code'] );
	}

	public function test_cause_key_uses_the_normalized_code(): void {
		$args = [ '_meta' => [ 'error_code' => 'invalid_request' ] ];

		self::assertSame(
			'stonewright/design-apply-to-post|stonewright_invalid_request|',
			ErrorPatterns::cause_key( 'stonewright/design-apply-to-post', $args )
		);
		// Auth origin keeps the protocol spelling, so the two never share a bucket.
		self::assertSame(
			'oauth/token|invalid_request|',
			ErrorPatterns::cause_key( 'oauth/token', $args, 'auth' )
		);
	}

	public function test_feature_disabled_is_an_expected_safety_block(): void {
		// The guards emit the bare code; the pattern layer normalizes it, and the
		// expected-safety list must be spelled for the normalized form.
		$normalized = ErrorPatterns::normalize_code( 'feature_disabled', 'stonewright/design-spec-to-elementor-v4', 'error' );
		self::assertSame( 'stonewright_feature_disabled', $normalized );
		self::assertTrue( ErrorPatterns::is_expected_safety_code( $normalized ) );

		$args = [ '_meta' => [ 'error_code' => 'feature_disabled', 'error_message' => 'V4 is off.' ] ];
		ErrorPatterns::observe( 'stonewright/design-spec-to-elementor-v4', 'error', $args );
		ErrorPatterns::observe( 'stonewright/design-spec-to-elementor-v4', 'error', $args );

		// Expected blocks are counted for the hard stop but never promoted to learning.
		$store = (array) get_option( ErrorPatterns::OPTION_KEY, [] );
		self::assertCount( 1, $store );
		$row = (array) array_values( $store )[0];
		self::assertSame( 'stonewright_feature_disabled', $row['error_code'] ?? '' );
		self::assertTrue( (bool) ( $row['expected'] ?? false ) );
		self::assertSame( 'blocked_pending_repair', $row['state'] ?? '' );
		self::assertSame( '', (string) ( $row['learning_key'] ?? '' ) );
	}

	public function test_memory_entry_lookup_passes_sql_string_and_scalar_binds_to_prepare(): void {
		$wpdb = new class() {
			public string $prefix = 'wp_';
			/** @var list<array{query:string,args:list<mixed>}> */
			public array $prepare_calls = [];

			public function prepare( string $query, mixed ...$args ): string {
				foreach ( $args as $arg ) {
					if ( ! is_scalar( $arg ) && null !== $arg ) {
						throw new \TypeError( 'wpdb::prepare() received a non-scalar placeholder.' );
					}
				}
				$this->prepare_calls[] = [
					'query' => $query,
					'args'  => array_values( $args ),
				];
				return $query;
			}

			public function get_var( string $query ): mixed {
				return 0;
			}

			/** @return array<int, string> */
			public function get_col( string $query, int $x = 0 ): array {
				return [];
			}
		};
		$GLOBALS['wpdb'] = $wpdb;

		$method = new \ReflectionMethod( ErrorPatterns::class, 'memory_entry_by_key' );
		$method->invoke( null, 'audit', 'draft-lesson-example' );

		self::assertNotEmpty( $wpdb->prepare_calls );
		$call = $wpdb->prepare_calls[0];
		self::assertIsString( $call['query'] );
		self::assertStringContainsString( 'SELECT id FROM', $call['query'] );
		self::assertSame( [ 'audit', 'draft-lesson-example' ], $call['args'] );
	}
}
