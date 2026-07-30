<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\Admin;

/**
 * Generates a Claude Desktop .mcpb bundle for one-click Stonewright setup.
 *
 * The bundle embeds npx companion config with STONEWRIGHT_WP_URL only.
 * Credentials are never written into the archive.
 */
final class McpbBundle {

	public const DOWNLOAD_ACTION = 'stonewright_download_mcpb';
	public const NONCE_ACTION    = 'stonewright_download_mcpb';

	public static function register(): void {
		add_action( 'admin_post_' . self::DOWNLOAD_ACTION, [ self::class, 'handle_download' ] );
	}

	/**
	 * @return array<string, mixed>
	 */
	public static function build_manifest(): array {
		$site_name = function_exists( 'get_bloginfo' ) ? trim( (string) get_bloginfo( 'name' ) ) : '';
		$display   = '' !== $site_name ? 'Stonewright: ' . $site_name : 'Stonewright';
		$version   = defined( 'STONEWRIGHT_VERSION' ) ? (string) constant( 'STONEWRIGHT_VERSION' ) : '0.0.0';
		$site_url  = function_exists( 'get_site_url' ) ? (string) get_site_url() : '';

		return [
			'manifest_version' => '0.3',
			'name'             => 'stonewright',
			'display_name'     => $display,
			'version'          => $version,
			'description'      => __(
				'AI builder tools for this WordPress site via the Stonewright companion.',
				'stonewright'
			),
			'author'           => [ 'name' => 'Stonewright' ],
			'server'           => [
				// entry_point is required by the MCPB schema; the real process is launched via mcp_config.
				'type'        => 'node',
				'entry_point' => 'server/index.js',
				'mcp_config'  => [
					'command' => 'npx',
					'args'    => [
						'-y',
						'--package',
						ConnectClientConfig::companion_package_spec(),
						'stonewright-mcp',
					],
					'env'     => [
						'STONEWRIGHT_WP_URL'          => $site_url,
						'STONEWRIGHT_MCP_TOOL_PROFILE' => 'essential',
					],
				],
			],
		];
	}

	/**
	 * @return string|\WP_Error Absolute path to a temporary .mcpb zip.
	 */
	public static function create_zip(): string|\WP_Error {
		if ( ! class_exists( \ZipArchive::class ) ) {
			return new \WP_Error(
				'stonewright_mcpb_zip_missing',
				__( 'Cannot build the bundle: the PHP zip extension is not available on this server. Use the JSON config instead.', 'stonewright' )
			);
		}

		$manifest = self::build_manifest();
		$json     = (string) wp_json_encode(
			$manifest,
			JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
		);

		$stub = "// Placeholder entry point. The MCP server is launched via mcp_config\n"
			. "// (npx @stonewright/companion). This file is never executed.\n";

		$tmp = function_exists( 'wp_tempnam' ) ? (string) wp_tempnam( 'stonewright-mcpb' ) : tempnam( sys_get_temp_dir(), 'stonewright-mcpb' );
		if ( '' === $tmp || false === $tmp ) {
			return new \WP_Error(
				'stonewright_mcpb_temp_failed',
				__( 'Could not create the bundle archive.', 'stonewright' )
			);
		}

		$zip  = new \ZipArchive();
		$mode = \ZipArchive::CREATE | \ZipArchive::OVERWRITE;
		if ( true !== $zip->open( $tmp, $mode ) ) {
			@unlink( $tmp );
			return new \WP_Error(
				'stonewright_mcpb_open_failed',
				__( 'Could not create the bundle archive.', 'stonewright' )
			);
		}

		$zip->addFromString( 'manifest.json', $json );
		$zip->addFromString( 'server/index.js', $stub );
		$zip->close();

		return $tmp;
	}

	public static function download_url(): string {
		return wp_nonce_url(
			admin_url( 'admin-post.php?action=' . self::DOWNLOAD_ACTION ),
			self::NONCE_ACTION
		);
	}

	public static function handle_download(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You are not allowed to download this bundle.', 'stonewright' ) );
		}

		check_admin_referer( self::NONCE_ACTION );

		$path = self::create_zip();
		if ( is_wp_error( $path ) ) {
			wp_die( esc_html( $path->get_error_message() ) );
		}

		$host     = function_exists( 'wp_parse_url' ) ? (string) wp_parse_url( home_url(), PHP_URL_HOST ) : 'site';
		$filename = 'stonewright-' . sanitize_file_name( '' !== $host ? $host : 'site' ) . '.mcpb';

		nocache_headers();
		header( 'Content-Type: application/octet-stream' );
		header( 'Content-Disposition: attachment; filename="' . $filename . '"' );
		header( 'Content-Length: ' . (string) filesize( $path ) );
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_readfile
		readfile( $path );
		wp_delete_file( $path );
		exit;
	}
}
