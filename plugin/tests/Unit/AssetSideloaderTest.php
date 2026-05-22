<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Stonewright\WpMcp\DesignSpec\AssetSideloader;

/**
 * @covers \Stonewright\WpMcp\DesignSpec\AssetSideloader
 */
final class AssetSideloaderTest extends TestCase {

	// ── Shared valid PNG body (1x1 pixel GIF-ish placeholder) ────────────────

	private static string $valid_image_body;

	public static function setUpBeforeClass(): void {
		// Enough bytes to pass size check. Not actual valid PNG, but content-type is mocked.
		self::$valid_image_body = str_repeat( "\x89PNG\r\n", 30 );
	}

	protected function setUp(): void {
		$GLOBALS['stonewright_test_asset_responses'] = [];
		$GLOBALS['stonewright_test_options']         = [];
		$GLOBALS['stonewright_test_next_post_id']    = 5001;
		$GLOBALS['stonewright_test_posts']           = [];
		$GLOBALS['stonewright_test_post_meta_calls'] = [];
		$GLOBALS['stonewright_test_wpdb_inserts']    = [];
	}

	// ── SSRF: scheme checks ──────────────────────────────────────────────────

	public function test_file_scheme_rejected(): void {
		$result = AssetSideloader::sideload( 'file:///etc/passwd' );
		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'stonewright_asset_blocked_scheme', $result->get_error_code() );
	}

	public function test_gopher_scheme_rejected(): void {
		$result = AssetSideloader::sideload( 'gopher://evil.internal/something' );
		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'stonewright_asset_blocked_scheme', $result->get_error_code() );
	}

	public function test_ftp_scheme_rejected(): void {
		$result = AssetSideloader::sideload( 'ftp://images.example.com/photo.jpg' );
		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'stonewright_asset_blocked_scheme', $result->get_error_code() );
	}

	// ── SSRF: private IPv4 ranges ────────────────────────────────────────────

	/**
	 * @dataProvider private_ip_provider
	 */
	public function test_private_ip_rejected( string $url ): void {
		$result = AssetSideloader::sideload( $url );
		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'stonewright_asset_blocked_host', $result->get_error_code(), "Expected blocked host for $url" );
	}

	/**
	 * @return array<string, array{0: string}>
	 */
	public static function private_ip_provider(): array {
		return [
			'loopback 127.0.0.1'         => [ 'http://127.0.0.1/image.png' ],
			'loopback 127.0.0.2'         => [ 'http://127.0.0.2/image.png' ],
			'rfc1918 10.0.0.1'           => [ 'http://10.0.0.1/image.png' ],
			'rfc1918 10.255.255.1'       => [ 'http://10.255.255.1/image.png' ],
			'rfc1918 172.16.0.1'         => [ 'http://172.16.0.1/image.png' ],
			'rfc1918 172.31.255.1'       => [ 'http://172.31.255.1/image.png' ],
			'rfc1918 192.168.1.1'        => [ 'http://192.168.1.1/image.png' ],
			'rfc1918 192.168.255.254'    => [ 'http://192.168.255.254/image.png' ],
			'link-local 169.254.0.1'     => [ 'http://169.254.0.1/image.png' ],
			'localhost hostname'         => [ 'http://localhost/image.png' ],
		];
	}

	// ── SSRF: IPv6 loopback ──────────────────────────────────────────────────

	public function test_ipv6_loopback_rejected(): void {
		// [::1] is the IPv6 loopback — must be blocked.
		$result = AssetSideloader::sideload( 'http://[::1]/image.png' );
		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'stonewright_asset_blocked_host', $result->get_error_code() );
	}

	// ── SSRF: IPv4-mapped IPv6 ───────────────────────────────────────────────

	/**
	 * @dataProvider ipv4_mapped_ipv6_provider
	 */
	public function test_ipv4_mapped_ipv6_rejected( string $url ): void {
		$result = AssetSideloader::sideload( $url );
		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame(
			'stonewright_asset_blocked_host',
			$result->get_error_code(),
			"Expected blocked host for IPv4-mapped IPv6 URL: $url"
		);
	}

	/**
	 * @return array<string, array{0: string}>
	 */
	public static function ipv4_mapped_ipv6_provider(): array {
		return [
			'ipv4-mapped loopback ::ffff:127.0.0.1'     => [ 'http://[::ffff:127.0.0.1]/image.png' ],
			'ipv4-mapped rfc1918 ::ffff:10.0.0.1'       => [ 'http://[::ffff:10.0.0.1]/image.png' ],
			'ipv4-mapped 192.168 ::ffff:192.168.1.1'    => [ 'http://[::ffff:192.168.1.1]/image.png' ],
		];
	}

	// ── SSRF: IPv4-compatible and 6to4 IPv6 ─────────────────────────────────

	/**
	 * @dataProvider ipv4_compat_and_6to4_provider
	 */
	public function test_ipv4_compat_and_6to4_ipv6_rejected( string $url ): void {
		$result = AssetSideloader::sideload( $url );
		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame(
			'stonewright_asset_blocked_host',
			$result->get_error_code(),
			"Expected blocked host for IPv4-compat/6to4 URL: $url"
		);
	}

	/**
	 * @return array<string, array{0: string}>
	 */
	public static function ipv4_compat_and_6to4_provider(): array {
		return [
			// RFC 4291 §2.5.5.1 IPv4-compatible (deprecated) — high 96 bits zero
			'ipv4-compat loopback ::127.0.0.1'    => [ 'http://[::127.0.0.1]/image.png' ],
			'ipv4-compat rfc1918 ::10.0.0.1'      => [ 'http://[::10.0.0.1]/image.png' ],
			'ipv4-compat 192.168 ::192.168.1.1'   => [ 'http://[::192.168.1.1]/image.png' ],
			// RFC 3056 6to4 — 2002::/16 with embedded IPv4 in bits 16-47
			'6to4 loopback 2002:7f00:0001::'       => [ 'http://[2002:7f00:1::]/image.png' ],
			'6to4 rfc1918 2002:0a00:0001::'        => [ 'http://[2002:a00:1::]/image.png' ],
		];
	}

	// ── Content-Type rejection ───────────────────────────────────────────────

	public function test_html_content_type_rejected(): void {
		$url = 'https://cdn.example.com/tricky-file.png';
		$GLOBALS['stonewright_test_asset_responses'][ $url ] = [
			'response' => [ 'code' => 200 ],
			'headers'  => [ 'content-type' => 'text/html; charset=utf-8' ],
			'body'     => '<html><body>Not an image</body></html>',
		];

		$result = AssetSideloader::sideload( $url );
		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'stonewright_asset_not_image', $result->get_error_code() );
	}

	public function test_json_content_type_rejected(): void {
		$url = 'https://cdn.example.com/data.json';
		$GLOBALS['stonewright_test_asset_responses'][ $url ] = [
			'response' => [ 'code' => 200 ],
			'headers'  => [ 'content-type' => 'application/json' ],
			'body'     => '{"evil": true}',
		];

		$result = AssetSideloader::sideload( $url );
		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'stonewright_asset_not_image', $result->get_error_code() );
	}

	// ── Size limit ───────────────────────────────────────────────────────────

	public function test_oversized_body_rejected(): void {
		$url = 'https://cdn.example.com/huge.png';

		// Override the max bytes filter to a very small value for this test.
		add_filter(
			'stonewright_asset_max_bytes',
			static fn () => 50,
			10,
			1
		);

		$GLOBALS['stonewright_test_asset_responses'][ $url ] = [
			'response' => [ 'code' => 200 ],
			'headers'  => [ 'content-type' => 'image/png' ],
			'body'     => str_repeat( 'x', 100 ),
		];

		$result = AssetSideloader::sideload( $url );

		remove_filter( 'stonewright_asset_max_bytes', static fn () => 50, 10 );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'stonewright_asset_too_large', $result->get_error_code() );
	}

	// ── HTTP error codes ─────────────────────────────────────────────────────

	public function test_http_404_rejected(): void {
		$url = 'https://cdn.example.com/missing.png';
		$GLOBALS['stonewright_test_asset_responses'][ $url ] = [
			'response' => [ 'code' => 404 ],
			'headers'  => [],
			'body'     => '',
		];

		$result = AssetSideloader::sideload( $url );
		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'stonewright_asset_http_error', $result->get_error_code() );
	}

	// ── Happy path ───────────────────────────────────────────────────────────

	public function test_happy_path_public_image_creates_attachment(): void {
		$url = 'https://cdn.example.com/hero.png';
		$GLOBALS['stonewright_test_asset_responses'][ $url ] = [
			'response' => [ 'code' => 200 ],
			'headers'  => [ 'content-type' => 'image/png' ],
			'body'     => self::$valid_image_body,
		];

		// Ensure upload dir exists so the stub can proceed.
		wp_mkdir_p( WP_CONTENT_DIR . '/uploads' );

		$result = AssetSideloader::sideload( $url );

		$this->assertIsInt( $result, 'Expected an attachment ID integer' );
		$this->assertGreaterThan( 0, $result );

		// Verify audit log recorded the sideload with all required fields.
		$inserts = $GLOBALS['stonewright_test_wpdb_inserts'];
		$log_inserts = array_values( array_filter(
			$inserts,
			static fn ( $r ) => isset( $r['data']['ability_name'] ) && $r['data']['ability_name'] === 'stonewright/design.asset_sideload'
		) );
		$this->assertNotEmpty( $log_inserts, 'Audit log must record asset sideload' );

		// The audit log args are JSON-encoded in the 'sanitized_args' column.
		$audit_entry   = $log_inserts[0];
		$args_raw      = $audit_entry['data']['sanitized_args'] ?? '{}';
		$recorded_args = is_string( $args_raw ) ? json_decode( $args_raw, true ) : $args_raw;
		$this->assertIsArray( $recorded_args, 'Audit log sanitized_args must be decodable JSON' );
		$this->assertArrayHasKey( 'source_url',    $recorded_args, 'Audit log must include source_url' );
		$this->assertArrayHasKey( 'attachment_id', $recorded_args, 'Audit log must include attachment_id' );
		$this->assertArrayHasKey( 'size',          $recorded_args, 'Audit log must include size' );
		$this->assertArrayHasKey( 'content_type',  $recorded_args, 'Audit log must include content_type' );
		$this->assertSame( $url, $recorded_args['source_url'] );
		$this->assertSame( $result, $recorded_args['attachment_id'] );
		$this->assertGreaterThan( 0, $recorded_args['size'] );
		$this->assertSame( 'image/png', $recorded_args['content_type'] );
	}

	public function test_happy_path_attachment_url_accessible(): void {
		$url = 'https://cdn.example.com/banner.jpg';
		$GLOBALS['stonewright_test_asset_responses'][ $url ] = [
			'response' => [ 'code' => 200 ],
			'headers'  => [ 'content-type' => 'image/jpeg' ],
			'body'     => self::$valid_image_body,
		];

		wp_mkdir_p( WP_CONTENT_DIR . '/uploads' );

		$attachment_id = AssetSideloader::sideload( $url );
		$this->assertIsInt( $attachment_id );

		$attachment_url = wp_get_attachment_url( $attachment_id );
		$this->assertIsString( $attachment_url );
		$this->assertNotEmpty( $attachment_url );
	}
}
