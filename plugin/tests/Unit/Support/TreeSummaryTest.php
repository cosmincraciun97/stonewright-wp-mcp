<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\Tests\Unit\Support;

use PHPUnit\Framework\TestCase;
use Stonewright\WpMcp\Support\TreeSummary;

/**
 * @covers \Stonewright\WpMcp\Support\TreeSummary
 */
final class TreeSummaryTest extends TestCase {

	/**
	 * @return array<int, array<string, mixed>>
	 */
	private function sample_tree(): array {
		$settings = [];
		for ( $i = 0; $i < 40; $i++ ) {
			$settings[ 'key_' . $i ] = $i;
		}
		$settings['title'] = str_repeat( 'A', 100 );

		return [
			[
				'id'       => 'root',
				'elType'   => 'container',
				'settings' => [ '_title' => 'Root' ],
				'elements' => [
					[
						'id'         => 'child',
						'elType'     => 'widget',
						'widgetType' => 'heading',
						'settings'   => $settings,
						'elements'   => [],
					],
					[
						'id'         => 'extra',
						'elType'     => 'widget',
						'widgetType' => 'text-editor',
						'settings'   => [ 'editor' => '<p>Tagged</p>' ],
						'elements'   => [],
					],
				],
			],
		];
	}

	public function test_outline_rows_and_cap(): void {
		$result = TreeSummary::outline(
			$this->sample_tree(),
			2,
			static fn( array $element, array $ctx ): array => TreeSummary::default_row( $element, $ctx )
		);

		self::assertSame( 3, $result['count'] );
		self::assertSame( 2, $result['returned_count'] );
		self::assertTrue( $result['truncated'] );
		self::assertCount( 2, $result['outline'] );
		self::assertSame( 'root', $result['outline'][0]['id'] );
		self::assertSame( 'child', $result['outline'][1]['id'] );
		self::assertSame( 'root', $result['outline'][1]['parent_id'] );
		self::assertSame( '0.0', $result['outline'][1]['path'] );
		self::assertSame( 1, $result['outline'][1]['depth'] );
	}

	public function test_label_truncation_and_settings_keys_cap(): void {
		$result = TreeSummary::outline(
			$this->sample_tree(),
			10,
			static fn( array $element, array $ctx ): array => TreeSummary::default_row( $element, $ctx )
		);

		$child = $result['outline'][1];
		self::assertSame( 80, strlen( $child['label'] ) );
		self::assertStringEndsWith( '...', $child['label'] );
		self::assertCount( 30, $child['settings_keys'] );
		self::assertSame( 'Tagged', $result['outline'][2]['label'] );
	}

	public function test_estimated_tokens_matches_self_report_pattern(): void {
		$result = TreeSummary::outline(
			$this->sample_tree(),
			10,
			static fn( array $element, array $ctx ): array => TreeSummary::default_row( $element, $ctx )
		);

		$without = $result;
		unset( $without['estimated_tokens'] );
		$json     = (string) wp_json_encode( $without, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
		$expected = (int) ceil( strlen( $json ) / 4 );

		self::assertSame( $expected, $result['estimated_tokens'] );
		self::assertGreaterThan( 0, $result['estimated_tokens'] );
	}

	public function test_custom_row_mapper(): void {
		$result = TreeSummary::outline(
			$this->sample_tree(),
			5,
			static function ( array $element, array $ctx ): array {
				return [
					'id'   => (string) ( $ctx['id'] ?? '' ),
					'type' => (string) ( $element['elType'] ?? '' ),
				];
			}
		);

		self::assertSame(
			[
				[ 'id' => 'root', 'type' => 'container' ],
				[ 'id' => 'child', 'type' => 'widget' ],
				[ 'id' => 'extra', 'type' => 'widget' ],
			],
			$result['outline']
		);
	}
}
