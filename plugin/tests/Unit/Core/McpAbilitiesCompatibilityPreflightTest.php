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

		self::assertTrue( $result['compatible'] );
		self::assertSame( 'loaded', $result['adapter']['status'] );
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

	public function test_abilities_ownership_conflict_is_diagnostic_but_does_not_block_a_single_adapter_owner(): void {
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
		self::assertTrue( $result['compatible'], 'Only duplicate McpAdapter ownership blocks adapter boot.' );
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
