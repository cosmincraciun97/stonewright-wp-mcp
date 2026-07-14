<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\Tests\Unit\ElementorWidgets;

use PHPUnit\Framework\TestCase;
use Stonewright\WpMcp\Abilities\ElementorV3\AddWidget;
use Stonewright\WpMcp\Abilities\ElementorWidgets\AddHeading;

/**
 * @covers \Stonewright\WpMcp\Abilities\ElementorV3\AddWidget
 * @covers \Stonewright\WpMcp\Abilities\ElementorWidgets\WidgetAbilityBase
 */
final class WidgetWriteGuardsTest extends TestCase {

	protected function setUp(): void {
		$GLOBALS['stonewright_test_posts'] = [
			321 => (object) [
				'ID'           => 321,
				'post_type'    => 'page',
				'post_status'  => 'draft',
				'post_title'   => 'Target',
				'post_content' => '',
				'post_excerpt' => '',
				'meta'         => [
					'_elementor_data'      => '[{"id":"root","elType":"container","settings":[],"elements":[]}]',
					'_elementor_edit_mode' => 'builder',
					'_elementor_version'   => defined( 'ELEMENTOR_VERSION' ) ? ELEMENTOR_VERSION : '3.0.0',
				],
			],
		];
		$GLOBALS['stonewright_test_post_meta_calls'] = [];
	}

	protected function tearDown(): void {
		$GLOBALS['stonewright_test_posts'] = [];
		$GLOBALS['stonewright_test_post_meta_calls'] = [];
	}

	public function test_raw_add_widget_rejects_known_widget_by_default(): void {
		$result = ( new AddWidget() )->execute(
			[
				'post_id'     => 321,
				'parent_id'   => 'root',
				'widget_type' => 'icon-box',
				'settings'    => [
					'title_text' => 'Vizibilitate',
					'icon'       => [
						'value'   => 'fas fa-eye',
						'library' => 'fa-solid',
					],
				],
			]
		);

		self::assertInstanceOf( \WP_Error::class, $result );
		self::assertSame( 'stonewright_known_widget_requires_dedicated_ability', $result->get_error_code() );
		self::assertSame( [], $GLOBALS['stonewright_test_post_meta_calls'] );
	}

	public function test_forced_raw_known_widget_still_validates_required_settings(): void {
		$result = ( new AddWidget() )->execute(
			[
				'post_id'                => 321,
				'parent_id'              => 'root',
				'widget_type'            => 'icon-box',
				'allow_raw_known_widget' => true,
				'settings'               => [
					'title_text' => 'Vizibilitate',
					'icon'       => [
						'value'   => 'fas fa-eye',
						'library' => 'fa-solid',
					],
				],
			]
		);

		self::assertInstanceOf( \WP_Error::class, $result );
		self::assertSame( 'stonewright_elementor_settings_invalid', $result->get_error_code() );

		$data = $result->get_error_data();
		self::assertIsArray( $data );
		self::assertSame( 'settings.icon', $data['violations'][0]['path'] );
		self::assertSame( 'unknown_setting', $data['violations'][0]['code'] );
	}

	public function test_dedicated_widget_adds_group_activator_for_supplied_group_subkey(): void {
		$result = ( new AddHeading() )->execute(
			[
				'post_id'   => 321,
				'parent_id' => 'root',
				'settings'  => [
					'title'                => 'Devino expozant la nZEB Expo',
					'typography_font_size' => [
						'size' => 36,
						'unit' => 'px',
					],
				],
			]
		);

		self::assertIsArray( $result );
		self::assertContains( 'typography_typography', $result['activated_groups'] );

		$post = $GLOBALS['stonewright_test_posts'][321];
		$tree = json_decode( stripslashes( (string) $post->meta['_elementor_data'] ), true );

		self::assertSame(
			'custom',
			$tree[0]['elements'][0]['settings']['typography_typography']
		);
	}
}
