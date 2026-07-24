<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\Tests\Unit\Knowledge;

use PHPUnit\Framework\TestCase;
use Stonewright\WpMcp\Knowledge\Lifecycle\SchemaRepairIncidentStore;

/**
 * @covers \Stonewright\WpMcp\Knowledge\Lifecycle\SchemaRepairIncidentStore
 */
final class SchemaRepairLearningTest extends TestCase {

	protected function setUp(): void {
		$GLOBALS['stonewright_test_options'] = [];
	}

	protected function tearDown(): void {
		$GLOBALS['stonewright_test_options'] = [];
	}

	public function test_incidents_are_bounded_per_runtime_and_store_no_attempted_values(): void {
		for ( $index = 0; $index < 25; ++$index ) {
			SchemaRepairIncidentStore::record(
				[
					'widget'              => 'loop-grid',
					'control'             => 'control_' . $index,
					'expected_type'       => 'number',
					'received_type'       => 'object',
					'schema_hash'         => str_repeat( 'a', 64 ),
					'runtime_fingerprint' => str_repeat( 'b', 64 ),
					'task_hash'           => str_repeat( 'c', 64 ),
				]
			);
		}

		$stored = (array) get_option( 'stonewright_schema_repair_incidents', [] );
		self::assertCount( 20, $stored );
		self::assertStringNotContainsString( 'size', wp_json_encode( $stored ) );
	}
}
