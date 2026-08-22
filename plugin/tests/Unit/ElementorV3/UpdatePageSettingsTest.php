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
						'hide_title' => 'yes',
					],
				],
			],
		];
		unset( $GLOBALS['stonewright_test_update_post_meta_return'] );
		unset( $GLOBALS['stonewright_test_update_post_meta_returns'] );
	}

	protected function tearDown(): void {
		$GLOBALS['stonewright_test_options'] = [];
		$GLOBALS['stonewright_test_posts']   = [];
		unset( $GLOBALS['stonewright_test_update_post_meta_return'] );
		unset( $GLOBALS['stonewright_test_update_post_meta_returns'] );
	}

	public function test_custom_css_requires_custom_code_approval(): void {
		$result = ( new UpdatePageSettings() )->execute(
			[
				'post_id'  => 10,
				'settings' => [
					'custom_css' => '.elementor-10{overflow-x:hidden;}',
				],
				'mode'     => 'merge',
			]
		);

		self::assertInstanceOf( \WP_Error::class, $result );
		self::assertSame( 'stonewright_custom_code_approval_required', $result->get_error_code() );
		self::assertSame( 'custom_css', $result->get_error_data()['offending_key'] );
		self::assertSame( 'stonewright/theme-custom-css', $result->get_error_data()['gated_tool'] );
	}

	public function test_idempotent_same_settings_are_successful_when_update_post_meta_returns_false(): void {
		$GLOBALS['stonewright_test_update_post_meta_returns'] = [ '_elementor_page_settings' => false ];

		$result = ( new UpdatePageSettings() )->execute(
			[
				'post_id'  => 10,
				'settings' => [
					'hide_title' => 'yes',
				],
				'mode'     => 'merge',
			]
		);

		self::assertIsArray( $result );
		self::assertSame( 10, $result['post_id'] );
		self::assertNotEmpty( $result['snapshot_id'] );
	}

	public function test_changed_settings_still_report_write_failure_when_update_post_meta_returns_false(): void {
		$GLOBALS['stonewright_test_update_post_meta_returns'] = [ '_elementor_page_settings' => false ];

		$result = ( new UpdatePageSettings() )->execute(
			[
				'post_id'  => 10,
				'settings' => [
					'hide_title' => 'no',
				],
				'mode'     => 'merge',
			]
		);

		self::assertInstanceOf( \WP_Error::class, $result );
		self::assertSame( 'stonewright_write_failed', $result->get_error_code() );
	}
}
