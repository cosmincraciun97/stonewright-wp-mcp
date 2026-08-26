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
		self::assertSame( [ '1.0.0-beta.1.md', '1.0.0-beta.10.md', '1.0.0-beta.11.1.md', '1.0.0-beta.11.md', '1.0.0-beta.12.md', '1.0.0-beta.13.1.md', '1.0.0-beta.13.2.md', '1.0.0-beta.13.md', '1.0.0-beta.2.md', '1.0.0-beta.3.md', '1.0.0-beta.4.md', '1.0.0-beta.5.md', '1.0.0-beta.6.md', '1.0.0-beta.7.md', '1.0.0-beta.8.md', '1.0.0-beta.9.md' ], $versioned );
	}

	public function test_root_changelog_keeps_latest_five_releases_and_links_older_history(): void {
		$root_path   = dirname( __DIR__, 4 ) . '/CHANGELOG.md';
		$plugin_path = dirname( __DIR__, 3 ) . '/CHANGELOG.md';
		$raw         = (string) file_get_contents( $root_path );
		$plugin_raw  = (string) file_get_contents( $plugin_path );
		preg_match_all( '/^## \\[([^\\]]+)\\]/m', $raw, $m );
		$headers = $m[1] ?? [];
		$versions = array_values(
			array_filter(
				$headers,
				static fn( string $h ): bool => 'Unreleased' !== $h && ! str_starts_with( $h, 'Older' )
			)
		);
		self::assertContains( 'Unreleased', $headers );
		self::assertSame( [ '1.0.0-beta.13.2', '1.0.0-beta.13.1', '1.0.0-beta.13', '1.0.0-beta.12', '1.0.0-beta.11.1' ], $versions );
		self::assertStringContainsString( '## Older releases', $raw );
		self::assertStringContainsString( '## Older releases', $plugin_raw );
		foreach ( range( 1, 7 ) as $release_number ) {
			self::assertStringContainsString( 'docs/releases/1.0.0-beta.' . $release_number . '.md', $raw );
			self::assertStringContainsString( '../docs/releases/1.0.0-beta.' . $release_number . '.md', $plugin_raw );
		}
	}

	public function test_claude_md_is_a_symlink_to_agents_md(): void {
		$root   = dirname( __DIR__, 4 );
		$claude = $root . '/CLAUDE.md';
		$agents = $root . '/AGENTS.md';

		self::assertTrue( is_link( $claude ), 'CLAUDE.md must stay a symlink so Hard rules are not duplicated.' );
		self::assertSame( 'AGENTS.md', readlink( $claude ) );
		self::assertFileEquals( $agents, $claude );

		$body = (string) file_get_contents( $claude );
		self::assertStringContainsString( 'Updater contract is part of every user-consumed release', $body );
		self::assertStringContainsString( 'GitHub release notes are untrusted Markdown', $body );
		self::assertStringContainsString( 'View details must never render a release older', $body );
		self::assertStringContainsString( 'plugin release ZIP must bundle the built-in skill pack', $body );
	}
}
