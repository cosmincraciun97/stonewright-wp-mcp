<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\Security;

/**
 * Which WP-CLI argv the WP-CLI abilities refuse before the companion is called.
 *
 * The companion applies the same two lists when it runs a command; this copy lets an ability refuse
 * early, with a clear error, and decide which commands write files.
 *
 * - deny_reason(): the deny-list every runner applies (run, batch, jobs): commands that run code,
 *   install or change code, change users and roles, or touch the database or configuration directly.
 * - batch_reason(): the batch runner's allowlist on top of the deny-list: read-oriented commands and
 *   cache or rewrite flushes only.
 * - writes_files(): commands that write files, which DISALLOW_FILE_MODS forbids.
 *
 * The argv is read the way WP-CLI reads it, not the way a caller spells it: a leading `@alias`, global
 * flags before the command or between its words, quotes around a word, case, padding, and a whole
 * command in one token all reduce to the same command words. A global flag with a space-separated value
 * is read both ways (value skipped, value kept) and the argv is refused when either reading is refused.
 */
final class WpCliCommandPolicy {

	private const VALUE_FLAGS = [
		'--path',
		'--url',
		'--user',
		'--context',
		'--ssh',
		'--http',
		'--config',
		'--require',
		'--exec',
		'--skip-plugins',
		'--skip-themes',
		'--skip-packages',
	];

	private const BLOCKED_GROUPS = [ 'eval', 'eval-file', 'shell', 'package', 'config', 'scaffold' ];

	private const BLOCKED_FLAGS = [ '--exec', '--require', '--prompt', '--config', '--ssh', '--http' ];

	/** @var list<list<string>> */
	private const BLOCKED_PATHS = [
		[ 'plugin', 'install' ],
		[ 'plugin', 'update' ],
		[ 'plugin', 'delete' ],
		[ 'plugin', 'activate' ],
		[ 'plugin', 'uninstall' ],
		[ 'plugin', 'toggle' ],
		[ 'theme', 'install' ],
		[ 'theme', 'update' ],
		[ 'theme', 'delete' ],
		[ 'theme', 'activate' ],
		[ 'theme', 'enable' ],
		[ 'core', 'download' ],
		[ 'core', 'update' ],
		[ 'core', 'install' ],
		[ 'core', 'multisite-install' ],
		[ 'core', 'multisite-convert' ],
		[ 'cron', 'event', 'run' ],
		[ 'cli', 'update' ],
		[ 'cli', 'alias', 'add' ],
		[ 'cli', 'alias', 'update' ],
		[ 'cli', 'alias', 'set' ],
		[ 'cli', 'alias', 'delete' ],
		[ 'role', 'create' ],
		[ 'role', 'delete' ],
		[ 'role', 'reset' ],
		[ 'cap', 'add' ],
		[ 'cap', 'remove' ],
		[ 'super-admin', 'add' ],
		[ 'super-admin', 'remove' ],
	];

	/** @var array<string, list<list<string>>> */
	private const ALLOWED_ONLY = [
		'db'   => [ [ 'check' ], [ 'size' ], [ 'tables' ], [ 'columns' ], [ 'prefix' ], [ 'search' ] ],
		'user' => [ [ 'get' ], [ 'list' ], [ 'list-caps' ], [ 'meta', 'get' ], [ 'meta', 'list' ] ],
	];

	private const OPTION_WRITES = [ 'add', 'update', 'delete', 'patch', 'set-autoload' ];

	private const SENSITIVE_OPTIONS = [
		'default_role',
		'users_can_register',
		'wp_user_roles',
		'active_plugins',
		'active_sitewide_plugins',
		'siteurl',
		'home',
		'admin_email',
		'new_admin_email',
		'template',
		'stylesheet',
		'auto_update_plugins',
		'auto_update_themes',
	];

