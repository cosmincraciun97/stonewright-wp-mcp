<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\Tests\Unit\ElementorV3;

use PHPUnit\Framework\TestCase;
use Stonewright\WpMcp\Abilities\ElementorV3\DesignMirrorExport;

/**
 * The export is returned to the authenticated caller and never written to a
 * path that the web server can serve.
 *
 * @covers \Stonewright\WpMcp\Abilities\ElementorV3\DesignMirrorExport
 */
final class DesignMirrorExportTest extends TestCase {

	private string $uploads;

	protected function setUp(): void {
		$this->uploads = sys_get_temp_dir() . '/sw-mirror-' . bin2hex( random_bytes( 6 ) );
		mkdir( $this->uploads, 0777, true );
		$GLOBALS['stonewright_test_upload_dir'] = [
			'basedir' => $this->uploads,
			'baseurl' => 'https://example.test/wp-content/uploads',
			'error'   => false,
		];
		$tree = '[{"id":"root","elType":"container","settings":{"z":1,"a":2},"elements":[{"id":"h1","elType":"widget","widgetType":"heading","settings":{"title":"Private marker","align":"left"},"elements":[]}]}]';
		$GLOBALS['stonewright_test_posts'] = [
			801 => $this->post( 801, 'publish', 'public-page', 'Public page', $tree ),
			802 => $this->post( 802, 'private', 'private-page', 'Private page', $tree ),
			803 => $this->post( 803, 'draft', '', 'Untitled draft', $tree ),
		];
		$GLOBALS['stonewright_test_options']        = [ 'stonewright_mode' => 'development' ];
		$GLOBALS['stonewright_test_user_caps']      = [ 'edit_post' => true, 'edit_posts' => true ];
		$GLOBALS['stonewright_test_user_logged_in'] = true;
	}

	protected function tearDown(): void {
		unset( $GLOBALS['stonewright_test_upload_dir'], $GLOBALS['stonewright_test_user_can_callback'] );
		$GLOBALS['stonewright_test_posts']          = [];
		$GLOBALS['stonewright_test_options']        = [];
		$GLOBALS['stonewright_test_user_caps']      = [];
		$GLOBALS['stonewright_test_user_logged_in'] = false;
		$this->remove_tree( $this->uploads );
	}

	private function post( int $id, string $status, string $slug, string $title, string $tree ): object {
		return (object) [
			'ID'           => $id,
			'post_type'    => 'page',
			'post_status'  => $status,
			'post_title'   => $title,
			'post_name'    => $slug,
			'post_content' => '',
			'post_excerpt' => '',
			'meta'         => [ '_elementor_data' => $tree, '_elementor_edit_mode' => 'builder' ],
		];
	}

	/** @return list<string> */
	private function files_under_uploads(): array {
		$found = [];
		$it    = new \RecursiveIteratorIterator( new \RecursiveDirectoryIterator( $this->uploads, \FilesystemIterator::SKIP_DOTS ) );
		foreach ( $it as $file ) {
			$found[] = (string) $file;
		}
		return $found;
	}

	private function remove_tree( string $dir ): void {
		if ( ! is_dir( $dir ) ) {
			return;
		}
		foreach ( scandir( $dir ) ?: [] as $name ) {
			if ( '.' === $name || '..' === $name ) {
				continue;
			}
			$path = $dir . '/' . $name;
			is_dir( $path ) && ! is_link( $path ) ? $this->remove_tree( $path ) : unlink( $path );
		}
		rmdir( $dir );
	}

	public function test_export_writes_no_file_anywhere_under_uploads(): void {
		$result = ( new DesignMirrorExport() )->execute( [ 'post_ids' => [ 801, 802, 803 ] ] );

		self::assertIsArray( $result );
		self::assertSame( [], $this->files_under_uploads() );
		self::assertDirectoryDoesNotExist( $this->uploads . '/stonewright-mirror' );
	}

	public function test_result_carries_the_json_and_has_no_path_or_directory(): void {
		$result = ( new DesignMirrorExport() )->execute( [ 'post_ids' => [ 801 ] ] );

		self::assertIsArray( $result );
		self::assertTrue( $result['ok'] );
		self::assertArrayNotHasKey( 'dir', $result );
		self::assertCount( 1, $result['exports'] );
		$export = $result['exports'][0];
		self::assertSame( 801, $export['post_id'] );
		self::assertTrue( $export['ok'] );
		self::assertSame( 'public-page', $export['slug'] );
		self::assertSame( 'public-page.json', $export['filename'] );
		self::assertArrayNotHasKey( 'path', $export );
		self::assertSame( strlen( $export['json'] ), $export['bytes'] );
		self::assertSame( hash( 'sha256', $export['json'] ), $export['sha256'] );
		self::assertStringEndsWith( "\n", $export['json'] );

		$decoded = json_decode( $export['json'], true );
		self::assertSame( 801, $decoded['post_id'] );
		self::assertSame( 'Public page', $decoded['title'] );
		self::assertSame( [ 'a' => 2, 'z' => 1 ], $decoded['elementor'][0]['settings'] );
		self::assertSame( [ 'align' => 'left', 'title' => 'Private marker' ], $decoded['elementor'][0]['elements'][0]['settings'] );
	}

