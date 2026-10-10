<?php
/**
 * Rollback recipes named by the change journal.
 * SPDX-License-Identifier: GPL-2.0-or-later
 *
 * @package Stonewright
 */

declare( strict_types=1 );

namespace Stonewright\WpMcp\Security;

use Stonewright\WpMcp\CustomCode\ProviderRegistry;
use Stonewright\WpMcp\CustomCode\ProviderSupport;
use Stonewright\WpMcp\Sandbox\SandboxFiles;

/**
 * Undoes a journaled change from the reference the entry recorded before the write.
 *
 * Every recipe works from state the write created for the purpose (a post snapshot, an option
 * restore point, a theme backup, the plugin's previous state, a sandbox twin, a custom-code
 * snapshot), is safe to run again, and reports the same small shape:
 * `status` is succeeded, failed, noop (already as it was) or not_available (no recipe), and
 * `detail` is a short machine reason when something went wrong. A recipe never probes the
 * site: whoever runs it probes afterwards.
 */
final class RollbackRecipes {

	/** @var callable(string):?object|null */
	private static $provider_resolver = null;

	/**
	 * Run the recipe of a journal entry.
	 *
	 * @param array<string, mixed> $entry
	 * @return array{status:string,recipe:string,detail:string}
	 */
	public static function run( array $entry ): array {
		$type = self::type( $entry );
		try {
			$outcome = match ( $type ) {
				'post_snapshot'  => self::post_snapshot( $entry ),
				'option_restore' => self::option_restore( $entry ),
				'theme_backup'   => self::theme_backup( $entry ),
				'plugin_state'   => self::plugin_state( $entry ),
				'sandbox_file'   => self::sandbox_file( $entry ),
				'sandbox_restore' => self::sandbox_restore( $entry ),
				default          => self::provider_snapshot( $entry ),
			};
		} catch ( \Throwable $failure ) {
			unset( $failure );
			$outcome = [ 'status' => 'failed', 'detail' => 'exception' ];
		}
		return [
			'status' => $outcome['status'],
			'recipe' => $type,
			'detail' => $outcome['detail'],
		];
	}

	/**
	 * Save what the recipe of an entry is about to overwrite, so that the recipe can be undone.
	 *
	 * The result's `restore` is shaped like a journal entry: running it with run() puts the state saved
	 * here back, the way a recipe puts the older state back. Nothing is written to the journal.
	 *
	 * @param array<string, mixed> $entry
	 * @return array{status:string,detail:string,restore?:array<string,mixed>}
	 */
	public static function capture( array $entry ): array {
		try {
			return match ( self::type( $entry ) ) {
				'post_snapshot'  => self::capture_post( $entry ),
				'option_restore' => self::capture_options( $entry ),
				'theme_backup'   => self::capture_theme( $entry ),
				'plugin_state'   => self::capture_plugin( $entry ),
				'sandbox_file'   => self::capture_sandbox( $entry ),
				default          => self::capture_provider( $entry ),
			};
		} catch ( \Throwable $failure ) {
			unset( $failure );
			return [ 'status' => 'failed', 'detail' => 'exception' ];
		}
	}

	/**
	 * Whether the entry names a recipe that can run.
	 *
	 * @param array<string, mixed> $entry
	 */
	public static function available( array $entry ): bool {
		if ( 'none' !== self::type( $entry ) ) {
			return true;
		}
		// A snippet recipe is only as long-lived as the provider snapshot it names.
		return self::provider_snapshot_alive( $entry );
	}