	/** @var list<list<string>> */
	private const BATCH_ALLOWED = [
		[ 'core', 'version' ],
		[ 'core', 'is-installed' ],
		[ 'core', 'check-update' ],
		[ 'cli', 'info' ],
		[ 'cli', 'version' ],
		[ 'post', 'get' ],
		[ 'post', 'list' ],
		[ 'post', 'exists' ],
		[ 'post', 'meta', 'get' ],
		[ 'post', 'meta', 'list' ],
		[ 'post', 'term', 'list' ],
		[ 'post-type', 'get' ],
		[ 'post-type', 'list' ],
		[ 'taxonomy', 'get' ],
		[ 'taxonomy', 'list' ],
		[ 'term', 'get' ],
		[ 'term', 'list' ],
		[ 'term', 'meta', 'get' ],
		[ 'term', 'meta', 'list' ],
		[ 'option', 'get' ],
		[ 'option', 'list' ],
		[ 'plugin', 'get' ],
		[ 'plugin', 'list' ],
		[ 'plugin', 'status' ],
		[ 'plugin', 'is-installed' ],
		[ 'plugin', 'is-active' ],
		[ 'plugin', 'path' ],
		[ 'theme', 'get' ],
		[ 'theme', 'list' ],
		[ 'theme', 'status' ],
		[ 'theme', 'is-installed' ],
		[ 'theme', 'is-active' ],
		[ 'theme', 'path' ],
		[ 'theme', 'mod', 'get' ],
		[ 'theme', 'mod', 'list' ],
		[ 'user', 'get' ],
		[ 'user', 'list' ],
		[ 'site', 'list' ],
		[ 'menu', 'list' ],
		[ 'menu', 'item', 'list' ],
		[ 'menu', 'location', 'list' ],
		[ 'media', 'image-size' ],
		[ 'comment', 'get' ],
		[ 'comment', 'list' ],
		[ 'widget', 'list' ],
		[ 'sidebar', 'list' ],
		[ 'role', 'list' ],
		[ 'role', 'exists' ],
		[ 'cap', 'list' ],
		[ 'transient', 'get' ],
		[ 'cron', 'event', 'list' ],
		[ 'cron', 'schedule', 'list' ],
		[ 'db', 'check' ],
		[ 'db', 'size' ],
		[ 'db', 'tables' ],
		[ 'db', 'columns' ],
		[ 'db', 'prefix' ],
		[ 'db', 'search' ],
		[ 'language', 'core', 'list' ],
		[ 'language', 'plugin', 'list' ],
		[ 'language', 'theme', 'list' ],
		[ 'search-replace' ],
		// Flushes rebuild caches and rewrite rules and change no content.
		[ 'cache', 'type' ],
		[ 'cache', 'flush' ],
		[ 'rewrite', 'list' ],
		[ 'rewrite', 'flush' ],
		[ 'elementor', 'flush-css' ],
		[ 'elementor', 'flush_css' ],
	];

	/** @var list<list<string>> */
	private const FILE_WRITING = [
		[ 'plugin', 'install' ],
		[ 'plugin', 'update' ],
		[ 'plugin', 'delete' ],
		[ 'plugin', 'uninstall' ],
		[ 'theme', 'install' ],
		[ 'theme', 'update' ],
		[ 'theme', 'delete' ],
		[ 'core', 'download' ],
		[ 'core', 'update' ],
		[ 'config' ],
		[ 'package' ],
		[ 'scaffold' ],
		[ 'language' ],
		[ 'cli', 'update' ],
		[ 'i18n', 'make-pot' ],
		[ 'i18n', 'make-json' ],
		[ 'i18n', 'make-php' ],
		[ 'export' ],
		[ 'db', 'export' ],
		[ 'maintenance-mode', 'activate' ],
		[ 'maintenance-mode', 'deactivate' ],
	];

	/**
	 * Why the deny-list refuses this argv, or null when it does not.
	 *
	 * @param array<int, mixed> $argv
	 */
	public static function deny_reason( array $argv ): ?string {
		$parsed = self::parse( $argv );
		foreach ( $parsed['flags'] as $flag ) {
			foreach ( self::BLOCKED_FLAGS as $blocked ) {
				if ( $flag === $blocked || str_starts_with( $flag, $blocked . '=' ) ) {
					return 'Blocked WP-CLI flag: ' . $blocked;
				}
			}
		}
		foreach ( $parsed['paths'] as $path ) {
			$reason = self::deny_reason_for_path( $path, $parsed['flags'] );
			if ( null !== $reason ) {
				return $reason;
			}
		}

		return null;
	}

	/**
	 * Why the batch runner refuses this argv (deny-list first, then the read-oriented allowlist), or null.
	 *
	 * @param array<int, mixed> $argv
	 */
	public static function batch_reason( array $argv ): ?string {
		$denied = self::deny_reason( $argv );
		if ( null !== $denied ) {
			return $denied;
		}

		$parsed = self::parse( $argv );
		if ( $parsed['leading_non_command'] ) {
			return 'WP-CLI batch commands start with the command words; aliases and global flags before them are not allowed. Use the path, url and user inputs instead.';
		}
		foreach ( $parsed['paths'] as $path ) {
			if ( ! self::matches_any( $path, self::BATCH_ALLOWED ) ) {
				return 'WP-CLI batch runs read-oriented commands only; not allowed: ' . ( implode( ' ', array_slice( $path, 0, 3 ) ) ?: '(empty)' ) . '. Use a typed ability, or the single-command runner where a profile lists it, for writes.';
			}
		}
		if ( count( $parsed['paths'] ) > 1 ) {
			return 'WP-CLI batch commands cannot put a flag value before the command words.';
		}

		return null;
	}

