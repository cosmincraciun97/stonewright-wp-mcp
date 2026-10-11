<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\Tests\Unit\WpCli;

use PHPUnit\Framework\TestCase;
use Stonewright\WpMcp\Security\WpCliCommandPolicy;

/**
 * The plugin-side copy of the companion's WP-CLI command policy: same deny-list, same read-oriented
 * batch allowlist, same reading of aliases, global flags, quotes and case.
 *
 * @covers \Stonewright\WpMcp\Security\WpCliCommandPolicy
 */
final class WpCliCommandPolicyTest extends TestCase {

	/**
	 * @return array<string, array{0: list<string>}>
	 */
	public static function denied(): array {
		$rows = [
			'plugin install'               => [ 'plugin', 'install', 'some-plugin' ],
			'plugin install --activate'    => [ 'plugin', 'install', 'some-plugin', '--activate' ],
			'plugin update'                => [ 'plugin', 'update', '--all' ],
			'plugin delete'                => [ 'plugin', 'delete', 'some-plugin' ],
			'plugin activate'              => [ 'plugin', 'activate', 'some-plugin' ],
			'plugin uninstall'             => [ 'plugin', 'uninstall', 'some-plugin' ],
			'theme install'                => [ 'theme', 'install', 'some-theme' ],
			'theme update'                 => [ 'theme', 'update', '--all' ],
			'theme delete'                 => [ 'theme', 'delete', 'some-theme' ],
			'theme activate'               => [ 'theme', 'activate', 'some-theme' ],
			'db query'                     => [ 'db', 'query', 'SELECT 1' ],
			'db import'                    => [ 'db', 'import', 'dump.sql' ],
			'db reset'                     => [ 'db', 'reset', '--yes' ],
			'db drop'                      => [ 'db', 'drop', '--yes' ],
			'db export'                    => [ 'db', 'export', 'dump.sql' ],
			'db cli'                       => [ 'db', 'cli' ],
			'bare db'                      => [ 'db' ],
			'config get'                   => [ 'config', 'get', 'DB_PASSWORD' ],
			'config set'                   => [ 'config', 'set', 'WP_DEBUG', 'true' ],
			'user create'                  => [ 'user', 'create', 'x', 'x@example.test', '--role=administrator' ],
			'user update'                  => [ 'user', 'update', '1', '--user_pass=secret' ],
			'user delete'                  => [ 'user', 'delete', '2', '--yes' ],
			'user add-role'                => [ 'user', 'add-role', '2', 'administrator' ],
			'user set-role'                => [ 'user', 'set-role', '2', 'administrator' ],
			'user meta update'             => [ 'user', 'meta', 'update', '2', 'wp_capabilities', '{}' ],
			'cron event run'               => [ 'cron', 'event', 'run', 'some_hook' ],
			'search-replace'               => [ 'search-replace', 'old', 'new' ],
			'search-replace dry-run=false' => [ 'search-replace', 'old', 'new', '--dry-run=false' ],
			'core download'                => [ 'core', 'download' ],
			'core update'                  => [ 'core', 'update' ],
			'package install'              => [ 'package', 'install', 'x' ],
			'eval'                         => [ 'eval', 'echo 1;' ],
			'eval-file'                    => [ 'eval-file', 'x.php' ],
			'shell'                        => [ 'shell' ],
			'scaffold plugin'              => [ 'scaffold', 'plugin', 'x' ],
			'language install'             => [ 'language', 'core', 'install', 'ro_RO' ],
			'cli update'                   => [ 'cli', 'update' ],
			'role create'                  => [ 'role', 'create', 'x', 'X' ],
			'cap add'                      => [ 'cap', 'add', 'editor', 'manage_options' ],
			'sensitive option update'      => [ 'option', 'update', 'default_role', 'administrator' ],
			'option update registration'   => [ 'option', 'update', 'users_can_register', '1' ],
			'option update plugin setting' => [ 'option', 'update', 'stonewright_mode', 'development' ],
			'option patch'                 => [ 'option', 'patch', 'update', 'wp_user_roles', 'administrator', 'x' ],
			'--exec flag'                  => [ 'post', 'list', '--exec=echo 1;' ],
			'--require flag'               => [ 'post', 'list', '--require=bootstrap.php' ],
			'--config flag'                => [ 'post', 'list', '--config=other.yml' ],
			'--ssh flag'                   => [ 'post', 'list', '--ssh=host' ],
			// Bypass shapes.
			'leading alias'                => [ '@prod', 'plugin', 'install', 'x' ],
			'alias then global flag'       => [ '@prod', '--path=/srv/site', 'eval', '1' ],
			'global flags first'           => [ '--path=/srv/site', '--url=https://example.test', '--user=1', 'db', 'query', 'SELECT 1' ],
			'global flag, separate value'  => [ '--path', '/srv/site', 'plugin', 'install', 'x' ],
			'--user, separate value'       => [ '--user', '1', 'user', 'create', 'x', 'x@example.test' ],
			'double quotes'                => [ '"plugin"', '"install"', 'x' ],
			'single quotes'                => [ "'eval'", 'echo 1;' ],
			'quotes after flags'           => [ '--path=/srv/site', "'eval-file'", 'x.php' ],
			'upper case'                   => [ 'PLUGIN', 'Install', 'x' ],
			'padded tokens'                => [ '  plugin  ', ' install ', 'x' ],
			'one token'                    => [ 'plugin install x' ],
			'quoted one token'             => [ '"db query" "SELECT 1"' ],
			'flags between words'          => [ 'plugin', '--format=json', 'install', 'x' ],
			'flag inside the words'        => [ 'cron', 'event', '--quiet', 'run', 'some_hook' ],
			'everything at once'           => [ '@staging', '--url=https://a.example.test', "'Plugin'", '"Update"', '--all' ],
			'value flag hides the group'   => [ '--skip-plugins', 'plugin', 'install', 'x' ],
		];
		$out = [];
		foreach ( $rows as $label => $argv ) {
			$out[ $label ] = [ $argv ];
		}
		return $out;
	}

