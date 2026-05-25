<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\Tests\Unit\Removal;

use PHPUnit\Framework\TestCase;
use Stonewright\WpMcp\Core\AbilityRegistry;

/**
 * @coversNothing
 */
final class FigmaQaSurfaceTest extends TestCase {

	public function test_registry_exposes_no_figma_or_qa_abilities(): void {
		foreach ( AbilityRegistry::list() as $class ) {
			self::assertStringNotContainsString( 'Figma', $class );
			self::assertStringNotContainsString( '\\QA\\', $class );

			if ( ! class_exists( $class ) ) {
				continue;
			}

			$ability = new $class();
			self::assertFalse( str_starts_with( $ability->name(), 'stonewright/qa-' ) );
			self::assertSame( false, str_contains( strtolower( $ability->name() ), 'figma' ) );
			self::assertNotSame( 'qa', $ability->category() );
		}
	}

	public function test_companion_runtime_has_no_figma_or_visual_qa_modules(): void {
		$root  = dirname( __DIR__, 4 );
		$paths = $this->runtime_files( $root . '/companion/src' );

		foreach ( $paths as $path ) {
			$relative = str_replace( '\\', '/', substr( $path, strlen( $root ) + 1 ) );
			self::assertDoesNotMatchRegularExpression( '/figma|playwright|pixel-diff|screenshot|lighthouse|axe/i', $relative );

			$contents = (string) file_get_contents( $path );
			self::assertDoesNotMatchRegularExpression( '/figma|playwright|pixel[-_ ]diff|companion_screenshot|companion_pixel_diff|lighthouse|axe-core|stonewright-qa/i', $contents );
		}
	}

	/**
	 * @return array<int, string>
	 */
	private function runtime_files( string $dir ): array {
		if ( ! is_dir( $dir ) ) {
			return [];
		}

		$files = [];
		$it    = new \RecursiveIteratorIterator( new \RecursiveDirectoryIterator( $dir ) );
		foreach ( $it as $file ) {
			if ( ! $file instanceof \SplFileInfo || ! $file->isFile() ) {
				continue;
			}
			if ( in_array( $file->getExtension(), [ 'ts', 'json' ], true ) ) {
				$files[] = $file->getPathname();
			}
		}
		return $files;
	}
}
