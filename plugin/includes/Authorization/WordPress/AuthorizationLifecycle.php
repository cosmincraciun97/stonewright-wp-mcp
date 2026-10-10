<?php
/**
 * Plugin lifecycle entry points for OAuth storage.
 * SPDX-License-Identifier: GPL-2.0-or-later
 *
 * @package Stonewright
 */

declare( strict_types=1 );

namespace Stonewright\WpMcp\Authorization\WordPress;

use Stonewright\WpMcp\Support\Logger;

/**
 * What the plugin bootstrap calls:
 * - activate() on plugin activation: install or upgrade the tables, create missing
 *   keys (never throws) and schedule the daily clean-up an hour from now;
 * - deactivate() on plugin deactivation: clear the clean-up schedule (data stays);
 * - register() on every load: the clean-up handler, upgrade() on init, and the key
 *   notice with its retry handler. upgrade() covers what activation skipped: on every
 *   request the schema upgrade for file-copy updates and the clean-up event, and on the
 *   request that installs or upgrades the tables of a site that never used OAuth, such
 *   as a sub-site's first init after a network activation, the keys.
 */
final class AuthorizationLifecycle {

	private static ?AuthorizationStorage $storage = null;

	/** Use another composition (tests, or a caller that already built one); null restores the default. */
	public static function use_storage( ?AuthorizationStorage $storage ): void {
		self::$storage = $storage;
	}

	public static function storage(): AuthorizationStorage {
		return self::$storage ??= AuthorizationStorage::wordpress();
	}

	public static function activate(): void {
		$storage = self::storage();
		try {
			$storage->tables()->install();
		} catch ( \Throwable $failure ) {
			Logger::error( 'oauth_schema_install_failed', [ 'error_class' => get_class( $failure ) ] );
		}
		$storage->keys()->ensure();
		self::schedule();
	}

	public static function deactivate(): void {
		wp_clear_scheduled_hook( Housekeeping::HOOK );
	}

	public static function register(): void {
		add_action( Housekeeping::HOOK, [ self::class, 'collect_garbage' ] );
		add_action( 'init', [ self::class, 'upgrade' ] );
		add_action( 'admin_notices', [ self::class, 'render_key_notice' ] );
		add_action( 'admin_post_' . KeyRecoveryNotice::ACTION, [ self::class, 'retry_keys' ] );
	}

	/**
	 * On init: upgrade the tables when a file-copy update skipped activation, and give a
	 * site whose tables this call installed or upgraded what activation would have given
	 * it. WordPress runs the activation hook once for a network activation, so a sub-site
	 * arrives here on its first init without keys. Every other request, the schema being
	 * current, only reads the version and checks the clean-up event: the keys are not
	 * looked at, so a request costs no query for them.
	 */
	public static function upgrade(): void {
		self::schedule();
		try {
			$storage = self::storage();
			$tables = $storage->tables();
			$was_current = $tables->current();
			$is_current = $tables->maybe_upgrade();
		} catch ( \Throwable $failure ) {
			Logger::error( 'oauth_schema_install_failed', [ 'error_class' => get_class( $failure ) ] );
			return;
		}
		if ( $is_current && ! $was_current ) {
			self::provision_keys( $storage );
		}
	}

	public static function schedule(): void {
		if ( false === wp_next_scheduled( Housekeeping::HOOK ) ) {
			wp_schedule_event( time() + HOUR_IN_SECONDS, 'daily', Housekeeping::HOOK );
		}
	}

	public static function collect_garbage(): void {
		self::storage()->housekeeping()->run();
	}

	public static function render_key_notice(): void {
		self::storage()->key_notice()->render();
	}

	public static function retry_keys(): void {
		self::storage()->key_notice()->handle();
	}

	/**
	 * Create the keys of a site that never used OAuth: no key, no client and no grant.
	 * New keys invalidate every credential already issued, so a site with OAuth state is
	 * left to KeyRecoveryNotice, where an administrator decides; so is a site where the
	 * creation fails, and the notice shows whenever keys are missing. This runs once, on
	 * the request that installed or upgraded the tables, never on a later one. Never throws.
	 */
	private static function provision_keys( AuthorizationStorage $storage ): void {
		$keys = $storage->keys();
		if ( $keys->ready() ) {
			return;
		}
		try {
			if ( $storage->tables()->holds_state() ) {
				Logger::warning( 'oauth_key_creation_skipped', [ 'reason' => 'oauth_state_present' ] );
			} else {
				$keys->ensure();
			}
		} catch ( \Throwable $failure ) {
			Logger::warning( 'oauth_key_creation_skipped', [ 'reason' => get_class( $failure ) ] );
		}
	}
}
