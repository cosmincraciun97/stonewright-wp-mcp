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
		mkdir( $this->dir . '/shards', 0777, true );
		WidgetCatalog::set_manifest_path( $this->dir . '/index.php' );
	}

	protected function tearDown(): void {
		WidgetCatalog::set_manifest_path( null );
		$this->remove_dir( $this->dir );
	}

	public function test_catalog_loads_only_the_requested_php_shard(): void {
		file_put_contents(
			$this->dir . '/index.php',
			"<?php\nreturn [ 'version' => 'php', 'widgets' => [ 'heading' => [ 'shard' => 'shards/heading.php', 'source' => 'free' ], 'image' => [ 'shard' => 'shards/image.php', 'source' => 'free' ] ], 'totals' => [] ];\n"
		);
		file_put_contents(
			$this->dir . '/shards/heading.php',
			"<?php\nreturn [ 'slug' => 'heading', 'title' => 'Heading', 'settings_index' => [ 'title' => [ 'type' => 'text' ] ] ];\n"
		);

		$entry = WidgetCatalog::entry( 'heading' );

		self::assertSame( 'Heading', $entry['title'] );
		self::assertArrayHasKey( 'title', WidgetCatalog::settings_index( 'heading' ) );
		self::assertSame( [ 'heading', 'image' ], WidgetCatalog::slugs() );
	}

	public function test_invalid_or_traversing_shard_returns_safe_stub(): void {
		file_put_contents(
			$this->dir . '/index.php',
			"<?php\nreturn [ 'version' => 'php', 'widgets' => [ 'image' => [ 'shard' => '../secret.php' ] ], 'totals' => [] ];\n"
		);

		$entry = WidgetCatalog::entry( 'image' );

		self::assertSame( 'image', $entry['slug'] );
		self::assertSame( [], $entry['settings_index'] );
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
