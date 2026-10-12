<?php
// SPDX-License-Identifier: GPL-2.0-or-later
declare( strict_types=1 );

namespace Stonewright\WpMcp\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Stonewright\WpMcp\Abilities\AbilityKernel;
use Stonewright\WpMcp\Security\IncidentStore;

/**
 * The audit row of a failed call keeps the real, redacted message of the failure, not only its code.
 *
 * @covers \Stonewright\WpMcp\Abilities\AbilityKernel
 * @covers \Stonewright\WpMcp\Security\AuditEvent
 */
final class AbilityKernelAuditMessageTest extends TestCase {

	protected function setUp(): void {
		$GLOBALS['stonewright_test_wpdb_inserts']    = [];
		$GLOBALS['stonewright_test_options']         = [];
		$GLOBALS['stonewright_test_current_user_id'] = 1;
		IncidentStore::reset_for_tests();
	}

	protected function tearDown(): void {
		$GLOBALS['stonewright_test_wpdb_inserts']    = [];
		$GLOBALS['stonewright_test_options']         = [];
		$GLOBALS['stonewright_test_current_user_id'] = 0;
		IncidentStore::reset_for_tests();
	}

	/** @param callable():(array<string,mixed>|\WP_Error) $outcome @return array<string, mixed> The redacted details of the audit row. */
	private function details_of( callable $outcome ): array {
		$kernel = new class( $outcome ) extends AbilityKernel {
			/** @param callable():(array<string,mixed>|\WP_Error) $outcome */
			public function __construct( private $outcome ) {}
			public function name(): string { return 'stonewright/test-audit-message'; }
			public function label(): string { return 'Audit message'; }
			public function description(): string { return 'Synthetic failures.'; }
			public function category(): string { return 'test'; }
			public function execute( array $args ): array|\WP_Error {
				return $this->audit( $args, fn() => ( $this->outcome )() );
			}
		};
		$GLOBALS['stonewright_test_wpdb_inserts'] = [];
		$kernel->execute( [ 'post_id' => 5 ] );
		self::assertNotEmpty( $GLOBALS['stonewright_test_wpdb_inserts'] );
		$details = json_decode( (string) ( $GLOBALS['stonewright_test_wpdb_inserts'][0]['data']['redacted_details'] ?? '' ), true );
		self::assertIsArray( $details );

		return $details;
	}

	public function test_a_structured_failure_without_a_message_says_which_check_failed_and_what_to_do(): void {
		$details = $this->details_of(
			static fn(): array => [
				'ok'                  => false,
				'root_error_code'     => 'stonewright_example_failed',
				'failed_check'        => 'frontend',
				'verification_status' => 'failed',
				'repair'              => 'Clear the page cache and call this ability again. A second sentence that is not needed.',
			]
		);

		self::assertSame( 'stonewright_example_failed', $details['root_error_code'] );
		self::assertStringContainsString( 'The frontend check failed.', $details['error_message'] );
		self::assertStringContainsString( 'Verification_status failed', $details['error_message'] );
		self::assertStringContainsString( 'Next: Clear the page cache and call this ability again.', $details['error_message'] );
		self::assertStringNotContainsString( 'second sentence', $details['error_message'] );
		self::assertStringNotContainsString( 'The call failed', $details['error_message'] );
	}

	public function test_a_failed_verification_without_ok_false_still_gets_its_message(): void {
		$details = $this->details_of(
			static fn(): array => [
				'ok'                  => true,
				'effect_verified'     => false,
				'verification_status' => 'failed',
				'failed_check'        => 'readback',
			]
		);

		self::assertStringContainsString( 'The readback check failed.', $details['error_message'] );
	}

	public function test_a_failed_step_names_its_message(): void {
		$details = $this->details_of(
			static fn(): array => [
				'ok'    => false,
				'steps' => [
					[ 'id' => 'plan', 'status' => 'ok' ],
					[ 'id' => 'apply', 'status' => 'failed', 'message' => 'The page is locked by another write.' ],
				],
			]
		);

		self::assertSame( 'Step apply failed: The page is locked by another write.', $details['error_message'] );
	}

	public function test_an_error_without_a_message_takes_the_reason_and_the_rejected_path_from_its_data(): void {
		$details = $this->details_of(
			static fn(): \WP_Error => new \WP_Error(
				'stonewright_example_invalid',
				'',
				[ 'reason' => 'The value does not fit the control.', 'violations' => [ [ 'path' => 'settings.title_color', 'code' => 'invalid_shape' ] ] ]
			)
		);

		self::assertSame( 'The value does not fit the control. Rejected settings.title_color (invalid_shape).', $details['error_message'] );
		self::assertSame( 'stonewright_example_invalid', $details['error_code'] );
	}

	public function test_the_whole_human_message_of_an_error_is_kept_up_to_the_limit(): void {
		$message = 'Elementor batch validation failed for 2 operation(s). No page data was written. ' . str_repeat( 'Detail sentence about one setting. ', 20 );
		$details = $this->details_of( static fn(): \WP_Error => new \WP_Error( 'stonewright_batch_operation_failed', $message, [ 'status' => 400 ] ) );

		self::assertStringStartsWith( 'Elementor batch validation failed for 2 operation(s). No page data was written. Detail sentence', $details['error_message'] );
		self::assertGreaterThanOrEqual( 490, mb_strlen( $details['error_message'] ) );
		self::assertLessThanOrEqual( 500, mb_strlen( $details['error_message'] ) );
	}

	public function test_a_secret_in_the_message_is_redacted_in_the_row(): void {
		$details = $this->details_of(
			static fn(): \WP_Error => new \WP_Error( 'stonewright_example_failed', 'The request failed with token=fixture-token-abcdef123456 and Authorization: Bearer fixture-bearer-abcdef123456.' )
		);

		self::assertStringNotContainsString( 'fixture-token-abcdef123456', $details['error_message'] );
		self::assertStringNotContainsString( 'fixture-bearer-abcdef123456', $details['error_message'] );
		self::assertStringContainsString( 'The request failed with', $details['error_message'] );
	}

	public function test_a_derived_message_is_redacted_too(): void {
		$details = $this->details_of(
			static fn(): array => [ 'ok' => false, 'repair' => 'Retry with token=fixture-token-abcdef123456 set.' ]
		);

		self::assertStringNotContainsString( 'fixture-token-abcdef123456', $details['error_message'] );
		self::assertStringContainsString( 'Next: Retry with', $details['error_message'] );
	}
}
