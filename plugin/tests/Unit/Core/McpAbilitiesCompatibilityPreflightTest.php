<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\Tests\Unit\Core;

use PHPUnit\Framework\TestCase;
use Stonewright\WpMcp\Core\McpAbilitiesCompatibilityPreflight;

/**
 * @covers \Stonewright\WpMcp\Core\McpAbilitiesCompatibilityPreflight
 */
final class McpAbilitiesCompatibilityPreflightTest extends TestCase {

	protected function tearDown(): void {
		$GLOBALS['stonewright_test_filters'] = [];
		McpAbilitiesCompatibilityPreflight::reset_for_tests();
	}

	public function test_duplicate_adapter_candidates_are_reported_without_loading_the_class(): void {
		$loads = new FakeLoadCounter();
		$autoloaders = [
			new FakeClassLoader( '/srv/wp-content/plugins/stonewright/vendor/wordpress/mcp-adapter/includes/Core/McpAdapter.php', $loads ),
			new FakeClassLoader( '/srv/wp-content/plugins/another-mcp/vendor/wordpress/mcp-adapter/includes/Core/McpAdapter.php', $loads ),
		];

		$result = McpAbilitiesCompatibilityPreflight::inspect( $autoloaders, 'Vendor\\MissingAdapter' );

		self::assertFalse( $result['compatible'] );
		self::assertSame( 'conflict', $result['adapter']['status'] );
		self::assertSame( [ 'plugin:another-mcp', 'plugin:stonewright' ], $result['adapter']['owners'] );
		self::assertSame( 0, $loads->count, 'Preflight must inspect class maps without invoking an autoloader.' );
		self::assertStringNotContainsString( '/srv/', (string) wp_json_encode( $result ) );
	}

	public function test_same_physical_adapter_seen_by_multiple_loaders_is_not_a_conflict(): void {
		$loads = new FakeLoadCounter();
		$file = '/srv/wp-content/plugins/stonewright/vendor/wordpress/mcp-adapter/includes/Core/McpAdapter.php';

		$result = McpAbilitiesCompatibilityPreflight::inspect(
			[ new FakeClassLoader( $file, $loads ), new FakeClassLoader( $file, $loads ) ],
			'Vendor\\MissingAdapter'
		);

		self::assertSame( 'available', $result['adapter']['status'] );
		self::assertSame( [ 'plugin:stonewright' ], $result['adapter']['owners'] );
		self::assertNotSame( 'conflict', $result['adapter']['status'] );
	}

	public function test_loaded_class_ownership_is_reported_with_a_non_path_fingerprint(): void {
		$result = McpAbilitiesCompatibilityPreflight::inspect( [], LoadedAdapterFixture::class );

		self::assertFalse( $result['compatible'] );
		self::assertSame( 'loaded', $result['adapter']['status'] );
		self::assertSame( 'incompatible', $result['adapter']['abi']['status'] );
		self::assertNotEmpty( $result['adapter']['candidates'][0]['fingerprint'] );
		self::assertArrayNotHasKey( 'path', $result['adapter']['candidates'][0] );
	}

	public function test_abilities_api_ownership_is_reported_in_the_same_preflight(): void {
		$loads = new FakeLoadCounter();
		$GLOBALS['stonewright_test_filters']['stonewright_compatibility_class_names'] = static fn( array $classes ): array => [
			'adapter'            => 'Vendor\\MissingAdapter',
			'abilities_registry' => 'Vendor\\MissingRegistry',
			'ability'            => 'Vendor\\MissingAbility',
		];
		$autoloaders = [ new MultiClassLoader(
			[
				'Vendor\\MissingAdapter'  => '/srv/wp-content/plugins/stonewright/vendor/wordpress/mcp-adapter/includes/Core/McpAdapter.php',
				'Vendor\\MissingRegistry' => '/srv/wp-content/plugins/stonewright/vendor/wordpress/abilities-api/includes/class-wp-abilities-registry.php',
				'Vendor\\MissingAbility'  => '/srv/wp-content/plugins/stonewright/vendor/wordpress/abilities-api/includes/class-wp-ability.php',
			],
			$loads
		) ];

		$result = McpAbilitiesCompatibilityPreflight::inspect( $autoloaders );

		self::assertSame( 'available', $result['abilities']['registry']['status'] );
		self::assertSame( 'available', $result['abilities']['ability']['status'] );
		self::assertSame( [ 'plugin:stonewright' ], $result['abilities']['registry']['owners'] );
	}

