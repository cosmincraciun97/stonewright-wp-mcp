<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Stonewright\WpMcp\QA\QaArtifactStore;

/**
 * @covers \Stonewright\WpMcp\QA\QaArtifactStore
 */
final class QaArtifactStorePurgeSymlinkTest extends TestCase {

	/** Temporary directory created for each test. */
	private string $tmpRoot = '';

	protected function setUp(): void {
		parent::setUp();

		// Create an isolated temp root so we don't collide with WP_CONTENT_DIR.
		$this->tmpRoot = sys_get_temp_dir() . '/stonewright-symlink-test-' . getmypid() . '-' . random_int( 0, 0xffff );
		mkdir( $this->tmpRoot, 0700, true );
	}

	protected function tearDown(): void {
		parent::tearDown();
		$this->rmdirRecursive( $this->tmpRoot );
	}

	/**
	 * purge_older_than() must remove the symlink itself but MUST NOT follow it
	 * into the symlink target (i.e. the target file must survive intact).
	 */
	public function test_purge_does_not_follow_symlink_outside_artifacts_root(): void {
		// ------------------------------------------------------------------ //
		// Build a fake stonewright-qa tree under WP_CONTENT_DIR.              //
		// ------------------------------------------------------------------ //
		$qa_base = WP_CONTENT_DIR . '/uploads/stonewright-qa';
		wp_mkdir_p( $qa_base );

		// Create a request-id sub-directory that is old enough to be purged.
		$request_id = 'aaaaaaaa-0000-4000-8000-aabbccddeeff';
		$artifact_dir = $qa_base . '/' . $request_id;
		wp_mkdir_p( $artifact_dir );

		// ------------------------------------------------------------------ //
		// Create a target *outside* the artifacts root.                       //
		// ------------------------------------------------------------------ //
		$target_dir  = $this->tmpRoot . '/outside-target';
		mkdir( $target_dir, 0700, true );
		$target_file = $target_dir . '/sentinel.txt';
		file_put_contents( $target_file, 'should-survive' );

		// Place a symlink inside the artifact directory pointing at $target_dir.
		$symlink_path = $artifact_dir . '/evil-link';
		if ( ! @symlink( $target_dir, $symlink_path ) ) {
			$this->markTestSkipped( 'symlink() is unavailable in this PHP/Windows environment.' );
		}

		// Force mtime to the past AFTER creating the symlink (symlink creation
		// bumps the parent dir's mtime to now, which would evade the cutoff).
		touch( $artifact_dir, 1 );
		clearstatcache();

		// ------------------------------------------------------------------ //
		// Run the purge.                                                      //
		// ------------------------------------------------------------------ //
		$purged = QaArtifactStore::purge_older_than( 0 );

		// ------------------------------------------------------------------ //
		// Assertions.                                                         //
		// ------------------------------------------------------------------ //

		// At least one directory was purged.
		$this->assertGreaterThanOrEqual( 1, $purged, 'Expected at least one artifact directory to be purged' );

		// The artifact directory itself is gone.
		$this->assertDirectoryDoesNotExist( $artifact_dir, 'Artifact directory should have been removed' );

		// The symlink is gone (it lived inside the artifact dir).
		$this->assertFalse( is_link( $symlink_path ), 'Symlink inside artifact dir should have been removed' );

		// The target OUTSIDE the artifacts root must be untouched.
		$this->assertDirectoryExists( $target_dir, 'Target directory outside artifacts root must NOT be deleted' );
		$this->assertFileExists( $target_file, 'Sentinel file inside target directory must NOT be deleted' );
		$this->assertSame( 'should-survive', file_get_contents( $target_file ), 'Sentinel file contents must be intact' );
	}

	// ---------------------------------------------------------------------- //
	// Helpers                                                                  //
	// ---------------------------------------------------------------------- //

	private function rmdirRecursive( string $dir ): void {
		if ( ! is_dir( $dir ) ) {
			return;
		}
		$entries = scandir( $dir );
		if ( false === $entries ) {
			return;
		}
		foreach ( $entries as $entry ) {
			if ( '.' === $entry || '..' === $entry ) {
				continue;
			}
			$path = $dir . '/' . $entry;
			if ( is_link( $path ) ) {
				unlink( $path );
			} elseif ( is_dir( $path ) ) {
				$this->rmdirRecursive( $path );
			} else {
				unlink( $path );
			}
		}
		rmdir( $dir );
	}
}
