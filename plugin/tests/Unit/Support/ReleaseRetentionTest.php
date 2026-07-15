<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\Tests\Unit\Support;

use PHPUnit\Framework\TestCase;

/**
 * Enforces the 5-release retention policy on docs/releases and changelog shape.
 */
final class ReleaseRetentionTest extends TestCase {

	public function test_docs_releases_keeps_at_most_five_versioned_notes(): void {
		$root = dirname( __DIR__, 4 ) . '/docs/releases';
		self::assertDirectoryExists( $root );
		$versioned = [];
		foreach ( scandir( $root ) ?: [] as $name ) {
			if ( preg_match( '/^1\\.0\\.0-alpha\\.\\d+.*\\.md$/', $name ) ) {
				$versioned[] = $name;
			}
		}
		self::assertLessThanOrEqual( 5, count( $versioned ), 'Too many release notes: ' . implode( ', ', $versioned ) );
		self::assertNotEmpty( $versioned );
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
		self::assertLessThanOrEqual( 5, count( $versions ), 'Versions: ' . implode( ', ', $versions ) );
		self::assertStringContainsString( '5-release retention policy', $raw );
	}
}
