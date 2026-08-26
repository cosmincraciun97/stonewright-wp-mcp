<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\Tests\Unit\Elementor\V4;

use PHPUnit\Framework\TestCase;
use Stonewright\WpMcp\Elementor\V4\AtomicPropContractAdapter;
use Stonewright\WpMcp\Elementor\V4\AtomicPropDescriptorNormalizer;
use Stonewright\WpMcp\Elementor\V4\AtomicSchemaRepository;

/**
 * @covers \Stonewright\WpMcp\Elementor\V4\AtomicPropDescriptorNormalizer
 * @covers \Stonewright\WpMcp\Elementor\V4\AtomicPropContractAdapter
 * @covers \Stonewright\WpMcp\Elementor\V4\AtomicSchemaRepository::provider_policy
 */
final class AtomicPropDescriptorNormalizerTest extends TestCase {

	protected function setUp(): void {
		AtomicPropContractAdapter::reset();
		AtomicSchemaRepository::invalidate();
	}

	protected function tearDown(): void {
		AtomicPropContractAdapter::reset();
		AtomicSchemaRepository::invalidate();
	}

	public function test_json_serializable_prop_uses_current_descriptor_format(): void {
		$result = AtomicPropDescriptorNormalizer::normalize( new SerializableProp() );

		self::assertTrue( $result['ok'] );
		self::assertSame( 'elementor-json-serializable-v1', $result['descriptor_format'] );
		self::assertSame( [ 'default' => '', 'type' => 'string' ], $result['runtime_descriptor'] );
		self::assertNull( $result['issue'] );
	}

	public function test_legacy_to_json_schema_prop_uses_legacy_descriptor_format(): void {
		$result = AtomicPropDescriptorNormalizer::normalize( new LegacyProp() );

		self::assertTrue( $result['ok'] );
		self::assertSame( 'legacy-json-schema-v1', $result['descriptor_format'] );
		self::assertSame( [ 'type' => 'number' ], $result['runtime_descriptor'] );
		self::assertNull( $result['issue'] );
	}

	public function test_throwing_prop_returns_bounded_descriptor_unavailable_without_exception_message(): void {
		$result = AtomicPropDescriptorNormalizer::normalize( new ThrowingProp() );

		self::assertFalse( $result['ok'] );
		self::assertNull( $result['runtime_descriptor'] );
		self::assertSame( 'descriptor_unavailable', $result['issue']['code'] );
		self::assertSame( \RuntimeException::class, $result['issue']['error_class'] );
		self::assertArrayNotHasKey( 'message', $result['issue'] );
		self::assertStringNotContainsString( 'synthetic failure', (string) wp_json_encode( $result ) );
	}

	public function test_malformed_prop_returns_bounded_descriptor_unavailable(): void {
		$result = AtomicPropDescriptorNormalizer::normalize( new MalformedProp() );

		self::assertFalse( $result['ok'] );
		self::assertSame( 'descriptor_unavailable', $result['issue']['code'] );
		self::assertSame( '', $result['issue']['error_class'] );
		self::assertStringNotContainsString( 'MalformedProp', (string) wp_json_encode( $result['issue'] ) );
	}

	public function test_prefers_json_serializable_over_legacy_when_both_exist(): void {
		$result = AtomicPropDescriptorNormalizer::normalize( new DualFormatProp() );

		self::assertTrue( $result['ok'] );
		self::assertSame( 'elementor-json-serializable-v1', $result['descriptor_format'] );
		self::assertSame( [ 'type' => 'string' ], $result['runtime_descriptor'] );
	}

