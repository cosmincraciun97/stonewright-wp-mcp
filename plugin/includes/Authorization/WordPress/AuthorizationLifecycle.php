<?php
/**
 * Plugin lifecycle entry points for OAuth storage.
 * SPDX-License-Identifier: AGPL-3.0-or-later
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
 * - register() on every load: the clean-up handler, the schema upgrade on init for
 *   file-copy updates, and the key notice with its retry handler.
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

	/** Upgrade the tables when a file-copy update skipped activation. */
	public static function upgrade(): void {
		try {
			self::storage()->tables()->maybe_upgrade();
		} catch ( \Throwable $failure ) {
			Logger::error( 'oauth_schema_install_failed', [ 'error_class' => get_class( $failure ) ] );
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
}
