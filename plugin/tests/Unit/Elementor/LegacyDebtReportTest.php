<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\Tests\Unit\Elementor;

use PHPUnit\Framework\TestCase;
use Stonewright\WpMcp\Abilities\ElementorV3\LegacyDebtReport;

/** @covers \Stonewright\WpMcp\Abilities\ElementorV3\LegacyDebtReport */
final class LegacyDebtReportTest extends TestCase {

	protected function setUp(): void {
		$GLOBALS['stonewright_test_posts'] = [
			77 => (object) [
				'ID'          => 77,
				'post_type'   => 'page',
				'post_status' => 'publish',
				'post_author' => 17,
				'meta'        => [
					'_elementor_version' => '3.21.0',
					'_elementor_data'    => (string) wp_json_encode( [
						[
							'id'         => 'button-1',
							'elType'     => 'widget',
							'widgetType' => 'button',
							'settings'   => [ 'legacy_unknown_control' => 'preserve-me' ],
							'elements'   => [],
						],
					] ),
				],
			],
		];
		$GLOBALS['stonewright_test_user_caps'] = [ 'edit_post' => true ];
	}

	protected function tearDown(): void {
		$GLOBALS['stonewright_test_posts'] = [];
		$GLOBALS['stonewright_test_user_caps'] = [];
	}

	public function test_reports_bounded_legacy_debt_without_writing_or_normalizing(): void {
		$result = ( new LegacyDebtReport() )->execute( [ 'post_id' => 77 ] );

		self::assertIsArray( $result );
		self::assertSame( 77, $result['post_id'] );
		self::assertSame( '3.21.0', $result['schema_version'] );
		self::assertIsInt( $result['invalid_paths_count'] );
		self::assertIsArray( $result['issues'] );
		self::assertFalse( $result['write_performed'] );
		self::assertArrayHasKey( 'required_approval', $result );
	}
}
