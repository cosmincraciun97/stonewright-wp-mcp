<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\Tests\Unit\Expertise;

use PHPUnit\Framework\TestCase;
use Stonewright\WpMcp\Abilities\Expertise\ExpertiseGet;
use Stonewright\WpMcp\Expertise\BundledPacks;
use Stonewright\WpMcp\Expertise\ExpertiseEvaluator;
use Stonewright\WpMcp\Expertise\ExpertisePromotion;
use Stonewright\WpMcp\Expertise\ExpertiseResolver;
use Stonewright\WpMcp\Expertise\ExpertiseStore;
use Stonewright\WpMcp\Expertise\ExpertiseTable;
use Stonewright\WpMcp\Expertise\PackValidator;

/** @coversDefaultClass \Stonewright\WpMcp\Expertise\ExpertiseResolver */
final class ExpertiseEngineTest extends TestCase {

	private mixed $original_wpdb;

	protected function setUp(): void {
		$this->original_wpdb = $GLOBALS['wpdb'] ?? null;
		$GLOBALS['wpdb'] = new class() {
			public string $prefix = 'wp_';
			public int $insert_id = 10;
			public function get_charset_collate(): string { return 'DEFAULT CHARACTER SET utf8mb4'; }
			public function prepare( string $query, mixed ...$args ): string { return $query; }
			public function get_var( string $query ): mixed { return str_contains( $query, 'SHOW TABLES' ) ? null : null; }
			/** @return list<array<string, mixed>> */
			public function get_results( string $query, string $output = 'OBJECT' ): array { return []; }
			/** @param array<string, mixed> $row */
			public function insert( string $table, array $row ): int { ++$this->insert_id; return 1; }
		};
		ExpertiseStore::reset_cache();
	}

	protected function tearDown(): void {
		if ( null !== $this->original_wpdb ) { $GLOBALS['wpdb'] = $this->original_wpdb; } else { unset( $GLOBALS['wpdb'] ); }
	}

	public function test_p0_curriculum_is_valid_and_has_twelve_evals_per_pack(): void {
		$packs = BundledPacks::all();
		self::assertCount( 10, $packs );
		foreach ( $packs as $pack ) {
			self::assertSame( [], PackValidator::errors( $pack ), (string) $pack['id'] );
			self::assertGreaterThanOrEqual( 12, count( $pack['eval_cases'] ) );
			self::assertLessThanOrEqual( 60, str_word_count( (string) $pack['trigger'] ) );
		}
	}

	public function test_site_storage_has_versioned_pack_and_scorecard_tables(): void {
		$sql = implode( "\n", ExpertiseTable::schema_sql() );
		self::assertStringContainsString( 'pack_hash char(64)', $sql );
		self::assertStringContainsString( 'runtime_fingerprint char(64)', $sql );
		self::assertStringContainsString( 'metrics_json longtext', $sql );
	}

	public function test_resolver_returns_top_three_compatible_refs_and_honors_known_hash(): void {
		$runtime = self::runtime();
		$matches = ExpertiseResolver::resolve( 'Build a responsive Elementor V3 landing page from a Figma screenshot', 'elementor', [], $runtime );
		self::assertLessThanOrEqual( 3, count( $matches ) );
		self::assertContains( 'elementor-v3', array_column( $matches, 'id' ) );
		self::assertNotContains( 'elementor-v4-atomic', array_column( $matches, 'id' ) );

		$first  = $matches[0];
		$cached = ExpertiseResolver::resolve( 'Build a responsive Elementor V3 landing page from a Figma screenshot', 'elementor', [ $first['id'] => $first['hash'] ], $runtime );
		$cached_by_id = array_column( $cached, null, 'id' );
		self::assertTrue( $cached_by_id[ $first['id'] ]['cached'] );
		self::assertArrayNotHasKey( 'trigger', $cached_by_id[ $first['id'] ] );
	}

	public function test_version_mismatch_blocks_activation_instead_of_falling_back(): void {
		$runtime = self::runtime();
		$runtime['versions']['elementor_core'] = '4.1.0';
		$matches = ExpertiseResolver::resolve( 'Edit Elementor V3 widgets', 'elementor', [], $runtime );
		self::assertNotContains( 'elementor-v3', array_column( $matches, 'id' ) );
	}

	public function test_body_is_lazy_and_below_body_budget(): void {
		$result = ( new ExpertiseGet() )->execute( [ 'id' => 'elementor-v3', 'section' => 'body' ] );
		self::assertIsArray( $result );
		self::assertArrayNotHasKey( 'eval_cases', $result['pack'] );
		self::assertLessThan( 1200, (int) ceil( strlen( wp_json_encode( $result ) ?: '' ) / 4 ) );
	}

	public function test_evaluator_scores_verified_p0_and_stable_requires_two_runtimes_or_approval(): void {
		$report = ExpertiseEvaluator::evaluate( 'wordpress-core', self::runtime(), false );
		self::assertIsArray( $report );
		self::assertGreaterThanOrEqual( 90, $report['score'] );
		self::assertSame( 0, $report['critical_failures'] );
		self::assertSame( 12, $report['cases_total'] );

		$blocked = ExpertisePromotion::promote( 'elementor-v3', 'stable', false, '' );
		self::assertInstanceOf( \WP_Error::class, $blocked );
		self::assertSame( 'stonewright_expertise_stability_gate', $blocked->get_error_code() );
	}

	/** @return array<string, mixed> */
	private static function runtime(): array {
		$capabilities = [];
		foreach ( BundledPacks::all() as $pack ) { $capabilities = array_merge( $capabilities, $pack['required_capabilities'] ); }
		return [
			'versions' => [ 'wordpress' => '6.9.0', 'php' => PHP_VERSION, 'elementor_core' => '3.30.0', 'elementor_pro' => '3.30.0', 'woocommerce' => '10.0.0', 'acf' => '6.4.0' ],
			'capabilities' => array_values( array_unique( $capabilities ) ),
			'fingerprint' => str_repeat( 'a', 64 ),
		];
	}
}