	/**
	 * One sentence about what the rollback does.
	 *
	 * @param array<string, mixed> $entry
	 */
	public static function describe( array $entry ): string {
		$ref    = self::ref( $entry );
		$detail = self::detail( $entry );
		return match ( self::type( $entry ) ) {
			'post_snapshot'  => sprintf(
				/* translators: %s: post ID. */
				__( 'Restore post %s from the snapshot taken before the change.', 'stonewright' ),
				(string) ( $detail['post_id'] ?? $entry['resource_key'] ?? '' )
			),
			'option_restore' => __( 'Restore the options and theme settings from the restore point taken before the change.', 'stonewright' ),
			'theme_backup'   => self::theme_backup_plan( $ref ),
			'plugin_state'   => ! empty( $detail['was_active'] )
				? sprintf(
					/* translators: %s: plugin file. */
					__( 'Activate %s again.', 'stonewright' ),
					$ref
				)
				: sprintf(
					/* translators: %s: plugin file. */
					__( 'Deactivate %s.', 'stonewright' ),
					$ref
				),
			'sandbox_file'   => sprintf(
				/* translators: %s: sandbox file name. */
				__( 'Disable the active copy of sandbox file %s. The draft stays for review.', 'stonewright' ),
				$ref
			),
			default          => self::describe_snippet( $entry ),
		};
	}

	/**
	 * One sentence about the rollback of a snippet, which depends on whether its provider snapshot is still there.
	 *
	 * @param array<string, mixed> $entry
	 */
	private static function describe_snippet( array $entry ): string {
		if ( null === self::provider_detail( $entry ) ) {
			return __( 'No automatic rollback is available for this change. Undo it by hand, then check the site again.', 'stonewright' );
		}
		return self::provider_snapshot_alive( $entry )
			? __( 'Restore the snippet from the provider snapshot taken before the change.', 'stonewright' )
			: __( 'The provider snapshot taken before the change has expired, so no automatic rollback is available. Undo it by hand, then check the site again.', 'stonewright' );
	}

	/** One sentence about what a theme-file rollback does, from the kind of reference it holds. */
	private static function theme_backup_plan( string $ref ): string {
		if ( str_starts_with( $ref, 'absent:' ) ) {
			return __( 'Delete the theme file the change created.', 'stonewright' );
		}
		if ( str_starts_with( $ref, 'empty:' ) ) {
			return __( 'Empty the theme file again; it was empty before the change.', 'stonewright' );
		}
		return __( 'Restore the theme file from the backup taken before the change.', 'stonewright' );
	}

	/**
	 * Replace how a custom-code provider is found. For tests.
	 *
	 * @param callable(string):?object|null $resolver
	 */
	public static function set_provider_resolver( ?callable $resolver ): void {
		self::$provider_resolver = $resolver;
	}

	// -----------------------------------------------------------------------
	// Recipes.
	// -----------------------------------------------------------------------

	/**
	 * @param array<string, mixed> $entry
	 * @return array{status:string,detail:string}
	 */
	private static function post_snapshot( array $entry ): array {
		$detail      = self::detail( $entry );
		$post_id     = (int) ( $detail['post_id'] ?? $entry['resource_key'] ?? 0 );
		$snapshot_id = '' !== self::ref( $entry ) ? self::ref( $entry ) : (string) ( $detail['snapshot_id'] ?? '' );
		if ( $post_id < 1 || ! get_post( $post_id ) ) {
			return [ 'status' => 'failed', 'detail' => 'post_missing' ];
		}
		if ( '' === $snapshot_id || null === Backup::get_snapshot( $post_id, $snapshot_id ) ) {
			return [ 'status' => 'failed', 'detail' => 'snapshot_missing' ];
		}
		return Backup::restore( $post_id, $snapshot_id )
			? [ 'status' => 'succeeded', 'detail' => '' ]
			: [ 'status' => 'failed', 'detail' => 'restore_failed' ];
	}

	/**
	 * @param array<string, mixed> $entry
	 * @return array{status:string,detail:string}
	 */
	private static function option_restore( array $entry ): array {
		$restore_id = self::ref( $entry );
		$store      = get_option( Backup::OPTION_SNAPSHOTS, [] );
		if ( '' === $restore_id || ! is_array( $store ) || ! isset( $store[ $restore_id ] ) ) {
			return [ 'status' => 'failed', 'detail' => 'snapshot_missing' ];
		}
		return Backup::restore_options( $restore_id )
			? [ 'status' => 'succeeded', 'detail' => '' ]
			: [ 'status' => 'failed', 'detail' => 'restore_failed' ];
	}

