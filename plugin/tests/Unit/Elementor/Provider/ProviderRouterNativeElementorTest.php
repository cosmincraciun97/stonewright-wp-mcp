<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\Tests\Unit\Elementor\Provider;

use PHPUnit\Framework\TestCase;
use Stonewright\WpMcp\Elementor\Provider\NativeContracts;
use Stonewright\WpMcp\Elementor\Provider\ProviderRouter;
use Stonewright\WpMcp\Elementor\Provider\UpstreamAbilityDiscovery;

/**
 * @covers \Stonewright\WpMcp\Elementor\Provider\ProviderRouter
 * @covers \Stonewright\WpMcp\Elementor\Provider\NativeElementorReport
 */
final class ProviderRouterNativeElementorTest extends TestCase {

	private const CERTIFIED = [
		'elementor/build-composition',
		'elementor/get-page-structure',
		'elementor/manage-classes',
		'elementor/manage-default-styles',
		'elementor/manage-global-variable',
	];

	public function test_all_four_native_abilities_are_certified_from_the_recorded_runtime(): void {
		$result = $this->router( self::recorded_abilities() )->inspect();

		foreach ( self::CERTIFIED as $name ) {
			$preference = $result['native_preferred'][ $name ];
			self::assertTrue( $preference['available'], $name );
			self::assertSame( 'certified', $preference['certification'], $name );
			self::assertSame( 'official_contract_certified', $preference['reason'], $name );
			self::assertSame( [], $preference['issues'], $name );
			self::assertFalse( $preference['routable_write'], $name );
			self::assertTrue( $preference['safety_closure_required'], $name );
			self::assertSame( 'elementor-core', $preference['provider_id'], $name );
			self::assertSame( 'full_bounded', $preference['schema_output'], $name );
			self::assertMatchesRegularExpression( '/^[a-f0-9]{64}$/', $preference['schema_fingerprint'], $name );
			self::assertNotSame( [], $preference['contract']['side_effects'], $name );
		}
		self::assertSame( 'native-readback', $result['native_preferred']['elementor/get-page-structure']['selection'] );
		self::assertSame( 'read', $result['native_preferred']['elementor/get-page-structure']['contract']['access'] );
		foreach ( [ 'elementor/build-composition', 'elementor/manage-default-styles' ] as $name ) {
			self::assertSame( 'native-preferred', $result['native_preferred'][ $name ]['selection'], $name );
			self::assertSame( 'write', $result['native_preferred'][ $name ]['contract']['access'], $name );
			self::assertSame( 'allowed', $result['native_preferred'][ $name ]['native_write'], $name );
			self::assertSame( 'stonewright/elementor-native-execute', $result['native_preferred'][ $name ]['execute_with'], $name );
		}
		foreach ( [ 'elementor/manage-classes', 'elementor/manage-global-variable' ] as $name ) {
			$preference = $result['native_preferred'][ $name ];
			self::assertSame( 'certified', $preference['certification'], $name );
			self::assertSame( 'native-write-refused', $preference['selection'], $name );
			self::assertSame( 'refused', $preference['native_write'], $name );
			self::assertSame( 'upstream_global_clear_cache', $preference['native_write_reason'], $name );
			self::assertArrayNotHasKey( 'execute_with', $preference, $name );
		}
		self::assertSame( 'read_only', $result['native_preferred']['elementor/get-page-structure']['native_write'] );
		self::assertSame( 'native-write-refused', $result['native_elementor']['certification']['elementor/manage-classes']['selection'] );
		self::assertSame( 'native-preferred', $result['native_elementor']['certification']['elementor/build-composition']['selection'] );
		self::assertFalse( $result['writes_enabled'] );
	}

