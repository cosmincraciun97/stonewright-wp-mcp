<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\Tests\Unit\Elementor\Provider;

use PHPUnit\Framework\TestCase;
use Stonewright\WpMcp\Elementor\Provider\UpstreamAbilityDiscovery;

/**
 * @covers \Stonewright\WpMcp\Elementor\Provider\UpstreamAbilityDiscovery
 */
final class NativeEnvironmentTest extends TestCase {

	public function test_every_requirement_met_reports_an_active_mcp_module_with_nothing_missing(): void {
		$environment = UpstreamAbilityDiscovery::summarize_environment( self::facts() );

		self::assertSame( [ 'installed' => true, 'version' => '4.3.4' ], $environment['elementor'] );
		self::assertTrue( $environment['mcp_module']['present'] );
		self::assertTrue( $environment['mcp_module']['active'] );
		self::assertTrue( $environment['mcp_module']['site_exposure_enabled'] );
		self::assertTrue( $environment['mcp_module']['atomic_editor_active'] );
		self::assertSame( [], $environment['mcp_module']['missing'] );
		self::assertSame( [ 'met' => true ], $environment['mcp_module']['requirements']['abilities_api'] );
		self::assertSame( [ 'met' => true, 'version' => '0.6.1', 'provider_id' => 'elementor-core' ], $environment['mcp_module']['requirements']['mcp_adapter'] );
		self::assertSame( [ 'met' => true, 'version' => '1.0.19' ], $environment['mcp_module']['requirements']['mcp_composer'] );
	}

	/** @return array<string,array{0:string,1:string}> */
	public static function requirements(): array {
		return [
			'abilities api' => [ 'abilities_api', 'abilities_api' ],
			'mcp adapter'   => [ 'mcp_adapter', 'mcp_adapter' ],
			'mcp composer'  => [ 'mcp_composer', 'mcp_composer' ],
		];
	}

	/** @dataProvider requirements */
	public function test_each_unmet_requirement_is_named_and_the_module_is_not_active( string $fact, string $name ): void {
		$facts          = self::facts();
		$facts[ $fact ] = false;
		$facts['module_active'] = false;

		$environment = UpstreamAbilityDiscovery::summarize_environment( $facts );

		self::assertSame( [ $name ], $environment['mcp_module']['missing'] );
		self::assertFalse( $environment['mcp_module']['requirements'][ $name ]['met'] );
		self::assertFalse( $environment['mcp_module']['active'] );
	}

	public function test_exposure_option_and_atomic_editor_state_are_reported_independently_of_requirements(): void {
		$facts = self::facts();
		$facts['site_exposure_enabled'] = false;
		$facts['atomic_editor_active']  = false;

		$environment = UpstreamAbilityDiscovery::summarize_environment( $facts );

		self::assertFalse( $environment['mcp_module']['site_exposure_enabled'] );
		self::assertFalse( $environment['mcp_module']['atomic_editor_active'] );
		self::assertSame( [], $environment['mcp_module']['missing'] );
	}

	public function test_elementor_without_the_mcp_module_reports_it_absent(): void {
		$facts = self::facts();
		$facts['module_present'] = false;
		$facts['module_active']  = false;

		$environment = UpstreamAbilityDiscovery::summarize_environment( $facts );

		self::assertTrue( $environment['elementor']['installed'] );
		self::assertFalse( $environment['mcp_module']['present'] );
		self::assertFalse( $environment['mcp_module']['active'] );
	}

	public function test_missing_elementor_is_reported_as_not_installed_with_an_empty_version(): void {
		$environment = UpstreamAbilityDiscovery::summarize_environment( [] );

		self::assertSame( [ 'installed' => false, 'version' => '' ], $environment['elementor'] );
		self::assertFalse( $environment['mcp_module']['present'] );
		self::assertSame( [ 'abilities_api', 'mcp_adapter', 'mcp_composer' ], $environment['mcp_module']['missing'] );
	}

	public function test_hostile_fact_values_are_coerced_and_bounded(): void {
		$environment = UpstreamAbilityDiscovery::summarize_environment(
			[
				'elementor_version'   => str_repeat( '9', 5000 ),
				'module_present'      => 'yes',
				'mcp_adapter'         => 1,
				'mcp_adapter_version' => [ 'nested' ],
				'mcp_adapter_provider'=> str_repeat( 'p', 5000 ),
				'mcp_composer_version'=> str_repeat( 'v', 5000 ),
			]
		);

		self::assertLessThanOrEqual( 100, strlen( $environment['elementor']['version'] ) );
		self::assertTrue( $environment['mcp_module']['present'] );
		self::assertSame( '', $environment['mcp_module']['requirements']['mcp_adapter']['version'] );
		self::assertLessThanOrEqual( 100, strlen( $environment['mcp_module']['requirements']['mcp_adapter']['provider_id'] ) );
		self::assertLessThanOrEqual( 100, strlen( $environment['mcp_module']['requirements']['mcp_composer']['version'] ) );
		self::assertLessThan( 2000, strlen( (string) wp_json_encode( $environment ) ) );
	}

	public function test_live_environment_probe_is_read_only_and_returns_the_documented_shape(): void {
		$environment = UpstreamAbilityDiscovery::native_environment();

		self::assertSame( [ 'elementor', 'mcp_module' ], array_keys( $environment ) );
		self::assertSame( [ 'installed', 'version' ], array_keys( $environment['elementor'] ) );
		self::assertSame(
			[ 'present', 'active', 'site_exposure_enabled', 'atomic_editor_active', 'requirements', 'missing' ],
			array_keys( $environment['mcp_module'] )
		);
		self::assertSame( [ 'abilities_api', 'mcp_adapter', 'mcp_composer' ], array_keys( $environment['mcp_module']['requirements'] ) );
	}

	/** @return array<string,mixed> */
	private static function facts(): array {
		return [
			'elementor_version'     => '4.3.4',
			'module_present'        => true,
			'module_active'         => true,
			'abilities_api'         => true,
			'mcp_adapter'           => true,
			'mcp_adapter_version'   => '0.6.1',
			'mcp_adapter_provider'  => 'elementor-core',
			'mcp_composer'          => true,
			'mcp_composer_version'  => '1.0.19',
			'site_exposure_enabled' => true,
			'atomic_editor_active'  => true,
		];
	}
}
