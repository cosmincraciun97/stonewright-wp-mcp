<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\Tests\Unit\Elementor\Provider;

use PHPUnit\Framework\TestCase;
use Stonewright\WpMcp\Elementor\V4\AtomicPropContractAdapter;
use Stonewright\WpMcp\Elementor\V4\AtomicSchemaRepository;

/** @covers \Stonewright\WpMcp\Elementor\V4\AtomicSchemaRepository::runtime_discovery */
final class AtomicProviderDiscoveryTest extends TestCase {

	private object $original;

	protected function setUp(): void {
		AtomicPropContractAdapter::reset();
		AtomicSchemaRepository::invalidate();
		$this->original = \Elementor\Plugin::$instance;
		\Elementor\Plugin::$instance = (object) [
			'elements_manager' => new AtomicDiscoveryManager(),
			'widgets_manager'  => new AtomicDiscoveryManager(),
		];
	}

	protected function tearDown(): void {
		\Elementor\Plugin::$instance = $this->original;
		AtomicPropContractAdapter::reset();
		AtomicSchemaRepository::invalidate();
	}

	public function test_one_broken_extension_does_not_hide_other_atomic_providers(): void {
		$result = AtomicSchemaRepository::runtime_discovery();

		self::assertSame( [ 'e-good', 'e-legacy', 'e-after-broken-prop' ], array_column( $result['items'], 'atomic_type' ) );
		self::assertMatchesRegularExpression( '/^[a-f0-9]{64}$/', $result['items'][0]['schema_fingerprint'] );
		self::assertSame( 'live_elementor_runtime', $result['items'][0]['provenance']['schema'] );
		self::assertSame( 'elementor-json-serializable-v1', $result['items'][0]['props']['title']['descriptor_format'] );
		self::assertArrayHasKey( 'runtime_descriptor', $result['items'][0]['props']['title'] );
		self::assertArrayNotHasKey( 'json_schema', $result['items'][0]['props']['title'] );
		self::assertSame( 'legacy-json-schema-v1', $result['items'][1]['props']['count']['descriptor_format'] );
		self::assertSame( [ 'schema_unavailable', 'descriptor_unavailable' ], array_column( $result['issues'], 'code' ) );
		self::assertSame( [ 'e-bad', 'e-bad-prop' ], array_column( $result['issues'], 'atomic_type' ) );
		self::assertSame( \RuntimeException::class, $result['issues'][1]['error_class'] );
		self::assertStringNotContainsString( 'broken prop schema', (string) wp_json_encode( $result ) );
		self::assertStringNotContainsString( 'broken extension', (string) wp_json_encode( $result ) );
	}

	public function test_uncertified_runtime_schemas_remain_inventory_only(): void {
		$discovery = AtomicSchemaRepository::runtime_discovery();
		$write_schemas = AtomicSchemaRepository::all();
		$good = $discovery['items'][0];

		self::assertContains( 'e-good', array_column( $discovery['items'], 'atomic_type' ) );
		self::assertSame( 'inventory-only', $good['schema_certification'] );
		self::assertFalse( $good['write_eligible'] );
		self::assertArrayNotHasKey( 'e-good', $write_schemas );
		self::assertArrayHasKey( 'e-heading', $write_schemas );
		self::assertTrue( $write_schemas['e-heading']['write_eligible'] );
		self::assertSame( 'bundled', $write_schemas['e-heading']['schema_certification'] );
	}

	public function test_official_ownership_is_not_write_certification(): void {
		$policy = AtomicSchemaRepository::provider_policy(
			[
				'provider_id' => 'elementor-core',
				'source'      => 'live_runtime',
				'provenance'  => [ 'ownership' => 'active_plugin_header' ],
				'props'       => [
					'title' => [
						'descriptor_format'  => 'elementor-json-serializable-v1',
						'runtime_descriptor' => [ 'type' => 'string', 'default' => '' ],
					],
				],
			]
		);

		self::assertSame( 'official', $policy['ownership_trust'] );
		self::assertSame( 'inventory-only', $policy['schema_certification'] );
		self::assertFalse( $policy['write_eligible'] );
	}
}

final class AtomicDiscoveryManager {
	/** @return array<string,object> */
	public function get_element_types(): array {
		return [
			'e-good'              => new GoodAtomicExtension(),
			'e-legacy'            => new LegacyAtomicExtension(),
			'e-bad'               => new BrokenAtomicExtension(),
			'e-bad-prop'          => new BrokenAtomicPropExtension(),
			'e-after-broken-prop' => new GoodAtomicExtension(),
		];
	}

	/** @return array<string,object> */
	public function get_widget_types(): array {
		return [];
	}
}

final class GoodAtomicExtension {
	/** @return array<string,object> */
	public function get_props_schema(): array {
		return [ 'title' => new AtomicPropSchema() ];
	}
}

final class BrokenAtomicExtension {
	/** @return array<string,object> */
	public function get_props_schema(): array {
		throw new \RuntimeException( 'broken extension' );
	}
}

final class BrokenAtomicPropExtension {
	/** @return array<string,object> */
	public function get_props_schema(): array {
		return [ 'broken' => new BrokenAtomicPropSchema() ];
	}
}

final class LegacyAtomicExtension {
	/** @return array<string,object> */
	public function get_props_schema(): array {
		return [ 'count' => new LegacyAtomicProp() ];
	}
}

final class AtomicPropSchema implements \JsonSerializable {
	public function jsonSerialize(): array {
		return [ 'type' => 'string', 'minLength' => 1 ];
	}
}

final class LegacyAtomicProp {
	/** @return array<string,mixed> */
	public function to_json_schema(): array {
		return [ 'type' => 'number' ];
	}
}

final class BrokenAtomicPropSchema implements \JsonSerializable {
	public function jsonSerialize(): mixed {
		throw new \RuntimeException( 'broken prop schema' );
	}
}
