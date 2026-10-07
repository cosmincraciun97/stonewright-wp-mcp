<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\Tests\Unit\RescueRuntime\Support;

/**
 * Test environment for the rescue MU-plugin: loads the real file once, and gives every test
 * a clean request, a clean options store, a fake wpdb, a captured header/cookie channel and
 * a journal directory under the test content directory.
 */
final class MuRuntime {

	public const PLUGIN  = 'stonewright/stonewright.php';
	public const JOURNAL = 'journal-0123456789abcdef0123456789abcdef.json';

	/** @var object{headers:list<array{0:string,1:int}>,cookies:list<array{name:string,value:string,options:array<string,mixed>}>,exits:int,headers_sent:bool,ssl:bool}|null */
	public static ?object $capture = null;

	/** @var array<string, mixed> */
	private static array $saved = [];

	public static function path(): string {
		return dirname( __DIR__, 4 ) . '/mu/stonewright-rescue.php';
	}

	public static function load(): void {
		require_once __DIR__ . '/wp-stubs.php';
		if ( ! class_exists( \Stonewright_Rescue::class, false ) ) {
			require_once self::path();
		}
	}

	/** Starts a test: clean globals, a fake wpdb, the capture channel, an active Stonewright plugin. */
	public static function begin( bool $active = true ): void {
		self::load();
		self::$saved = [
			'options'  => $GLOBALS['stonewright_test_options'] ?? [],
			'filters'  => $GLOBALS['stonewright_test_filters'] ?? [],
			'actions'  => $GLOBALS['stonewright_test_actions'] ?? [],
			'caps'     => $GLOBALS['stonewright_test_user_caps_by_id'] ?? [],
			'missing'  => $GLOBALS['stonewright_test_missing_user_ids'] ?? [],
			'uid'      => $GLOBALS['stonewright_test_current_user_id'] ?? 0,
			'wpdb'     => $GLOBALS['wpdb'] ?? null,
			'io'       => $GLOBALS['stonewright_rescue_io'] ?? null,
			'get'      => $_GET,
			'post'     => $_POST,
			'cookie'   => $_COOKIE,
			'server'   => $_SERVER,
			'site_opt' => $GLOBALS['stonewright_test_site_options'] ?? [],
		];
		$GLOBALS['stonewright_test_options']         = [
			'home'    => 'https://example.test',
			'siteurl' => 'https://example.test',
		];
		$GLOBALS['stonewright_test_filters']         = [];
		$GLOBALS['stonewright_test_actions']         = [];
		$GLOBALS['stonewright_test_user_caps_by_id'] = [];
		$GLOBALS['stonewright_test_missing_user_ids'] = [];
		$GLOBALS['stonewright_test_current_user_id'] = 0;
		$GLOBALS['stonewright_test_site_options']    = [];
		$GLOBALS['wpdb']                             = new MuFakeWpdb();

		$_GET    = [];
		$_POST   = [];
		$_COOKIE = [];
		foreach ( [ 'HTTP_AUTHORIZATION', 'REDIRECT_HTTP_AUTHORIZATION', 'PHP_AUTH_USER', 'REQUEST_URI', 'SCRIPT_FILENAME', 'SCRIPT_NAME' ] as $key ) {
			unset( $_SERVER[ $key ] );
		}
		$_SERVER['REQUEST_METHOD'] = 'GET';

		$capture               = new \stdClass();
		$capture->headers      = [];
		$capture->cookies      = [];
		$capture->exits        = 0;
		$capture->headers_sent = false;
		$capture->ssl          = true;
		self::$capture         = $capture;
		$GLOBALS['stonewright_rescue_io'] = [
			'ssl'          => static fn(): bool => $capture->ssl,
			'header'       => static function ( string $line, int $status = 0 ) use ( $capture ): void {
				$capture->headers[] = [ $line, $status ];
			},
			'cookie'       => static function ( string $name, string $value, array $options ) use ( $capture ): bool {
				$capture->cookies[] = [ 'name' => $name, 'value' => $value, 'options' => $options ];
				return true;
			},
			'headers_sent' => static fn(): bool => $capture->headers_sent,
			'exit'         => static function () use ( $capture ): void {
				++$capture->exits;
				throw new MuStop( 'exit' );
			},
		];

		\Stonewright_Rescue::reset();
		self::remove_tree( self::state_dir() );
		self::remove_tree( self::themes_dir() );
		self::remove_tree( dirname( self::plugin_file() ) );
		if ( $active ) {
			self::activate();
		}
	}

