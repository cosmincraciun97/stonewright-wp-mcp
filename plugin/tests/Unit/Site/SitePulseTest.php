<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\Tests\Unit\Site;

use PHPUnit\Framework\TestCase;
use Stonewright\WpMcp\Abilities\Site\SitePulse;
use Stonewright\WpMcp\Core\AbilityRegistry;

/**
 * @covers \Stonewright\WpMcp\Abilities\Site\SitePulse
 */
final class SitePulseTest extends TestCase {

	protected function setUp(): void {
		$GLOBALS['stonewright_test_user_logged_in'] = true;
		$GLOBALS['stonewright_test_user_caps']      = [ 'manage_options' => true ];
		$GLOBALS['stonewright_test_options']        = [ 'stonewright_mode' => 'development' ];
		$GLOBALS['stonewright_test_autoload_bytes'] = 100_000;
		$GLOBALS['stonewright_test_admin_count']    = 1;
		$GLOBALS['stonewright_test_plugin_updates'] = 0;
		$GLOBALS['stonewright_test_orphan_postmeta'] = 0;
	}

	protected function tearDown(): void {
		unset(
			$GLOBALS['stonewright_test_autoload_bytes'],
			$GLOBALS['stonewright_test_admin_count'],
			$GLOBALS['stonewright_test_plugin_updates'],
			$GLOBALS['stonewright_test_orphan_postmeta']
		);
		$GLOBALS['stonewright_test_user_caps']      = [];
		$GLOBALS['stonewright_test_user_logged_in'] = false;
		$GLOBALS['stonewright_test_options']        = [];
	}

	public function test_registry_exposes_site_pulse(): void {
		$ability = AbilityRegistry::ability_by_name( 'stonewright/site-pulse' );
		self::assertInstanceOf( SitePulse::class, $ability );
		self::assertSame( 'site', $ability->category() );
	}

	public function test_permission_requires_manage_options(): void {
		$ability = new SitePulse();
		self::assertTrue( $ability->permission_callback( [] ) );

		$GLOBALS['stonewright_test_user_caps'] = [ 'edit_posts' => true ];
		self::assertFalse( $ability->permission_callback( [] ) );
	}

	public function test_returns_score_grade_and_top_fixes(): void {
		$GLOBALS['stonewright_test_autoload_bytes']  = 3 * 1024 * 1024;
		$GLOBALS['stonewright_test_admin_count']     = 6;
		$GLOBALS['stonewright_test_plugin_updates']  = 4;
		$GLOBALS['stonewright_test_orphan_postmeta'] = 250;

		$result = ( new SitePulse() )->execute( [] );

		self::assertIsArray( $result );
		self::assertArrayHasKey( 'score', $result );
		self::assertArrayHasKey( 'grade', $result );
		self::assertArrayHasKey( 'fixes', $result );
		self::assertArrayHasKey( 'checks', $result );
		self::assertArrayHasKey( 'summary', $result );
		self::assertIsInt( $result['score'] );
		self::assertGreaterThanOrEqual( 0, $result['score'] );
		self::assertLessThanOrEqual( 100, $result['score'] );
		self::assertLessThanOrEqual( 5, count( $result['fixes'] ) );
		self::assertNotEmpty( $result['fixes'] );

		foreach ( $result['fixes'] as $fix ) {
			self::assertArrayHasKey( 'id', $fix );
			self::assertArrayHasKey( 'fix', $fix );
			self::assertArrayHasKey( 'points', $fix );
		}

		$summary = $result['summary'];
		self::assertSame( PHP_VERSION, $summary['php_version'] );
		self::assertArrayHasKey( 'object_cache', $summary );
		self::assertArrayHasKey( 'plugin_updates', $summary );
	}

	public function test_healthy_fixture_scores_high(): void {
		// Default fixture: HTTPS, 1 admin, no updates, small autoload.
		// May still lose points for object cache / file edit / debug depending on env.
		$result = ( new SitePulse() )->execute( [] );
		self::assertGreaterThanOrEqual( 50, $result['score'] );
		self::assertMatchesRegularExpression( '/^[A-F]$/', $result['grade'] );
	}
}
