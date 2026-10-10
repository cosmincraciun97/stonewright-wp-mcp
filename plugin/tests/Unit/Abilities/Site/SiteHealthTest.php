<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\Tests\Unit\Abilities\Site;

use PHPUnit\Framework\TestCase;
use Stonewright\WpMcp\Abilities\Site\Health;
use Stonewright\WpMcp\Abilities\Site\SiteHealthTest as SiteHealthTestAbility;

/**
 * WordPress describes a direct Site Health test by the name of a `get_test_<name>()` method
 * on its Site Health object; add-on tests may hand over a callable instead. Both kinds run,
 * and a named test returns its own result.
 *
 * @covers \Stonewright\WpMcp\Abilities\Site\Health
 * @covers \Stonewright\WpMcp\Abilities\Site\SiteHealthTest
 * @covers \Stonewright\WpMcp\Support\SiteHealthRunner
 */
final class SiteHealthTest extends TestCase {

	protected function setUp(): void {
		$GLOBALS['stonewright_test_user_caps']        = [ 'manage_options' => true, 'read' => true ];
		$GLOBALS['stonewright_test_site_health_tests'] = [
			'direct' => [
				'wordpress_version' => [ 'label' => 'WordPress version', 'test' => 'wordpress_version' ],
				'https_status'      => [ 'label' => 'HTTPS status', 'test' => 'https_status' ],
				'addon_check'       => [
					'label' => 'Add-on check',
					'test'  => static fn(): array => [ 'status' => 'recommended', 'label' => 'An add-on check', 'description' => '<p>Add-on note.</p>' ],
				],
				'missing_method'    => [ 'label' => 'Missing', 'test' => 'no_such_test' ],
			],
			'async'  => [],
		];
	}

	protected function tearDown(): void {
		$GLOBALS['stonewright_test_user_caps']         = [];
		unset( $GLOBALS['stonewright_test_site_health_tests'] );
	}

	public function test_site_health_runs_tests_named_by_method_and_callable_tests(): void {
		$result = ( new Health() )->execute( [] );

		$by_name = [];
		foreach ( $result['tests'] as $test ) {
			$by_name[ $test['name'] ] = $test;
		}

		self::assertSame( [ 'wordpress_version', 'https_status', 'addon_check' ], array_keys( $by_name ) );
		self::assertSame( 'good', $by_name['wordpress_version']['status'] );
		self::assertSame( 'Your site is running the current WordPress version.', $by_name['wordpress_version']['label'] );
		self::assertSame( 'recommended', $by_name['addon_check']['status'] );
	}

	public function test_a_test_that_throws_is_reported_instead_of_dropped(): void {
		$GLOBALS['stonewright_test_site_health_tests']['direct']['broken'] = [
			'label' => 'Broken check',
			'test'  => static function (): array {
				throw new \RuntimeException( 'boom' );
			},
		];

		$result  = ( new Health() )->execute( [] );
		$by_name = array_column( $result['tests'], null, 'name' );

		self::assertSame( 'error', $by_name['broken']['status'] );
		self::assertSame( 'Broken check', $by_name['broken']['label'] );
	}

	public function test_a_named_test_returns_its_own_result(): void {
		$result = ( new SiteHealthTestAbility() )->execute( [ 'test' => 'https-status' ] );

		self::assertIsArray( $result );
		self::assertTrue( $result['supported'] );
		self::assertSame( 'https-status', $result['test'] );
		self::assertSame( 'good', $result['result']['status'] );
		self::assertSame( 'Your website is using an active HTTPS connection.', $result['result']['label'] );
		self::assertSame( 'The site is served over HTTPS.', $result['result']['description'] );
		self::assertSame( 'Security', $result['result']['badge'] );
	}

	public function test_every_named_test_the_ability_offers_is_answered_with_a_status(): void {
		$schema = ( new SiteHealthTestAbility() )->input_schema();

		foreach ( $schema['properties']['test']['enum'] as $name ) {
			$result = ( new SiteHealthTestAbility() )->execute( [ 'test' => $name ] );

			self::assertIsArray( $result, $name );
			self::assertTrue( $result['supported'], $name );
			self::assertNotSame( 'unknown', $result['result']['status'], $name );
			self::assertContains( $result['result']['status'], [ 'good', 'recommended', 'critical' ], $name );
		}
	}
}
