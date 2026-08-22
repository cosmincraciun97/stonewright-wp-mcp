<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\Tests\Unit\FSE;

use PHPUnit\Framework\TestCase;
use Stonewright\WpMcp\Abilities\FSE\GetThemeJson;

/**
 * @covers \Stonewright\WpMcp\Abilities\FSE\GetThemeJson
 */
final class GetThemeJsonTest extends TestCase {

	protected function setUp(): void {
		$GLOBALS['stonewright_test_user_caps']      = [ 'edit_theme_options' => true ];
		$GLOBALS['stonewright_test_user_logged_in'] = true;
		$GLOBALS['stonewright_test_stylesheet']     = 'twentytwentyfour';
	}

	protected function tearDown(): void {
		$GLOBALS['stonewright_test_user_caps']      = [];
		$GLOBALS['stonewright_test_user_logged_in'] = false;
		unset( $GLOBALS['stonewright_test_stylesheet'] );
	}

	public function test_omitted_response_mode_defaults_to_summary_without_merged_json(): void {
		$result = ( new GetThemeJson() )->execute( [] );

		self::assertIsArray( $result );
		self::assertSame( 'summary', $result['response_mode'] );
		self::assertArrayHasKey( 'merged_keys', $result );
		self::assertArrayHasKey( 'settings_keys', $result );
		self::assertArrayNotHasKey( 'merged', $result );
		self::assertArrayNotHasKey( 'theme', $result );
		self::assertArrayNotHasKey( 'user', $result );
		self::assertSame( 'twentytwentyfour', $result['theme_slug'] );
	}

	public function test_full_response_mode_restores_merged_theme_and_user_payloads(): void {
		$result = ( new GetThemeJson() )->execute( [ 'responseMode' => 'full' ] );

		self::assertIsArray( $result );
		self::assertSame( 'full', $result['response_mode'] );
		self::assertArrayHasKey( 'merged', $result );
		self::assertArrayHasKey( 'theme', $result );
		self::assertArrayHasKey( 'user', $result );
		self::assertIsArray( $result['merged'] );
	}
}
