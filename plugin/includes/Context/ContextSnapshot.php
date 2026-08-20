<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\Context;

use Stonewright\WpMcp\Core\AbilityRegistry;
use Stonewright\WpMcp\Security\GlobalRules;

/**
 * Redacted, admin-safe view of the task-start / ContextBuilder snapshot.
 */
final class ContextSnapshot {

	/**
	 * @var list<string>
	 */
	private const URL_KEYS = [ 'normalized_url', 'site_url', 'write_target_url', 'url', 'home' ];

	/**
	 * @var list<string>
	 */
	private const EMAIL_KEYS = [ 'email', 'user_email', 'admin_email' ];

	/**
	 * @var list<string>
	 */
	private const ID_KEYS = [ 'post_id', 'id' ];

	/**
	 * @return array<string, mixed>
	 */
	public static function for_admin(): array {
		$built = ContextBuilder::build( 'Admin context snapshot', 'unknown', 'read' );

		$plugins = [];
		if ( function_exists( 'get_plugins' ) ) {
			foreach ( get_plugins() as $file => $plugin ) {
				if ( ! is_array( $plugin ) ) {
					continue;
				}
				$plugins[] = [
					'name'    => (string) ( $plugin['Name'] ?? $file ),
					'version' => (string) ( $plugin['Version'] ?? '' ),
					'active'  => function_exists( 'is_plugin_active' ) && is_plugin_active( (string) $file ),
				];
			}
		}

		$target = is_array( $built['target_context'] ?? null ) ? $built['target_context'] : [];

		return self::redact(
			[
				'php_version'         => PHP_VERSION,
				'wordpress_version'   => function_exists( 'get_bloginfo' ) ? (string) get_bloginfo( 'version' ) : '',
				'mode'                => (string) get_option( 'stonewright_mode', 'development' ),
				'tool_profile'        => (string) ( $target['tool_profile'] ?? AbilityRegistry::mcp_surface() ),
				'plugins'             => $plugins,
				'safety_rules'        => GlobalRules::ids_for_severity( 'hard' ),
				'user_context'        => $built['user_context'] ?? UserContext::get(),
				'custom_instructions' => $built['custom_instructions'] ?? [ 'enabled' => false, 'text' => '' ],
				'target_context'      => $target,
			]
		);
	}

	public static function redact( mixed $value, string $key = '' ): mixed {
		if ( is_array( $value ) ) {
			$out = [];
			foreach ( $value as $child_key => $child ) {
				$out[ $child_key ] = self::redact( $child, is_string( $child_key ) ? $child_key : $key );
			}
			return $out;
		}

		if ( is_int( $value ) && in_array( $key, self::ID_KEYS, true ) ) {
			return '[redacted-id]';
		}

		if ( ! is_string( $value ) ) {
			return $value;
		}

		if ( in_array( $key, self::URL_KEYS, true ) ) {
			return '[redacted-url]';
		}
		if ( in_array( $key, self::EMAIL_KEYS, true ) ) {
			return '[redacted-email]';
		}
		if ( in_array( $key, self::ID_KEYS, true ) && 1 === preg_match( '/^\d+$/', $value ) ) {
			return '[redacted-id]';
		}

		$redacted = preg_replace( '/[A-Z0-9._%+\-]+@[A-Z0-9.\-]+\.[A-Z]{2,}/i', '[redacted-email]', $value ) ?? $value;
		$redacted = preg_replace( '#https?://[^\s"\']+#i', '[redacted-url]', $redacted ) ?? $redacted;
		$redacted = preg_replace( '/\bpost\s+\d+\b/i', 'post [redacted-id]', $redacted ) ?? $redacted;
		$redacted = preg_replace( '/\b(?:post_id|id)\s*[:=]\s*\d+\b/i', '[redacted-id]', $redacted ) ?? $redacted;

		return $redacted;
	}
}
