<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\Admin;

use Stonewright\WpMcp\Companion\CompanionContract;
use Stonewright\WpMcp\Core\AbilityRegistry;
use Stonewright\WpMcp\Support\TokenSurfaceBudgets;

/**
 * Produces a compact, side-effect-free setup report for the configuration UI.
 */
final class SetupDiagnostics {

	/**
	 * @return array{ready: bool, checks: list<array{id: string, status: string, label: string, detail: string}>, versions: array<string, string|int>}
	 */
	public static function report(): array {
		$enabled       = (bool) get_option( 'stonewright_enabled', false );
		$https         = is_ssl() || str_starts_with( (string) get_site_url(), 'https://' );
		$app_passwords = self::application_passwords_available();
		$endpoint      = ConnectClientConfig::mcp_endpoint_url();
		$tool_count    = count( AbilityRegistry::enabled_abilities() );

		$transport_detail = $https
			? __( 'HTTPS active.', 'stonewright' )
			: __( 'Running over HTTP. Fine for local and LAN sites; HTTPS is recommended when connecting from outside your network.', 'stonewright' );

		$app_password_detail = $app_passwords
			? __( 'Available for the current user.', 'stonewright' )
			: ( $https
				? __( 'Unavailable; check the user profile or Application Passwords settings.', 'stonewright' )
				: __( 'Unavailable on this HTTP site. For local setups add define( \'WP_ENVIRONMENT_TYPE\', \'local\' ); to wp-config.php so Application Passwords work without HTTPS.', 'stonewright' ) );

		$checks = [
			self::check( 'plugin', $enabled, __( 'Stonewright abilities', 'stonewright' ), $enabled ? __( 'Enabled.', 'stonewright' ) : __( 'Enable Stonewright in step 1.', 'stonewright' ) ),
			[
				'id'     => 'transport',
				'status' => $https ? 'ok' : 'info',
				'label'  => __( 'Connection transport', 'stonewright' ),
				'detail' => $transport_detail,
			],
			self::check( 'application_passwords', $app_passwords, __( 'Application Passwords', 'stonewright' ), $app_password_detail ),
			self::check( 'endpoint', '' !== $endpoint, __( 'MCP endpoint', 'stonewright' ), $endpoint ),
			self::check( 'tool_budget', $tool_count <= TokenSurfaceBudgets::ESSENTIAL_MAX_TOOLS, __( 'Compact tool surface', 'stonewright' ), sprintf( __( '%d tools exposed in the current profile.', 'stonewright' ), $tool_count ) ),
		];

		return [
			'ready'    => ! in_array( 'error', array_column( $checks, 'status' ), true ),
			'checks'   => $checks,
			'versions' => [
				'plugin'             => defined( 'STONEWRIGHT_VERSION' ) ? (string) constant( 'STONEWRIGHT_VERSION' ) : 'unknown',
				'companion_contract' => CompanionContract::EXPECTED_CONTRACT_VERSION,
				'wordpress'          => isset( $GLOBALS['wp_version'] ) ? (string) $GLOBALS['wp_version'] : 'unknown',
				'php'                => PHP_VERSION,
				'tool_count'         => $tool_count,
			],
		];
	}

	/**
	 * @return array{id: string, status: string, label: string, detail: string}
	 */
	private static function check( string $id, bool $passes, string $label, string $detail ): array {
		return [
			'id'     => $id,
			'status' => $passes ? 'ok' : 'error',
			'label'  => $label,
			'detail' => $detail,
		];
	}

	private static function application_passwords_available(): bool {
		if ( ! class_exists( '\\WP_Application_Passwords' ) ) {
			return false;
		}

		if ( function_exists( 'wp_is_application_passwords_available' ) && ! wp_is_application_passwords_available() ) {
			return false;
		}

		return ! function_exists( 'wp_is_application_passwords_available_for_user' )
			|| (bool) wp_is_application_passwords_available_for_user( wp_get_current_user() );
	}
}
