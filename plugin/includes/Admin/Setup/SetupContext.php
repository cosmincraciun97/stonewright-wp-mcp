<?php
/**
 * Everything the Setup screen reads from the site, in one place.
 * SPDX-License-Identifier: GPL-2.0-or-later
 *
 * @package Stonewright
 */

declare( strict_types=1 );

namespace Stonewright\WpMcp\Admin\Setup;

use Stonewright\WpMcp\Admin\Connect\ConnectedClients;
use Stonewright\WpMcp\Admin\Connect\SignInStatus;
use Stonewright\WpMcp\Admin\ConnectClientConfig;
use Stonewright\WpMcp\Admin\SetupState;
use Stonewright\WpMcp\Security\PluginEffectiveState;

/**
 * The values the partials of the Setup screen print, read once per request. Stored secrets are only tested for
 * presence: their values never reach this object or the page.
 *
 * @phpstan-import-type Status from SignInStatus
 * @phpstan-import-type Connection from ConnectedClients
 */
final class SetupContext {

	/**
	 * @param array<int, array<string, mixed>>               $app_passwords
	 * @param Status                                         $oauth_status
	 * @param array{1: string, 2: string, 3: string}         $step_states done|current|todo per step.
	 * @param list<Connection>                               $oauth_connections
	 */
	public function __construct(
		public readonly bool $enabled,
		public readonly string $effective_state,
		public readonly string $mode,
		public readonly string $companion_url,
		public readonly bool $has_companion_token,
		public readonly string $bridge_token,
		public readonly string $bridge_launch_env,
		public readonly bool $atomic_enabled,
		public readonly string $mcp_surface,
		public readonly bool $has_unsplash_key,
		public readonly bool $has_pexels_key,
		public readonly string $username,
		public readonly string $prompt_password,
		public readonly string $app_password_status,
		public readonly string $connect_prompt,
		public readonly string $prompt_preview,
		public readonly array $app_passwords,
		public readonly bool $has_app_password,
		public readonly array $oauth_status,
		public readonly bool $oauth_available,
		public readonly array $step_states,
		public readonly string $selected_client,
		public readonly string $selected_method,
		public readonly bool $oauth_selected,
		public readonly array $oauth_connections,
	) {}

	/**
	 * Reads the site. The order of the reads is the order the screen has always used.
	 */
	public static function current(): self {
		$enabled             = PluginEffectiveState::enabled_requested();
		$effective_state     = PluginEffectiveState::effective_state();
		$mode                = (string) get_option( 'stonewright_mode', 'development' );
		$companion_url       = (string) get_option( 'stonewright_companion_url', '' );
		// Stored secrets are only tested for presence: their values never reach the page.
		$has_companion_token = '' !== (string) get_option( 'stonewright_companion_token', '' );
		$bridge_token        = $has_companion_token ? '<your-saved-bridge-token>' : '<choose-a-long-random-token>';
		$bridge_launch_env   = implode(
			"\n",
			[
				'STONEWRIGHT_HTTP_ENABLE=1',
				'PORT=8765',
				'COMPANION_BEARER_TOKEN=' . $bridge_token,
				'COMPANION_ALLOWED_ORIGINS=http://localhost,http://127.0.0.1',
			]
		);
		$atomic_enabled      = (bool) get_option( 'stonewright_elementor_v4_atomic', false );
		$mcp_surface         = \Stonewright\WpMcp\Core\AbilityRegistry::mcp_surface();
		$has_unsplash_key    = '' !== (string) get_option( 'stonewright_unsplash_access_key', '' );
		$has_pexels_key      = '' !== (string) get_option( 'stonewright_pexels_api_key', '' );
		$current_user        = wp_get_current_user();
		$current_user_id     = get_current_user_id();
		$username            = isset( $current_user->user_login ) ? (string) $current_user->user_login : '';
		$username            = '' !== $username ? $username : 'your-wp-username';
		// The one-time password is never read from server persistence. JavaScript
		// replaces this canonical placeholder in the current tab only.
		$prompt_password     = '<your-application-password>';
		$app_password_status = isset( $_GET['stonewright_app_password'] ) // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only notice selector.
			? sanitize_key( (string) wp_unslash( $_GET['stonewright_app_password'] ) ) // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			: '';
		$connect_prompt      = ConnectClientConfig::paste_to_agent_prompt( '', '', self::selected_client( $current_user_id ) );
		$prompt_preview      = self::prompt_preview( $connect_prompt );
		$app_passwords       = ApplicationPasswords::for_current_user();
		$has_app_password    = [] !== $app_passwords;
		$oauth_status        = SignInStatus::current();
		// OAuth stays selectable while the plugin is off: the transport decides whether it can ever work here.
		$oauth_available     = $oauth_status['transport_allowed'];
		$step_states         = self::step_states( $enabled, $has_app_password, $oauth_available );
		$selected_client     = self::selected_client( $current_user_id );
		$selected_method     = self::selected_method( $current_user_id );
		$auth_method         = SetupState::auth_method( $current_user_id );
		if ( ! $oauth_available ) {
			$auth_method = 'application-password';
		}
		$oauth_selected      = 'oauth' === $auth_method;
		$oauth_connections   = ConnectedClients::current();

		return new self(
			$enabled,
			$effective_state,
			$mode,
			$companion_url,
			$has_companion_token,
			$bridge_token,
			$bridge_launch_env,
			$atomic_enabled,
			$mcp_surface,
			$has_unsplash_key,
			$has_pexels_key,
			$username,
			$prompt_password,
			$app_password_status,
			$connect_prompt,
			$prompt_preview,
			$app_passwords,
			$has_app_password,
			$oauth_status,
			$oauth_available,
			$step_states,
			$selected_client,
			$selected_method,
			$oauth_selected,
			$oauth_connections
		);
	}

	/**
	 * @return array{1: string, 2: string, 3: string} done|current|todo per step.
	 */
	public static function step_states( bool $enabled, bool $has_app_password, bool $oauth_available ): array {
		if ( ! $enabled ) {
			return [ 1 => 'current', 2 => 'todo', 3 => 'todo' ];
		}
		if ( ! $has_app_password && ! $oauth_available ) {
			return [ 1 => 'done', 2 => 'current', 3 => 'todo' ];
		}
		return [ 1 => 'done', 2 => 'done', 3 => 'current' ];
	}

	public static function selected_client( int $user_id ): string {
		return SetupState::selected_client( $user_id );
	}

	public static function selected_method( int $user_id ): string {
		$default = 'stdio';
		if ( $user_id <= 0 ) {
			return $default;
		}
		$saved = get_user_meta( $user_id, 'stonewright_setup_method', true );
		return is_string( $saved ) && in_array( $saved, [ 'stdio', 'http' ], true ) ? $saved : $default;
	}

	/** The first lines of the paste-to-agent prompt, with a note that the rest is hidden. */
	public static function prompt_preview( string $prompt ): string {
		$lines = explode( "\n", $prompt );
		$slice = array_slice( $lines, 0, 8 );
		return implode( "\n", $slice ) . "\n\n" . __( '-- full setup rules hidden; expand below --', 'stonewright' );
	}
}