	public function test_rejects_resources_closures_recursion_and_non_finite_floats(): void {
		$resource = fopen( 'php://memory', 'r' );
		self::assertIsResource( $resource );
		try {
			$resource_result = AtomicPropDescriptorNormalizer::normalize( new ArrayProp( [ 'file' => $resource ] ) );
			self::assertFalse( $resource_result['ok'] );
			self::assertSame( 'descriptor_unavailable', $resource_result['issue']['code'] );
		} finally {
			fclose( $resource );
		}

		$closure_result = AtomicPropDescriptorNormalizer::normalize( new ArrayProp( [ 'fn' => static fn(): int => 1 ] ) );
		self::assertFalse( $closure_result['ok'] );

		$recursive_result = AtomicPropDescriptorNormalizer::normalize( new RecursiveProp() );
		self::assertFalse( $recursive_result['ok'] );

		$nan_result = AtomicPropDescriptorNormalizer::normalize( new ArrayProp( [ 'n' => NAN ] ) );
		self::assertFalse( $nan_result['ok'] );

		$inf_result = AtomicPropDescriptorNormalizer::normalize( new ArrayProp( [ 'n' => INF ] ) );
		self::assertFalse( $inf_result['ok'] );
	}

	public function test_rejects_unbounded_depth_key_count_and_encoded_bytes(): void {
		$deep = 0;
		for ( $i = 0; $i < 9; $i++ ) {
			$deep = [ 'n' => $deep ];
		}
		$deep_result = AtomicPropDescriptorNormalizer::normalize( new ArrayProp( is_array( $deep ) ? $deep : [] ) );
		self::assertFalse( $deep_result['ok'] );

		$keys = [];
		for ( $i = 0; $i < 257; $i++ ) {
			$keys[ 'k' . $i ] = 1;
		}
		$keys_result = AtomicPropDescriptorNormalizer::normalize( new ArrayProp( $keys ) );
		self::assertFalse( $keys_result['ok'] );

		$wide = [];
		for ( $i = 0; $i < 200; $i++ ) {
			$wide[ 'k' . $i ] = str_repeat( 'a', 160 );
		}
		$bytes_result = AtomicPropDescriptorNormalizer::normalize( new ArrayProp( $wide ) );
		self::assertFalse( $bytes_result['ok'] );
	}

	public function test_truncates_dynamic_strings_and_canonicalizes_keys(): void {
		$result = AtomicPropDescriptorNormalizer::normalize(
			new ArrayProp(
				[
					'zeta' => 'ok',
					'alpha' => str_repeat( 'b', 1200 ),
				]
			)
		);

		self::assertTrue( $result['ok'] );
		self::assertSame( [ 'alpha', 'zeta' ], array_keys( $result['runtime_descriptor'] ) );
		self::assertSame( 1000, strlen( (string) $result['runtime_descriptor']['alpha'] ) );
	}

	public function test_official_runtime_stays_inventory_only_until_adapter_matches(): void {
		$normalized = AtomicPropDescriptorNormalizer::normalize( new SerializableProp() );
		$evidence   = self::official_evidence( $normalized );

		$before = AtomicSchemaRepository::provider_policy( $evidence );
		self::assertSame( 'official', $before['ownership_trust'] );
		self::assertSame( 'inventory-only', $before['schema_certification'] );
		self::assertFalse( $before['write_eligible'] );

		AtomicPropContractAdapter::register(
			[
				'id'                     => 'synthetic-string-v1',
				'descriptor_format'      => 'elementor-json-serializable-v1',
				'elementor_version_min'  => '3.29.0',
				'elementor_version_max'  => '3.32.99',
				'input_fingerprints'     => [ AtomicPropContractAdapter::fingerprint( $normalized['runtime_descriptor'] ) ],
				'compact_contract'       => [ 'key' => 'title', 'type' => 'html-v3' ],
			]
		);

		$after = AtomicSchemaRepository::provider_policy( $evidence );
		self::assertSame( 'official', $after['ownership_trust'] );
		self::assertSame( 'adapter-certified', $after['schema_certification'] );
		self::assertTrue( $after['write_eligible'] );
	}