	public static function end(): void {
		\Stonewright_Rescue::reset();
		$GLOBALS['stonewright_test_options']          = self::$saved['options'];
		$GLOBALS['stonewright_test_filters']          = self::$saved['filters'];
		$GLOBALS['stonewright_test_actions']          = self::$saved['actions'];
		$GLOBALS['stonewright_test_user_caps_by_id']  = self::$saved['caps'];
		$GLOBALS['stonewright_test_missing_user_ids'] = self::$saved['missing'];
		$GLOBALS['stonewright_test_current_user_id']  = self::$saved['uid'];
		$GLOBALS['stonewright_test_site_options']     = self::$saved['site_opt'];
		$GLOBALS['wpdb']                              = self::$saved['wpdb'];
		if ( null === self::$saved['io'] ) {
			unset( $GLOBALS['stonewright_rescue_io'] );
		} else {
			$GLOBALS['stonewright_rescue_io'] = self::$saved['io'];
		}
		$_GET    = self::$saved['get'];
		$_POST   = self::$saved['post'];
		$_COOKIE = self::$saved['cookie'];
		$_SERVER = self::$saved['server'];
		self::remove_tree( self::state_dir() );
		self::remove_tree( self::themes_dir() );
		self::remove_tree( dirname( self::plugin_file() ) );
		self::$capture = null;
	}

	/** Makes Stonewright an active plugin that exists on disk, and records its basename for the runtime. */
	public static function activate(): void {
		$GLOBALS['stonewright_test_options']['stonewright_rescue_plugin'] = self::PLUGIN;
		$GLOBALS['stonewright_test_options']['active_plugins']            = [ 'akismet/akismet.php', self::PLUGIN, 'shop/shop.php' ];
		if ( ! is_dir( dirname( self::plugin_file() ) ) ) {
			mkdir( dirname( self::plugin_file() ), 0777, true );
		}
		file_put_contents( self::plugin_file(), "<?php\n" );
	}

	/** Boots the runtime the way WordPress does when it loads the MU-plugin. */
	public static function boot(): void {
		\Stonewright_Rescue::reset();
		\Stonewright_Rescue::boot();
	}

	/**
	 * Boots the runtime and runs the safe boot step of the request (the tests run on the CLI,
	 * where boot() leaves it out). Returns whether the runtime ended the request.
	 */
	public static function start(): bool {
		self::boot();
		try {
			\Stonewright_Rescue::start_safe_boot();
		} catch ( MuStop ) {
			return true;
		}
		return false;
	}

	/** Issues a key for an administrator and redeems it; returns the session token the browser received. */
	public static function open_session( int $user_id = 7 ): string {
		self::admin( $user_id );
		self::boot();
		$issued = \Stonewright_Rescue::issue_key( $user_id );
		self::request( 'wp-login.php', [ 'stonewright_rescue' => $issued['key'] ] );
		self::start();
		$cookie = end( self::$capture->cookies );
		self::$capture->cookies = [];
		self::$capture->headers = [];
		self::$capture->exits   = 0;
		return $cookie['value'];
	}

	public static function admin( int $id ): void {
		$GLOBALS['stonewright_test_user_caps_by_id'][ $id ] = [ 'manage_options' => true ];
	}

	public static function subscriber( int $id ): void {
		$GLOBALS['stonewright_test_user_caps_by_id'][ $id ] = [ 'read' => true ];
	}

	public static function option( string $name ): mixed {
		return $GLOBALS['stonewright_test_options'][ $name ] ?? null;
	}

	public static function set_option( string $name, mixed $value ): void {
		$GLOBALS['stonewright_test_options'][ $name ] = $value;
	}

	/** @return list<string> Names of the stored options that start with $prefix. */
	public static function option_names( string $prefix ): array {
		return array_values(
			array_filter(
				array_keys( $GLOBALS['stonewright_test_options'] ),
				static fn ( $name ): bool => str_starts_with( (string) $name, $prefix )
			)
		);
	}

	/** Runs a request that goes to wp-login.php, wp-admin or the front end. */
	public static function request( string $script, array $get = [], array $cookie = [], string $method = 'GET' ): void {
		$_SERVER['REQUEST_METHOD']  = $method;
		$_SERVER['SCRIPT_FILENAME'] = ABSPATH . $script;
		$_GET                       = $get;
		$_COOKIE                    = $cookie;
		$_SERVER['REQUEST_URI']     = '/' . $script . ( [] === $get ? '' : '?' . http_build_query( $get ) );
	}

	/** Applies a filter through the test harness (one callback per hook). */
	public static function filter( string $hook, mixed $value, mixed ...$args ): mixed {
		return apply_filters( $hook, $value, ...$args );
	}