	public function test_abilities_ownership_conflict_blocks_boot_before_adapter_instantiation(): void {
		$loads = new FakeLoadCounter();
		$GLOBALS['stonewright_test_filters']['stonewright_compatibility_class_names'] = static fn( array $classes ): array => [
			'adapter'            => 'Vendor\\MissingAdapter',
			'abilities_registry' => 'Vendor\\MissingRegistry',
			'ability'            => 'Vendor\\MissingAbility',
		];
		$autoloaders = [
			new MultiClassLoader(
				[
					'Vendor\\MissingAdapter'  => '/srv/wp-content/plugins/stonewright/vendor/wordpress/mcp-adapter/includes/Core/McpAdapter.php',
					'Vendor\\MissingRegistry' => '/srv/wp-content/plugins/stonewright/vendor/wordpress/abilities-api/includes/class-wp-abilities-registry.php',
				],
				$loads
			),
			new MultiClassLoader(
				[
					'Vendor\\MissingRegistry' => '/srv/wp-content/plugins/another-abilities/vendor/wordpress/abilities-api/includes/class-wp-abilities-registry.php',
				],
				$loads
			),
		];

		$result = McpAbilitiesCompatibilityPreflight::inspect( $autoloaders );

		self::assertSame( 'available', $result['adapter']['status'] );
		self::assertSame( 'conflict', $result['abilities']['registry']['status'] );
		self::assertFalse( $result['compatible'] );
		self::assertContains( 'abilities_registry_multiple_owners', $result['blocking_reasons'] );
	}

	public function test_loaded_runtime_must_satisfy_complete_abi_contract(): void {
		$GLOBALS['stonewright_test_filters']['stonewright_compatibility_class_names'] = static fn(): array => [
			'adapter' => CompatibleAdapterFixture::class,
			'abilities_registry' => CompatibleRegistryFixture::class,
			'ability' => CompatibleAbilityFixture::class,
		];
		$result = McpAbilitiesCompatibilityPreflight::inspect( [] );

		self::assertTrue( $result['compatible'] );
		self::assertSame( 'compatible', $result['adapter']['abi']['status'] );
		self::assertSame( '0.3.0', $result['adapter']['abi']['version'] );
		self::assertSame( [], $result['blocking_reasons'] );
	}

	public function test_incompatible_adapter_abi_blocks_boot(): void {
		$GLOBALS['stonewright_test_filters']['stonewright_compatibility_class_names'] = static fn(): array => [
			'adapter' => IncompatibleAdapterFixture::class,
			'abilities_registry' => CompatibleRegistryFixture::class,
			'ability' => CompatibleAbilityFixture::class,
		];

		$result = McpAbilitiesCompatibilityPreflight::inspect( [] );

		self::assertFalse( $result['compatible'] );
		self::assertSame( 'incompatible', $result['adapter']['abi']['status'] );
		self::assertContains( 'missing_public_static_instance', $result['adapter']['abi']['issues'] );
		self::assertContains( 'adapter_abi_incompatible', $result['blocking_reasons'] );
	}

	public function test_release_style_jetpack_and_package_manifests_expose_competing_owners(): void {
		$fixtures = dirname( __DIR__, 2 ) . '/fixtures/Compatibility';
		$GLOBALS['stonewright_test_filters']['stonewright_compatibility_class_names'] = static fn(): array => [
			'adapter' => CompatibleAdapterFixture::class,
			'abilities_registry' => CompatibleRegistryFixture::class,
			'ability' => CompatibleAbilityFixture::class,
		];

		$result = McpAbilitiesCompatibilityPreflight::inspect( [], CompatibleAdapterFixture::class, [ $fixtures . '/release-a', $fixtures . '/release-b' ] );

		self::assertFalse( $result['compatible'] );
		self::assertSame( 'conflict', $result['adapter']['status'] );
		self::assertSame( [ 'plugin:release-a', 'plugin:release-b' ], $result['adapter']['owners'] );
		self::assertSame( [ '0.3.0', '0.4.0' ], array_column( $result['adapter']['packages'], 'version' ) );
		self::assertStringNotContainsString( $fixtures, (string) wp_json_encode( $result ) );
	}

	public function test_release_package_without_jetpack_mapping_is_incompatible(): void {
		$fixtures = dirname( __DIR__, 2 ) . '/fixtures/Compatibility';
		$GLOBALS['stonewright_test_filters']['stonewright_compatibility_class_names'] = static fn(): array => [
			'adapter' => CompatibleAdapterFixture::class,
			'abilities_registry' => CompatibleRegistryFixture::class,
			'ability' => CompatibleAbilityFixture::class,
		];

		$result = McpAbilitiesCompatibilityPreflight::inspect( [], CompatibleAdapterFixture::class, [ $fixtures . '/release-no-jetpack' ] );

		self::assertFalse( $result['compatible'] );
		self::assertSame( 'incompatible', $result['adapter']['abi']['status'] );
		self::assertContains( 'jetpack_manifest_missing', $result['adapter']['abi']['issues'] );
	}