	public function test_adapter_mismatch_and_inferred_types_remain_inventory_only(): void {
		$normalized = AtomicPropDescriptorNormalizer::normalize( new SerializableProp() );
		$evidence   = self::official_evidence( $normalized );

		AtomicPropContractAdapter::register(
			[
				'id'                    => 'wrong-fingerprint',
				'descriptor_format'     => 'elementor-json-serializable-v1',
				'elementor_version_min' => '3.29.0',
				'elementor_version_max' => '3.32.99',
				'input_fingerprints'    => [ hash( 'sha256', 'other-fixture' ) ],
				'compact_contract'      => [ 'key' => 'title', 'type' => 'html-v3' ],
			]
		);
		self::assertSame( 'inventory-only', AtomicSchemaRepository::provider_policy( $evidence )['schema_certification'] );

		AtomicPropContractAdapter::reset();
		AtomicPropContractAdapter::register(
			[
				'id'                    => 'version-miss',
				'descriptor_format'     => 'elementor-json-serializable-v1',
				'elementor_version_min' => '3.0.0',
				'elementor_version_max' => '3.20.0',
				'input_fingerprints'    => [ AtomicPropContractAdapter::fingerprint( $normalized['runtime_descriptor'] ) ],
				'compact_contract'      => [ 'key' => 'title', 'type' => 'html-v3' ],
			]
		);
		self::assertFalse( AtomicSchemaRepository::provider_policy( $evidence )['write_eligible'] );

		AtomicPropContractAdapter::reset();
		AtomicPropContractAdapter::register(
			[
				'id'                    => 'inferred-json-schema-type',
				'descriptor_format'     => 'elementor-json-serializable-v1',
				'elementor_version_min' => '3.29.0',
				'elementor_version_max' => '3.32.99',
				'input_fingerprints'    => [ AtomicPropContractAdapter::fingerprint( $normalized['runtime_descriptor'] ) ],
				'compact_contract'      => [ 'key' => 'title', 'type' => 'string' ],
			]
		);
		$inferred = AtomicSchemaRepository::provider_policy(
			self::official_evidence( $normalized ) + [
				'atomic_type'   => 'e-heading',
				'prop'          => 'title',
				'runtime_class' => 'Elementor\\Modules\\AtomicWidgets\\Elements\\Heading',
			]
		);
		self::assertSame( 'inventory-only', $inferred['schema_certification'] );
		self::assertFalse( $inferred['write_eligible'] );
	}

	public function test_bundled_contracts_remain_write_eligible(): void {
		$schema = AtomicSchemaRepository::for_atomic_type( 'e-heading' );

		self::assertIsArray( $schema );
		self::assertSame( 'official', $schema['ownership_trust'] );
		self::assertSame( 'bundled', $schema['schema_certification'] );
		self::assertTrue( $schema['write_eligible'] );

		$policy = AtomicSchemaRepository::provider_policy( $schema );
		self::assertSame( 'official', $policy['ownership_trust'] );
		self::assertSame( 'bundled', $policy['schema_certification'] );
		self::assertTrue( $policy['write_eligible'] );
	}

	/**
	 * @param array{descriptor_format:?string,runtime_descriptor:?array<string,mixed>} $normalized
	 * @return array<string,mixed>
	 */
	private static function official_evidence( array $normalized ): array {
		return [
			'provider_id'         => 'elementor-core',
			'source'              => 'live_runtime',
			'source_version'      => '3.30.0',
			'elementor_version'   => '3.30.0',
			'descriptor_format'   => $normalized['descriptor_format'],
			'runtime_descriptor'  => $normalized['runtime_descriptor'],
			'provenance'          => [ 'ownership' => 'active_plugin_header' ],
		];
	}
}

final class SerializableProp implements \JsonSerializable {
	public function jsonSerialize(): array {
		return [ 'type' => 'string', 'default' => '' ];
	}
}

final class LegacyProp {
	/** @return array<string,mixed> */
	public function to_json_schema(): array {
		return [ 'type' => 'number' ];
	}
}

final class ThrowingProp implements \JsonSerializable {
	public function jsonSerialize(): mixed {
		throw new \RuntimeException( 'synthetic failure' );
	}
}

final class MalformedProp {
}

final class DualFormatProp implements \JsonSerializable {
	public function jsonSerialize(): array {
		return [ 'type' => 'string' ];
	}

	/** @return array<string,mixed> */
	public function to_json_schema(): array {
		return [ 'type' => 'number' ];
	}
}

final class ArrayProp implements \JsonSerializable {
	/** @param array<array-key,mixed> $payload */
	public function __construct( private array $payload ) {}

	public function jsonSerialize(): array {
		return $this->payload;
	}
}

final class RecursiveProp implements \JsonSerializable {
	public function jsonSerialize(): mixed {
		return $this;
	}
}
