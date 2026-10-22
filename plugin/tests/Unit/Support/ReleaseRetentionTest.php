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
		self::assertSame( [ '1.0.0-beta.1.md', '1.0.0-beta.10.md', '1.0.0-beta.11.1.md', '1.0.0-beta.11.md', '1.0.0-beta.12.md', '1.0.0-beta.13.1.md', '1.0.0-beta.13.2.md', '1.0.0-beta.13.3.md', '1.0.0-beta.13.md', '1.0.0-beta.14.md', '1.0.0-beta.15.md', '1.0.0-beta.16.1.md', '1.0.0-beta.16.2.md', '1.0.0-beta.16.md', '1.0.0-beta.17.1.md', '1.0.0-beta.17.2.md', '1.0.0-beta.17.md', '1.0.0-beta.2.md', '1.0.0-beta.3.md', '1.0.0-beta.4.md', '1.0.0-beta.5.md', '1.0.0-beta.6.md', '1.0.0-beta.7.md', '1.0.0-beta.8.md', '1.0.0-beta.9.md' ], $versioned );
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
		self::assertSame( [ '1.0.0-beta.17.2', '1.0.0-beta.17.1', '1.0.0-beta.17', '1.0.0-beta.16.2', '1.0.0-beta.16.1' ], $versions );
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

		if ( is_link( $claude ) ) {
			self::assertSame( 'AGENTS.md', readlink( $claude ) );
			self::assertFileEquals( $agents, $claude );
		} else {
			// A checkout that cannot create symlinks (Windows with core.symlinks=false) writes the link out as a
			// small file that holds its target. The contract is then read from the git index, where the entry
			// must still be a symlink (mode 120000) whose target is AGENTS.md.
			$entry = $this->git( $root, [ 'ls-files', '-s', '--', 'CLAUDE.md' ] );
			if ( null === $entry ) {
				self::markTestSkipped( 'CLAUDE.md is not a symlink in this working tree and no git index is available to read its mode (not a git checkout, or git is missing).' );
			}
			self::assertMatchesRegularExpression( '/^120000 [0-9a-f]+ 0	CLAUDE\.md$/', trim( $entry ), 'CLAUDE.md must stay a symlink in the repository so Hard rules are not duplicated.' );
			self::assertSame( 'AGENTS.md', $this->git( $root, [ 'cat-file', 'blob', ':CLAUDE.md' ] ) );
		}

		// CLAUDE.md is AGENTS.md by the contract above, so its Hard rules are read from AGENTS.md.
		$body = (string) file_get_contents( $agents );
		self::assertStringContainsString( 'Updater contract is part of every user-consumed release', $body );
		self::assertStringContainsString( 'GitHub release notes are untrusted Markdown', $body );
		self::assertStringContainsString( 'View details must never render a release older', $body );
		self::assertStringContainsString( 'plugin release ZIP must bundle the built-in skill pack', $body );
	}

	/**
	 * Output of a read-only git command in the repository root, or null when git cannot answer.
	 *
	 * @param list<string> $arguments
	 */
	private function git( string $root, array $arguments ): ?string {
		$command = 'git -C ' . escapeshellarg( $root ) . ' ' . implode( ' ', array_map( 'escapeshellarg', $arguments ) );
		$output  = [];
		$code    = 1;
		exec( $command . ' 2>&1', $output, $code );
		$text = trim( implode( "
", $output ) );

		return 0 === $code && '' !== $text ? $text : null;
	}
}
