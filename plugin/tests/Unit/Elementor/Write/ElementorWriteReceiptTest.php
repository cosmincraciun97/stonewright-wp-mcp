<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\Tests\Unit\Elementor\Write;

use PHPUnit\Framework\TestCase;
use Stonewright\WpMcp\Elementor\Write\ElementorWriteReceipt;

/**
 * @covers \Stonewright\WpMcp\Elementor\Write\ElementorWriteReceipt
 */
final class ElementorWriteReceiptTest extends TestCase {

	public function test_receipt_is_bounded_and_hashes_are_validated(): void {
		$receipt = new ElementorWriteReceipt( 42, 'v3', [ 'hero', 'hero' ], false, 'change-42' );
		$receipt
			->set_hashes( str_repeat( 'a', 64 ), 'bad', str_repeat( 'b', 64 ), str_repeat( 'c', 64 ) )
			->set_lock( [ 'status' => 'busy', 'fingerprint' => str_repeat( 'd', 64 ), 'age_seconds' => 12, 'retry_after' => 8 ] )
			->set( 'client_secret', 'must-not-appear' )
			->set( 'architecture_digest', [ 'architecture' => 'v3', 'nested' => [ 'safe' => 'value' ] ] );

		$data = $receipt->to_array();
		self::assertSame( 'change-42', $data['change_set_id'] );
		self::assertSame( '', $data['planned_hash'] );
		self::assertSame( str_repeat( 'a', 64 ), $data['before_hash'] );
		self::assertSame( str_repeat( 'd', 64 ), $data['lock']['fingerprint'] );
		self::assertArrayNotHasKey( 'client_secret', $data );
		self::assertSame( [ 'architecture' => 'v3' ], $data['architecture_digest'] );
	}

	public function test_failure_and_rollback_leave_a_machine_readable_recovery_record(): void {
		$receipt = new ElementorWriteReceipt( 42, 'mixed', [ 'hero' ], false, 'change-42' );
		$receipt->rollback( 'succeeded', [ 'snapshot_id' => 'snapshot-1', 'confirmation_token' => 'hidden' ] );
		$receipt->fail( new \WP_Error( 'readback_mismatch', 'Readback mismatch.', [ 'retryable' => true, 'retry_after_seconds' => 7 ] ), 'verify.readback' );

		$data = $receipt->to_array();
		self::assertSame( 'readback_mismatch', $data['root_error_code'] );
		self::assertSame( 'verify.readback', $data['root_error_path'] );
		self::assertTrue( $data['retryable'] );
		self::assertSame( 7, $data['retry_after_seconds'] );
		self::assertSame( 'succeeded', $data['rollback_status'] );
		self::assertSame( [ 'snapshot_id' => 'snapshot-1' ], $data['recovery'] );
	}
}
