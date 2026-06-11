<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\Tests\Unit\Elementor\WidgetRegistry;

use PHPUnit\Framework\TestCase;
use Stonewright\WpMcp\Elementor\WidgetRegistry\WidgetCatalog;

/**
 * @covers \Stonewright\WpMcp\Elementor\WidgetRegistry\WidgetCatalog
 */
final class WidgetCatalogTest extends TestCase {

	private string $dir;

	protected function setUp(): void {
		$this->dir = sys_get_temp_dir() . '/stonewright-widget-catalog-' . uniqid( '', true );
		mkdir( $this->dir );
		WidgetCatalog::set_manifest_path( $this->dir . '/manifest.json' );
	}

	protected function tearDown(): void {
		WidgetCatalog::set_manifest_path( null );
		$this->remove_dir( $this->dir );
	}

	public function test_manifest_prefers_precompiled_php_array_when_available(): void {
		file_put_contents(
			$this->dir . '/manifest.json',
			wp_json_encode(
				[
					'version' => 'json',
					'widgets' => [],
					'totals'  => [],
				]
			)
		);
		file_put_contents(
			$this->dir . '/manifest.php',
			"<?php\nreturn [ 'version' => 'php', 'widgets' => [ 'heading' => [] ], 'totals' => [] ];\n"
		);

		$manifest = WidgetCatalog::manifest();

		self::assertSame( 'php', $manifest['version'] );
		self::assertArrayHasKey( 'heading', $manifest['widgets'] );
	}

	public function test_manifest_falls_back_to_json_when_php_file_is_invalid(): void {
		file_put_contents(
			$this->dir . '/manifest.json',
			wp_json_encode(
				[
					'version' => 'json',
					'widgets' => [ 'image' => [] ],
					'totals'  => [],
				]
			)
		);
		file_put_contents(
			$this->dir . '/manifest.php',
			"<?php\nreturn 'not-an-array';\n"
		);

		$manifest = WidgetCatalog::manifest();

		self::assertSame( 'json', $manifest['version'] );
		self::assertArrayHasKey( 'image', $manifest['widgets'] );
	}

	private function remove_dir( string $dir ): void {
		if ( ! is_dir( $dir ) ) {
			return;
		}

		foreach ( scandir( $dir ) ?: [] as $entry ) {
			if ( '.' === $entry || '..' === $entry ) {
				continue;
			}
			$path = $dir . DIRECTORY_SEPARATOR . $entry;
			if ( is_dir( $path ) ) {
				$this->remove_dir( $path );
				continue;
			}
			unlink( $path );
		}
		rmdir( $dir );
	}
}