	/**
	 * @param array<string, mixed> $entry
	 * @return array{status:string,detail:string}
	 */
	private static function theme_backup( array $entry ): array {
		$ref      = self::ref( $entry );
		$absolute = (string) ( self::detail( $entry )['absolute'] ?? '' );
		if ( str_starts_with( $ref, 'absent:' ) ) {
			return ThemeWriteTransaction::remove_created_file( $absolute );
		}
		if ( str_starts_with( $ref, 'empty:' ) ) {
			return ThemeWriteTransaction::empty_file( $absolute );
		}
		return ThemeWriteTransaction::restore_for_rescue( $ref );
	}

	/**
	 * @param array<string, mixed> $entry
	 * @return array{status:string,detail:string}
	 */
	private static function plugin_state( array $entry ): array {
		$plugin = self::ref( $entry );
		if ( ! self::is_plugin_file( $plugin ) ) {
			return [ 'status' => 'failed', 'detail' => 'invalid_plugin' ];
		}
		if ( ! function_exists( 'activate_plugin' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}
		if ( ! empty( self::detail( $entry )['was_active'] ) ) {
			$result = activate_plugin( $plugin, '', false, true );
			return is_wp_error( $result )
				? [ 'status' => 'failed', 'detail' => substr( sanitize_key( (string) $result->get_error_code() ), 0, 64 ) ]
				: [ 'status' => 'succeeded', 'detail' => '' ];
		}
		deactivate_plugins( $plugin, true );
		return [ 'status' => 'succeeded', 'detail' => '' ];
	}

	/**
	 * @param array<string, mixed> $entry
	 * @return array{status:string,detail:string}
	 */
	private static function sandbox_file( array $entry ): array {
		$name = self::ref( $entry );
		if ( ! self::is_sandbox_name( $name ) ) {
			return [ 'status' => 'failed', 'detail' => 'invalid_name' ];
		}
		$twin = SandboxFiles::mu_dir() . '/' . SandboxFiles::active_prefix() . $name;
		if ( ! file_exists( $twin ) ) {
			return [ 'status' => 'noop', 'detail' => '' ];
		}
		return is_wp_error( SandboxFiles::disable( $name ) )
			? [ 'status' => 'failed', 'detail' => 'disable_failed' ]
			: [ 'status' => 'succeeded', 'detail' => '' ];
	}

	/**
	 * Put the active copy of a sandbox file back as it was saved. It only puts back what a recipe
	 * overwrote; a journal entry never names it.
	 *
	 * @param array<string, mixed> $entry
	 * @return array{status:string,detail:string}
	 */
	private static function sandbox_restore( array $entry ): array {
		$detail = self::detail( $entry );
		$name   = (string) ( $detail['name'] ?? '' );
		if ( ! self::is_sandbox_name( $name ) ) {
			return [ 'status' => 'failed', 'detail' => 'invalid_name' ];
		}
		if ( empty( $detail['active'] ) ) {
			return [ 'status' => 'noop', 'detail' => '' ];
		}
		$bytes = (string) ( $detail['bytes'] ?? '' );
		$hash  = hash( 'sha256', $bytes );
		$twin  = SandboxFiles::mu_dir() . '/' . SandboxFiles::active_prefix() . $name;
		clearstatcache( true, $twin );
		if ( is_file( $twin ) && hash_equals( $hash, hash( 'sha256', (string) file_get_contents( $twin ) ) ) ) {
			return [ 'status' => 'noop', 'detail' => '' ];
		}
		if ( false === file_put_contents( $twin, $bytes, LOCK_EX ) ) { // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
			return [ 'status' => 'failed', 'detail' => 'restore_failed' ];
		}
		clearstatcache( true, $twin );
		if ( ! hash_equals( $hash, hash( 'sha256', (string) file_get_contents( $twin ) ) ) ) {
			return [ 'status' => 'failed', 'detail' => 'readback_mismatch' ];
		}
		if ( is_file( $twin . '.disabled' ) ) {
			@unlink( $twin . '.disabled' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink, WordPress.PHP.NoSilencedErrors.Discouraged
		}
		return [ 'status' => 'succeeded', 'detail' => '' ];
	}

	/**
	 * The custom-code snapshot a provider took before it saved a snippet.
	 *
	 * @param array<string, mixed> $entry
	 * @return array{status:string,detail:string}
	 */
	private static function provider_snapshot( array $entry ): array {
		$detail = self::provider_detail( $entry );
		if ( null === $detail ) {
			return [ 'status' => 'not_available', 'detail' => 'no_recipe' ];
		}
		$provider = self::provider( $detail['provider'] );
		if ( null === $provider || ! method_exists( $provider, 'rollback' ) ) {
			return [ 'status' => 'failed', 'detail' => 'provider_missing' ];
		}
		$args = [ 'snapshot_id' => $detail['snapshot_id'] ];
		if ( '' !== $detail['target_id'] ) {
			$args['target_id'] = $detail['target_id'];
		}
		$result = $provider->rollback( $args );
		if ( is_wp_error( $result ) ) {
			return [ 'status' => 'failed', 'detail' => substr( sanitize_key( (string) $result->get_error_code() ), 0, 64 ) ];
		}
		return is_array( $result ) && true === ( $result['effect_verified'] ?? false )
			? [ 'status' => 'succeeded', 'detail' => '' ]
			: [ 'status' => 'failed', 'detail' => 'restore_not_verified' ];
	}

	// -----------------------------------------------------------------------
	// Capture: the state a recipe is about to overwrite.
	// -----------------------------------------------------------------------

	/**
	 * @param array<string, mixed> $entry
	 * @return array{status:string,detail:string,restore?:array<string,mixed>}
	 */
	private static function capture_post( array $entry ): array {
		$detail      = self::detail( $entry );
		$post_id     = (int) ( $detail['post_id'] ?? $entry['resource_key'] ?? 0 );
		$snapshot_id = '' !== self::ref( $entry ) ? self::ref( $entry ) : (string) ( $detail['snapshot_id'] ?? '' );
		if ( $post_id < 1 || ! get_post( $post_id ) ) {
			return [ 'status' => 'failed', 'detail' => 'post_missing' ];
		}
		// The snapshot the recipe restores must outlive the one that is saved now.
		$saved = Backup::snapshot_post( $post_id, '' !== $snapshot_id ? [ $snapshot_id ] : [], false );
		if ( '' === $saved ) {
			return [ 'status' => 'failed', 'detail' => 'snapshot_failed' ];
		}
		return self::captured( 'post_snapshot', $saved, [ 'post_id' => $post_id, 'snapshot_id' => $saved ], 'post', (string) $post_id );
	}

	/**
	 * @param array<string, mixed> $entry
	 * @return array{status:string,detail:string,restore?:array<string,mixed>}
	 */
	private static function capture_options( array $entry ): array {
		$restore_id = self::ref( $entry );
		$store      = get_option( Backup::OPTION_SNAPSHOTS, [] );
		$row        = is_array( $store ) && isset( $store[ $restore_id ] ) && is_array( $store[ $restore_id ] ) ? $store[ $restore_id ] : null;
		if ( '' === $restore_id || null === $row ) {
			return [ 'status' => 'failed', 'detail' => 'snapshot_missing' ];
		}
		$options    = array_map( 'strval', array_keys( (array) ( $row['options'] ?? [] ) ) );
		$theme_mods = array_map( 'strval', array_keys( (array) ( $row['theme_mods'] ?? [] ) ) );
		$saved      = Backup::snapshot_options( $options, $theme_mods, false, [ $restore_id ] );
		$after      = get_option( Backup::OPTION_SNAPSHOTS, [] );
		if ( '' === $saved || ! is_array( $after ) || ! isset( $after[ $saved ] ) ) {
			return [ 'status' => 'failed', 'detail' => 'snapshot_failed' ];
		}
		return self::captured( 'option_restore', $saved, [ 'restore_id' => $saved ], 'option', implode( ',', array_slice( $options, 0, 8 ) ) );
	}

	/**
	 * @param array<string, mixed> $entry
	 * @return array{status:string,detail:string,restore?:array<string,mixed>}
	 */
	private static function capture_theme( array $entry ): array {
		$saved = ThemeWriteTransaction::capture_for_rescue( self::ref( $entry ), (string) ( self::detail( $entry )['absolute'] ?? '' ) );
		if ( 'ok' !== $saved['status'] ) {
			return [ 'status' => 'failed', 'detail' => $saved['detail'] ];
		}
		return self::captured( 'theme_backup', (string) $saved['ref'], [ 'absolute' => (string) $saved['absolute'] ], 'theme_file', (string) ( $entry['resource_key'] ?? '' ) );
	}

	/**
	 * @param array<string, mixed> $entry
	 * @return array{status:string,detail:string,restore?:array<string,mixed>}
	 */
	private static function capture_plugin( array $entry ): array {
		$plugin = self::ref( $entry );
		if ( ! self::is_plugin_file( $plugin ) ) {
			return [ 'status' => 'failed', 'detail' => 'invalid_plugin' ];
		}
		if ( ! function_exists( 'is_plugin_active' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}
		return self::captured( 'plugin_state', $plugin, [ 'plugin' => $plugin, 'was_active' => (bool) is_plugin_active( $plugin ) ], 'plugin', $plugin );
	}

	/**
	 * The active copy of a sandbox file, byte for byte: the recipe renames it away.
	 *
	 * @param array<string, mixed> $entry
	 * @return array{status:string,detail:string,restore?:array<string,mixed>}
	 */
	private static function capture_sandbox( array $entry ): array {
		$name = self::ref( $entry );
		if ( ! self::is_sandbox_name( $name ) ) {
			return [ 'status' => 'failed', 'detail' => 'invalid_name' ];
		}
		$twin = SandboxFiles::mu_dir() . '/' . SandboxFiles::active_prefix() . $name;
		clearstatcache( true, $twin );
		if ( ! file_exists( $twin ) ) {
			// Nothing is active, so the recipe changes nothing and there is nothing to put back.
			return self::captured( 'sandbox_restore', $name, [ 'name' => $name, 'active' => false ], 'sandbox', $name );
		}
		$bytes = is_file( $twin ) ? file_get_contents( $twin ) : false;
		if ( false === $bytes ) {
			return [ 'status' => 'failed', 'detail' => 'unreadable' ];
		}
		return self::captured( 'sandbox_restore', $name, [ 'name' => $name, 'active' => true, 'bytes' => $bytes ], 'sandbox', $name );
	}

	/**
	 * The snippet as its provider holds it now, saved as a provider snapshot.
	 *
	 * @param array<string, mixed> $entry
	 * @return array{status:string,detail:string,restore?:array<string,mixed>}
	 */
	private static function capture_provider( array $entry ): array {
		$detail = self::provider_detail( $entry );
		if ( null === $detail ) {
			return [ 'status' => 'failed', 'detail' => 'no_recipe' ];
		}
		$provider = self::provider( $detail['provider'] );
		if ( null === $provider || ! method_exists( $provider, 'read' ) ) {
			return [ 'status' => 'failed', 'detail' => 'provider_missing' ];
		}
		$read = $provider->read( $detail['target_id'] );
		if ( is_wp_error( $read ) ) {
			return [ 'status' => 'failed', 'detail' => substr( sanitize_key( (string) $read->get_error_code() ), 0, 64 ) ];
		}
		if ( ! is_array( $read ) || ! isset( $read['code'] ) || ! is_string( $read['code'] ) ) {
			return [ 'status' => 'failed', 'detail' => 'read_failed' ];
		}
		$extra    = array_key_exists( 'active', $read ) ? [ 'active' => (bool) $read['active'] ] : [];
		$snapshot = ProviderSupport::snapshot_record( $detail['provider'], $detail['target_id'], is_string( $read['path'] ?? null ) ? $read['path'] : '', $read['code'], $extra );
		if ( null === ProviderSupport::load_snapshot( $snapshot['snapshot_id'] ) ) {
			return [ 'status' => 'failed', 'detail' => 'snapshot_not_stored' ];
		}
		return [
			'status'  => 'ok',
			'detail'  => '',
			'restore' => [
				'resource_type' => 'custom_code',
				'resource_key'  => $detail['provider'] . ':' . $detail['target_id'],
				'recipe'        => [ 'type' => 'none', 'ref' => $snapshot['snapshot_id'] ],
				'recipe_detail' => [ 'provider' => $detail['provider'], 'snapshot_id' => $snapshot['snapshot_id'], 'target_id' => $detail['target_id'] ],
			],
		];
	}

	/**
	 * An entry-shaped recipe that puts the saved state back.
	 *
	 * @param array<string, bool|int|string> $detail
	 * @return array{status:string,detail:string,restore:array<string,mixed>}
	 */
	private static function captured( string $type, string $ref, array $detail, string $resource_type, string $resource_key ): array {
		return [
			'status'  => 'ok',
			'detail'  => '',
			'restore' => [
				'resource_type' => $resource_type,
				'resource_key'  => $resource_key,
				'recipe'        => [ 'type' => $type, 'ref' => $ref ],
				'recipe_detail' => $detail,
			],
		];
	}

	private static function is_plugin_file( string $plugin ): bool {
		return 1 === preg_match( '#^[A-Za-z0-9][A-Za-z0-9._-]*(?:/[A-Za-z0-9][A-Za-z0-9._-]*)*\.php$#D', $plugin ) && ! str_contains( strtolower( $plugin ), 'stonewright' );
	}

	private static function is_sandbox_name( string $name ): bool {
		return $name === basename( $name ) && SandboxFiles::valid_name( $name ) && ! in_array( $name, SandboxFiles::RESERVED_NAMES, true );
	}

	/** The custom-code provider with this id, or null. */
	private static function provider( string $id ): ?object {
		$resolver = self::$provider_resolver ?? static fn ( string $provider_id ): ?object => ProviderRegistry::get( $provider_id );
		$provider = $resolver( $id );
		return is_object( $provider ) ? $provider : null;
	}

	// -----------------------------------------------------------------------
	// Entry accessors.
	// -----------------------------------------------------------------------

	/** @param array<string, mixed> $entry */
	private static function type( array $entry ): string {
		$recipe = is_array( $entry['recipe'] ?? null ) ? $entry['recipe'] : [];
		return isset( $recipe['type'] ) && is_scalar( $recipe['type'] ) ? (string) $recipe['type'] : 'none';
	}

	/** @param array<string, mixed> $entry */
	private static function ref( array $entry ): string {
		$recipe = is_array( $entry['recipe'] ?? null ) ? $entry['recipe'] : [];
		return isset( $recipe['ref'] ) && is_scalar( $recipe['ref'] ) ? (string) $recipe['ref'] : '';
	}

	/**
	 * @param array<string, mixed> $entry
	 * @return array<string, mixed>
	 */
	private static function detail( array $entry ): array {
		return is_array( $entry['recipe_detail'] ?? null ) ? $entry['recipe_detail'] : [];
	}

	/**
	 * Whether the entry names a provider snapshot that still exists. The snapshot is kept for a day.
	 *
	 * @param array<string, mixed> $entry
	 */
	private static function provider_snapshot_alive( array $entry ): bool {
		$detail = self::provider_detail( $entry );
		return null !== $detail && null !== ProviderSupport::load_snapshot( $detail['snapshot_id'] );
	}

	/**
	 * @param array<string, mixed> $entry
	 * @return array{provider:string,snapshot_id:string,target_id:string}|null
	 */
	private static function provider_detail( array $entry ): ?array {
		$detail   = self::detail( $entry );
		$provider = isset( $detail['provider'] ) && is_scalar( $detail['provider'] ) ? sanitize_key( (string) $detail['provider'] ) : '';
		$snapshot = isset( $detail['snapshot_id'] ) && is_scalar( $detail['snapshot_id'] ) ? (string) $detail['snapshot_id'] : '';
		if ( '' === $provider || '' === $snapshot ) {
			return null;
		}
		return [
			'provider'    => $provider,
			'snapshot_id' => $snapshot,
			'target_id'   => isset( $detail['target_id'] ) && is_scalar( $detail['target_id'] ) ? (string) $detail['target_id'] : '',
		];
	}
}