	public function test_private_and_draft_posts_are_returned_in_the_result_only(): void {
		$result = ( new DesignMirrorExport() )->execute( [ 'post_ids' => [ 802, 803 ] ] );

		self::assertIsArray( $result );
		self::assertStringContainsString( 'Private marker', $result['exports'][0]['json'] );
		self::assertSame( 'private-page.json', $result['exports'][0]['filename'] );
		self::assertSame( 'post-803.json', $result['exports'][1]['filename'] );
		self::assertSame( [], $this->files_under_uploads() );
	}

	public function test_post_the_caller_cannot_edit_is_not_exported(): void {
		$GLOBALS['stonewright_test_user_can_callback'] = static fn( string $cap, mixed ...$args ): bool => 'edit_post' === $cap && 801 === (int) ( $args[0] ?? 0 );

		$result = ( new DesignMirrorExport() )->execute( [ 'post_ids' => [ 801, 802 ] ] );

		self::assertIsArray( $result );
		self::assertTrue( $result['exports'][0]['ok'] );
		self::assertFalse( $result['exports'][1]['ok'] );
		self::assertSame( 'forbidden', $result['exports'][1]['error'] );
		self::assertArrayNotHasKey( 'json', $result['exports'][1] );
		self::assertStringNotContainsString( 'Private marker', (string) wp_json_encode( $result['exports'][1] ) );
	}

	public function test_permission_callback_still_requires_edit_permission_for_every_post(): void {
		$GLOBALS['stonewright_test_user_caps'] = [];

		$permission = ( new DesignMirrorExport() )->permission_callback( [ 'post_ids' => [ 801 ] ] );

		self::assertInstanceOf( \WP_Error::class, $permission );
	}

	public function test_missing_post_is_reported_without_failing_the_call(): void {
		$result = ( new DesignMirrorExport() )->execute( [ 'post_ids' => [ 999 ] ] );

		self::assertIsArray( $result );
		self::assertSame( [ [ 'post_id' => 999, 'ok' => false, 'error' => 'not_found' ] ], $result['exports'] );
	}

	public function test_total_result_size_is_bounded(): void {
		$big = [ [ 'id' => 'root', 'elType' => 'container', 'settings' => [ 'blob' => str_repeat( 'x', 700000 ) ], 'elements' => [] ] ];
		foreach ( [ 811, 812, 813 ] as $id ) {
			$GLOBALS['stonewright_test_posts'][ $id ] = $this->post( $id, 'publish', 'big-' . $id, 'Big ' . $id, (string) wp_json_encode( $big ) );
		}

		$result = ( new DesignMirrorExport() )->execute( [ 'post_ids' => [ 811, 812, 813 ] ] );

		self::assertIsArray( $result );
		self::assertTrue( $result['exports'][0]['ok'] );
		self::assertTrue( $result['exports'][1]['ok'] );
		self::assertFalse( $result['exports'][2]['ok'] );
		self::assertSame( 'response_too_large', $result['exports'][2]['error'] );
		self::assertGreaterThan( 700000, $result['exports'][2]['bytes'] );
		self::assertArrayNotHasKey( 'json', $result['exports'][2] );
	}

	public function test_contract_and_description_state_that_nothing_is_published(): void {
		$ability = new DesignMirrorExport();
		$schema  = $ability->output_schema();

		self::assertSame( [ 'ok', 'exports' ], $schema['required'] );
		self::assertArrayNotHasKey( 'dir', $schema['properties'] );
		self::assertStringNotContainsString( 'wp-content/uploads', $ability->description() );
		self::assertStringContainsString( 'no file', strtolower( $ability->description() ) );
	}

	public function test_executing_the_export_removes_files_left_by_earlier_versions(): void {
		$previous_log = ini_get( 'error_log' );
		ini_set( 'error_log', $this->uploads . '/php.log' );
		$legacy = $this->uploads . '/stonewright-mirror';
		mkdir( $legacy, 0777, true );
		file_put_contents( $legacy . '/public-page.json', (string) wp_json_encode( [ 'post_id' => 801, 'slug' => 'public-page', 'elementor' => [] ] ) );

		$result = ( new DesignMirrorExport() )->execute( [ 'post_ids' => [ 801 ] ] );
		ini_set( 'error_log', false === $previous_log ? '' : $previous_log );

		self::assertIsArray( $result );
		self::assertFileDoesNotExist( $legacy . '/public-page.json' );
		self::assertFileExists( $legacy . '/.htaccess' );
		self::assertFileExists( $legacy . '/index.php' );
	}
}