	/**
	 * @dataProvider denied
	 * @param list<string> $argv
	 */
	public function test_deny_list_refuses( array $argv ): void {
		self::assertNotNull( WpCliCommandPolicy::deny_reason( $argv ), implode( ' ', $argv ) );
	}

	/**
	 * @return array<string, array{0: list<string>}>
	 */
	public static function allowed(): array {
		$rows = [
			'plugin list'            => [ 'plugin', 'list', '--format=json' ],
			'plugin deactivate'      => [ 'plugin', 'deactivate', 'some-plugin' ],
			'theme list'             => [ 'theme', 'list' ],
			'db check'               => [ 'db', 'check' ],
			'user list'              => [ 'user', 'list', '--format=json' ],
			'user get'               => [ 'user', 'get', '1' ],
			'cron event list'        => [ 'cron', 'event', 'list' ],
			'search-replace dry run' => [ 'search-replace', 'old', 'new', '--dry-run' ],
			'post create'            => [ 'post', 'create', '--post_title=Home' ],
			'option get'             => [ 'option', 'get', 'home' ],
			'ordinary option update' => [ 'option', 'update', 'blogdescription', 'Tagline' ],
			'alias, read command'    => [ '@staging', 'post', 'list' ],
			'global flags, read'     => [ '--path=/srv/site', '--url=https://example.test', 'post', 'list' ],
			'core version'           => [ 'core', 'version' ],
			'cache flush'            => [ 'cache', 'flush' ],
		];
		$out = [];
		foreach ( $rows as $label => $argv ) {
			$out[ $label ] = [ $argv ];
		}
		return $out;
	}

	/**
	 * @dataProvider allowed
	 * @param list<string> $argv
	 */
	public function test_deny_list_keeps_allowing( array $argv ): void {
		self::assertNull( WpCliCommandPolicy::deny_reason( $argv ), implode( ' ', $argv ) );
	}

	/**
	 * @return array<string, array{0: list<string>}>
	 */
	public static function batch_allowed(): array {
		$rows = [
			'post list'         => [ 'post', 'list', '--format=json' ],
			'post get'          => [ 'post', 'get', '42', '--format=json' ],
			'post meta list'    => [ 'post', 'meta', 'list', '42' ],
			'term list'         => [ 'term', 'list', 'category' ],
			'option get'        => [ 'option', 'get', 'home' ],
			'plugin list'       => [ 'plugin', 'list' ],
			'theme list'        => [ 'theme', 'list' ],
			'user list'         => [ 'user', 'list' ],
			'core version'      => [ 'core', 'version' ],
			'db check'          => [ 'db', 'check' ],
			'cron event list'   => [ 'cron', 'event', 'list' ],
			'cache flush'       => [ 'cache', 'flush' ],
			'rewrite flush'     => [ 'rewrite', 'flush' ],
			'elementor flush'   => [ 'elementor', 'flush-css' ],
			'search-replace'    => [ 'search-replace', 'old', 'new', '--dry-run' ],
			'case and padding'  => [ ' Post ', 'LIST' ],
		];
		$out = [];
		foreach ( $rows as $label => $argv ) {
			$out[ $label ] = [ $argv ];
		}
		return $out;
	}

