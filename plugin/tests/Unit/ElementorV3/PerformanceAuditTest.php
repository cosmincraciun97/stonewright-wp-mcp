<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\Tests\Unit\ElementorV3;

use PHPUnit\Framework\TestCase;
use Stonewright\WpMcp\Abilities\ElementorV3\PerformanceAudit;
use Stonewright\WpMcp\Abilities\System\ToolProfile;
use Stonewright\WpMcp\Core\AbilityRegistry;

/**
 * @covers \Stonewright\WpMcp\Abilities\ElementorV3\PerformanceAudit
 */
final class PerformanceAuditTest extends TestCase {

	private const EXPECTED_KEYS = [
		'post_id',
		'elementor_data_bytes',
		'node_count',
		'widget_count',
		'container_count',
		'max_depth',
		'architecture',
		'e_paragraph_count',
		'nested_widget_counts',
		'settings_key_count',
		'responsive_settings_count',
		'empty_setting_count',
		'invalid_setting_sample',
		'stonewright_backups',
		'revision_count',
		'largest_meta_keys',
		'warnings',
	];

	protected function setUp(): void {
		$GLOBALS['stonewright_test_user_caps'] = [ 'edit_post' => true ];
		$GLOBALS['stonewright_test_posts']     = [];
		$GLOBALS['stonewright_test_revisions'] = [];
	}

	protected function tearDown(): void {
		$GLOBALS['stonewright_test_user_caps'] = [];
		$GLOBALS['stonewright_test_posts']     = [];
		$GLOBALS['stonewright_test_revisions'] = [];
	}

	public function test_performance_audit_is_registered(): void {
		self::assertContains( PerformanceAudit::class, AbilityRegistry::list() );
		self::assertContains( 'stonewright/elementor-performance-audit', ToolProfile::profile_tools( 'elementor-design' ) );
	}

	public function test_report_is_bounded_and_content_free(): void {
		$children = [];
		for ( $index = 0; $index < 4; ++$index ) {
			$children[] = [
				'id'         => 'atomic-' . (string) $index,
				'elType'     => 'widget',
				'widgetType' => 'e-paragraph',
				'settings'   => [ 'content' => 'Private paragraph SECRET-CONTENT' ],
				'elements'   => [],
			];
		}
		$children[] = [
			'id'         => 'nested-acc',
			'elType'     => 'widget',
			'widgetType' => 'nested-accordion',
			'settings'   => [
				'title'        => '',
				'gap_tablet'   => [ 'size' => 12, 'unit' => 'px' ],
				'items'        => [],
			],
			'elements'   => [
				[
					'id'         => 'heading-1',
					'elType'     => 'widget',
					'widgetType' => 'heading',
					'settings'   => [ 'title' => 'Private heading SECRET-CONTENT' ],
					'elements'   => [],
				],
			],
		];
		$children[] = [
			'id'         => 'invalid-0',
			'elType'     => 'widget',
			'widgetType' => 'third-party-card',
			'settings'   => [ 'flex_gap' => [ 'unit' => '%' ] ],
			'elements'   => [],
		];
		$tree = [
			[
				'id'       => 'legacy-root',
				'elType'   => 'container',
				'settings' => [ 'container_type' => 'flex' ],
				'elements' => $children,
			],
		];
		$raw = (string) wp_json_encode( $tree, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
		$backups = [
			'snap_a' => [ 'snapshot_id' => 'snap_a', 'meta' => [ '_elementor_data' => $raw ] ],
			'snap_b' => [ 'snapshot_id' => 'snap_b', 'meta' => [ '_elementor_data' => $raw ] ],
		];
		$GLOBALS['stonewright_test_posts'][9050] = (object) [
			'ID'   => 9050,
			'meta' => [
				'_elementor_data'        => $raw,
				'_elementor_edit_mode'   => 'builder',
				'_stonewright_backups'   => $backups,
				'_synthetic_large_blob'  => str_repeat( 'x', 2048 ),
			],
		];
		$GLOBALS['stonewright_test_revisions'][9050] = [
			[ 'ID' => 501, 'post_parent' => 9050 ],
			[ 'ID' => 502, 'post_parent' => 9050 ],
			[ 'ID' => 503, 'post_parent' => 9050 ],
		];

		$result = ( new PerformanceAudit() )->execute( [ 'post_id' => 9050 ] );

		self::assertIsArray( $result );
		foreach ( self::EXPECTED_KEYS as $key ) {
			self::assertArrayHasKey( $key, $result );
		}
		self::assertSame( array_values( self::EXPECTED_KEYS ), array_keys( $result ) );
		self::assertSame( 9050, $result['post_id'] );
		self::assertSame( strlen( $raw ), $result['elementor_data_bytes'] );
		self::assertSame( 8, $result['node_count'] );
		self::assertSame( 7, $result['widget_count'] );
		self::assertSame( 1, $result['container_count'] );
		self::assertSame( 3, $result['max_depth'] );
		self::assertSame(
			[
				'v3_nodes'        => 4,
				'v4_atomic_nodes' => 4,
				'mixed'           => true,
			],
			$result['architecture']
		);
		self::assertSame( 4, $result['e_paragraph_count'] );
		self::assertSame( 1, $result['nested_widget_counts']['nested-accordion'] );
		self::assertSame( 0, $result['nested_widget_counts']['nested-tabs'] );
		self::assertSame( 2, $result['stonewright_backups']['snapshot_count'] );
		self::assertGreaterThan( 0, $result['stonewright_backups']['serialized_bytes'] );
		self::assertSame( 3, $result['revision_count'] );
		self::assertGreaterThan( 0, $result['invalid_setting_sample']['count'] );
		self::assertContains( 'flex_gap', $result['invalid_setting_sample']['sample_keys'] );
		self::assertLessThanOrEqual( 20, count( $result['invalid_setting_sample']['sample_keys'] ) );
		self::assertLessThanOrEqual( 10, count( $result['largest_meta_keys'] ) );
		$largest_keys = array_column( $result['largest_meta_keys'], 'key' );
		self::assertContains( '_synthetic_large_blob', $largest_keys );
		foreach ( $result['largest_meta_keys'] as $row ) {
			self::assertArrayHasKey( 'key', $row );
			self::assertArrayHasKey( 'serialized_bytes', $row );
			self::assertArrayNotHasKey( 'value', $row );
		}
		self::assertContains( 'mixed_architecture', $result['warnings'] );
		self::assertArrayNotHasKey( 'tree', $result );
		$encoded = (string) wp_json_encode( $result );
		self::assertStringNotContainsString( 'SECRET-CONTENT', $encoded );
		self::assertStringNotContainsString( 'Private paragraph', $encoded );
		self::assertStringNotContainsString( 'Private heading', $encoded );
		self::assertStringNotContainsString( str_repeat( 'x', 64 ), $encoded );
	}

	public function test_missing_post_returns_not_found(): void {
		$result = ( new PerformanceAudit() )->execute( [ 'post_id' => 999 ] );

		self::assertInstanceOf( \WP_Error::class, $result );
		self::assertSame( 'stonewright_not_found', $result->get_error_code() );
	}
}
