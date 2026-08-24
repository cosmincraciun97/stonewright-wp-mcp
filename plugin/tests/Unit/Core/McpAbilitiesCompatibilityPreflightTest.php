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
		unset( $GLOBALS['stonewright_manifest_side_effect'] );
		unset( $GLOBALS['wp_version'] );
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

	/**
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_abilities_api_ownership_is_reported_in_the_same_preflight(): void {
		$loads = new FakeLoadCounter();
		$autoloaders = [ new MultiClassLoader(
			[
				'Vendor\\MissingAdapter' => '/srv/wp-content/plugins/stonewright/vendor/wordpress/mcp-adapter/includes/Core/McpAdapter.php',
			],
			$loads
		) ];

		$result = McpAbilitiesCompatibilityPreflight::inspect( $autoloaders, 'Vendor\\MissingAdapter' );

		self::assertSame( 'available', $result['abilities']['registry']['status'] );
		self::assertSame( 'available', $result['abilities']['ability']['status'] );
		self::assertSame( 'WP_Abilities_Registry', $result['abilities']['registry']['class'] );
		self::assertSame( [ 'plugin:plugin' ], $result['abilities']['registry']['owners'] );
	}

	public function test_abilities_ownership_conflict_blocks_boot_before_adapter_instantiation(): void {
		$loads = new FakeLoadCounter();
		$autoloaders = [
			new MultiClassLoader(
				[
					'Vendor\\MissingAdapter'  => '/srv/wp-content/plugins/stonewright/vendor/wordpress/mcp-adapter/includes/Core/McpAdapter.php',
					'WP_Abilities_Registry'    => '/srv/wp-content/plugins/stonewright/vendor/wordpress/abilities-api/includes/class-wp-abilities-registry.php',
				],
				$loads
			),
			new MultiClassLoader(
				[
					'WP_Abilities_Registry' => '/srv/wp-content/plugins/another-abilities/vendor/wordpress/abilities-api/includes/class-wp-abilities-registry.php',
				],
				$loads
			),
		];

		$result = McpAbilitiesCompatibilityPreflight::inspect( $autoloaders, 'Vendor\\MissingAdapter' );

		self::assertSame( 'available', $result['adapter']['status'] );
		self::assertSame( 'conflict', $result['abilities']['registry']['status'] );
		self::assertFalse( $result['compatible'] );
		self::assertContains( 'abilities_registry_multiple_owners', $result['blocking_reasons'] );
	}

	/**
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_compatible_decoys_cannot_hide_a_canonical_abilities_owner(): void {
		$plugin_root = dirname( __DIR__, 3 );
		require_once $plugin_root . '/vendor/wordpress/abilities-api/includes/abilities-api/class-wp-ability.php';
		require_once $plugin_root . '/vendor/wordpress/abilities-api/includes/abilities-api/class-wp-abilities-registry.php';

		$loads = new FakeLoadCounter();
		$GLOBALS['stonewright_test_filters']['stonewright_compatibility_class_names'] = static fn(): array => [
			'adapter'            => CompatibleAdapterFixture::class,
			'abilities_registry' => CompatibleRegistryFixture::class,
			'ability'            => CompatibleAbilityFixture::class,
		];
		$GLOBALS['stonewright_test_filters']['stonewright_compatibility_class_candidates'] = static fn(): array => [];
		$autoloaders = [ new MultiClassLoader(
			[
				'WP_Abilities_Registry' => '/srv/wp-content/plugins/hidden-owner/vendor/wordpress/abilities-api/includes/abilities-api/class-wp-abilities-registry.php',
				'WP_Ability'            => '/srv/wp-content/plugins/hidden-owner/vendor/wordpress/abilities-api/includes/abilities-api/class-wp-ability.php',
			],
			$loads
		) ];

		$result = McpAbilitiesCompatibilityPreflight::inspect( $autoloaders, CompatibleAdapterFixture::class );

		self::assertFalse( $result['compatible'] );
		self::assertSame( 'WP_Abilities_Registry', $result['abilities']['registry']['class'] );
		self::assertSame( 'WP_Ability', $result['abilities']['ability']['class'] );
		self::assertSame( 'conflict', $result['abilities']['registry']['status'] );
		self::assertSame( 'conflict', $result['abilities']['ability']['status'] );
		self::assertContains( 'plugin:hidden-owner', $result['abilities']['registry']['owners'] );
		self::assertContains( 'abilities_registry_multiple_owners', $result['blocking_reasons'] );
		self::assertContains( 'ability_multiple_owners', $result['blocking_reasons'] );
		self::assertSame( 0, $loads->count );
	}

	/**
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_loaded_runtime_must_satisfy_complete_abi_contract(): void {
		require_once dirname( __DIR__, 2 ) . '/fixtures/Compatibility/compatible-runtime.php';

		$result = McpAbilitiesCompatibilityPreflight::inspect( [], CompatibleAdapterFixture::class, [] );

		self::assertSame( [], $result['adapter']['abi']['issues'] );
		self::assertSame( [], $result['abilities']['registry']['abi']['issues'] );
		self::assertTrue( $result['compatible'] );
		self::assertSame( 'compatible', $result['adapter']['abi']['status'] );
		self::assertSame( '0.3.0', $result['adapter']['abi']['version'] );
		self::assertSame( [], $result['blocking_reasons'] );
	}

	/**
	 * PHP 8.1–8.4 report `self`; 8.5 reports the declaring class. Both must certify.
	 *
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_self_return_type_is_equivalent_to_the_declaring_class(): void {
		require_once dirname( __DIR__, 2 ) . '/fixtures/Compatibility/compatible-runtime.php';

		$result = McpAbilitiesCompatibilityPreflight::inspect( [], CompatibleAdapterFixture::class, [] );

		self::assertNotContains( 'missing_public_static_instance', $result['adapter']['abi']['issues'] );
		self::assertNotContains( 'missing_public_static_get_instance', $result['abilities']['registry']['abi']['issues'] );
		self::assertSame( 'compatible', $result['adapter']['abi']['status'] );
		self::assertSame( 'compatible', $result['abilities']['registry']['abi']['status'] );
		self::assertTrue( $result['compatible'] );
	}

	/**
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_every_missing_required_symbol_blocks_with_reason_and_remediation(): void {
		$GLOBALS['stonewright_test_filters']['stonewright_compatibility_class_names'] = static fn(): array => [
			'adapter'            => 'Vendor\\AbsentAdapter',
			'abilities_registry' => 'Vendor\\AbsentRegistry',
			'ability'            => 'Vendor\\AbsentAbility',
		];

		$result = McpAbilitiesCompatibilityPreflight::inspect( [], 'Vendor\\AbsentAdapter', [] );

		self::assertFalse( $result['compatible'] );
		self::assertSame(
			[ 'adapter_unavailable', 'abilities_registry_unavailable', 'ability_unavailable' ],
			$result['blocking_reasons']
		);
		foreach ( [ $result['adapter'], $result['abilities']['registry'], $result['abilities']['ability'] ] as $symbol ) {
			self::assertSame( 'unavailable', $symbol['status'] );
			self::assertSame( 'required_symbol_unavailable', $symbol['reason'] );
			self::assertNotSame( '', $symbol['remediation'] );
		}
	}

	/**
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_wordpress_core_abilities_and_guarded_stonewright_fallback_are_compatible(): void {
		$fixtures = dirname( __DIR__, 2 ) . '/fixtures/Compatibility';
		$GLOBALS['wp_version'] = '6.9.1';
		require_once $fixtures . '/compatible-core-runtime.php';
		$GLOBALS['stonewright_test_filters']['stonewright_compatibility_class_candidates'] = static function ( array $paths, string $class ): array {
			if ( 'WP_Abilities_Registry' === $class ) {
				$paths[] = '/srv/wordpress/wp-includes/abilities-api/class-wp-abilities-registry.php';
			}
			if ( 'WP_Ability' === $class ) {
				$paths[] = '/srv/wordpress/wp-includes/abilities-api/class-wp-ability.php';
			}
			return $paths;
		};

		$result = McpAbilitiesCompatibilityPreflight::inspect( [], CompatibleAdapterFixture::class, [ $fixtures . '/release-a' ] );

		self::assertSame( [], $result['adapter']['abi']['issues'] );
		self::assertSame( [], $result['abilities']['registry']['abi']['issues'] );
		self::assertTrue( $result['compatible'] );
		self::assertSame( 'loaded', $result['abilities']['registry']['status'] );
		self::assertSame( [ 'wordpress-core' ], $result['abilities']['registry']['owners'] );
		self::assertSame( 'guarded_fallback', $result['abilities']['registry']['packages'][0]['state'] ?? null );
		self::assertSame( '6.9.1', $result['abilities']['registry']['abi']['version'] );
	}

	/**
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_inactive_plugin_manifests_are_not_compatibility_owners(): void {
		$fixtures = dirname( __DIR__, 2 ) . '/fixtures/Compatibility';
		if ( ! defined( 'WP_PLUGIN_DIR' ) ) {
			define( 'WP_PLUGIN_DIR', $fixtures );
		}
		$GLOBALS['stonewright_test_options']['active_plugins'] = [];

		$result = McpAbilitiesCompatibilityPreflight::inspect();
		$encoded = (string) wp_json_encode( $result );

		self::assertStringNotContainsString( 'plugin:release-a', $encoded );
		self::assertStringNotContainsString( 'plugin:release-b', $encoded );
		self::assertNotSame( 'conflict', $result['adapter']['status'] );
	}

	public function test_incompatible_adapter_abi_blocks_boot(): void {
		$GLOBALS['stonewright_test_filters']['stonewright_compatibility_class_names'] = static fn(): array => [
			'adapter' => IncompatibleAdapterFixture::class,
			'abilities_registry' => CompatibleRegistryFixture::class,
			'ability' => CompatibleAbilityFixture::class,
		];

		$result = McpAbilitiesCompatibilityPreflight::inspect( [], IncompatibleAdapterFixture::class );

		self::assertFalse( $result['compatible'] );
		self::assertSame( 'incompatible', $result['adapter']['abi']['status'] );
		self::assertContains( 'missing_public_static_instance', $result['adapter']['abi']['issues'] );
		self::assertContains( 'adapter_abi_incompatible', $result['blocking_reasons'] );
	}

	public function test_all_integer_adapter_signature_fails_before_invocation(): void {
		WrongTypedAdapterFixture::$invocations = 0;
		$GLOBALS['stonewright_test_filters']['stonewright_compatibility_class_names'] = static fn(): array => [
			'adapter'            => WrongTypedAdapterFixture::class,
			'abilities_registry' => CompatibleRegistryFixture::class,
			'ability'            => CompatibleAbilityFixture::class,
		];

		$result = McpAbilitiesCompatibilityPreflight::inspect( [], WrongTypedAdapterFixture::class );

		self::assertFalse( $result['compatible'] );
		self::assertContains( 'incompatible_create_server_signature', $result['adapter']['abi']['issues'] );
		self::assertSame( 0, WrongTypedAdapterFixture::$invocations );
	}

	/**
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_extra_required_registry_parameter_fails_before_invocation(): void {
		require_once dirname( __DIR__, 2 ) . '/fixtures/Compatibility/incompatible-registry-runtime.php';
		\WP_Abilities_Registry::$invocations = 0;

		$result = McpAbilitiesCompatibilityPreflight::inspect( [], CompatibleAdapterFixture::class, [] );

		self::assertFalse( $result['compatible'] );
		self::assertContains( 'incompatible_register_signature', $result['abilities']['registry']['abi']['issues'] );
		self::assertSame( 0, \WP_Abilities_Registry::$invocations );
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

	/**
	 * WooCommerce 10.9 vendors wordpress/mcp-adapter 0.3.0 with a classmap
	 * Jetpack manifest instead of jetpack_autoload_psr4.php.
	 *
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_same_version_official_adapter_vendors_do_not_block_a_compatible_runtime(): void {
		require_once dirname( __DIR__, 2 ) . '/fixtures/Compatibility/compatible-runtime.php';
		require_once dirname( __DIR__, 3 ) . '/vendor/wordpress/mcp-adapter/includes/Core/McpAdapter.php';
		$fixtures = dirname( __DIR__, 2 ) . '/fixtures/Compatibility';

		$result = McpAbilitiesCompatibilityPreflight::inspect(
			[],
			\WP\MCP\Core\McpAdapter::class,
			[ $fixtures . '/release-a', $fixtures . '/release-woo' ]
		);

		self::assertNotSame( 'conflict', $result['adapter']['status'] );
		self::assertSame( [ 'plugin:release-a', 'plugin:release-woo' ], $result['adapter']['owners'] );
		self::assertSame( [ '0.3.0', '0.3.0' ], array_column( $result['adapter']['packages'], 'version' ) );
		self::assertTrue( $result['compatible'] );
		self::assertSame( [], $result['adapter']['abi']['issues'] );
		self::assertSame( '0.3.0', $result['adapter']['abi']['version'] );
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

	/**
	 * @dataProvider invalid_jetpack_manifest_fixtures
	 */
	public function test_release_package_requires_an_exact_jetpack_adapter_mapping( string $fixture ): void {
		require_once dirname( __DIR__, 2 ) . '/fixtures/Compatibility/compatible-runtime.php';
		$fixtures = dirname( __DIR__, 2 ) . '/fixtures/Compatibility';

		$result = McpAbilitiesCompatibilityPreflight::inspect( [], CompatibleAdapterFixture::class, [ $fixtures . '/' . $fixture ] );

		self::assertFalse( $result['compatible'] );
		self::assertSame( 'incompatible', $result['adapter']['abi']['status'] );
		self::assertContains( 'jetpack_manifest_missing', $result['adapter']['abi']['issues'] );
	}

	/** @return array<string,array{string}> */
	public static function invalid_jetpack_manifest_fixtures(): array {
		return [
			'classmap decoy' => [ 'release-invalid-classmap' ],
			'psr-4 decoy'    => [ 'release-invalid-psr4' ],
		];
	}

	public function test_manifest_inspection_does_not_execute_side_effects_or_accept_dynamic_adapter_mapping(): void {
		$fixtures = dirname( __DIR__, 2 ) . '/fixtures/Compatibility';
		$GLOBALS['stonewright_test_filters']['stonewright_compatibility_class_names'] = static fn(): array => [
			'adapter'            => CompatibleAdapterFixture::class,
			'abilities_registry' => CompatibleRegistryFixture::class,
			'ability'            => CompatibleAbilityFixture::class,
		];

		$result = McpAbilitiesCompatibilityPreflight::inspect( [], CompatibleAdapterFixture::class, [ $fixtures . '/release-dynamic-classmap' ] );

		self::assertArrayNotHasKey( 'stonewright_manifest_side_effect', $GLOBALS );
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

	/**
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_vendored_mcp_adapter_satisfies_production_preflight(): void {
		require_once dirname( __DIR__, 3 ) . '/vendor/wordpress/abilities-api/includes/abilities-api/class-wp-ability.php';
		require_once dirname( __DIR__, 3 ) . '/vendor/wordpress/abilities-api/includes/abilities-api/class-wp-abilities-registry.php';
		require_once dirname( __DIR__, 3 ) . '/vendor/wordpress/mcp-adapter/includes/Core/McpAdapter.php';

		$result = McpAbilitiesCompatibilityPreflight::inspect();

		self::assertSame(
			[],
			$result['adapter']['abi']['issues'],
			(string) wp_json_encode(
				[
					'blocking'        => $result['blocking_reasons'],
					'adapter'         => $result['adapter']['abi'],
					'registry'        => $result['abilities']['registry']['abi'],
					'ability'         => $result['abilities']['ability']['abi'],
					'adapter_status'  => $result['adapter']['status'],
					'adapter_owners'  => $result['adapter']['owners'],
					'registry_status' => $result['abilities']['registry']['status'],
					'registry_owners' => $result['abilities']['registry']['owners'],
				]
			)
		);
		self::assertTrue( $result['compatible'] );
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
	public function create_server( string $id, string $namespace, string $route, string $name, string $description, string $version, array $transports, ?string $error_handler, ?string $observability_handler = null, array $tools = [], array $resources = [], array $prompts = [], ?callable $configure = null ) {
		unset( $id, $namespace, $route, $name, $description, $version, $transports, $error_handler, $observability_handler, $tools, $resources, $prompts, $configure );
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
	public function get_label(): string { return $this->name; }
	public function get_description(): string { return $this->name; }
	public function get_meta(): array { return $this->properties; }
	public function get_input_schema(): array { return []; }
	public function get_output_schema(): array { return []; }
}

final class WrongTypedAdapterFixture {
	public const VERSION = '0.3.0';
	public static int $invocations = 0;
	private function __construct() {}
	public static function instance(): self { ++self::$invocations; return new self(); }
	public function create_server( int $id, int $namespace, int $route, int $name, int $description, int $version, int $transports, int $error_handler, int $observability_handler = 0, int $tools = 0, int $resources = 0, int $prompts = 0, int $configure = 0 ) { ++self::$invocations; }
}
