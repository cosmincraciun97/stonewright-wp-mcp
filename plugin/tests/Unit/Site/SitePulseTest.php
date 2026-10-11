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

	private mixed $original_wpdb = null;

	protected function setUp(): void {
		$GLOBALS['stonewright_test_user_logged_in'] = true;
		$GLOBALS['stonewright_test_user_caps']      = [ 'manage_options' => true ];
		$GLOBALS['stonewright_test_options']        = [ 'stonewright_mode' => 'development' ];
		SitePulse::use_metrics(
			[
				'autoload_bytes'  => 100_000,
				'admin_count'     => 1,
				'plugin_updates'  => 0,
				'orphan_postmeta' => 0,
			]
		);
		$this->original_wpdb = $GLOBALS['wpdb'] ?? null;
	}

	protected function tearDown(): void {
		SitePulse::use_metrics( null );
		if ( null !== $this->original_wpdb ) {
			$GLOBALS['wpdb'] = $this->original_wpdb;
		}
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
		SitePulse::use_metrics(
			[
				'autoload_bytes'  => 3 * 1024 * 1024,
				'admin_count'     => 6,
				'plugin_updates'  => 4,
				'orphan_postmeta' => 250,
			]
		);

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

	public function test_the_checks_do_not_read_test_globals(): void {
		SitePulse::use_metrics( null );
		$GLOBALS['stonewright_test_autoload_bytes']  = 99 * 1024 * 1024;
		$GLOBALS['stonewright_test_orphan_postmeta'] = 5000;

		$result = ( new SitePulse() )->execute( [] );
		unset( $GLOBALS['stonewright_test_autoload_bytes'], $GLOBALS['stonewright_test_orphan_postmeta'] );

		self::assertIsArray( $result );
		self::assertNotSame( 5000, $result['summary']['orphan_postmeta'] ?? null );
	}

	public function test_the_orphan_postmeta_scan_is_bounded(): void {
		SitePulse::use_metrics( [ 'autoload_bytes' => 100_000, 'admin_count' => 1, 'plugin_updates' => 0 ] );
		$GLOBALS['wpdb'] = new class() {
			public string $postmeta = 'wp_postmeta';
			public string $posts    = 'wp_posts';
			public string $options  = 'wp_options';
			/** @var list<string> */
			public array $queries = [];

			public function get_var( string $query = '' ): int {
				$this->queries[] = $query;
				return 7;
			}
		};

		( new SitePulse() )->execute( [] );

		$orphan_queries = array_values( array_filter( $GLOBALS['wpdb']->queries, static fn ( string $q ): bool => str_contains( $q, 'wp_postmeta' ) ) );
		self::assertNotSame( [], $orphan_queries );
		foreach ( $orphan_queries as $query ) {
			self::assertMatchesRegularExpression( '/LIMIT\s+\d+/i', $query, 'The scan stops at a fixed number of rows.' );
		}
	}

	public function test_healthy_fixture_scores_high(): void {
		// Default fixture: HTTPS, 1 admin, no updates, small autoload.
		// May still lose points for object cache / file edit / debug depending on env.
		$result = ( new SitePulse() )->execute( [] );
		self::assertGreaterThanOrEqual( 50, $result['score'] );
		self::assertMatchesRegularExpression( '/^[A-F]$/', $result['grade'] );
	}
}