	public static function has_filter( string $hook ): bool {
		return false !== has_filter( $hook );
	}

	public static function has_action( string $hook ): bool {
		return ! empty( $GLOBALS['stonewright_test_actions'][ $hook ] );
	}

	public static function fire( string $hook, mixed ...$args ): void {
		do_action( $hook, ...$args );
	}

	public static function plugin_file(): string {
		return WP_CONTENT_DIR . '/plugins/' . self::PLUGIN;
	}

	public static function themes_dir(): string {
		return WP_CONTENT_DIR . '/themes';
	}

	/** Installs a theme directory the runtime accepts as a default theme. */
	public static function install_theme( string $slug, bool $block = false ): void {
		$dir = self::themes_dir() . '/' . $slug;
		if ( ! is_dir( $dir . '/templates' ) ) {
			mkdir( $dir . '/templates', 0777, true );
		}
		file_put_contents( $dir . '/style.css', "/* Theme Name: x */\n" );
		file_put_contents( $block ? $dir . '/templates/index.html' : $dir . '/index.php', $block ? '<!-- wp:post-content /-->' : "<?php\n" );
	}

	public static function state_dir(): string {
		return WP_CONTENT_DIR . '/uploads/stonewright-state';
	}

	public static function journal_path(): string {
		return self::state_dir() . '/' . self::JOURNAL;
	}

	/** An absolute path inside ABSPATH for a path relative to it. */
	public static function absolute( string $relative ): string {
		return ABSPATH . $relative;
	}

	/**
	 * An error_get_last() array.
	 *
	 * @return array{type:int,message:string,file:string,line:int}
	 */
	public static function fatal( string $relative = 'wp-content/themes/site-a/functions.php', int $type = E_ERROR, int $line = 12, string $message = 'Uncaught Error: Call to undefined function site_a_boot()' ): array {
		return [
			'type'    => $type,
			'message' => $message,
			'file'    => self::absolute( $relative ),
			'line'    => $line,
		];
	}

	/**
	 * A journal entry of the shared contract shape.
	 *
	 * @param array<string, mixed> $override
	 * @return array<string, mixed>
	 */
	public static function entry( string $id, array $override = [] ): array {
		return array_merge(
			[
				'id'             => $id,
				'ability'        => 'stonewright/theme-file-patch',
				'resource_type'  => 'theme_file',
				'resource_key'   => 'functions.php',
				'recipe'         => [ 'type' => 'theme_backup', 'ref' => 'sw-theme-backup-1234' ],
				'paths'          => [ 'wp-content/themes/site-a/functions.php' ],
				'armed_at'       => time() - 30,
				'probe_deadline' => time() + 60,
				'state'          => 'armed',
				'incident'       => null,
			],
			$override
		);
	}

	/**
	 * Writes the journal file and records its name in the option the runtime reads.
	 *
	 * @param list<array<string, mixed>> $entries
	 */
	public static function write_journal( array $entries, string $updated = '' ): string {
		if ( ! is_dir( self::state_dir() ) ) {
			mkdir( self::state_dir(), 0777, true );
		}
		file_put_contents(
			self::journal_path(),
			'' !== $updated ? $updated : json_encode( [ 'version' => 1, 'updated_at' => 1000, 'entries' => $entries ], JSON_UNESCAPED_SLASHES )
		);
		self::set_option( 'stonewright_rescue_journal_file', self::JOURNAL );
		return self::journal_path();
	}

	/** @return array<string, mixed>|null */
	public static function read_journal(): ?array {
		$raw = is_file( self::journal_path() ) ? file_get_contents( self::journal_path() ) : false;
		$doc = is_string( $raw ) ? json_decode( $raw, true ) : null;
		return is_array( $doc ) ? $doc : null;
	}

	/** @return array<string, mixed>|null The journal entry with this id. */
	public static function journal_entry( string $id ): ?array {
		foreach ( self::read_journal()['entries'] ?? [] as $entry ) {
			if ( ( $entry['id'] ?? '' ) === $id ) {
				return $entry;
			}
		}
		return null;
	}

	public static function remove_tree( string $dir ): void {
		if ( ! is_dir( $dir ) ) {
			return;
		}
		$items = new \RecursiveIteratorIterator(
			new \RecursiveDirectoryIterator( $dir, \FilesystemIterator::SKIP_DOTS ),
			\RecursiveIteratorIterator::CHILD_FIRST
		);
		foreach ( $items as $item ) {
			$item->isDir() ? @rmdir( $item->getPathname() ) : @unlink( $item->getPathname() );
		}
		@rmdir( $dir );
	}
}
