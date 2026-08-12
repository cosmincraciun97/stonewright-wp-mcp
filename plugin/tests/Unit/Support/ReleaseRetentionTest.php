<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\Tests\Unit\Support;

use PHPUnit\Framework\TestCase;

/**
 * Enforces the supported public-release baseline.
 */
final class ReleaseRetentionTest extends TestCase {

	public function test_docs_releases_starts_with_public_beta(): void {
		$root = dirname( __DIR__, 4 ) . '/docs/releases';
		self::assertDirectoryExists( $root );
		$versioned = [];
		foreach ( scandir( $root ) ?: [] as $name ) {
			if ( preg_match( '/^1\\.0\\.0-(?:beta|rc)\\.\\d+.*\\.md$/', $name ) ) {
				$versioned[] = $name;
			}
		}
		self::assertSame( [ '1.0.0-beta.1.md', '1.0.0-beta.2.md', '1.0.0-beta.3.md', '1.0.0-beta.4.md', '1.0.0-beta.5.md', '1.0.0-beta.6.md', '1.0.0-beta.7.md' ], $versioned );
	}

	public function test_root_changelog_keeps_latest_five_releases_and_links_older_history(): void {
		$path = dirname( __DIR__, 4 ) . '/CHANGELOG.md';
		$raw  = (string) file_get_contents( $path );
		preg_match_all( '/^## \\[([^\\]]+)\\]/m', $raw, $m );
		$headers = $m[1] ?? [];
		$versions = array_values(
			array_filter(
				$headers,
				static fn( string $h ): bool => 'Unreleased' !== $h && ! str_starts_with( $h, 'Older' )
			)
		);
		self::assertContains( 'Unreleased', $headers );
		self::assertSame( [ '1.0.0-beta.7', '1.0.0-beta.6', '1.0.0-beta.5', '1.0.0-beta.4', '1.0.0-beta.3' ], $versions );
		self::assertStringContainsString( '## Older releases', $raw );
		self::assertStringContainsString( 'docs/releases/1.0.0-beta.1.md', $raw );
	}
}