	public function test_release_package_with_missing_runtime_class_blocks_boot(): void {
		$fixtures = dirname( __DIR__, 2 ) . '/fixtures/Compatibility';
		$GLOBALS['stonewright_test_filters']['stonewright_compatibility_class_names'] = static fn(): array => [
			'adapter' => 'Vendor\\UnavailableReleaseAdapter',
			'abilities_registry' => CompatibleRegistryFixture::class,
			'ability' => CompatibleAbilityFixture::class,
		];

		$result = McpAbilitiesCompatibilityPreflight::inspect( [], 'Vendor\\UnavailableReleaseAdapter', [ $fixtures . '/release-a' ] );

		self::assertFalse( $result['compatible'] );
		self::assertSame( 'incompatible', $result['adapter']['abi']['status'] );
		self::assertContains( 'class_not_loaded', $result['adapter']['abi']['issues'] );
		self::assertContains( 'adapter_abi_incompatible', $result['blocking_reasons'] );
	}

	public function test_release_package_below_supported_abi_version_blocks_boot(): void {
		$fixtures = dirname( __DIR__, 2 ) . '/fixtures/Compatibility';
		$GLOBALS['stonewright_test_filters']['stonewright_compatibility_class_names'] = static fn(): array => [
			'adapter' => CompatibleAdapterFixture::class,
			'abilities_registry' => CompatibleRegistryFixture::class,
			'ability' => CompatibleAbilityFixture::class,
		];

		$result = McpAbilitiesCompatibilityPreflight::inspect( [], CompatibleAdapterFixture::class, [ $fixtures . '/release-old' ] );

		self::assertFalse( $result['compatible'] );
		self::assertSame( 'incompatible', $result['adapter']['abi']['status'] );
		self::assertContains( 'unsupported_package_version', $result['adapter']['abi']['issues'] );
	}

	public function test_registration_source_gates_adapter_boot_on_preflight(): void {
		$source = (string) file_get_contents( dirname( __DIR__, 3 ) . '/includes/Core/PluginRegistration.php' );
		$preflight = strpos( $source, 'McpAbilitiesCompatibilityPreflight::inspect()' );
		$adapter = strpos( $source, '\\WP\\MCP\\Core\\McpAdapter::instance()' );

		self::assertNotFalse( $preflight );
		self::assertNotFalse( $adapter );
		self::assertLessThan( $adapter, $preflight );
		self::assertStringContainsString( "['compatible']", $source );
	}
}

final class FakeLoadCounter {
	public int $count = 0;
}

final class FakeClassLoader {
	public function __construct( private string $file, private FakeLoadCounter $loads ) {}

	public function findFile( string $class ): string|false {
		unset( $class );
		return $this->file;
	}

	public function loadClass( string $class ): void {
		unset( $class );
		++$this->loads->count;
	}
}

final class MultiClassLoader {
	/** @param array<string,string> $files */
	public function __construct( private array $files, private FakeLoadCounter $loads ) {}

	public function findFile( string $class ): string|false {
		return $this->files[ $class ] ?? false;
	}

	public function loadClass( string $class ): void {
		unset( $class );
		++$this->loads->count;
	}
}

final class LoadedAdapterFixture {}

final class CompatibleAdapterFixture {
	public const VERSION = '0.3.0';
	private function __construct() {}
	public static function instance(): self { return new self(); }
	public function create_server( string $id, string $namespace, string $route, string $name, string $description, string $version, array $transports, ?string $error_handler, ?string $observability_handler = null, array $tools = [], array $resources = [], array $prompts = [] ): self {
		unset( $id, $namespace, $route, $name, $description, $version, $transports, $error_handler, $observability_handler, $tools, $resources, $prompts );
		return $this;
	}
}

final class IncompatibleAdapterFixture {
	public const VERSION = 'not-semver';
}

final class CompatibleRegistryFixture {
	private function __construct() {}
	public static function get_instance(): self { return new self(); }
	public function register( string $name, array $properties = [] ): ?CompatibleAbilityFixture {
		return new CompatibleAbilityFixture( $name, $properties );
	}
}

class CompatibleAbilityFixture {
	public function __construct( private string $name, private array $properties ) {}
	public function get_name(): string { return $this->name; }
	public function get_meta(): array { return $this->properties; }
	public function get_input_schema(): array { return []; }
	public function get_output_schema(): array { return []; }
}
