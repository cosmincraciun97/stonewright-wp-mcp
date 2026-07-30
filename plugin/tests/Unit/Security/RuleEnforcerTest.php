<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\Tests\Unit\Security;

use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use Stonewright\WpMcp\DesignSpec\Validator;
use Stonewright\WpMcp\Elementor\ElementorWriter;
use Stonewright\WpMcp\Elementor\Integrity\DocumentIntegrityGate;
use Stonewright\WpMcp\Security\AuditLog;
use Stonewright\WpMcp\Security\ConfirmationToken;
use Stonewright\WpMcp\Security\ErrorPatterns;
use Stonewright\WpMcp\Security\GlobalRules;
use Stonewright\WpMcp\Security\PhpSyntaxValidator;
use Stonewright\WpMcp\Security\RuleEnforcer;

/**
 * A `hard` rule is only honest if a runtime guard makes the violation fail and
 * the audit trail can name the rule that fired.
 *
 * These tests defend four properties:
 * 1. Rule-violation metadata is canonical — a caller cannot soften it.
 * 2. Only runtime-enforced rule ids can be attributed; anything else throws.
 * 3. A guard rejection is a safety block, not a server error, and it carries
 *    `rule_id` into the persisted audit row.
 * 4. A working guard never becomes a learning incident.
 *
 * @covers \Stonewright\WpMcp\Security\RuleEnforcer
 */
