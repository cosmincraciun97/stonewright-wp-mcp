<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\Tests\Unit\Design;

use PHPUnit\Framework\TestCase;
use Stonewright\WpMcp\Abilities\Design\DirectionBrief;
use Stonewright\WpMcp\Design\Direction\DesignDirectionService;

/**
 * @covers \Stonewright\WpMcp\Abilities\Design\DirectionBrief
 */
final class DirectionBriefTest extends TestCase {

	protected function setUp(): void {
		$GLOBALS['stonewright_test_options'] = [ DesignDirectionService::ACTIVE_OPTION => 73 ];

		global $wpdb;
		$document = 'A calm editorial system with restrained spacing.';
		$contract = [
			'tokens'    => [ 'spacing' => [ 'section' => '88px' ] ],
			'dials'     => [ 'variance' => 80, 'density' => 25, 'motion' => 10 ],
			'guidance'  => [ 'do' => [ 'Use strong editorial hierarchy.' ], 'avoid' => [] ],
			'waivers'   => [],
			'readiness' => [ 'ready' => true, 'sync_ready' => false, 'issues' => [] ],
		];
		$wpdb->direction_rows = [
			73 => [
				'id'               => 73,
				'slug'             => 'quarry',
				'status'           => 'ready',
				'contract_json'    => (string) wp_json_encode( $contract ),
				'contract_hash'    => DesignDirectionService::hash( $contract ),
				'source_type'      => 'import',
				'source_refs_json' => (string) wp_json_encode( [ 'rationale' => $document ] ),
				'revision'         => 3,
				'created_at'       => '2026-07-01 00:00:00',
				'updated_at'       => '2026-07-02 00:00:00',
			],
		];
	}

	protected function tearDown(): void {
		$GLOBALS['stonewright_test_options'] = [];
		global $wpdb;
		$wpdb->direction_rows = [];
	}

	public function test_returns_compact_ready_elementor_guidance_without_full_contract(): void {
		$result = ( new DirectionBrief() )->execute( [] );

		self::assertIsArray( $result );
		self::assertTrue( $result['active'] );
		self::assertTrue( $result['ready'] );
		self::assertSame( 'asymmetric_preferred', $result['brief']['elementor_guidance']['variance']['layout_rhythm'] );
		self::assertSame( 'blocked', $result['brief']['elementor_guidance']['motion']['motion_fx'] );
		self::assertSame( '88px', $result['brief']['tokens']['spacing']['section'] );
		self::assertArrayNotHasKey( 'contract', $result['brief'] );
		self::assertArrayNotHasKey( 'document', $result );
	}

	public function test_returns_bounded_sanitized_imported_document_on_demand(): void {
		global $wpdb;
		$document = '<strong>Safe design rationale.</strong> ' . str_repeat( 'x', 40000 );
		$sanitized = wp_strip_all_tags( $document );
		$wpdb->direction_rows[73]['source_refs_json'] = (string) wp_json_encode( [ 'rationale' => $document ] );

		$result = ( new DirectionBrief() )->execute( [ 'include_document' => true ] );

		self::assertIsArray( $result );
		self::assertSame( substr( $sanitized, 0, 32768 ), $result['document'] );
		self::assertSame( 32768, strlen( $result['document'] ) );
	}

	public function test_reports_an_empty_brief_when_no_direction_is_active(): void {
		$GLOBALS['stonewright_test_options'][ DesignDirectionService::ACTIVE_OPTION ] = 0;

		$result = ( new DirectionBrief() )->execute( [] );

		self::assertIsArray( $result );
		self::assertFalse( $result['active'] );
		self::assertFalse( $result['ready'] );
		self::assertInstanceOf( \stdClass::class, $result['brief'] );
	}
}
