<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\Core;

/**
 * Detects missing Composer vendor installs and surfaces clear failure UX.
 *
 * Common failure mode: installing the GitHub "Source code" ZIP instead of the
 * release build ZIP that ships vendor/.
 */
final class VendorGuard {

	public const RELEASES_URL = 'https://github.com/cosmincraciun97/stonewright-wp-mcp/releases';

	private static ?\WP_Error $error = null;

	public static function autoload_path(): string {
		$dir = defined( 'STONEWRIGHT_DIR' ) ? (string) constant( 'STONEWRIGHT_DIR' ) : '';
		return $dir . 'vendor/autoload.php';
	}

	public static function is_vendor_present(): bool {
		return is_readable( self::autoload_path() );
	}

	public static function missing_vendor_error(): \WP_Error {
		return new \WP_Error(
			'stonewright_missing_vendor',
			__(
				'Stonewright is installed without its bundled vendor directory. This usually means the GitHub source ZIP was installed instead of the Stonewright release build ZIP. Download the release ZIP from GitHub Releases, then reinstall. The MCP endpoint cannot load until vendor/ is present.',
				'stonewright'
			),
			[ 'status' => 500 ]
		);
	}

	public static function detect_and_store(): void {
		if ( ! self::is_vendor_present() ) {
			self::$error = self::missing_vendor_error();
		}
	}

	public static function get_error(): ?\WP_Error {
		return self::$error;
	}

	public static function has_error(): bool {
		return null !== self::$error;
	}

	/**
	 * @internal Tests only.
	 */
	public static function set_error_for_tests( ?\WP_Error $error ): void {
		self::$error = $error;
	}

	/**
	 * @internal Tests only.
	 */
	public static function reset_for_tests(): void {
		self::$error = null;
	}

	public static function register(): void {
		add_action( 'admin_notices', [ self::class, 'render_admin_notice' ] );
		add_action( 'rest_api_init', [ self::class, 'register_missing_mcp_endpoint' ], 999 );
	}

	public static function render_admin_notice(): void {
		$error = self::get_error();
		if ( null === $error ) {
			return;
		}
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		$releases = self::RELEASES_URL;
		echo '<div class="notice notice-error sw-notice"><p><strong>Stonewright:</strong> ';
		echo esc_html( $error->get_error_message() );
		echo ' <a href="' . esc_url( $releases ) . '" target="_blank" rel="noopener noreferrer">';
		echo esc_html__( 'Open GitHub Releases', 'stonewright' );
		echo '</a></p></div>';
	}

	/**
	 * When vendor/ is missing, expose /wp-json/mcp/stonewright as an explicit 500
	 * with an actionable message instead of a mute 404.
	 */
	public static function register_missing_mcp_endpoint(): void {
		// Only register the explicit failure route when an error has been stored
		// (typically missing vendor/). Healthy installs leave the real MCP route alone.
		if ( null === self::get_error() ) {
			return;
		}

		register_rest_route(
			'mcp',
			'/stonewright',
			[
				'methods'             => [ 'GET', 'POST' ],
				'callback'            => [ self::class, 'missing_mcp_callback' ],
				'permission_callback' => static fn(): bool => true,
			]
		);
	}

	/**
	 * @param \WP_REST_Request $request Unused.
	 */
	public static function missing_mcp_callback( \WP_REST_Request $request ): \WP_Error {
		$error = self::get_error() ?? self::missing_vendor_error();
		return new \WP_Error(
			$error->get_error_code(),
			$error->get_error_message(),
			[ 'status' => 500 ]
		);
	}
}