	public function test_provider_row_lists_the_certified_write_primitives_and_never_the_read_only_ability(): void {
		$result   = $this->router( self::recorded_abilities() )->inspect();
		$provider = self::index_by( $result['providers'], 'id' )['elementor-core'];

		self::assertSame( 'certified', $provider['certification'] );
		self::assertFalse( $provider['read_only'] );
		self::assertSame(
			[ 'elementor/build-composition', 'elementor/manage-classes', 'elementor/manage-default-styles', 'elementor/manage-global-variable' ],
			array_column( $provider['write_primitives'], 'name' )
		);
		foreach ( $provider['write_primitives'] as $primitive ) {
			self::assertFalse( $primitive['routable'] );
			self::assertTrue( $primitive['safety_closure_required'] );
		}
	}

	public function test_provider_certification_does_not_depend_on_ability_order(): void {
		$abilities = self::recorded_abilities();
		$orders    = [ $abilities, array_reverse( $abilities ) ];
		foreach ( $orders as $order ) {
			$order[] = self::unsupported_candidate( 'elementor/manage-elements', 'Manage_Elements_Ability' );
			$provider = self::index_by( $this->router( $order )->inspect()['providers'], 'id' )['elementor-core'];

			self::assertSame( 'certified', $provider['certification'] );
		}

		$rejected = self::recorded_abilities();
		foreach ( $rejected as $index => $ability ) {
			$rejected[ $index ]['runtime_class'] = 'Elementor\\Modules\\Mcp\\Abilities\\Other_Ability';
		}
		$rejected[] = self::unsupported_candidate( 'elementor/manage-elements', 'Manage_Elements_Ability' );
		$provider = self::index_by( $this->router( $rejected )->inspect()['providers'], 'id' )['elementor-core'];
		self::assertSame( 'rejected', $provider['certification'] );
		self::assertSame( [], $provider['write_primitives'] );
		self::assertTrue( $provider['read_only'] );
	}

	public function test_a_mismatch_in_any_certified_ability_fails_closed_with_the_exact_reasons(): void {
		foreach ( self::CERTIFIED as $name ) {
			$abilities = self::recorded_abilities();
			foreach ( $abilities as $index => $ability ) {
				if ( $name === $ability['name'] ) {
					$abilities[ $index ]['runtime_class'] = 'Elementor\\Modules\\Mcp\\Abilities\\Other_Ability';
					$abilities[ $index ]['input_schema']['properties']['injected'] = [ 'type' => 'string' ];
				}
			}
			$result     = $this->router( $abilities )->inspect();
			$preference = $result['native_preferred'][ $name ];

			self::assertSame( 'rejected', $preference['certification'], $name );
			self::assertSame( 'unsupported', $preference['selection'], $name );
			self::assertSame( 'upstream_contract_not_certified', $preference['reason'], $name );
			self::assertContains( 'runtime_identity_mismatch', $preference['issues'], $name );
			self::assertContains( 'input_schema_mismatch', $preference['issues'], $name );
			self::assertArrayNotHasKey( 'input_schema', $preference, $name );
			self::assertSame( 'summary_only_untrusted_or_rejected', $preference['schema_output'], $name );
			foreach ( array_diff( self::CERTIFIED, [ $name ] ) as $other ) {
				self::assertSame( 'certified', $result['native_preferred'][ $other ]['certification'], $name . ' must not affect ' . $other );
			}
		}
	}

	public function test_manage_elements_stays_unsupported_with_its_reasons(): void {
		$abilities = self::recorded_abilities();
		$abilities[] = self::unsupported_candidate( 'elementor/manage-elements', 'Manage_Elements_Ability' );

		$result = $this->router( $abilities )->inspect();

		$elements = $result['native_preferred']['elementor/manage-elements'];
		self::assertSame( 'unsupported', $elements['selection'] );
		self::assertSame( 'unsupported', $elements['certification'] );
		self::assertSame( 'upstream_global_clear_cache', $elements['reason'] );
		self::assertSame( [ 'upstream_global_clear_cache', 'staged_in_autosave' ], $elements['reasons'] );
		self::assertFalse( $elements['routable_write'] );
		$composition = $result['native_preferred']['elementor/build-composition'];
		self::assertSame( 'native-preferred', $composition['selection'] );
		self::assertContains( 'staged_in_autosave', array_column( $composition['contract']['side_effects'], 'id' ) );
		self::assertContains( 'autosave_truth', $composition['contract']['closure_requirements'] );
		self::assertFalse( $composition['routable_write'] );
		$names = array_column( self::index_by( $result['providers'], 'id' )['elementor-core']['write_primitives'], 'name' );
		self::assertNotContains( 'elementor/manage-elements', $names );
	}

