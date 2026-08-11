<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\Admin;

use Stonewright\WpMcp\Core\AbilityRegistry;
use Stonewright\WpMcp\Security\PluginEffectiveState;

/**
 * Typed setup profile DTO for admin UI and connection export.
 *
 * Individual controls use scoped writers so Apply-now / Save settings / client
 * picker changes do not clobber unrelated fields.
 */
final class SetupState {

	public const META_AUTH_METHOD        = 'stonewright_setup_auth_method';
	public const META_CLIENT             = 'stonewright_setup_client';
	public const META_METHOD             = 'stonewright_setup_method';
	public const META_STARTUP_PROFILE    = 'stonewright_setup_client_startup_profile';
	public const OPTION_INSTALL_MODE     = 'stonewright_install_mode';
	public const OPTION_SITE_ALIAS       = 'stonewright_site_alias';
	public const OPTION_SITE_ENVIRONMENT = 'stonewright_site_environment';

	/**
	 * Full setup snapshot (no credentials).
	 *
	 * @return array{
	 *   wordpress_mode: string,
	 *   mcp_surface: string,
	 *   abilities_requested: bool,
	 *   abilities_effective: string,
	 *   effectively_enabled: bool,
	 *   elementor_v4_atomic: bool,
	 *   install_mode: string,
	 *   auth_method: string,
	 *   selected_client: string,
	 *   client_startup_profile: string,
	 *   transport_method: string,
	 *   site_alias: string,
	 *   site_environment: string
	 * }
	 */
	public static function export( ?int $user_id = null ): array {
		$user_id = null !== $user_id ? $user_id : get_current_user_id();
		$client  = self::selected_client( $user_id );

		return [
			'wordpress_mode'          => self::wordpress_mode(),
			'mcp_surface'             => AbilityRegistry::mcp_surface(),
			'abilities_requested'     => PluginEffectiveState::enabled_requested(),
			'abilities_effective'     => PluginEffectiveState::effective_state(),
			'effectively_enabled'     => PluginEffectiveState::is_effectively_enabled(),
			'elementor_v4_atomic'     => (bool) get_option( 'stonewright_elementor_v4_atomic', false ),
			'install_mode'            => self::install_mode(),
			'auth_method'             => self::auth_method( $user_id ),
			'selected_client'         => $client,
			'client_startup_profile'  => self::client_startup_profile( $user_id, $client ),
			'transport_method'        => self::transport_method( $user_id ),
			'site_alias'              => (string) get_option( self::OPTION_SITE_ALIAS, '' ),
			'site_environment'        => (string) get_option( self::OPTION_SITE_ENVIRONMENT, '' ),
		];
	}

	public static function wordpress_mode(): string {
		$mode = (string) get_option( 'stonewright_mode', 'development' );
		return in_array( $mode, [ 'development', 'staging', 'production-safe' ], true ) ? $mode : 'development';
	}

	public static function install_mode(): string {
		$mode = (string) get_option( self::OPTION_INSTALL_MODE, 'auto' );
		return in_array( $mode, [ 'direct-only', 'plugin-only', 'auto' ], true ) ? $mode : 'auto';
	}

	public static function auth_method( int $user_id ): string {
		if ( $user_id <= 0 ) {
			return 'oauth';
		}
		$saved = (string) get_user_meta( $user_id, self::META_AUTH_METHOD, true );
		return in_array( $saved, [ 'oauth', 'application-password' ], true ) ? $saved : 'oauth';
	}

	public static function set_auth_method( int $user_id, string $method ): string {
		$method = sanitize_key( $method );
		if ( ! in_array( $method, [ 'oauth', 'application-password' ], true ) ) {
			return self::auth_method( $user_id );
		}
		if ( $user_id > 0 ) {
			update_user_meta( $user_id, self::META_AUTH_METHOD, $method );
		}
		return $method;
	}

	public static function selected_client( int $user_id ): string {
		if ( $user_id <= 0 ) {
			return 'claude-code';
		}
		$saved = sanitize_key( (string) get_user_meta( $user_id, self::META_CLIENT, true ) );
		$known = ClientCatalog::slugs();
		if ( '' !== $saved && in_array( $saved, $known, true ) ) {
			return $saved;
		}
		return in_array( 'claude-code', $known, true ) ? 'claude-code' : ( $known[0] ?? 'claude-code' );
	}

	public static function transport_method( int $user_id ): string {
		if ( $user_id <= 0 ) {
			return 'stdio';
		}
		$saved = sanitize_key( (string) get_user_meta( $user_id, self::META_METHOD, true ) );
		return in_array( $saved, [ 'stdio', 'http' ], true ) ? $saved : 'stdio';
	}

	/**
	 * MCP startup profile for the selected client and saved site surface.
	 */
	public static function client_startup_profile( int $user_id, string $client_slug = '' ): string {
		if ( '' === $client_slug ) {
			$client_slug = self::selected_client( $user_id );
		}
		if ( $user_id > 0 ) {
			$saved = sanitize_key( (string) get_user_meta( $user_id, self::META_STARTUP_PROFILE, true ) );
			if ( in_array( $saved, [ 'bootstrap', 'essential', 'essential-static', 'full' ], true ) ) {
				return $saved;
			}
		}
		return ConnectClientConfig::recommended_startup_profile( $client_slug );
	}

