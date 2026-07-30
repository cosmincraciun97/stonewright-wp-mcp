<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Stonewright\WpMcp\Sandbox\SandboxManifest;

/**
 * @covers \Stonewright\WpMcp\Sandbox\SandboxManifest
 */
final class SandboxManifestTest extends TestCase {

	private string $sandbox_dir;

	protected function setUp(): void {
		$this->sandbox_dir = WP_CONTENT_DIR . '/stonewright-sandbox';
		if ( ! is_dir( $this->sandbox_dir ) ) {
			mkdir( $this->sandbox_dir, 0755, true );
		}

		$GLOBALS['stonewright_test_user_caps'] = [
			'edit_plugins'  => true,
			'manage_options' => true,
		];
	}

	protected function tearDown(): void {
		// Clean up manifest files created during tests.
		$manifests = glob( $this->sandbox_dir . '/*.manifest.json' );
		if ( is_array( $manifests ) ) {
			foreach ( $manifests as $f ) {
				if ( file_exists( $f ) ) {
					unlink( $f );
				}
			}
		}
		$tmps = glob( $this->sandbox_dir . '/*.manifest.json.tmp.*' );
		if ( is_array( $tmps ) ) {
			foreach ( $tmps as $f ) {
				if ( file_exists( $f ) ) {
					unlink( $f );
				}
			}
		}

		$GLOBALS['stonewright_test_user_caps'] = [];
	}

	// -------------------------------------------------------------------------
	// validate()
	// -------------------------------------------------------------------------

	public function test_validate_empty_array_is_valid(): void {
		$this->assertTrue( SandboxManifest::validate( [] ) );
	}

	public function test_validate_full_valid_manifest(): void {
		$data = [
			'title'       => 'My Snippet',
			'description' => 'A cool snippet',
			'author'      => 'Alice',
			'version'     => '1.0.0',
			'category'    => 'snippet',
			'created_at'  => '2026-05-21T12:00:00Z',
			'tags'        => [ 'php', 'wp-hooks' ],
		];
		$this->assertTrue( SandboxManifest::validate( $data ) );
	}

	public function test_validate_rejects_unknown_key(): void {
		$this->assertFalse( SandboxManifest::validate( [ 'foo' => 'bar' ] ) );
	}

	public function test_validate_rejects_invalid_category(): void {
		$this->assertFalse( SandboxManifest::validate( [ 'category' => 'theme' ] ) );
	}

	public function test_validate_rejects_non_string_title(): void {
		$this->assertFalse( SandboxManifest::validate( [ 'title' => 123 ] ) );
	}

	public function test_validate_rejects_non_array_tags(): void {
		$this->assertFalse( SandboxManifest::validate( [ 'tags' => 'php' ] ) );
	}

	public function test_validate_rejects_tags_array_with_non_string(): void {
		$this->assertFalse( SandboxManifest::validate( [ 'tags' => [ 'php', 42 ] ] ) );
	}

	public function test_validate_accepts_all_valid_categories(): void {
		foreach ( [ 'snippet', 'widget', 'plugin' ] as $cat ) {
			$this->assertTrue( SandboxManifest::validate( [ 'category' => $cat ] ), "Category '{$cat}' should be valid" );
		}
	}

	// -------------------------------------------------------------------------
	// read()
	// -------------------------------------------------------------------------

	public function test_read_returns_null_when_no_manifest_file(): void {
		$result = SandboxManifest::read( 'nonexistent.php' );
		$this->assertNull( $result );
	}

	public function test_read_returns_data_for_valid_manifest(): void {
		$data = [ 'title' => 'Test', 'category' => 'snippet' ];
		file_put_contents(
			$this->sandbox_dir . '/my-snippet.manifest.json',
			(string) json_encode( $data )
		);

		$result = SandboxManifest::read( 'my-snippet.php' );
		$this->assertIsArray( $result );
		$this->assertSame( 'Test', $result['title'] );
		$this->assertSame( 'snippet', $result['category'] );
	}

	public function test_read_returns_null_for_invalid_json(): void {
		file_put_contents(
			$this->sandbox_dir . '/bad.manifest.json',
			'not-json'
		);
		$this->assertNull( SandboxManifest::read( 'bad.php' ) );
	}

	public function test_read_returns_null_for_invalid_schema(): void {
		// Unknown key 'foo' — fails validation.
		file_put_contents(
			$this->sandbox_dir . '/invalid-schema.manifest.json',
			(string) json_encode( [ 'foo' => 'bar' ] )
		);
		$this->assertNull( SandboxManifest::read( 'invalid-schema.php' ) );
	}

	// -------------------------------------------------------------------------
	// write()
	// -------------------------------------------------------------------------

	public function test_write_creates_manifest_file(): void {
		$data   = [ 'title' => 'Written', 'category' => 'widget', 'version' => '2.0' ];
		$result = SandboxManifest::write( 'new-widget.php', $data );

		$this->assertTrue( $result );
		$path = $this->sandbox_dir . '/new-widget.manifest.json';
		$this->assertFileExists( $path );

		$read_back = json_decode( (string) file_get_contents( $path ), true );
		$this->assertSame( 'Written', $read_back['title'] );
		$this->assertSame( 'widget', $read_back['category'] );

		// Clean up.
		unlink( $path );
	}

	public function test_write_rejects_invalid_data(): void {
		$result = SandboxManifest::write( 'some.php', [ 'unknown_key' => 'value' ] );
		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'stonewright_sandbox_manifest_invalid', $result->get_error_code() );
	}

	public function test_write_requires_manage_sandbox_permission(): void {
		$GLOBALS['stonewright_test_user_caps'] = [];

		$result = SandboxManifest::write( 'some.php', [ 'title' => 'Test' ] );
		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'stonewright_sandbox_manifest_permission', $result->get_error_code() );
	}

	public function test_write_then_read_round_trip(): void {
		$data   = [
			'title'       => 'Round Trip',
			'description' => 'Full round trip test',
			'category'    => 'plugin',
			'version'     => '3.1.4',
			'tags'        => [ 'roundtrip', 'test' ],
		];
		$result = SandboxManifest::write( 'roundtrip.php', $data );
		$this->assertTrue( $result );

		$read = SandboxManifest::read( 'roundtrip.php' );
		$this->assertIsArray( $read );
		$this->assertSame( 'Round Trip', $read['title'] );
		$this->assertSame( 'plugin', $read['category'] );
		$this->assertSame( [ 'roundtrip', 'test' ], $read['tags'] );

		// Clean up.
		$path = $this->sandbox_dir . '/roundtrip.manifest.json';
		if ( file_exists( $path ) ) {
			unlink( $path );
		}
	}
}