	/**
	 * Whether the command writes files (code, configuration, exports, rewrite rules, the maintenance file).
	 *
	 * @param array<int, mixed> $argv
	 */
	public static function writes_files( array $argv ): bool {
		$parsed = self::parse( $argv );
		foreach ( $parsed['paths'] as $path ) {
			if ( self::matches_any( $path, self::FILE_WRITING ) ) {
				return true;
			}
			if ( [ 'rewrite', 'flush' ] === array_slice( $path, 0, 2 ) && in_array( '--hard', $parsed['flags'], true ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * @param list<string> $path
	 * @param list<string> $flags
	 */
	private static function deny_reason_for_path( array $path, array $flags ): ?string {
		$group = $path[0] ?? '';
		if ( in_array( $group, self::BLOCKED_GROUPS, true ) ) {
			return 'Blocked WP-CLI command group: ' . $group;
		}
		foreach ( self::BLOCKED_PATHS as $blocked ) {
			if ( self::starts_with( $path, $blocked ) ) {
				return 'Blocked WP-CLI command: ' . implode( ' ', $blocked );
			}
		}
		if ( 'language' === $group && in_array( $path[2] ?? '', [ 'install', 'update', 'uninstall', 'activate' ], true ) ) {
			return 'Blocked WP-CLI command: language ' . ( $path[1] ?? '' ) . ' ' . $path[2];
		}
		if ( isset( self::ALLOWED_ONLY[ $group ] ) && ! self::matches_any( array_slice( $path, 1 ), self::ALLOWED_ONLY[ $group ] ) ) {
			return trim( 'Blocked WP-CLI command: ' . $group . ' ' . ( $path[1] ?? '' ) );
		}
		if ( 'search-replace' === $group && ! self::has_dry_run( $flags ) ) {
			return 'Blocked WP-CLI command: search-replace without --dry-run';
		}
		if ( 'option' === $group && in_array( $path[1] ?? '', self::OPTION_WRITES, true ) ) {
			$key = 'patch' === $path[1] ? ( $path[3] ?? null ) : ( $path[2] ?? null );
			if ( null !== $key && self::is_sensitive_option( $key ) ) {
				return 'Blocked WP-CLI command: option ' . $path[1] . ' ' . $key;
			}
		}

		return null;
	}

	private static function is_sensitive_option( string $key ): bool {
		return in_array( $key, self::SENSITIVE_OPTIONS, true )
			|| 1 === preg_match( '/(?:^|_)user_roles$/', $key )
			|| str_starts_with( $key, 'stonewright_' );
	}

	/**
	 * @param list<string> $flags
	 */
	private static function has_dry_run( array $flags ): bool {
		foreach ( $flags as $flag ) {
			if ( in_array( $flag, [ '--dry-run', '--dry-run=1', '--dry-run=true', '--dry-run=yes' ], true ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * @param list<string>       $path
	 * @param list<list<string>> $allowed
	 */
	private static function matches_any( array $path, array $allowed ): bool {
		foreach ( $allowed as $prefix ) {
			if ( self::starts_with( $path, $prefix ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * @param list<string> $path
	 * @param list<string> $prefix
	 */
	private static function starts_with( array $path, array $prefix ): bool {
		foreach ( $prefix as $index => $word ) {
			if ( ( $path[ $index ] ?? null ) !== $word ) {
				return false;
			}
		}

		return true;
	}

	private static function strip_quotes( string $token ): string {
		return (string) preg_replace( '/^[\s\'"`]+|[\s\'"`]+$/u', '', trim( $token ) );
	}

	/**
	 * @param array<int, mixed> $argv
	 * @return array{paths: list<list<string>>, flags: list<string>, leading_non_command: bool}
	 */
	private static function parse( array $argv ): array {
		$cleaned = [];
		foreach ( $argv as $raw ) {
			$token = mb_strtolower( self::strip_quotes( is_scalar( $raw ) ? (string) $raw : '' ) );
			if ( '' === $token ) {
				continue;
			}
			if ( str_starts_with( $token, '-' ) ) {
				$cleaned[] = $token;
				continue;
			}
			// A whole command in one token ("plugin install x") reads as separate words.
			foreach ( preg_split( '/\s+/u', $token ) ?: [] as $word ) {
				$part = self::strip_quotes( $word );
				if ( '' !== $part ) {
					$cleaned[] = $part;
				}
			}
		}

		$readings = [];
		$flags    = [];
		foreach ( [ true, false ] as $skip_values ) {
			$words     = [];
			$skip_next = false;
			foreach ( $cleaned as $token ) {
				if ( str_starts_with( $token, '-' ) ) {
					if ( $skip_values ) {
						$flags[] = $token;
					}
					$name      = str_contains( $token, '=' ) ? '' : $token;
					$skip_next = $skip_values && in_array( $name, self::VALUE_FLAGS, true );
					continue;
				}
				if ( $skip_next ) {
					$skip_next = false;
					continue;
				}
				if ( str_starts_with( $token, '@' ) && [] === $words ) {
					continue;
				}
				$words[] = $token;
			}
			$readings[] = $words;
		}

		$paths = [ $readings[0] ];
		if ( $readings[1] !== $readings[0] ) {
			$paths[] = $readings[1];
		}
		$first = $cleaned[0] ?? '';

		return [
			'paths'               => $paths,
			'flags'               => $flags,
			'leading_non_command' => str_starts_with( $first, '-' ) || str_starts_with( $first, '@' ),
		];
	}
}
