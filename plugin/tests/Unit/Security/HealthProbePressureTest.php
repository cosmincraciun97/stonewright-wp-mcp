<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\Tests\Unit\Security;

use PHPUnit\Framework\TestCase;
use Stonewright\WpMcp\Security\HealthProbe;
use Stonewright\WpMcp\Security\SitePressure;

/**
 * @covers \Stonewright\WpMcp\Security\HealthProbe
 */
final class HealthProbePressureTest extends TestCase {

	/** @var list<array{url:string,args:array<string,mixed>}> */
	private array $requests = [];

	protected function setUp(): void {
		$GLOBALS['stonewright_test_options']         = [ 'stonewright_mode' => 'development' ];
		$GLOBALS['stonewright_test_transients']      = [];
		$GLOBALS['stonewright_test_filters']         = [];
		$GLOBALS['stonewright_test_current_user_id'] = 7;
		$GLOBALS['stonewright_test_user_caps_by_id'] = [ 7 => [ 'manage_options' => true, 'read' => true, 'edit_post' => true ] ];
		$this->requests                              = [];
		SitePressure::reset_for_tests();
		HealthProbe::set_transport(
			function ( string $url, array $args ) {
				$this->requests[] = [ 'url' => $url, 'args' => $args ];
				return [ 'response' => [ 'code' => 200 ], 'body' => 'ok', 'headers' => [] ];
			}
		);
	}

	protected function tearDown(): void {
		HealthProbe::set_transport( null );
		SitePressure::reset_for_tests();
		$GLOBALS['stonewright_test_options']    = [];
		$GLOBALS['stonewright_test_transients'] = [];
	}

	public function test_site_legs_are_the_full_set_on_a_calm_site(): void {
		$this->assertSame( [ 'home', 'admin', 'rest' ], HealthProbe::legs_for( 'site' ) );
	}

	public function test_site_legs_degrade_to_home_under_elevated_pressure(): void {
		SitePressure::set_probe( static fn (): float => 400.0 );
		$this->assertSame( [ 'home' ], HealthProbe::legs_for( 'site' ) );
		$this->assertSame( [ 'home' ], HealthProbe::legs_for( 'light' ) );
		$this->assertSame( [ 'post' ], HealthProbe::legs_for( 'post' ), 'A probe of the page that was just written keeps its leg.' );
	}

	public function test_critical_pressure_runs_one_quick_leg(): void {
		SitePressure::set_probe( static fn (): float => 1500.0 );
		$probe = HealthProbe::run( [ 'legs' => [ 'home', 'admin', 'rest' ], 'user_id' => 7 ] );

		$this->assertCount( 1, $probe['legs'], 'One leg runs.' );
		$this->assertCount( 1, $this->requests );
		$this->assertLessThanOrEqual( 3, $this->requests[0]['args']['timeout'] ?? 0, 'The request is a short one.' );
	}

	public function test_a_calm_site_runs_every_leg_it_was_asked_for(): void {
		$probe = HealthProbe::run( [ 'legs' => [ 'home', 'rest' ], 'user_id' => 7 ] );
		$this->assertCount( 2, $probe['legs'] );
	}
}
