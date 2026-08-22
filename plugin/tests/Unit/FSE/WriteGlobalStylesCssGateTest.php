<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\Tests\Unit\FSE;

use PHPUnit\Framework\TestCase;
use Stonewright\WpMcp\Abilities\FSE\WriteGlobalStyles;
use Stonewright\WpMcp\Security\CustomCodeGrant;
use Stonewright\WpMcp\ThemeJson\CssGrantGate;

/**
 * @covers \Stonewright\WpMcp\Abilities\FSE\WriteGlobalStyles
 * @covers \Stonewright\WpMcp\ThemeJson\CssGrantGate
 * @covers \Stonewright\WpMcp\FSE\GlobalStylesWriter
 */
final class WriteGlobalStylesCssGateTest extends TestCase {

	protected function setUp(): void {
		$GLOBALS['stonewright_test_options']         = [ 'stonewright_mode' => 'development' ];
		$GLOBALS['stonewright_test_user_caps']       = [
			'manage_options'     => true,
			'edit_theme_options' => true,
		];
		$GLOBALS['stonewright_test_user_logged_in']  = true;
		$GLOBALS['stonewright_test_current_user_id'] = 1;
		$GLOBALS['stonewright_test_posts']           = [];
		$GLOBALS['stonewright_test_post_meta_calls'] = [];
		$GLOBALS['stonewright_test_transients']      = [];
	}

	protected function tearDown(): void {
		$GLOBALS['stonewright_test_options']         = [];
		$GLOBALS['stonewright_test_user_caps']       = [];
		$GLOBALS['stonewright_test_user_logged_in']  = false;
		$GLOBALS['stonewright_test_current_user_id'] = 0;
		$GLOBALS['stonewright_test_posts']           = [];
		$GLOBALS['stonewright_test_post_meta_calls'] = [];
		$GLOBALS['stonewright_test_transients']      = [];
	}

	public function test_styles_css_without_grant_is_rejected_with_json_path(): void {
		$result = ( new WriteGlobalStyles() )->execute(
			[
				'theme_json' => [
					'version' => 3,
					'styles'  => [
						'css' => '.hero { color: red; }',
					],
				],
			]
		);

		self::assertInstanceOf( \WP_Error::class, $result );
		self::assertSame( 'stonewright_custom_code_approval_required', $result->get_error_code() );
		$data = (array) $result->get_error_data();
		self::assertContains( 'styles.css', (array) ( $data['offending_paths'] ?? [] ) );
		self::assertSame( CssGrantGate::GRANT_PATH, (string) ( $data['path'] ?? '' ) );
	}

	public function test_per_block_css_without_grant_lists_the_block_path(): void {
		$result = ( new WriteGlobalStyles() )->execute(
			[
				'theme_json' => [
					'version' => 3,
					'styles'  => [
						'blocks' => [
							'core/paragraph' => [
								'css' => 'p { letter-spacing: 0.02em; }',
							],
						],
					],
				],
			]
		);

		self::assertInstanceOf( \WP_Error::class, $result );
		self::assertSame( 'stonewright_custom_code_approval_required', $result->get_error_code() );
		self::assertContains( 'styles.blocks.core/paragraph.css', (array) ( $result->get_error_data()['offending_paths'] ?? [] ) );
	}

	public function test_styles_css_with_valid_grant_writes(): void {
		$css    = '.hero { color: navy; }';
		$issued = CustomCodeGrant::issue(
			[
				'path'         => CssGrantGate::GRANT_PATH,
				'after_sha256' => CssGrantGate::candidate_hash( [ 'styles.css' => $css ] ),
				'language'     => 'css',
			]
		);
		self::assertIsArray( $issued );

		$result = ( new WriteGlobalStyles() )->execute(
			[
				'theme_json'        => [
					'version' => 3,
					'styles'  => [
						'css' => $css,
					],
				],
				'custom_code_grant' => (string) $issued['token'],
			]
		);

		self::assertIsArray( $result );
		self::assertArrayHasKey( 'post_id', $result );
		self::assertNotSame( '', (string) ( $result['snapshot_id'] ?? '' ) );
	}

	public function test_palette_only_write_does_not_require_grant(): void {
		$result = ( new WriteGlobalStyles() )->execute(
			[
				'theme_json' => [
					'version'  => 3,
					'settings' => [
						'color' => [
							'palette' => [
								[ 'slug' => 'contrast', 'color' => '#111111', 'name' => 'Contrast' ],
							],
						],
					],
				],
			]
		);

		self::assertIsArray( $result );
		self::assertArrayHasKey( 'post_id', $result );
	}
}
