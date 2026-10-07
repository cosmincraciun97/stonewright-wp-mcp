<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\Tests\Unit\Support;

use PHPUnit\Framework\TestCase;
use Stonewright\WpMcp\Support\SiteHealthRunner;

/**
 * @covers \Stonewright\WpMcp\Support\SiteHealthRunner
 */
final class SiteHealthRunnerTest extends TestCase {

	private static function health( array $tests ): object {
		return new class( $tests ) {
			/** @param array<string, mixed> $tests */
			public function __construct( private array $tests ) {}

			/** @return array<string, mixed> */
			public function get_tests(): array {
				return $this->tests;
			}

			/** @return array<string, mixed> */
			public function get_test_alpha(): array {
				return [
					'label'       => 'Alpha is fine',
					'status'      => 'good',
					'badge'       => [ 'label' => 'Performance', 'color' => 'blue' ],
					'description' => '<p>All <strong>good</strong>.</p><script>alert(1)</script>',
				];
			}

			/** @return array<string, mixed> */
			public function get_test_loopback_requests(): array {
				return [ 'label' => 'Loopback works', 'status' => 'good', 'description' => '' ];
			}

			private function get_test_hidden(): array {
				return [ 'label' => 'Hidden', 'status' => 'good' ];
			}
		};
	}

	public function test_direct_tests_named_by_method_and_by_callable_both_run(): void {
		$health = self::health(
			[
				'direct' => [
					'alpha'  => [ 'label' => 'Alpha', 'test' => 'alpha' ],
					'closure' => [ 'label' => 'Closure', 'test' => static fn(): array => [ 'label' => 'Closure ran', 'status' => 'critical' ] ],
					'hidden' => [ 'label' => 'Hidden', 'test' => 'hidden' ],
					'none'   => [ 'label' => 'None', 'test' => 'does_not_exist' ],
				],
			]
		);

		$rows = SiteHealthRunner::run_direct_tests( $health );

		self::assertSame(
			[
				[ 'name' => 'alpha', 'status' => 'good', 'label' => 'Alpha is fine' ],
				[ 'name' => 'closure', 'status' => 'critical', 'label' => 'Closure ran' ],
			],
			$rows
		);
	}

	public function test_a_test_that_returns_something_else_than_an_array_is_skipped(): void {
		$health = self::health( [ 'direct' => [ 'odd' => [ 'label' => 'Odd', 'test' => static fn(): string => 'text' ] ] ] );

		self::assertSame( [], SiteHealthRunner::run_direct_tests( $health ) );
	}

	public function test_a_named_test_runs_get_test_with_underscores(): void {
		$result = SiteHealthRunner::run_named_test( self::health( [] ), 'loopback-requests' );

		self::assertSame( [ 'status' => 'good', 'label' => 'Loopback works', 'description' => '', 'badge' => '' ], $result );
	}

	public function test_a_named_test_strips_markup_from_the_description(): void {
		$result = SiteHealthRunner::run_named_test( self::health( [] ), 'alpha' );

		self::assertSame( 'All good.', $result['description'] );
		self::assertSame( 'Performance', $result['badge'] );
	}

	public function test_a_named_test_the_site_does_not_provide_returns_null(): void {
		self::assertNull( SiteHealthRunner::run_named_test( self::health( [] ), 'page-cache' ) );
		self::assertNull( SiteHealthRunner::run_named_test( self::health( [] ), 'hidden' ) );
		self::assertNull( SiteHealthRunner::run_named_test( self::health( [] ), '../x' ) );
	}
}