	/**
	 * @dataProvider batch_allowed
	 * @param list<string> $argv
	 */
	public function test_batch_allows_read_oriented_commands( array $argv ): void {
		self::assertNull( WpCliCommandPolicy::batch_reason( $argv ), implode( ' ', $argv ) );
	}

	/**
	 * @return array<string, array{0: list<string>}>
	 */
	public static function batch_refused(): array {
		$rows = [
			'post create'         => [ 'post', 'create', '--post_title=Home' ],
			'post update'         => [ 'post', 'update', '42', '--post_status=publish' ],
			'post delete'         => [ 'post', 'delete', '42', '--force' ],
			'post meta update'    => [ 'post', 'meta', 'update', '42', 'k', 'v' ],
			'term create'         => [ 'term', 'create', 'category', 'Name' ],
			'option update'       => [ 'option', 'update', 'blogdescription', 'x' ],
			'plugin install'      => [ 'plugin', 'install', 'x' ],
			'db query'            => [ 'db', 'query', 'SELECT 1' ],
			'user create'         => [ 'user', 'create', 'x', 'x@example.test' ],
			'user meta update'    => [ 'user', 'meta', 'update', '2', 'wp_capabilities', '{}' ],
			'search-replace'      => [ 'search-replace', 'old', 'new' ],
			'eval'                => [ 'eval', 'echo 1;' ],
			'unknown group'       => [ 'some-plugin-command', 'run' ],
			'leading alias'       => [ '@prod', 'post', 'list' ],
			'leading global flag' => [ '--path=/srv/site', 'post', 'list' ],
			'flag value first'    => [ '--user', '1', 'post', 'create' ],
			'quoted write'        => [ '"post"', '"create"' ],
			'one-token write'     => [ 'post create --post_title=x' ],
			'flags between words' => [ 'post', '--format=json', 'create' ],
		];
		$out = [];
		foreach ( $rows as $label => $argv ) {
			$out[ $label ] = [ $argv ];
		}
		return $out;
	}

	/**
	 * @dataProvider batch_refused
	 * @param list<string> $argv
	 */
	public function test_batch_refuses_everything_else( array $argv ): void {
		self::assertNotNull( WpCliCommandPolicy::batch_reason( $argv ), implode( ' ', $argv ) );
	}

	/**
	 * @return array<string, array{0: list<string>, 1: bool}>
	 */
	public static function file_writers(): array {
		return [
			'plugin install'        => [ [ 'plugin', 'install', 'x' ], true ],
			'plugin update'         => [ [ 'plugin', 'update', '--all' ], true ],
			'theme delete'          => [ [ 'theme', 'delete', 'x' ], true ],
			'core download'         => [ [ 'core', 'download' ], true ],
			'config set'            => [ [ 'config', 'set', 'WP_DEBUG', 'true' ], true ],
			'language install'      => [ [ 'language', 'core', 'install', 'ro_RO' ], true ],
			'i18n make-pot'         => [ [ 'i18n', 'make-pot', '.' ], true ],
			'export'                => [ [ 'export', '--dir=/tmp' ], true ],
			'rewrite flush hard'    => [ [ 'rewrite', 'flush', '--hard' ], true ],
			'maintenance-mode'      => [ [ 'maintenance-mode', 'activate' ], true ],
			'aliased writer'        => [ [ '@prod', '--path=/srv', '"Plugin"', 'INSTALL', 'x' ], true ],
			'plugin list'           => [ [ 'plugin', 'list' ], false ],
			'plugin deactivate'     => [ [ 'plugin', 'deactivate', 'x' ], false ],
			'post create'           => [ [ 'post', 'create', '--post_title=x' ], false ],
			'rewrite flush'         => [ [ 'rewrite', 'flush' ], false ],
			'cache flush'           => [ [ 'cache', 'flush' ], false ],
		];
	}

	/**
	 * @dataProvider file_writers
	 * @param list<string> $argv
	 */
	public function test_writes_files_detects_file_changing_commands( array $argv, bool $expected ): void {
		self::assertSame( $expected, WpCliCommandPolicy::writes_files( $argv ), implode( ' ', $argv ) );
	}
}