	public function test_unregistered_abilities_are_unsupported_and_never_synthesized(): void {
		$result = $this->router( [] )->inspect();

		foreach ( NativeContracts::names() as $name ) {
			$preference = $result['native_preferred'][ $name ];
			self::assertFalse( $preference['available'], $name );
			self::assertSame( 'unsupported', $preference['selection'], $name );
			self::assertSame( 'upstream_ability_not_registered', $preference['reason'], $name );
			self::assertArrayNotHasKey( 'input_schema', $preference, $name );
		}
	}

	public function test_native_elementor_block_reports_environment_abilities_ownership_fingerprints_and_certification(): void {
		$abilities   = self::recorded_abilities();
		$abilities[] = self::unsupported_candidate( 'elementor/manage-elements', 'Manage_Elements_Ability' );
		$result      = $this->router( $abilities )->inspect();
		$block       = $result['native_elementor'];

		self::assertSame( 1, $block['schema'] );
		self::assertSame( 'available', $block['state'] );
		self::assertSame( [ 'installed' => true, 'version' => '4.3.4' ], $block['elementor'] );
		self::assertTrue( $block['mcp_module']['active'] );
		self::assertSame( [], $block['mcp_module']['missing'] );
		self::assertSame( 6, $block['abilities_count'] );
		self::assertSame(
			[ 'elementor/build-composition', 'elementor/get-page-structure', 'elementor/manage-classes', 'elementor/manage-default-styles', 'elementor/manage-elements', 'elementor/manage-global-variable' ],
			$block['abilities']
		);
		self::assertFalse( $block['abilities_truncated'] );
		self::assertSame( 'official', $block['ownership']['status'] );
		self::assertSame( [ 'elementor-core' ], $block['ownership']['provider_ids'] );
		self::assertSame( '4.3.4', $block['ownership']['source_version'] );
		self::assertSame( $block['abilities'], array_keys( $block['schema_fingerprints'] ) );
		foreach ( $block['schema_fingerprints'] as $fingerprint ) {
			self::assertMatchesRegularExpression( '/^[a-f0-9]{64}$/', $fingerprint );
		}
		self::assertSame( $result['native_preferred']['elementor/manage-classes']['schema_fingerprint'], $block['schema_fingerprints']['elementor/manage-classes'] );
		self::assertSame( self::CERTIFIED, $block['certified'] );
		self::assertSame( 'certified', $block['certification']['elementor/manage-classes']['state'] );
		self::assertSame( [ 'elementor/manage-elements' => 'upstream_global_clear_cache' ], $block['unsupported'] );
		self::assertFalse( $block['writes_enabled'] );
		self::assertFalse( $block['routable_write'] );
		self::assertSame( [], $block['contract_errors'] );
	}

	/** @return array<string,array{0:array<string,mixed>,1:string}> */
	public static function environment_states(): array {
		$base = [
			'elementor_version' => '4.3.4', 'module_present' => true, 'module_active' => true, 'abilities_api' => true,
			'mcp_adapter' => true, 'mcp_adapter_version' => '0.6.1', 'mcp_adapter_provider' => 'elementor-core',
			'mcp_composer' => true, 'mcp_composer_version' => '1.0.19', 'site_exposure_enabled' => true, 'atomic_editor_active' => true,
		];
		return [
			'not installed'         => [ [], 'not_installed' ],
			'module unavailable'    => [ array_merge( $base, [ 'module_present' => false, 'module_active' => false ] ), 'module_unavailable' ],
			'requirements missing'  => [ array_merge( $base, [ 'mcp_composer' => false, 'module_active' => false ] ), 'requirements_missing' ],
			'exposure disabled'     => [ array_merge( $base, [ 'site_exposure_enabled' => false ] ), 'exposure_disabled' ],
			'no abilities'          => [ $base, 'no_abilities_registered' ],
		];
	}

