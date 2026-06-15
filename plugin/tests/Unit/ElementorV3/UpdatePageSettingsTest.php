<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\Tests\Unit\ElementorV3;

use PHPUnit\Framework\TestCase;
use Stonewright\WpMcp\Abilities\ElementorV3\UpdatePageSettings;

/**
 * @covers \Stonewright\WpMcp\Abilities\ElementorV3\UpdatePageSettings
 */
final class UpdatePageSettingsTest extends TestCase {

	protected function setUp(): void {
		$GLOBALS['stonewright_test_options'] = [];
		$GLOBALS['stonewright_test_posts']   = [
			10 => (object) [
				'ID'           => 10,
				'post_type'    => 'page',
				'post_status'  => 'publish',
				'post_title'   => 'Settings Page',
				'post_content' => '',
				'post_excerpt' => '',
				'post_parent'  => 0,
				'post_name'    => 'settings-page',
				'meta'         => [
					'_elementor_page_settings' => [
						'custom_css' => '.elementor-10{overflow-x:hidden;}',
					],
				],
			],
		];
		unset( $GLOBALS['stonewright_test_update_post_meta_return'] );
	}

	protected function tearDown(): void {
		$GLOBALS['stonewright_test_options'] = [];
		$GLOBALS['stonewright_test_posts']   = [];
		unset( $GLOBALS['stonewright_test_update_post_meta_return'] );
	}

	public function test_idempotent_same_settings_are_successful_when_update_post_meta_returns_false(): void {
		$GLOBALS['stonewright_test_update_post_meta_return'] = false;

		$result = ( new UpdatePageSettings() )->execute(
			[
				'post_id'  => 10,
				'settings' => [
					'custom_css' => '.elementor-10{overflow-x:hidden;}',
				],
				'mode'     => 'merge',
			]
		);

		self::assertIsArray( $result );
		self::assertSame( 10, $result['post_id'] );
		self::assertNotEmpty( $result['snapshot_id'] );
	}

	public function test_changed_settings_still_report_write_failure_when_update_post_meta_returns_false(): void {
		$GLOBALS['stonewright_test_update_post_meta_return'] = false;

		$result = ( new UpdatePageSettings() )->execute(
			[
				'post_id'  => 10,
				'settings' => [
					'custom_css' => '.elementor-10{overflow-x:auto;}',
				],
				'mode'     => 'merge',
			]
		);

		self::assertInstanceOf( \WP_Error::class, $result );
		self::assertSame( 'stonewright_write_failed', $result->get_error_code() );
	}
}
