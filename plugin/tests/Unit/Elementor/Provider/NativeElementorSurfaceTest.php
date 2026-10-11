<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\Tests\Unit\Elementor\Provider;

use PHPUnit\Framework\TestCase;
use Stonewright\WpMcp\Abilities\Elementor\ProviderDiscovery;
use Stonewright\WpMcp\Abilities\ElementorV3\CapabilitiesSummary;
use Stonewright\WpMcp\Abilities\ElementorV3\Status;
use Stonewright\WpMcp\Abilities\Site\Capabilities;
use Stonewright\WpMcp\Abilities\System\TaskStart;
use Stonewright\WpMcp\Elementor\Provider\NativeContracts;
use Stonewright\WpMcp\Elementor\Provider\NativeElementorReport;
use Stonewright\WpMcp\Elementor\Provider\ProviderRouter;
use Stonewright\WpMcp\Elementor\Provider\UpstreamAbilityDiscovery;

/**
 * Native Elementor evidence is surfaced in site capabilities, Elementor status and task start.
 *
 * @covers \Stonewright\WpMcp\Abilities\Elementor\ProviderDiscovery
 * @covers \Stonewright\WpMcp\Abilities\Site\Capabilities
 * @covers \Stonewright\WpMcp\Abilities\ElementorV3\Status
 * @covers \Stonewright\WpMcp\Abilities\ElementorV3\CapabilitiesSummary
 * @covers \Stonewright\WpMcp\Elementor\Provider\NativeElementorReport
 */
final class NativeElementorSurfaceTest extends TestCase {

	protected function setUp(): void {
		$GLOBALS['stonewright_test_user_caps']       = [ 'read' => true, 'edit_posts' => true, 'manage_options' => true ];
		$GLOBALS['stonewright_test_user_logged_in']  = true;
		$GLOBALS['stonewright_test_current_user_id'] = 1;
		$GLOBALS['stonewright_test_options']         = [ 'stonewright_mode' => 'development', 'stonewright_disabled_abilities' => [] ];
	}

	protected function tearDown(): void {
		$GLOBALS['stonewright_test_options'] = [];
	}

	public function test_site_capabilities_reports_the_native_elementor_block(): void {
		$result = ( new Capabilities( $this->router() ) )->execute( [] );

		self::assertSame( 'available', $result['native_elementor']['state'] );
		self::assertSame( '4.3.4', $result['native_elementor']['elementor']['version'] );
		self::assertSame(
			[ 'elementor/build-composition', 'elementor/get-page-structure', 'elementor/manage-classes', 'elementor/manage-default-styles', 'elementor/manage-global-variable' ],
			$result['native_elementor']['certified']
		);
		self::assertTrue( $result['native_elementor']['routable_write'] );
		self::assertArrayHasKey( 'abilities', $result );
		self::assertArrayHasKey( 'integrations', $result );
	}

	public function test_site_capabilities_output_schema_declares_the_native_block_without_requiring_it(): void {
		$schema = ( new Capabilities() )->output_schema();

		self::assertSame( 'object', $schema['properties']['native_elementor']['type'] );
		self::assertNotContains( 'native_elementor', $schema['required'] );
	}

	public function test_site_capabilities_without_elementor_reports_not_installed_and_never_fails(): void {
		$router = new ProviderRouter(
			static fn(): array => [],
			static fn(): array => [],
			static fn(): array => [ 'items' => [], 'issues' => [] ],
			static fn(): array => [],
			static fn(): array => UpstreamAbilityDiscovery::summarize_environment( [] )
		);

		$result = ( new Capabilities( $router ) )->execute( [] );

		self::assertSame( 'not_installed', $result['native_elementor']['state'] );
		self::assertSame( [], $result['native_elementor']['certified'] );
	}

	public function test_elementor_status_exposes_the_native_block_once_and_not_inside_provider_discovery(): void {
		$result = ( new Status( $this->router() ) )->execute( [] );

		self::assertSame( 'available', $result['native_elementor']['state'] );
		self::assertArrayNotHasKey( 'native_elementor', $result['provider_discovery'] );
		self::assertArrayHasKey( 'native_preferred', $result['provider_discovery'] );
		self::assertSame( 'object', ( new Status() )->output_schema()['properties']['native_elementor']['type'] );
	}