	/** @dataProvider environment_states */
	public function test_state_explains_why_no_native_ability_is_available( array $facts, string $expected ): void {
		$router = new ProviderRouter(
			static fn(): array => [ 'document_architecture' => 'v4', 'write_target' => 'v4', 'write_blocked' => false ],
			static fn(): array => [],
			static fn(): array => [ 'items' => [], 'issues' => [] ],
			static fn(): array => [],
			static fn(): array => UpstreamAbilityDiscovery::summarize_environment( $facts )
		);

		$block = $router->inspect()['native_elementor'];

		self::assertSame( $expected, $block['state'] );
		self::assertSame( [], $block['abilities'] );
		self::assertSame( 0, $block['abilities_count'] );
		self::assertSame( [], $block['certified'] );
	}

	public function test_registered_but_uncertified_abilities_report_available_uncertified(): void {
		$abilities = self::recorded_abilities();
		foreach ( $abilities as $index => $ability ) {
			$abilities[ $index ]['runtime_class'] = 'Elementor\\Modules\\Mcp\\Abilities\\Other_Ability';
		}

		$block = $this->router( $abilities )->inspect()['native_elementor'];

		self::assertSame( 'available_uncertified', $block['state'] );
		self::assertSame( [], $block['certified'] );
		self::assertSame( 'rejected', $block['certification']['elementor/manage-classes']['state'] );
		self::assertContains( 'runtime_identity_mismatch', $block['certification']['elementor/manage-classes']['issues'] );
	}

	public function test_third_party_abilities_in_the_elementor_namespace_are_reported_as_mixed_ownership(): void {
		$abilities   = self::recorded_abilities();
		$abilities[] = [
			'name' => 'elementor/third-party-extra', 'description' => 'x', 'input_schema' => [], 'output_schema' => [],
			'meta' => [ 'annotations' => [ 'readonly' => true ] ], 'source_plugin' => 'acme-widgets/acme.php', 'source_version' => '1.0.0',
			'runtime_class' => 'Acme\\Extra', 'provenance' => [ 'ownership' => 'active_plugin_header' ],
		];

		$block = $this->router( $abilities )->inspect()['native_elementor'];

		self::assertSame( 'mixed', $block['ownership']['status'] );
		self::assertSame( [ 'elementor-core', 'plugin:acme-widgets' ], $block['ownership']['provider_ids'] );
		self::assertSame( self::CERTIFIED, $block['certified'] );
	}

	public function test_adversarial_inventory_is_bounded_in_the_native_block(): void {
		$abilities = self::recorded_abilities();
		for ( $index = 0; $index < 1000; ++$index ) {
			$abilities[] = [
				'name' => 'elementor/extra-' . $index, 'description' => 'x', 'input_schema' => [], 'output_schema' => [],
				'meta' => [ 'annotations' => [ 'readonly' => true ] ], 'source_plugin' => 'elementor/elementor.php', 'source_version' => '4.3.4',
				'runtime_class' => 'Elementor\\Modules\\Mcp\\Abilities\\Extra_' . $index, 'provenance' => [ 'ownership' => 'active_plugin_header' ],
			];
		}

		$block = $this->router( $abilities )->inspect()['native_elementor'];

		self::assertSame( 1005, $block['abilities_count'] );
		self::assertCount( 100, $block['abilities'] );
		self::assertTrue( $block['abilities_truncated'] );
		self::assertLessThanOrEqual( 100, count( $block['schema_fingerprints'] ) );
		self::assertTrue( $block['schema_fingerprints_truncated'] );
		self::assertSame( self::CERTIFIED, $block['certified'] );
		self::assertLessThan( 20000, strlen( (string) wp_json_encode( $block ) ) );
	}

