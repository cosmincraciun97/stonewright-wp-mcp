<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\Tests\Unit\Security;

use PHPUnit\Framework\TestCase;
use Stonewright\WpMcp\Security\AuditEvent;
use Stonewright\WpMcp\Security\VerifiedRepairReceipt;

/** @covers \Stonewright\WpMcp\Security\VerifiedRepairReceipt */
final class VerifiedRepairReceiptTest extends TestCase {

	public function test_builds_receipt_from_strictly_correlated_persisted_events(): void {
		$receipt = VerifiedRepairReceipt::from_events(
			$this->incident(),
			$this->failure(),
			$this->success(),
			'Read the live control schema, replace only the rejected value, then verify saved controls.'
		);

		self::assertIsArray( $receipt );
		self::assertSame( 'verified', $receipt['verification_status'] );
		self::assertTrue( $receipt['effect_verified'] );
		self::assertTrue( $receipt['learning_eligible'] );
		self::assertSame( hash( 'sha256', 'resource' ), $receipt['resource_key_hash'] );
		self::assertMatchesRegularExpression( '/^[a-f0-9]{64}$/', $receipt['repair_receipt_id'] );
		self::assertArrayNotHasKey( 'redacted_details', $receipt );
	}

	/** @dataProvider invalidCorrelationProvider */
	public function test_rejects_missing_or_mismatched_correlation( string $field, mixed $value ): void {
		$success = $this->success();
		$success[ $field ] = $value;

		$result = VerifiedRepairReceipt::from_events( $this->incident(), $this->failure(), $success, 'Read schema, replace rejected field, verify readback.' );

		self::assertInstanceOf( \WP_Error::class, $result );
		self::assertSame( 'stonewright_repair_uncorrelated', $result->get_error_code() );
	}

	/** @return array<string, array{string, mixed}> */
	public static function invalidCorrelationProvider(): array {
		return [
			'wrong outcome'       => [ 'outcome', AuditEvent::OUTCOME_FAILED ],
			'not verified'        => [ 'verification_status', 'pending' ],
			'effect not verified' => [ 'effect_verified', false ],
			'missing change set'  => [ 'change_set_id', '' ],
			'wrong change set'    => [ 'change_set_id', 'change-set-b' ],
			'missing resource'    => [ 'resource_key_hash', '' ],
			'wrong resource'      => [ 'resource_key_hash', hash( 'sha256', 'other' ) ],
			'wrong path'          => [ 'normalized_path', 'elementor/other/settings' ],
			'wrong verifier'      => [ 'ability', 'stonewright/other-verifier' ],
			'older success'       => [ 'recorded_at', '2026-08-12 09:59:59' ],
		];
	}

	public function test_empty_recipe_allows_resolution_but_not_learning(): void {
		$receipt = VerifiedRepairReceipt::from_events( $this->incident(), $this->failure(), $this->success(), '' );

		self::assertIsArray( $receipt );
		self::assertFalse( $receipt['learning_eligible'] );
		self::assertSame( '', $receipt['repair_recipe'] );
	}

	/** @dataProvider unsafeRecipeProvider */
	public function test_rejects_unsafe_recipe( string $recipe ): void {
		$result = VerifiedRepairReceipt::from_events( $this->incident(), $this->failure(), $this->success(), $recipe );

		self::assertInstanceOf( \WP_Error::class, $result );
		self::assertSame( 'stonewright_repair_recipe_unsafe', $result->get_error_code() );
	}

	/** @return array<string, array{string}> */
	public static function unsafeRecipeProvider(): array {
		return [
			'url'         => [ 'Open https://example.com and retry the write.' ],
			'email'       => [ 'Use editor@example.com, then retry and verify.' ],
			'filesystem'  => [ 'Patch /var/www/html/wp-content/file.php and retry.' ],
			'secret'      => [ 'Use Authorization: Bearer secret-token-value and retry.' ],
			'resource id' => [ 'Update post ID 4821, then verify the saved field.' ],
			'too vague'   => [ 'Retry it.' ],
			'too long'    => [ str_repeat( 'Read schema and verify field. ', 40 ) ],
		];
	}

	public function test_expected_security_and_auth_events_never_become_learning(): void {
		foreach ( [ AuditEvent::CATEGORY_AUTH, AuditEvent::CATEGORY_SAFETY ] as $category ) {
			$incident = $this->incident();
			$incident['category'] = $category;
			$result = VerifiedRepairReceipt::from_events( $incident, $this->failure(), $this->success(), 'Read schema, replace rejected field, verify readback.' );

			self::assertInstanceOf( \WP_Error::class, $result );
			self::assertSame( 'stonewright_repair_not_learnable', $result->get_error_code() );
		}
	}

	/** @return array<string, mixed> */
	private function incident(): array {
		return [
			'incident_id'       => hash( 'sha256', 'incident' ),
			'category'          => AuditEvent::CATEGORY_VALIDATION,
			'ability_family'    => 'elementor-v3',
			'root_error_code'   => 'stonewright_elementor_settings_invalid',
			'expected_verifier' => 'stonewright/elementor-post-write-verify',
			'last_seen'         => '2026-08-12 10:00:00',
		];
	}

	/** @return array<string, mixed> */
	private function failure(): array {
		return [
			'event_id'          => '11111111-1111-4111-8111-111111111111',
			'outcome'           => AuditEvent::OUTCOME_FAILED,
			'change_set_id'     => 'change-set-a',
			'resource_key_hash' => hash( 'sha256', 'resource' ),
			'normalized_path'   => 'elementor/widget/settings',
			'recorded_at'       => '2026-08-12 10:00:00',
		];
	}

	/** @return array<string, mixed> */
	private function success(): array {
		return [
			'event_id'            => '22222222-2222-4222-8222-222222222222',
			'ability'             => 'stonewright/elementor-post-write-verify',
			'outcome'             => AuditEvent::OUTCOME_SUCCESS,
			'verification_status' => 'verified',
			'effect_verified'     => true,
			'change_set_id'       => 'change-set-a',
			'resource_key_hash'   => hash( 'sha256', 'resource' ),
			'normalized_path'     => 'elementor/widget/settings',
			'after_sha256'        => hash( 'sha256', 'after' ),
			'recorded_at'         => '2026-08-12 10:01:00',
		];
	}
}