	public function test_provider_discovery_returns_the_native_block_and_lists_every_contract_in_its_policy(): void {
		$ability = new ProviderDiscovery( $this->router() );
		$result  = $ability->execute( [ 'post_id' => 0, 'architecture' => 'auto' ] );

		self::assertIsArray( $result );
		self::assertSame( 'available', $result['native_elementor']['state'] );
		self::assertTrue( $result['writes_enabled'] );
		self::assertSame( 'object', $ability->output_schema()['properties']['native_elementor']['type'] );
		self::assertEquals(
			[
				'elementor/manage-default-styles'  => 'native-preferred-when-certified',
				'elementor/manage-classes'         => 'certified-native-write-refused',
				'elementor/manage-global-variable' => 'certified-native-write-refused',
				'elementor/get-page-structure'     => 'native-readback-when-certified',
				'elementor/manage-elements'        => 'unsupported',
				'elementor/build-composition'      => 'native-preferred-when-certified',
			],
			$ability->meta()['provider_policy']
		);
		foreach ( array_keys( $ability->meta()['provider_policy'] ) as $name ) {
			self::assertArrayHasKey( $name, $result['native_preferred'] );
		}
	}

	public function test_the_provider_policy_is_derived_from_the_shipped_contracts(): void {
		$policy = ( new ProviderDiscovery( $this->router() ) )->meta()['provider_policy'];

		self::assertSame( NativeContracts::names(), array_keys( $policy ) );
		foreach ( NativeContracts::names() as $name ) {
			$contract = NativeContracts::for_ability( $name );
			$expected = 'unsupported' === $contract['status']
				? 'unsupported'
				: match ( $contract['routing']['native_write'] ) {
					'read_only' => 'native-readback-when-certified',
					'refused'   => 'certified-native-write-refused',
					default     => 'native-preferred-when-certified',
				};
			self::assertSame( $expected, $policy[ $name ], $name );
		}
	}

	public function test_provider_discovery_output_schema_declares_every_key_it_returns(): void {
		$ability = new ProviderDiscovery( $this->router() );
		$result  = $ability->execute( [ 'post_id' => 0, 'architecture' => 'auto' ] );
		$schema  = $ability->output_schema();

		self::assertIsArray( $result );
		self::assertSame( [], array_values( array_diff( array_keys( $result ), array_keys( $schema['properties'] ) ) ) );
		self::assertSame( 'boolean', $schema['properties']['writes_enabled']['type'] );
		self::assertSame( 'integer', $schema['properties']['issues_count']['type'] );
		self::assertSame( 'array', $schema['properties']['safety_closure']['type'] );
	}

	public function test_the_site_capabilities_description_no_longer_says_no_native_write_is_routable(): void {
		$description = (string) ( new Capabilities() )->output_schema()['properties']['native_elementor']['description'];

		self::assertStringNotContainsString( 'no native write is routable', $description );
		self::assertStringContainsString( 'stonewright-elementor-native-execute', $description );
	}

	public function test_capabilities_summary_carries_only_the_native_state_token(): void {
		$result = ( new CapabilitiesSummary( new Status( $this->router() ) ) )->execute( [] );

		self::assertSame( 'available', $result['status']['native_elementor'] );
	}

	public function test_capabilities_summary_carries_a_short_native_hint_when_native_elementor_is_available(): void {
		$result = ( new CapabilitiesSummary( new Status( $this->router() ) ) )->execute( [] );

		self::assertSame( 'available', $result['status']['native_elementor'] );
		self::assertStringContainsString( 'stonewright-elementor-native-execute', $result['status']['native_hint'] );
		self::assertLessThan( 700, strlen( $result['status']['native_hint'] ) );
	}

	public function test_capabilities_summary_names_an_out_of_range_elementor_version_in_the_hint(): void {
		$result = ( new CapabilitiesSummary( new Status( $this->router( '4.4.0' ) ) ) )->execute( [] );

		self::assertSame( 'available_uncertified', $result['status']['native_elementor'] );
		self::assertStringContainsString( 'Elementor 4.4.0', $result['status']['native_hint'] );
		self::assertStringContainsString( '4.3.0 - 4.3.*', $result['status']['native_hint'] );
		self::assertStringContainsString( 'elementor_version_out_of_range', $result['status']['native_hint'] );
	}