	public static function set_client_startup_profile( int $user_id, string $profile ): string {
		$profile = sanitize_key( $profile );
		if ( ! in_array( $profile, [ 'bootstrap', 'essential', 'essential-static', 'full' ], true ) ) {
			return self::client_startup_profile( $user_id );
		}
		if ( $user_id > 0 ) {
			update_user_meta( $user_id, self::META_STARTUP_PROFILE, $profile );
		}
		return $profile;
	}

	/**
	 * Scoped partial update. Only keys present in $fields are written.
	 *
	 * Supported scopes / keys:
	 * - wordpress_mode, mcp_surface, abilities_requested, elementor_v4_atomic
	 * - install_mode, site_alias, site_environment
	 * - auth_method, selected_client, transport_method, client_startup_profile
	 *
	 * @param array<string, mixed> $fields
	 * @return array<string, mixed> Updated export after write.
	 */
	public static function persist_partial( array $fields, ?int $user_id = null ): array {
		$user_id = null !== $user_id ? $user_id : get_current_user_id();
		$revision_before = AbilityRegistry::surface_revision();
		$runtime_before  = [
			'wordpress_mode'       => (string) get_option( 'stonewright_mode', 'development' ),
			'mcp_surface'          => AbilityRegistry::mcp_surface(),
			'abilities_requested'  => PluginEffectiveState::enabled_requested(),
			'elementor_v4_atomic'  => (bool) get_option( 'stonewright_elementor_v4_atomic', false ),
		];

		if ( array_key_exists( 'wordpress_mode', $fields ) ) {
			$mode = is_string( $fields['wordpress_mode'] ) ? strtolower( trim( $fields['wordpress_mode'] ) ) : '';
			if ( in_array( $mode, [ 'development', 'staging', 'production-safe' ], true ) ) {
				update_option( 'stonewright_mode', $mode );
			}
		}

		if ( array_key_exists( 'mcp_surface', $fields ) ) {
			$surface = is_string( $fields['mcp_surface'] ) ? strtolower( trim( $fields['mcp_surface'] ) ) : '';
			if ( in_array( $surface, [ 'bootstrap', 'essential', 'full' ], true ) ) {
				AbilityRegistry::set_mcp_surface( $surface );
			}
		}

		if ( array_key_exists( 'abilities_requested', $fields ) ) {
			PluginEffectiveState::set_enabled_requested( (bool) $fields['abilities_requested'] );
		}

		if ( array_key_exists( 'elementor_v4_atomic', $fields ) ) {
			update_option( 'stonewright_elementor_v4_atomic', (bool) $fields['elementor_v4_atomic'] );
		}

		if ( array_key_exists( 'install_mode', $fields ) ) {
			$mode = is_string( $fields['install_mode'] ) ? strtolower( trim( $fields['install_mode'] ) ) : '';
			if ( in_array( $mode, [ 'direct-only', 'plugin-only', 'auto' ], true ) ) {
				update_option( self::OPTION_INSTALL_MODE, $mode, false );
			}
		}

		if ( array_key_exists( 'site_alias', $fields ) ) {
			update_option(
				self::OPTION_SITE_ALIAS,
				sanitize_text_field( is_string( $fields['site_alias'] ) ? $fields['site_alias'] : '' ),
				false
			);
		}

		if ( array_key_exists( 'site_environment', $fields ) ) {
			$env = is_string( $fields['site_environment'] ) ? strtolower( trim( $fields['site_environment'] ) ) : '';
			if ( in_array( $env, [ '', 'local', 'development', 'staging', 'production' ], true ) ) {
				update_option( self::OPTION_SITE_ENVIRONMENT, $env, false );
			}
		}

		if ( array_key_exists( 'auth_method', $fields ) && is_string( $fields['auth_method'] ) ) {
			self::set_auth_method( $user_id, $fields['auth_method'] );
		}

		if ( array_key_exists( 'selected_client', $fields ) && is_string( $fields['selected_client'] ) ) {
			$slug  = sanitize_key( $fields['selected_client'] );
			$known = ClientCatalog::slugs();
			if ( in_array( $slug, $known, true ) && $user_id > 0 ) {
				update_user_meta( $user_id, self::META_CLIENT, $slug );
			}
		}

		if ( array_key_exists( 'transport_method', $fields ) && is_string( $fields['transport_method'] ) ) {
			$method = sanitize_key( $fields['transport_method'] );
			if ( in_array( $method, [ 'stdio', 'http' ], true ) && $user_id > 0 ) {
				update_user_meta( $user_id, self::META_METHOD, $method );
			}
		}

		if ( array_key_exists( 'client_startup_profile', $fields ) && is_string( $fields['client_startup_profile'] ) ) {
			self::set_client_startup_profile( $user_id, $fields['client_startup_profile'] );
		}

		$runtime_after = [
			'wordpress_mode'       => (string) get_option( 'stonewright_mode', 'development' ),
			'mcp_surface'          => AbilityRegistry::mcp_surface(),
			'abilities_requested'  => PluginEffectiveState::enabled_requested(),
			'elementor_v4_atomic'  => (bool) get_option( 'stonewright_elementor_v4_atomic', false ),
		];
		if ( $runtime_before !== $runtime_after && $revision_before === AbilityRegistry::surface_revision() ) {
			AbilityRegistry::bump_surface_revision();
		}

		return self::export( $user_id );
	}
}