	public function test_native_elementor_probe_skips_the_widget_and_atomic_discovery(): void {
		$calls  = [];
		$router = new ProviderRouter(
			static function () use ( &$calls ): array {
				$calls[] = 'architecture';
				return [];
			},
			static function () use ( &$calls ): array {
				$calls[] = 'v3';
				return [];
			},
			static function () use ( &$calls ): array {
				$calls[] = 'atomic';
				return [ 'items' => [], 'issues' => [] ];
			},
			static function () use ( &$calls ): array {
				$calls[] = 'abilities';
				return self::recorded_abilities();
			},
			static function () use ( &$calls ): array {
				$calls[] = 'environment';
				return self::environment();
			}
		);

		$block = $router->native_elementor();

		self::assertSame( [ 'abilities', 'environment' ], $calls );
		self::assertSame( 'available', $block['state'] );
		self::assertSame( self::CERTIFIED, $block['certified'] );
	}

	public function test_a_throwing_ability_or_environment_provider_degrades_without_leaking_details(): void {
		$throwing_abilities = new ProviderRouter(
			static fn(): array => [],
			static fn(): array => [],
			static fn(): array => [ 'items' => [], 'issues' => [] ],
			static function (): never {
				throw new \RuntimeException( 'private ability detail' );
			},
			fn(): array => self::environment()
		);
		$block = $throwing_abilities->native_elementor();
		self::assertSame( 'no_abilities_registered', $block['state'] );
		self::assertSame( [ 'abilities' ], $block['discovery_failed'] );
		self::assertStringNotContainsString( 'private ability detail', (string) wp_json_encode( $block ) );

		$throwing_environment = new ProviderRouter(
			static fn(): array => [],
			static fn(): array => [],
			static fn(): array => [ 'items' => [], 'issues' => [] ],
			static fn(): array => self::recorded_abilities(),
			static function (): never {
				throw new \RuntimeException( 'private environment detail' );
			}
		);
		$block = $throwing_environment->native_elementor();
		self::assertFalse( $block['elementor']['installed'] );
		self::assertSame( [ 'environment' ], $block['discovery_failed'] );
		self::assertStringNotContainsString( 'private environment detail', (string) wp_json_encode( $block ) );
	}

	/** @param list<array<string,mixed>> $abilities */
	private function router( array $abilities ): ProviderRouter {
		return new ProviderRouter(
			static fn(): array => [ 'document_architecture' => 'v4', 'write_target' => 'v4', 'write_blocked' => false ],
			static fn(): array => [],
			static fn(): array => [ 'items' => [], 'issues' => [] ],
			static fn(): array => $abilities,
			static fn(): array => self::environment()
		);
	}

	/** @return array<string,mixed> */
	private static function environment(): array {
		return UpstreamAbilityDiscovery::summarize_environment(
			[
				'elementor_version' => '4.3.4', 'module_present' => true, 'module_active' => true, 'abilities_api' => true,
				'mcp_adapter' => true, 'mcp_adapter_version' => '0.6.1', 'mcp_adapter_provider' => 'elementor-core',
				'mcp_composer' => true, 'mcp_composer_version' => '1.0.19', 'site_exposure_enabled' => true, 'atomic_editor_active' => true,
			]
		);
	}

	/** @return list<array<string,mixed>> */
	private static function recorded_abilities(): array {
		$path    = dirname( __DIR__, 3 ) . '/fixtures/elementor-native/elementor-4.3.4-abilities.json';
		$decoded = json_decode( (string) file_get_contents( $path ), true );
		return array_values( (array) $decoded['abilities'] );
	}

	/** @return array<string,mixed> */
	private static function unsupported_candidate( string $name, string $class ): array {
		$ability = self::recorded_abilities()[0];
		$ability['name'] = $name;
		$ability['runtime_class'] = 'Elementor\\Modules\\Mcp\\Abilities\\' . $class;
		$ability['meta']['annotations'] = [ 'readonly' => false, 'destructive' => true, 'idempotent' => false ];
		return $ability;
	}

	/** @param list<array<string,mixed>> $rows @return array<string,array<string,mixed>> */
	private static function index_by( array $rows, string $key ): array {
		$out = [];
		foreach ( $rows as $row ) {
			$out[ (string) $row[ $key ] ] = $row;
		}
		return $out;
	}
}
