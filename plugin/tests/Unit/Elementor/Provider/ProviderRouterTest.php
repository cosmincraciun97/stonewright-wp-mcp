<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\Tests\Unit\Elementor\Provider;

use PHPUnit\Framework\TestCase;
use Stonewright\WpMcp\Elementor\Provider\ProviderRouter;

/**
 * @covers \Stonewright\WpMcp\Elementor\Provider\ProviderRouter
 */
final class ProviderRouterTest extends TestCase {

	public function test_architecture_is_resolved_before_any_provider_discovery(): void {
		$order = [];
		$router = new ProviderRouter(
			static function () use ( &$order ): array {
				$order[] = 'architecture';
				return self::architecture( 'v3' );
			},
			static function () use ( &$order ): array {
				$order[] = 'v3';
				return [];
			},
			static function () use ( &$order ): array {
				$order[] = 'atomic';
				return [ 'items' => [], 'issues' => [] ];
			},
			static function () use ( &$order ): array {
				$order[] = 'abilities';
				return [];
			}
		);

		$router->inspect( 42 );

		self::assertSame( [ 'architecture', 'v3', 'atomic', 'abilities' ], $order );
	}

	public function test_discovers_official_and_third_party_v3_ownership_from_live_schema_evidence(): void {
		$router = $this->router(
			'v3',
			[
				self::v3_widget( 'heading', 'elementor/elementor.php', '3.30.0', 'Elementor\\Widget_Heading', 'core-hash' ),
				self::v3_widget( 'form', 'elementor-pro/elementor-pro.php', '3.30.0', 'ElementorPro\\Modules\\Forms\\Widgets\\Form', 'pro-hash' ),
				self::v3_widget( 'card', 'acme-widgets/acme.php', '2.4.0', 'Acme\\Widgets\\Card', 'acme-hash' ),
			]
		);

		$result = $router->inspect( 7 );
		$providers = self::index_by( $result['providers'], 'id' );

		self::assertSame( 'supported', $result['selection']['status'] );
		self::assertSame( 'official', $providers['elementor-core']['ownership'] );
		self::assertSame( 'official', $providers['elementor-pro']['ownership'] );
		self::assertSame( 'third-party', $providers['plugin:acme-widgets']['ownership'] );
		self::assertSame( [ 'v3' ], $providers['plugin:acme-widgets']['architectures'] );
		self::assertSame( 'acme-hash', $providers['plugin:acme-widgets']['capabilities'][0]['schema_fingerprint'] );
		self::assertSame( [], $providers['plugin:acme-widgets']['write_primitives'] );
		self::assertTrue( $providers['plugin:acme-widgets']['read_only'] );
	}

	public function test_discovers_atomic_extensions_and_preserves_runtime_schema_provenance(): void {
		$router = $this->router(
			'v4',
			[],
			[
				'items' => [
					[
						'atomic_type'       => 'e-acme-card',
						'kind'              => 'widget',
						'source_plugin'     => 'acme-atomic/acme.php',
						'source_version'    => '1.2.0',
						'runtime_class'     => 'Acme\\Atomic\\Card',
						'schema_fingerprint'=> 'atomic-hash',
						'provenance'        => [ 'schema' => 'live_elementor_runtime' ],
					],
				],
				'issues' => [],
			]
		);

		$result = $router->inspect( 8 );
		$provider = self::index_by( $result['providers'], 'id' )['plugin:acme-atomic'];

		self::assertSame( 'supported', $result['selection']['status'] );
		self::assertSame( [ 'v4' ], $provider['architectures'] );
		self::assertSame( 'atomic-hash', $provider['capabilities'][0]['schema_fingerprint'] );
		self::assertSame( 'live_elementor_runtime', $provider['capabilities'][0]['provenance']['schema'] );
	}

	public function test_incomplete_or_unknown_provider_evidence_is_unsupported_and_never_guessed(): void {
		$router = $this->router(
			'v4',
			[],
			[
				'items' => [
					[
						'atomic_type'    => 'e-mystery',
						'kind'           => 'widget',
						'source_plugin'  => '',
						'runtime_class'  => '',
					],
				],
				'issues' => [ [ 'code' => 'schema_unavailable', 'atomic_type' => 'e-broken' ] ],
			]
		);

		$result = $router->inspect( 9 );

		self::assertSame( 'unsupported', $result['selection']['status'] );
		self::assertSame( [], $result['providers'] );
		self::assertContains( 'incomplete_provider_evidence', array_column( $result['issues'], 'code' ) );
		self::assertContains( 'schema_unavailable', array_column( $result['issues'], 'code' ) );
	}

