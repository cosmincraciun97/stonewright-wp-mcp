<?php
/**
 * Theme switches, plugin deletes, php-execute and admin settings writes in the change ledger.
 * SPDX-License-Identifier: GPL-2.0-or-later
 *
 * @package Stonewright
 */

declare( strict_types=1 );

namespace Stonewright\WpMcp\Security\Adapters;

use Stonewright\WpMcp\Security\Permissions;

/**
 * Four kinds of change that happen to the site as a whole.
 *
 * The active theme (resource type theme_switch, family option). The image holds the stylesheet and the template that were
 * active, so a restore switches back with switch_theme(). The theme's own settings are kept by WordPress
 * for each theme and are not touched.
 *
 * A plugin that was deleted (resource type plugin, family plugin). It is recorded and not restorable: Stonewright cannot
 * bring back the files of a plugin, so the row says plugin_files_deleted. It holds no image.
 *
 * A snippet that ran through php-execute with writes allowed (resource type php_execute, family other). It is recorded
 * with the sha256 and the length of the code and nothing else: the code, its output and what it returned
 * stay out, as they stay out of the audit log. The row says php_execute_not_undoable, because a snippet can
 * do anything and Stonewright cannot say what it changed.
 *
 * A setting that a person or a script changed from an admin screen or a REST route, where no ability ran
 * (resource type admin_setting, family other). It is recorded with the name of the setting and a short sentence, never a
 * value that could be a credential, and not restorable (admin_write_not_tracked).
 */
final class SiteAdapter extends FamilyAdapter {

	public const FAMILY = 'option';

	public const RECIPE = 'site';

	/** Ability name of a row that an admin screen or a route wrote. */
	public const ADMIN_ABILITY = 'stonewright/admin-settings-write';

	protected const ABILITIES = [ 'stonewright/theme-activate', 'stonewright/plugin-delete', 'stonewright/php-execute' ];

	public static function types(): array {
		return [ 'theme_switch', 'plugin', 'php_execute', 'admin_setting' ];
	}

	public static function ledger_family( string $type ): string {
		return match ( $type ) {
			'plugin'       => 'plugin',
			'theme_switch' => 'option',
			default        => 'other',
		};
	}

	public static function image( string $type, string $id ): ?array {
		if ( 'theme_switch' !== $type ) {
			return null;
		}
		return [
			'stylesheet' => (string) get_stylesheet(),
			'template'   => (string) get_template(),
			'v'          => self::IMAGE_VERSION,
		];
	}

	public static function watch( string $ability, array $args ): array {
		return 'stonewright/theme-activate' === $ability ? [ [ 'type' => 'theme_switch', 'id' => 'active_theme', 'before' => self::image( 'theme_switch', 'active_theme' ) ] ] : [];
	}

	public static function context( string $ability, array $args ): array {
		if ( 'stonewright/plugin-delete' === $ability ) {
			$plugin = isset( $args['plugin'] ) && is_string( $args['plugin'] ) ? $args['plugin'] : '';
			return [ 'plugin' => 1 === preg_match( '#^[A-Za-z0-9._/-]{1,140}$#D', $plugin ) && ! str_contains( $plugin, '..' ) ? $plugin : '' ];
		}
		if ( 'stonewright/php-execute' === $ability ) {
			$code = isset( $args['code'] ) && is_string( $args['code'] ) ? $args['code'] : '';
			return [
				'sha256'    => hash( 'sha256', $code ),
				'bytes'     => strlen( $code ),
				'read_only' => ! empty( $args['read_only'] ),
			];
		}
		return [];
	}

	public static function events( string $ability, array $context, mixed $result ): array {
		if ( 'stonewright/plugin-delete' === $ability && FamilyLedger::succeeded( $result ) && is_array( $result ) && true === ( $result['deleted'] ?? false ) && '' !== (string) ( $context['plugin'] ?? '' ) ) {
			return [
				[
					'type'    => 'plugin',
					'id'      => (string) $context['plugin'],
					'summary' => 'Deleted plugin ' . $context['plugin'] . '; its files cannot be restored by Stonewright',
					'reason'  => 'plugin_files_deleted',
					'failed'  => false,
				],
			];
		}
		if ( 'stonewright/php-execute' === $ability && false === ( $context['read_only'] ?? true ) && isset( $context['sha256'] ) ) {
			// The snippet ran when it returned, and when it threw. A snippet that did not parse, or that a guard refused, did not run.
			$ran = is_array( $result ) || ( $result instanceof \WP_Error && 'stonewright_php_execute_failed' === (string) $result->get_error_code() );
			if ( $ran ) {
				return [
					[
						'type'    => 'php_execute',
						'id'      => (string) $context['sha256'],
						'summary' => 'PHP snippet ran (' . (int) $context['bytes'] . ' bytes, sha256 ' . substr( (string) $context['sha256'], 0, 12 ) . '); what it changed is not tracked',
						'reason'  => 'php_execute_not_undoable',
						'failed'  => $result instanceof \WP_Error,
					],
				];
			}
		}
		return [];
	}

	public static function summarize( string $ability, string $type, string $id, ?array $before, ?array $after ): string {
		if ( 'theme_switch' === $type && null !== $before && null !== $after ) {
			return 'Switched theme from ' . FamilyLedger::clip( (string) ( $before['stylesheet'] ?? '' ), 60 ) . ' to ' . FamilyLedger::clip( (string) ( $after['stylesheet'] ?? '' ), 60 );
		}
		return parent::summarize( $ability, $type, $id, $before, $after );
	}

	/**
	 * Record a setting that was changed where no ability ran: an admin screen, a REST route. Names and
	 * short sentences only; the value of a setting is never recorded.
	 */
	public static function note_admin_write( string $channel, string $setting, string $summary ): void {
		try {
			if ( FamilyLedger::restoring() || ! FamilyLedger::ready() ) {
				return;
			}
			$change = FamilyLedger::record(
				[
					'ability'           => self::ADMIN_ABILITY,
					'family'            => 'other',
					'resource_type'     => 'admin_setting',
					'resource_id'       => $setting,
					'before'            => null,
					'restorable'        => false,
					'restorable_reason' => 'admin_write_not_tracked',
					'summary'           => $summary,
				]
			);
			FamilyLedger::settle( $change, 'verified' );
		} catch ( \Throwable $failure ) {
			\Stonewright\WpMcp\Support\Logger::warning( 'change_ledger_record_failed', [ 'error' => $failure::class, 'channel' => $channel ] );
		}
	}

	protected static function subject( string $type, ?array $image, string $id ): string {
		return 'theme_switch' === $type ? 'theme ' . FamilyLedger::clip( (string) ( $image['stylesheet'] ?? '' ), 60 ) : $type . ' ' . FamilyLedger::clip( $id, 40 );
	}

	protected static function permitted( string $type, string $operation ): bool {
		return Permissions::switch_themes();
	}

	protected static function write( string $type, string $id, ?array $target, array $row, array $options, ?array $live ): array {
		if ( 'theme_switch' !== $type || null === $target ) {
			return self::refused( 'not_restorable' );
		}
		$stylesheet = (string) ( $target['stylesheet'] ?? '' );
		$theme      = '' === $stylesheet ? null : wp_get_theme( $stylesheet );
		if ( ! is_object( $theme ) || ! $theme->exists() ) {
			return self::refused( 'theme_missing' );
		}
		switch_theme( $stylesheet );
		$differences = self::differences( $target, self::image( 'theme_switch', $id ) );
		return self::applied( [] === $differences, [] === $differences ? 'switched' : 'differences:' . implode( ',', $differences ), $id );
	}
}
