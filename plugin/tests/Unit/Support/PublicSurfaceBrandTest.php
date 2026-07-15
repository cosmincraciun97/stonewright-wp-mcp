<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\Tests\Unit\Support;

use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use RegexIterator;
use SplFileInfo;

/**
 * Public product surface must not name third-party competitor brands.
 * Attribution lives only in the reuse ledger and SPDX headers.
 */
final class PublicSurfaceBrandTest extends TestCase {

	/** Case-insensitive brand token blocked on the public surface. */
	private const FORBIDDEN = 'novamira';

	public function test_public_surface_has_zero_forbidden_brand_mentions(): void {
		$root = dirname( __DIR__, 4 );
		$paths = [
			$root . '/README.md',
			$root . '/CHANGELOG.md',
			$root . '/plugin/CHANGELOG.md',
			$root . '/docs',
			$root . '/skills',
			$root . '/plugin/includes',
			$root . '/companion/src',
		];
		$allow = [
			$root . '/docs/upstream-code-reuse.md',
		];
		$hits = [];
		foreach ( $paths as $path ) {
			if ( is_file( $path ) ) {
				$this->scan_file( $path, $allow, $hits );
				continue;
			}
			if ( ! is_dir( $path ) ) {
				continue;
			}
			$iterator = new RecursiveIteratorIterator(
				new RecursiveDirectoryIterator( $path, RecursiveDirectoryIterator::SKIP_DOTS )
			);
			/** @var SplFileInfo $file */
			foreach ( $iterator as $file ) {
				if ( ! $file->isFile() ) {
					continue;
				}
				$ext = strtolower( $file->getExtension() );
				if ( ! in_array( $ext, [ 'md', 'php', 'ts', 'tsx', 'js', 'json', 'yml', 'yaml' ], true ) ) {
					continue;
				}
				$this->scan_file( $file->getPathname(), $allow, $hits );
			}
		}
		self::assertSame( [], $hits, "Forbidden brand mentions:\n" . implode( "\n", $hits ) );
	}

	/**
	 * @param list<string> $allow
	 * @param list<string> $hits
	 */
	private function scan_file( string $path, array $allow, array &$hits ): void {
		$real = realpath( $path ) ?: $path;
		foreach ( $allow as $ok ) {
			$ok_real = realpath( $ok ) ?: $ok;
			if ( $real === $ok_real ) {
				return;
			}
		}
		$raw = @file_get_contents( $path );
		if ( ! is_string( $raw ) || '' === $raw ) {
			return;
		}
		if ( false === stripos( $raw, self::FORBIDDEN ) ) {
			return;
		}
		foreach ( preg_split( '/\R/', $raw ) ?: [] as $line_no => $line ) {
			if ( false !== stripos( $line, self::FORBIDDEN ) ) {
				$hits[] = $path . ':' . ( $line_no + 1 ) . ': ' . trim( $line );
			}
		}
	}
}