final class RuleEnforcerTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		GlobalRules::reset_cache();
		$GLOBALS['stonewright_test_wpdb_inserts'] = [];
	}

	protected function tearDown(): void {
		GlobalRules::reset_cache();
		$GLOBALS['stonewright_test_wpdb_inserts'] = [];
		parent::tearDown();
	}

	// -------------------------------------------------------------------------
	// violation()
	// -------------------------------------------------------------------------

	public function test_violation_returns_canonical_blocked_metadata(): void {
		$error = RuleEnforcer::violation( 'backup-before-write', 'No snapshot was taken for post.' );

		self::assertInstanceOf( \WP_Error::class, $error );
		self::assertSame( RuleEnforcer::ERROR_CODE, $error->get_error_code() );

		$data = $error->get_error_data();
		self::assertIsArray( $data );
		self::assertSame( 409, $data['status'] );
		self::assertSame( 'blocked', $data['execution_status'] );
		self::assertSame( 'blocked', $data['verification_status'] );
		self::assertFalse( $data['retryable'] );
		self::assertSame( 'rule_violation', $data['error_code'] );
		self::assertSame( 'rule:backup-before-write', $data['cause_key'] );
		self::assertSame( 'backup-before-write', $data['rule_id'] );
		self::assertSame( 'hard', $data['rule_severity'] );
		self::assertSame( 'backup_snapshot', $data['rule_guard'] );
	}

	public function test_violation_message_names_the_rule_and_the_detail(): void {
		$rule  = GlobalRules::get( 'php-writes-must-parse' );
		$error = RuleEnforcer::violation( 'php-writes-must-parse', 'Parse error on line 12.' );

		self::assertIsArray( $rule );
		self::assertStringContainsString( $rule['rule'], $error->get_error_message() );
		self::assertStringContainsString( 'Parse error on line 12.', $error->get_error_message() );
	}

	public function test_diagnostics_cannot_overwrite_canonical_metadata(): void {
		$error = RuleEnforcer::violation(
			'backup-before-write',
			'Snapshot missing.',
			[
				'status'              => 200,
				'execution_status'    => 'ok',
				'verification_status' => 'verified',
				'retryable'           => true,
				'error_code'          => 'harmless',
				'cause_key'           => 'rule:something-else',
				'rule_id'             => 'read-before-write',
				'rule_severity'       => 'advisory',
				'rule_guard'          => 'none',
				'post_id'             => 41,
			]
		);

		$data = $error->get_error_data();
		self::assertIsArray( $data );
		self::assertSame( 409, $data['status'] );
		self::assertSame( 'blocked', $data['execution_status'] );
		self::assertSame( 'blocked', $data['verification_status'] );
		self::assertFalse( $data['retryable'] );
		self::assertSame( 'rule_violation', $data['error_code'] );
		self::assertSame( 'rule:backup-before-write', $data['cause_key'] );
		self::assertSame( 'backup-before-write', $data['rule_id'] );
		self::assertSame( 'hard', $data['rule_severity'] );
		self::assertSame( 'backup_snapshot', $data['rule_guard'] );
		self::assertSame( 41, $data['post_id'], 'Non-canonical diagnostics must survive.' );
	}

	public function test_violation_rejects_an_unknown_rule_id(): void {
		$this->expectException( InvalidArgumentException::class );
		$this->expectExceptionMessage( 'no-such-rule' );

		RuleEnforcer::violation( 'no-such-rule', 'Detail.' );
	}

	public function test_violation_rejects_a_rule_with_no_runtime_guard(): void {
		$this->expectException( InvalidArgumentException::class );
		$this->expectExceptionMessage( 'read-before-write' );

		RuleEnforcer::violation( 'read-before-write', 'Detail.' );
	}

	// -------------------------------------------------------------------------
	// attribute()
	// -------------------------------------------------------------------------

	public function test_attribute_keeps_the_guard_error_identity(): void {
		$original = new \WP_Error(
			'stonewright_elementor_double_encoded',
			'Elementor document appears double-encoded JSON.',
			[
				'status' => 400,
				'fix'    => [ 'json_decode_once' ],
			]
		);

		$attributed = RuleEnforcer::attribute( $original, 'elementor-no-double-encode' );

		self::assertSame( 'stonewright_elementor_double_encoded', $attributed->get_error_code() );
		self::assertSame( 'Elementor document appears double-encoded JSON.', $attributed->get_error_message() );

		$data = $attributed->get_error_data();
		self::assertIsArray( $data );
		self::assertSame( [ 'json_decode_once' ], $data['fix'], 'Guard diagnostics must survive attribution.' );
		self::assertSame( 400, $data['status'], 'Attribution must not rewrite the guard HTTP status.' );
		self::assertSame( 'elementor-no-double-encode', $data['rule_id'] );
		self::assertSame( 'hard', $data['rule_severity'] );
		self::assertSame( 'elementor_document_integrity', $data['rule_guard'] );
		self::assertSame( 'blocked', $data['execution_status'] );
		self::assertSame( 'blocked', $data['verification_status'] );
		self::assertFalse( $data['retryable'] );
		self::assertSame( 'rule:elementor-no-double-encode', $data['cause_key'] );
	}

	public function test_attribute_preserves_an_existing_cause_key(): void {
		$original = new \WP_Error(
			'stonewright_php_candidate_invalid',
			'Bad PHP.',
			[
				'status'    => 400,
				'cause_key' => 'php_candidate_invalid',
			]
		);

		$data = RuleEnforcer::attribute( $original, 'php-writes-must-parse' )->get_error_data();
		self::assertIsArray( $data );
		self::assertSame( 'php_candidate_invalid', $data['cause_key'], 'Existing cause keys group known incidents.' );
	}

	public function test_attribute_handles_a_guard_error_without_array_data(): void {
		$original = new \WP_Error( 'stonewright_backup_failed', 'Snapshot failed.' );

		$data = RuleEnforcer::attribute( $original, 'backup-before-write' )->get_error_data();
		self::assertIsArray( $data );
		self::assertSame( 'backup-before-write', $data['rule_id'] );
		self::assertSame( 'blocked', $data['execution_status'] );
	}

	public function test_attribute_rejects_rules_without_runtime_enforcement(): void {
		$this->expectException( InvalidArgumentException::class );

		RuleEnforcer::attribute( new \WP_Error( 'x', 'y' ), 'batch-related-mutations' );
	}

	public function test_every_runtime_rule_is_attributable(): void {
		foreach ( GlobalRules::all() as $rule ) {
			if ( 'runtime' !== $rule['enforcement']['kind'] ) {
				continue;
			}

			$data = RuleEnforcer::violation( $rule['id'], 'Detail.' )->get_error_data();
			self::assertIsArray( $data );
			self::assertSame( $rule['enforcement']['guard'], $data['rule_guard'] );
		}
	}

	// -------------------------------------------------------------------------
	// Audit classification
	// -------------------------------------------------------------------------

	public function test_audit_row_stays_blocked_and_carries_the_rule_id(): void {
		$error = RuleEnforcer::violation( 'confirm-destructive-in-production-safe', 'Token missing.' );
		$data  = $error->get_error_data();
		self::assertIsArray( $data );

		AuditLog::record(
			'stonewright/theme-file-patch',
			[
				'_meta' => [
					'error_code'          => 'stonewright_rule_violation',
					'execution_status'    => $data['execution_status'],
					'verification_status' => $data['verification_status'],
					'rule_id'             => $data['rule_id'],
					'cause_key'           => $data['cause_key'],
				],
			],
			'blocked'
		);

		$row = $GLOBALS['stonewright_test_wpdb_inserts'][0]['data'];
		self::assertSame( 'blocked', $row['result_status'] );
		self::assertSame( 'safety_block', $row['event_type'] );
		self::assertSame( 'warning', $row['severity'], 'A working guard is a warning, never a p0/high incident.' );
		self::assertStringContainsString(
			'confirm-destructive-in-production-safe',
			(string) $row['sanitized_args'],
			'The persisted row must name the rule that fired.'
		);
	}

	public function test_http_409_alone_never_escalates_a_block_to_an_error(): void {
		$kernel = new class() extends \Stonewright\WpMcp\Abilities\AbilityKernel {
			public function name(): string {
				return 'stonewright/test-rule-enforcer';
			}

			public function label(): string {
				return 'Test';
			}

			public function description(): string {
				return 'Test kernel for rule-violation audit classification.';
			}

			public function category(): string {
				return 'test';
			}

			/**
			 * @param array<string, mixed> $args
			 * @return array<string, mixed>|\WP_Error
			 */
			public function execute( array $args ): array|\WP_Error {
				return $this->audit(
					$args,
					static fn( array $a ): \WP_Error => RuleEnforcer::violation(
						'validate-spec-before-render',
						'Spec was never validated.'
					)
				);
			}
		};

		$result = $kernel->execute( [ 'post_id' => 7 ] );

		self::assertInstanceOf( \WP_Error::class, $result );
		$row = $GLOBALS['stonewright_test_wpdb_inserts'][0]['data'];
		self::assertSame( 'blocked', $row['result_status'] );
		self::assertSame( 'warning', $row['severity'] );
		self::assertStringContainsString( 'validate-spec-before-render', (string) $row['sanitized_args'] );
	}

	public function test_rule_violations_are_expected_safety_blocks(): void {
		self::assertTrue( ErrorPatterns::is_expected_safety_code( RuleEnforcer::ERROR_CODE ) );
		self::assertTrue( ErrorPatterns::is_expected_safety_code( 'rule_violation' ) );
	}

	// -------------------------------------------------------------------------
	// Wiring: the prohibited call must fail, and say which rule stopped it.
	// -------------------------------------------------------------------------

	public function test_double_encoded_elementor_write_is_blocked_with_rule_attribution(): void {
		$error = DocumentIntegrityGate::assert_write_allowed(
			[ (string) json_encode( [ [ 'id' => 'a1', 'elType' => 'container' ] ] ) ]
		);

		self::assertInstanceOf( \WP_Error::class, $error );
		self::assertSame( 'stonewright_elementor_double_encoded', $error->get_error_code() );
		$data = $error->get_error_data();
		self::assertIsArray( $data );
		self::assertSame( 'elementor-no-double-encode', $data['rule_id'] );
		self::assertSame( 'blocked', $data['execution_status'] );
	}

	public function test_widget_type_conversion_is_blocked_with_rule_attribution(): void {
		$previous = [
			[
				'id'         => 'w1',
				'elType'     => 'widget',
				'widgetType' => 'e-paragraph',
			],
		];
		$incoming = [
			[
				'id'         => 'w1',
				'elType'     => 'widget',
				'widgetType' => 'text-editor',
			],
		];

		$error = DocumentIntegrityGate::assert_write_allowed( $incoming, $previous );

		self::assertInstanceOf( \WP_Error::class, $error );
		$data = $error->get_error_data();
		self::assertIsArray( $data );
		self::assertSame( 'elementor-no-widget-type-conversion', $data['rule_id'] );
	}

	public function test_size_collapse_is_blocked_as_a_setting_strip(): void {
		$previous = [];
		for ( $i = 0; $i < 60; $i++ ) {
			$previous[] = [
				'id'         => 'w' . $i,
				'elType'     => 'widget',
				'widgetType' => 'heading',
				'settings'   => [ 'title' => str_repeat( 'padding text ', 6 ) ],
			];
		}

		$error = DocumentIntegrityGate::assert_write_allowed(
			[ [ 'id' => 'w0', 'elType' => 'widget', 'widgetType' => 'heading' ] ],
			$previous
		);

		self::assertInstanceOf( \WP_Error::class, $error );
		self::assertSame( 'stonewright_elementor_size_collapse', $error->get_error_code() );
		$data = $error->get_error_data();
		self::assertIsArray( $data );
		self::assertSame( 'elementor-no-setting-stripping', $data['rule_id'] );
	}

	public function test_unparseable_php_candidate_is_blocked_with_rule_attribution(): void {
		$error = PhpSyntaxValidator::validate_complete_file( "<?php\nfunction broken( {\n" );

		self::assertInstanceOf( \WP_Error::class, $error );
		$data = $error->get_error_data();
		self::assertIsArray( $data );
		self::assertSame( 'php-writes-must-parse', $data['rule_id'] );
		self::assertSame( 'blocked', $data['execution_status'] );
	}

	public function test_invalid_confirmation_token_is_blocked_with_rule_attribution(): void {
		$error = ConfirmationToken::verify_or_error( 'not-a-token', 'stonewright/theme-file-patch', [] );

		self::assertInstanceOf( \WP_Error::class, $error );
		$data = $error->get_error_data();
		self::assertIsArray( $data );
		self::assertSame( 'confirm-destructive-in-production-safe', $data['rule_id'] );
	}

	public function test_invalid_design_spec_is_blocked_with_rule_attribution(): void {
		$error = Validator::validate( [ 'sections' => 'not-a-list' ] );

		self::assertInstanceOf( \WP_Error::class, $error );
		self::assertSame( 'stonewright_spec_invalid', $error->get_error_code() );
		$data = $error->get_error_data();
		self::assertIsArray( $data );
		self::assertSame( 'validate-spec-before-render', $data['rule_id'] );
	}

	public function test_missing_snapshot_target_is_blocked_with_rule_attribution(): void {
		$diagnostics = [];
		$error       = ElementorWriter::write_transactional( 0, [ 'sections' => [] ], $diagnostics );

		self::assertInstanceOf( \WP_Error::class, $error );
		self::assertSame( 'stonewright_backup_failed', $error->get_error_code() );
		$data = $error->get_error_data();
		self::assertIsArray( $data );
		self::assertSame( 'backup-before-write', $data['rule_id'] );
	}
}
