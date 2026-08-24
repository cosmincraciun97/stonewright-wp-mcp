<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\Tests\Unit\Elementor\Provider;

use PHPUnit\Framework\TestCase;
use Stonewright\WpMcp\Elementor\V4\AtomicSchemaRepository;

/** @covers \Stonewright\WpMcp\Elementor\V4\AtomicSchemaRepository::runtime_discovery */
final class AtomicProviderDiscoveryTest extends TestCase {

	private object $original;

	protected function setUp(): void {
		AtomicSchemaRepository::invalidate();
		$this->original = \Elementor\Plugin::$instance;
		\Elementor\Plugin::$instance = (object) [
			'elements_manager' => new AtomicDiscoveryManager(),
			'widgets_manager'  => new AtomicDiscoveryManager(),
		];
	}

	protected function tearDown(): void {
		\Elementor\Plugin::$instance = $this->original;
		AtomicSchemaRepository::invalidate();
	}

	public function test_one_broken_extension_does_not_hide_other_atomic_providers(): void {
		$result = AtomicSchemaRepository::runtime_discovery();

		self::assertCount( 2, $result['items'] );
		self::assertSame( 'e-good', $result['items'][0]['atomic_type'] );
		self::assertSame( 'e-after-broken-prop', $result['items'][1]['atomic_type'] );
		self::assertMatchesRegularExpression( '/^[a-f0-9]{64}$/', $result['items'][0]['schema_fingerprint'] );
		self::assertSame( 'live_elementor_runtime', $result['items'][0]['provenance']['schema'] );
		self::assertSame( [ 'schema_unavailable', 'prop_schema_unavailable' ], array_column( $result['issues'], 'code' ) );
		self::assertSame( [ 'e-bad', 'e-bad-prop' ], array_column( $result['issues'], 'atomic_type' ) );
	}

	public function test_uncertified_runtime_schemas_remain_inventory_only(): void {
		$discovery = AtomicSchemaRepository::runtime_discovery();
		$write_schemas = AtomicSchemaRepository::all();

		self::assertContains( 'e-good', array_column( $discovery['items'], 'atomic_type' ) );
		self::assertArrayNotHasKey( 'e-good', $write_schemas );
		self::assertArrayHasKey( 'e-heading', $write_schemas );
	}
}

final class AtomicDiscoveryManager {
	/** @return array<string,object> */
	public function get_element_types(): array {
		return [
			'e-good'              => new GoodAtomicExtension(),
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

final class AtomicPropSchema {
	/** @return array<string,mixed> */
	public function to_json_schema(): array {
		return [ 'type' => 'string', 'minLength' => 1 ];
	}
}

final class BrokenAtomicPropSchema {
	/** @return array<string,mixed> */
	public function to_json_schema(): array {
		throw new \RuntimeException( 'broken prop schema' );
	}
}