	public function test_the_hint_is_empty_when_native_elementor_is_not_available(): void {
		$router = new ProviderRouter(
			static fn(): array => [],
			static fn(): array => [],
			static fn(): array => [ 'items' => [], 'issues' => [] ],
			static fn(): array => [],
			static fn(): array => UpstreamAbilityDiscovery::summarize_environment( [] )
		);

		$result = ( new CapabilitiesSummary( new Status( $router ) ) )->execute( [] );

		self::assertSame( 'not_installed', $result['status']['native_elementor'] );
		self::assertSame( '', $result['status']['native_hint'] );
	}

	public function test_compact_task_start_keeps_the_native_hint_and_nothing_else_of_the_native_block(): void {
		$method = new \ReflectionMethod( \Stonewright\WpMcp\Abilities\System\WorkflowPreflight::class, 'compact_response' );
		$method->setAccessible( true );
		$compact = $method->invoke(
			null,
			[
				'elementor' => [
					'included' => true,
					'status'   => [ 'installed' => true, 'version' => '4.3.4', 'native_elementor' => 'available', 'native_hint' => 'Use native.', 'native_report' => [ 'big' => 'no' ] ],
				],
			],
			[]
		);

		self::assertSame( 'available', $compact['elementor']['status']['native_elementor'] );
		self::assertSame( 'Use native.', $compact['elementor']['status']['native_hint'] );
		self::assertArrayNotHasKey( 'native_report', $compact['elementor']['status'] );
	}

	public function test_compact_report_is_small_and_tolerates_a_missing_block(): void {
		self::assertSame( 'not_installed', NativeElementorReport::compact( [] ) );
		self::assertSame( 'available', NativeElementorReport::compact( [ 'state' => 'available' ] ) );
		self::assertSame( 'not_installed', NativeElementorReport::compact( [ 'state' => str_repeat( 'x', 500 ) ] ) );
		self::assertSame( 'not_installed', NativeElementorReport::compact( [ 'state' => "bad state
" ] ) );
	}

	public function test_compact_task_start_for_elementor_carries_the_native_state_token(): void {
		$GLOBALS['stonewright_test_options']['stonewright_essential_tools_mode'] = true;

		$result = ( new TaskStart() )->execute(
			[
				'task'         => 'Implement a responsive Elementor landing-page hero from a supplied design image.',
				'surface'      => 'elementor',
				'intent'       => 'write',
				'responseMode' => 'compact',
			]
		);

		self::assertIsArray( $result );
		self::assertContains(
			$result['elementor']['status']['native_elementor'],
			[ 'not_installed', 'module_unavailable', 'requirements_missing', 'exposure_disabled', 'no_abilities_registered', 'available_uncertified', 'available' ]
		);
	}

	public function test_compact_task_start_for_a_non_elementor_task_adds_nothing(): void {
		$result = ( new TaskStart() )->execute(
			[
				'task'         => 'Update an existing post title and excerpt.',
				'surface'      => 'wordpress',
				'intent'       => 'write',
				'responseMode' => 'compact',
			]
		);

		self::assertIsArray( $result );
		self::assertArrayNotHasKey( 'elementor', $result );
		self::assertStringNotContainsString( 'native_elementor', (string) wp_json_encode( $result ) );
	}

	private function router( string $version = '4.3.4' ): ProviderRouter {
		$path    = dirname( __DIR__, 3 ) . '/fixtures/elementor-native/elementor-4.3.4-abilities.json';
		$decoded = json_decode( (string) file_get_contents( $path ), true );
		$all     = array_values( (array) $decoded['abilities'] );
		foreach ( $all as $index => $row ) {
			$all[ $index ]['source_version'] = $version;
		}
		return new ProviderRouter(
			static fn(): array => [ 'document_architecture' => 'v4', 'write_target' => 'v4', 'write_blocked' => false ],
			static fn(): array => [],
			static fn(): array => [ 'items' => [], 'issues' => [] ],
			static fn(): array => $all,
			static fn(): array => UpstreamAbilityDiscovery::summarize_environment(
				[
					'elementor_version' => $version, 'module_present' => true, 'module_active' => true, 'abilities_api' => true,
					'mcp_adapter' => true, 'mcp_adapter_version' => '0.6.1', 'mcp_adapter_provider' => 'elementor-core',
					'mcp_composer' => true, 'mcp_composer_version' => '1.0.19', 'site_exposure_enabled' => true, 'atomic_editor_active' => true,
				]
			)
		);
	}
}