	public function test_mixed_architecture_remains_unsupported_even_when_providers_exist(): void {
		$router = $this->router( 'mixed', [ self::v3_widget( 'heading', 'elementor/elementor.php', '3.30.0', 'Elementor\\Widget_Heading', 'hash' ) ] );

		$result = $router->inspect( 10 );

		self::assertSame( 'unsupported', $result['selection']['status'] );
		self::assertSame( 'mixed_architecture', $result['selection']['reason'] );
		self::assertFalse( $result['writes_enabled'] );
	}

	public function test_manage_default_styles_is_native_preferred_only_from_upstream_runtime_metadata(): void {
		$ability = [
			'name'          => 'elementor/manage-default-styles',
			'label'         => 'Manage default styles',
			'description'   => 'Reads and updates responsive default styles.',
			'input_schema'  => [ 'type' => 'object', 'properties' => [ 'operations' => [ 'type' => 'array', 'maxItems' => 20 ] ] ],
			'output_schema' => [ 'type' => 'object' ],
			'meta'          => [ 'category' => 'elementor-styles', 'source_plugin' => 'elementor/elementor.php', 'source_version' => '3.30.0' ],
			'runtime_class' => 'Elementor\\Modules\\AtomicWidgets\\Abilities\\ManageDefaultStyles',
		];
		$read_only = $ability;
		$read_only['name'] = 'elementor/get-page-structure';
		$read_only['meta']['annotations'] = [ 'readOnlyHint' => true ];
		$router = $this->router( 'v3', [], [ 'items' => [], 'issues' => [] ], [ $ability, $read_only ] );

		$result = $router->inspect();
		$preference = $result['native_preferred']['elementor/manage-default-styles'];

		self::assertTrue( $preference['available'] );
		self::assertSame( 'native-preferred', $preference['selection'] );
		self::assertSame( 20, $preference['input_schema']['properties']['operations']['maxItems'] );
		self::assertSame( $ability['description'], $preference['description'] );
		self::assertFalse( $preference['routable_write'] );
		self::assertTrue( $preference['safety_closure_required'] );
		self::assertMatchesRegularExpression( '/^[a-f0-9]{64}$/', $preference['schema_fingerprint'] );
		$provider = self::index_by( $result['providers'], 'id' )['elementor-core'];
		self::assertSame( [ 'global' ], $provider['architectures'] );
		self::assertSame( [ 'elementor/manage-default-styles' ], array_column( $provider['write_primitives'], 'name' ) );
	}

	public function test_manage_default_styles_is_not_synthesized_when_upstream_ability_is_absent(): void {
		$result = $this->router( 'v3' )->inspect();

		self::assertFalse( $result['native_preferred']['elementor/manage-default-styles']['available'] );
		self::assertSame( 'unsupported', $result['native_preferred']['elementor/manage-default-styles']['selection'] );
		self::assertArrayNotHasKey( 'input_schema', $result['native_preferred']['elementor/manage-default-styles'] );
	}

	/** @param list<array<string,mixed>> $v3 */
	private function router( string $architecture, array $v3 = [], array $atomic = [ 'items' => [], 'issues' => [] ], array $abilities = [] ): ProviderRouter {
		return new ProviderRouter(
			static fn(): array => self::architecture( $architecture ),
			static fn(): array => $v3,
			static fn(): array => $atomic,
			static fn(): array => $abilities
		);
	}

	/** @return array<string,mixed> */
	private static function architecture( string $architecture ): array {
		return [
			'document_architecture' => $architecture,
			'write_target'          => 'mixed' === $architecture ? 'v3-surgical' : $architecture,
			'write_blocked'         => in_array( $architecture, [ 'mixed', 'unknown' ], true ),
		];
	}

	/** @return array<string,mixed> */
	private static function v3_widget( string $type, string $plugin, string $version, string $class, string $hash ): array {
		return [
			'widget_type'        => $type,
			'source_plugin'      => $plugin,
			'source_version'     => $version,
			'runtime_class'      => $class,
			'schema_hash'        => $hash,
			'provenance'         => [ 'controls' => 'live_elementor_runtime' ],
		];
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
