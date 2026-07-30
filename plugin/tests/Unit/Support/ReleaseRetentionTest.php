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
		self::assertSame( [ '1.0.0-beta.1.md' ], $versioned );
	}

	public function test_root_changelog_has_at_most_five_versions_plus_unreleased(): void {
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
		self::assertSame( [ '1.0.0-beta.1' ], $versions );
		self::assertStringContainsString( 'public changelog starts with the first beta', $raw );
	}
}
