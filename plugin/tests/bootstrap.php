<?php
declare( strict_types=1 );

/**
 * PHPUnit bootstrap — no WordPress core required.
 * Registers autoloaders, stubs WP globals, and defines constants.
 */

define( 'STONEWRIGHT_DIR', dirname( __DIR__ ) . '/' );

if ( ! defined( 'WP_CONTENT_DIR' ) ) {
	$test_content_dir = sys_get_temp_dir() . '/stonewright-test-' . getmypid() . '/wp-content';
	if ( ! is_dir( $test_content_dir ) ) {
		mkdir( $test_content_dir, 0700, true );
	}
	define( 'WP_CONTENT_DIR', $test_content_dir );
}

if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', WP_CONTENT_DIR . '/' );
}

$stonewright_test_admin_include_dir = ABSPATH . 'wp-admin/includes';
if ( ! is_dir( $stonewright_test_admin_include_dir ) ) {
	mkdir( $stonewright_test_admin_include_dir, 0700, true );
}
foreach ( [ 'file.php', 'media.php', 'image.php', 'plugin.php', 'upgrade.php', 'class-wp-site-health.php' ] as $stonewright_test_include_file ) {
	$stonewright_test_include_path = $stonewright_test_admin_include_dir . '/' . $stonewright_test_include_file;
	if ( ! file_exists( $stonewright_test_include_path ) ) {
		file_put_contents( $stonewright_test_include_path, "<?php\n" );
	}
}

if ( ! defined( 'STONEWRIGHT_VERSION' ) ) {
	define( 'STONEWRIGHT_VERSION', '0.0.0-test' );
}

if ( ! defined( 'STONEWRIGHT_MIN_WP' ) ) {
	define( 'STONEWRIGHT_MIN_WP', '6.7' );
}

if ( ! defined( 'ARRAY_A' ) ) {
	define( 'ARRAY_A', 'ARRAY_A' );
}

if ( ! defined( 'MINUTE_IN_SECONDS' ) ) {
	define( 'MINUTE_IN_SECONDS', 60 );
}

// ---------------------------------------------------------------------------
// Autoloader — prefer Composer's; fall back to a hand-rolled PSR-4 mapper.
// ---------------------------------------------------------------------------
$composer_autoload = dirname( __DIR__ ) . '/vendor/autoload.php';
if ( file_exists( $composer_autoload ) ) {
	require_once $composer_autoload;
} else {
	spl_autoload_register( function ( string $class ): void {
		$map = [
			'Stonewright\\WpMcp\\Tests\\' => __DIR__ . '/',
			'Stonewright\\WpMcp\\'        => dirname( __DIR__ ) . '/includes/',
		];
		foreach ( $map as $prefix => $base ) {
			if ( 0 !== strpos( $class, $prefix ) ) {
				continue;
			}
			$relative = substr( $class, strlen( $prefix ) );
			$file     = $base . str_replace( '\\', '/', $relative ) . '.php';
			if ( file_exists( $file ) ) {
				require_once $file;
			}
			return;
		}
	} );
}

require_once __DIR__ . '/ElementorStubs.php';

// ---------------------------------------------------------------------------
// WordPress function stubs — only what the code under test actually calls.
// ---------------------------------------------------------------------------
if ( ! function_exists( 'wp_json_encode' ) ) {
	function wp_json_encode( mixed $value, int $flags = 0, int $depth = 512 ): string|false {
		if ( isset( $GLOBALS['stonewright_test_wp_json_encode_return'] ) ) {
			$result = $GLOBALS['stonewright_test_wp_json_encode_return'];
			unset( $GLOBALS['stonewright_test_wp_json_encode_return'] );
			if ( $result === false ) {
				// Poison json_last_error so callers reading json_last_error_msg()
				// observe a meaningful error message instead of the default
				// "No error" sentinel. Encoding a malformed UTF-8 sequence is
				// the cheapest reliable way to produce JSON_ERROR_UTF8.
				@json_encode( "\xB1\x31" );
			}
			return $result;
		}
		return json_encode( $value, $flags, $depth );
	}
}

if ( ! function_exists( '__' ) ) {
	function __( string $text, string $domain = 'default' ): string {
		return $text;
	}
}

$GLOBALS['stonewright_test_user_caps']     ??= [];
$GLOBALS['stonewright_test_user_logged_in'] ??= false;

if ( ! function_exists( 'is_user_logged_in' ) ) {
	function is_user_logged_in(): bool {
		return (bool) ( $GLOBALS['stonewright_test_user_logged_in'] ?? false );
	}
}

if ( ! function_exists( 'current_user_can' ) ) {
	function current_user_can( string $cap, mixed ...$args ): bool {
		// Allow tests to inject a callable that receives ($cap, ...$args) for
		// fine-grained checks (e.g. edit_post_meta with post_id + meta_key).
		if ( isset( $GLOBALS['stonewright_test_user_can_callback'] ) && is_callable( $GLOBALS['stonewright_test_user_can_callback'] ) ) {
			return (bool) ( $GLOBALS['stonewright_test_user_can_callback'] )( $cap, ...$args );
		}
		$caps = $GLOBALS['stonewright_test_user_caps'] ?? [];
		return ! empty( $caps[ $cap ] );
	}
}

$GLOBALS['stonewright_test_current_user_id'] ??= 0;

if ( ! function_exists( 'get_current_user_id' ) ) {
	function get_current_user_id(): int {
		return (int) ( $GLOBALS['stonewright_test_current_user_id'] ?? 0 );
	}
}

if ( ! function_exists( 'wp_get_current_user' ) ) {
	function wp_get_current_user(): object {
		return (object) [
			'ID'         => (int) ( $GLOBALS['stonewright_test_current_user_id'] ?? 0 ),
			'user_login' => (string) ( $GLOBALS['stonewright_test_current_user_login'] ?? 'admin' ),
		];
	}
}

if ( ! function_exists( 'wp_set_current_user' ) ) {
	function wp_set_current_user( int $id, string $name = '' ): object {
		$GLOBALS['stonewright_test_set_current_user'] = $id;
		$GLOBALS['stonewright_test_current_user_id']  = $id;
		return (object) [ 'ID' => $id ];
	}
}

if ( ! function_exists( 'wp_set_auth_cookie' ) ) {
	function wp_set_auth_cookie( int $user_id, bool $remember = false, mixed $secure = '', string $token = '' ): void {
		$GLOBALS['stonewright_test_auth_cookie'] = [
			'user_id'  => $user_id,
			'remember' => $remember,
			'secure'   => $secure,
			'token'    => $token,
		];
	}
}

$GLOBALS['stonewright_test_transients'] ??= [];

if ( ! function_exists( 'set_transient' ) ) {
	function set_transient( string $transient, mixed $value, int $expiration = 0 ): bool {
		$GLOBALS['stonewright_test_transients'][ $transient ] = $value;
		return true;
	}
}

if ( ! function_exists( 'get_transient' ) ) {
	function get_transient( string $transient ): mixed {
		return $GLOBALS['stonewright_test_transients'][ $transient ] ?? false;
	}
}

if ( ! function_exists( 'delete_transient' ) ) {
	function delete_transient( string $transient ): bool {
		unset( $GLOBALS['stonewright_test_transients'][ $transient ] );
		return true;
	}
}

$GLOBALS['stonewright_test_options'] ??= [];

if ( ! function_exists( 'get_option' ) ) {
	function get_option( string $option, mixed $default = false ): mixed {
		if ( array_key_exists( $option, $GLOBALS['stonewright_test_options'] ?? [] ) ) {
			return $GLOBALS['stonewright_test_options'][ $option ];
		}
		return $default;
	}
}

if ( ! function_exists( 'update_option' ) ) {
	function update_option( string $option, mixed $value, bool|string $autoload = true ): bool {
		$GLOBALS['stonewright_test_options'][ $option ] = $value;
		return true;
	}
}

if ( ! function_exists( 'delete_option' ) ) {
	function delete_option( string $option ): bool {
		if ( ! array_key_exists( $option, $GLOBALS['stonewright_test_options'] ?? [] ) ) {
			return false;
		}
		unset( $GLOBALS['stonewright_test_options'][ $option ] );
		return true;
	}
}

if ( ! function_exists( 'add_option' ) ) {
	function add_option( string $option, mixed $value = '', string $deprecated = '', bool $autoload = true ): bool {
		if ( array_key_exists( $option, $GLOBALS['stonewright_test_options'] ?? [] ) ) {
			return false;
		}
		$GLOBALS['stonewright_test_options'][ $option ] = $value;
		return true;
	}
}

if ( ! function_exists( 'wp_salt' ) ) {
	function wp_salt( string $scheme = 'auth' ): string {
		return 'test-salt-' . $scheme;
	}
}

if ( ! function_exists( 'wp_mkdir_p' ) ) {
	function wp_mkdir_p( string $target ): bool {
		if ( is_dir( $target ) ) {
			return true;
		}
		return mkdir( $target, 0755, true ) || is_dir( $target );
	}
}

if ( ! function_exists( 'wp_generate_uuid4' ) ) {
	function wp_generate_uuid4(): string {
		return sprintf(
			'%04x%04x-%04x-%04x-%04x-%04x%04x%04x',
			random_int( 0, 0xffff ),
			random_int( 0, 0xffff ),
			random_int( 0, 0xffff ),
			random_int( 0, 0x0fff ) | 0x4000,
			random_int( 0, 0x3fff ) | 0x8000,
			random_int( 0, 0xffff ),
			random_int( 0, 0xffff ),
			random_int( 0, 0xffff )
		);
	}
}

if ( ! function_exists( 'current_time' ) ) {
	function current_time( string $type, mixed $gmt = 0 ): string {
		return gmdate( 'Y-m-d H:i:s' );
	}
}

if ( ! function_exists( 'is_wp_error' ) ) {
	function is_wp_error( mixed $thing ): bool {
		return $thing instanceof \WP_Error;
	}
}

// ---------------------------------------------------------------------------
// Minimal $wpdb stub: collects inserts in $GLOBALS['stonewright_test_wpdb_inserts']
// without requiring a real database. Tests can inspect or reset that array.
// ---------------------------------------------------------------------------
$GLOBALS['stonewright_test_wpdb_inserts'] ??= [];

if ( ! isset( $GLOBALS['wpdb'] ) ) {
	$GLOBALS['wpdb'] = new class() {
		public string $prefix = 'wptests_';
		public int $insert_id = 1;

		/**
		 * @param array<string, mixed> $data
		 * @param array<int, string>   $format
		 */
		public function insert( string $table, array $data, array $format = [] ): int {
			$this->insert_id++;
			$GLOBALS['stonewright_test_wpdb_inserts'][] = [
				'table' => $table,
				'data'  => $data,
			];
			return 1;
		}

		public function get_charset_collate(): string {
			return '';
		}

		/** @return array<int, array<string, mixed>> */
		public function get_results( string $query, string $output = 'OBJECT' ): array {
			return [];
		}

		public function get_row( string $query, string $output = 'OBJECT' ): array|object|null {
			return null;
		}

		public function get_var( string $query ): mixed {
			return null;
		}

		/**
		 * @param array<string, mixed> $data
		 * @param array<string, mixed> $where
		 */
		public function update( string $table, array $data, array $where, array $format = [], array $where_format = [] ): int|false {
			return 1;
		}

		/**
		 * @param array<string, mixed> $where
		 */
		public function delete( string $table, array $where, array $where_format = [] ): int|false {
			return 1;
		}

		public function prepare( string $query, mixed ...$args ): string {
			return $query;
		}
	};
}

if ( ! function_exists( 'rest_url' ) ) {
	function rest_url( string $path = '' ): string {
		return 'https://example.test/wp-json/' . ltrim( $path, '/' );
	}
}

if ( ! function_exists( 'home_url' ) ) {
	function home_url( string $path = '' ): string {
		$base = $GLOBALS['stonewright_test_home_url'] ?? 'https://example.test/';
		return rtrim( $base, '/' ) . '/' . ltrim( $path, '/' );
	}
}

if ( ! function_exists( 'admin_url' ) ) {
	function admin_url( string $path = '' ): string {
		return 'https://example.test/wp-admin/' . ltrim( $path, '/' );
	}
}

if ( ! function_exists( 'get_bloginfo' ) ) {
	function get_bloginfo( string $show = '', string $filter = 'raw' ): string {
		return match ( $show ) {
			'name' => 'Stonewright Test',
			'description' => 'Test site',
			'version' => '6.9',
			default => '',
		};
	}
}

if ( ! function_exists( 'determine_locale' ) ) {
	function determine_locale(): string {
		return 'en_US';
	}
}

if ( ! function_exists( 'wp_timezone_string' ) ) {
	function wp_timezone_string(): string {
		return 'UTC';
	}
}

if ( ! function_exists( 'is_multisite' ) ) {
	function is_multisite(): bool {
		return false;
	}
}

if ( ! function_exists( 'is_ssl' ) ) {
	function is_ssl(): bool {
		return true;
	}
}

if ( ! function_exists( 'wp_get_theme' ) ) {
	function wp_get_theme(): object {
		return new class() {
			public function get( string $field ): string {
				return 'Version' === $field ? '1.0.0' : 'Stonewright Theme';
			}

			public function get_template(): string {
				return 'stonewright-theme';
			}

			public function get_stylesheet(): string {
				return 'stonewright-theme';
			}

			public function parent(): false {
				return false;
			}
		};
	}
}

if ( ! function_exists( 'get_stylesheet' ) ) {
	function get_stylesheet(): string {
		return 'stonewright-theme';
	}
}

if ( ! function_exists( 'wp_get_environment_type' ) ) {
	function wp_get_environment_type(): string {
		return 'local';
	}
}

if ( ! function_exists( 'current_theme_supports' ) ) {
	function current_theme_supports( string $feature, mixed ...$args ): bool {
		return false;
	}
}

if ( ! function_exists( 'wp_is_block_theme' ) ) {
	function wp_is_block_theme(): bool {
		return true;
	}
}

if ( ! function_exists( 'get_plugins' ) ) {
	function get_plugins( string $plugin_folder = '' ): array {
		return [
			'stonewright/stonewright.php' => [
				'Name'    => 'Stonewright',
				'Version' => '0.0.0-test',
				'Author'  => 'Stonewright',
			],
		];
	}
}

if ( ! function_exists( 'is_plugin_active' ) ) {
	function is_plugin_active( string $plugin ): bool {
		return 'stonewright/stonewright.php' === $plugin;
	}
}

if ( ! function_exists( 'wp_max_upload_size' ) ) {
	function wp_max_upload_size(): int {
		return 10485760;
	}
}

if ( ! function_exists( 'rest_get_url_prefix' ) ) {
	function rest_get_url_prefix(): string {
		return 'wp-json';
	}
}

if ( ! function_exists( 'esc_html' ) ) {
	function esc_html( string $text ): string {
		return htmlspecialchars( $text, ENT_QUOTES, 'UTF-8' );
	}
}

if ( ! function_exists( 'esc_attr' ) ) {
	function esc_attr( string $text ): string {
		return htmlspecialchars( $text, ENT_QUOTES, 'UTF-8' );
	}
}

if ( ! function_exists( 'esc_textarea' ) ) {
	function esc_textarea( string $text ): string {
		return htmlspecialchars( $text, ENT_QUOTES, 'UTF-8' );
	}
}

if ( ! function_exists( 'esc_url' ) ) {
	function esc_url( string $url ): string {
		return filter_var( $url, FILTER_SANITIZE_URL ) ?: '';
	}
}

if ( ! function_exists( 'esc_url_raw' ) ) {
	function esc_url_raw( string $url, array $protocols = [] ): string {
		return filter_var( $url, FILTER_SANITIZE_URL ) ?: '';
	}
}

if ( ! function_exists( 'apply_filters' ) ) {
	function apply_filters( string $hook_name, mixed $value, mixed ...$args ): mixed {
		// Allow per-hook overrides for tests: $GLOBALS['stonewright_test_filters'][$hook_name] = callable.
		$filter_override = $GLOBALS['stonewright_test_filters'][ $hook_name ] ?? null;
		if ( null !== $filter_override && is_callable( $filter_override ) ) {
			return $filter_override( $value, ...$args );
		}
		return $value;
	}
}

if ( ! function_exists( 'add_filter' ) ) {
	function add_filter( string $hook_name, callable $callback, int $priority = 10, int $accepted_args = 1 ): bool {
		$GLOBALS['stonewright_test_filters'][ $hook_name ] = $callback;
		return true;
	}
}

if ( ! function_exists( 'remove_filter' ) ) {
	function remove_filter( string $hook_name, callable $callback, int $priority = 10 ): bool {
		unset( $GLOBALS['stonewright_test_filters'][ $hook_name ] );
		return true;
	}
}

$GLOBALS['stonewright_test_filters'] ??= [];

if ( ! function_exists( 'sanitize_text_field' ) ) {
	function sanitize_text_field( string $text ): string {
		return trim( strip_tags( $text ) );
	}
}

if ( ! function_exists( 'sanitize_html_class' ) ) {
	function sanitize_html_class( string $classname, string $fallback = '' ): string {
		$sanitized = preg_replace( '/[^A-Za-z0-9_-]/', '-', $classname ) ?? '';
		$sanitized = trim( $sanitized, '-' );
		return '' !== $sanitized ? $sanitized : $fallback;
	}
}

if ( ! function_exists( 'sanitize_file_name' ) ) {
	function sanitize_file_name( string $filename ): string {
		return preg_replace( '/[^A-Za-z0-9._-]/', '-', $filename ) ?? '';
	}
}

if ( ! function_exists( 'sanitize_textarea_field' ) ) {
	function sanitize_textarea_field( string $text ): string {
		return trim( strip_tags( $text ) );
	}
}

// ---------------------------------------------------------------------------
// WP_Error stub.
// ---------------------------------------------------------------------------
if ( ! class_exists( 'WP_Error' ) ) {
	class WP_Error {
		/** @var array<int, array{code: string|int, message: string, data: mixed}> */
		private array $errors = [];

		public function __construct(
			string|int $code = '',
			string $message = '',
			mixed $data = ''
		) {
			if ( '' !== $code ) {
				$this->errors[] = [ 'code' => $code, 'message' => $message, 'data' => $data ];
			}
		}

		public function get_error_code(): string|int {
			return $this->errors[0]['code'] ?? '';
		}

		public function get_error_message( string|int $code = '' ): string {
			foreach ( $this->errors as $e ) {
				if ( '' === $code || $e['code'] === $code ) {
					return $e['message'];
				}
			}
			return '';
		}

		public function get_error_data( string|int $code = '' ): mixed {
			foreach ( $this->errors as $e ) {
				if ( '' === $code || $e['code'] === $code ) {
					return $e['data'];
				}
			}
			return null;
		}

		/** @return array<int, array{code: string|int, message: string, data: mixed}> */
		public function get_all_error_data(): array {
			return $this->errors;
		}
	}
}

// ---------------------------------------------------------------------------
// Post type object stubs — backed by $GLOBALS['stonewright_test_post_types'].
// Tests set: $GLOBALS['stonewright_test_post_types']['page'] = (object)['cap' => (object)['create_posts' => 'edit_pages', 'publish_posts' => 'publish_pages']];
// ---------------------------------------------------------------------------
$GLOBALS['stonewright_test_post_types'] ??= [];

if ( ! function_exists( 'get_post_type_object' ) ) {
	function get_post_type_object( string $post_type ): ?object {
		return $GLOBALS['stonewright_test_post_types'][ $post_type ] ?? null;
	}
}

// ---------------------------------------------------------------------------
// Post stubs — backed by $GLOBALS['stonewright_test_posts'].
// Tests set: $GLOBALS['stonewright_test_posts'][1] = (object)['ID' => 1, 'post_type' => 'page', ...];
// ---------------------------------------------------------------------------
$GLOBALS['stonewright_test_posts'] ??= [];

if ( ! function_exists( 'get_post' ) ) {
	function get_post( int|null $post = null, string $output = 'OBJECT', string $filter = 'raw' ): object|null {
		if ( null === $post ) {
			return null;
		}
		return $GLOBALS['stonewright_test_posts'][ (int) $post ] ?? null;
	}
}

// ---------------------------------------------------------------------------
// Post meta stubs — backed by $GLOBALS['stonewright_test_post_meta_calls'].
// Records calls; tests inspect the array to assert which keys were written.
// ---------------------------------------------------------------------------
$GLOBALS['stonewright_test_post_meta_calls'] ??= [];

if ( ! function_exists( 'update_post_meta' ) ) {
	function update_post_meta( int $post_id, string $meta_key, mixed $meta_value, mixed $prev_value = '' ): int|bool {
		if ( isset( $GLOBALS['stonewright_test_update_post_meta_return'] ) ) {
			return $GLOBALS['stonewright_test_update_post_meta_return'];
		}
		$GLOBALS['stonewright_test_post_meta_calls'][] = [
			'action'   => 'update',
			'post_id'  => $post_id,
			'meta_key' => $meta_key,
			'value'    => $meta_value,
		];
		// Also persist into the fake post's meta array so get_post_meta() can
		// read it back. This mirrors how real WordPress behaves and lets
		// round-trip read-after-write code paths (ElementorData::write
		// post-verification, GetTemplate->get_post_meta, etc.) work under tests.
		if ( isset( $GLOBALS['stonewright_test_posts'][ $post_id ] ) ) {
			$post                              = $GLOBALS['stonewright_test_posts'][ $post_id ];
			$meta                              = (array) ( $post->meta ?? [] );
			$meta[ $meta_key ]                 = $meta_value;
			$post->meta                        = $meta;
			$GLOBALS['stonewright_test_posts'][ $post_id ] = $post;
		}
		return true;
	}
}

if ( ! function_exists( 'add_post_meta' ) ) {
	function add_post_meta( int $post_id, string $meta_key, mixed $meta_value, bool $unique = false ): int|bool {
		$GLOBALS['stonewright_test_post_meta_calls'][] = [
			'action'   => 'add',
			'post_id'  => $post_id,
			'meta_key' => $meta_key,
			'value'    => $meta_value,
		];
		return true;
	}
}

if ( ! function_exists( 'delete_post_meta' ) ) {
	function delete_post_meta( int $post_id, string $meta_key, mixed $meta_value = '' ): bool {
		$GLOBALS['stonewright_test_post_meta_calls'][] = [
			'action'   => 'delete',
			'post_id'  => $post_id,
			'meta_key' => $meta_key,
			'value'    => $meta_value,
		];
		if ( isset( $GLOBALS['stonewright_test_posts'][ $post_id ] ) ) {
			$post = $GLOBALS['stonewright_test_posts'][ $post_id ];
			$meta = (array) ( $post->meta ?? [] );
			unset( $meta[ $meta_key ] );
			$post->meta = $meta;
			$GLOBALS['stonewright_test_posts'][ $post_id ] = $post;
		}
		return true;
	}
}

if ( ! function_exists( 'get_post_meta' ) ) {
	/**
	 * @return array<string, array<int, mixed>>|mixed
	 */
	function get_post_meta( int $post_id, string $key = '', bool $single = false ): mixed {
		$post = $GLOBALS['stonewright_test_posts'][ $post_id ] ?? null;
		if ( null === $post ) {
			return $key !== '' ? ( $single ? '' : [] ) : [];
		}
		$all_meta = (array) ( $post->meta ?? [] );
		if ( '' === $key ) {
			return array_map( fn( $v ) => [ $v ], $all_meta );
		}
		$val = $all_meta[ $key ] ?? null;
		if ( null === $val ) {
			return $single ? '' : [];
		}
		return $single ? $val : [ $val ];
	}
}

// ---------------------------------------------------------------------------
// wp_insert_post / wp_update_post stubs.
// Tests may override $GLOBALS['stonewright_test_wp_insert_post_return'] or
// $GLOBALS['stonewright_test_wp_update_post_return'] to control the return.
// Default: auto-increment from $GLOBALS['stonewright_test_next_post_id'].
// ---------------------------------------------------------------------------
$GLOBALS['stonewright_test_next_post_id']          ??= 1001;
$GLOBALS['stonewright_test_inserted_posts']        ??= [];
$GLOBALS['stonewright_test_wp_insert_post_return'] ??= null;
$GLOBALS['stonewright_test_wp_update_post_return'] ??= null;

if ( ! function_exists( 'wp_insert_post' ) ) {
	/**
	 * @param array<string, mixed> $postarr
	 * @return int|\WP_Error
	 */
	function wp_insert_post( array $postarr, bool $wp_error = false ): int|\WP_Error {
		if ( null !== $GLOBALS['stonewright_test_wp_insert_post_return'] ) {
			$ret = $GLOBALS['stonewright_test_wp_insert_post_return'];
			$GLOBALS['stonewright_test_wp_insert_post_return'] = null;
			return $ret;
		}
		$id = (int) $GLOBALS['stonewright_test_next_post_id']++;
		$GLOBALS['stonewright_test_inserted_posts'][] = array_merge( [ 'ID' => $id ], $postarr );
		// Also register in test posts so get_post() can find it.
		$GLOBALS['stonewright_test_posts'][ $id ] = (object) array_merge(
			[
				'ID'           => $id,
				'post_type'    => $postarr['post_type'] ?? 'post',
				'post_status'  => $postarr['post_status'] ?? 'draft',
				'post_title'   => $postarr['post_title'] ?? '',
				'post_content' => $postarr['post_content'] ?? '',
				'post_excerpt' => $postarr['post_excerpt'] ?? '',
				'post_parent'  => $postarr['post_parent'] ?? 0,
				'post_name'    => $postarr['post_name'] ?? '',
			],
			$postarr
		);
		return $id;
	}
}

if ( ! function_exists( 'wp_update_post' ) ) {
	/**
	 * @param array<string, mixed> $postarr
	 * @return int|\WP_Error
	 */
	function wp_update_post( array $postarr, bool $wp_error = false ): int|\WP_Error {
		if ( null !== $GLOBALS['stonewright_test_wp_update_post_return'] ) {
			$ret = $GLOBALS['stonewright_test_wp_update_post_return'];
			$GLOBALS['stonewright_test_wp_update_post_return'] = null;
			return $ret;
		}
		$id = (int) ( $postarr['ID'] ?? 0 );
		if ( isset( $GLOBALS['stonewright_test_posts'][ $id ] ) ) {
			foreach ( $postarr as $k => $v ) {
				$GLOBALS['stonewright_test_posts'][ $id ]->{ $k } = $v;
			}
		}
		return $id;
	}
}

if ( ! function_exists( 'post_type_supports' ) ) {
	function post_type_supports( string $post_type, string $feature ): bool {
		return false;
	}
}

if ( ! function_exists( 'wp_delete_post' ) ) {
	/**
	 * @return \WP_Post|false|null
	 */
	function wp_delete_post( int $post_id, bool $force_delete = false ): mixed {
		$post = $GLOBALS['stonewright_test_posts'][ $post_id ] ?? null;
		if ( null === $post ) {
			return false;
		}
		$GLOBALS['stonewright_test_deleted_posts'][] = [
			'post_id' => $post_id,
			'force'   => $force_delete,
		];
		if ( $force_delete ) {
			unset( $GLOBALS['stonewright_test_posts'][ $post_id ] );
		} else {
			$GLOBALS['stonewright_test_posts'][ $post_id ]->post_status = 'trash';
		}
		return $post;
	}
}

if ( ! function_exists( 'get_the_title' ) ) {
	function get_the_title( int|object $post = 0 ): string {
		if ( is_object( $post ) ) {
			return (string) ( $post->post_title ?? '' );
		}
		$record = $GLOBALS['stonewright_test_posts'][ (int) $post ] ?? null;
		return null === $record ? '' : (string) ( $record->post_title ?? '' );
	}
}

if ( ! function_exists( 'wp_save_post_revision' ) ) {
	function wp_save_post_revision( int $post_id ): int|\WP_Error|null {
		return 9001;
	}
}

// ---------------------------------------------------------------------------
// Minimal sanitize_key / wp_kses_post / wp_set_post_categories / etc.
// ---------------------------------------------------------------------------
if ( ! function_exists( 'sanitize_key' ) ) {
	function sanitize_key( string $key ): string {
		return preg_replace( '/[^a-z0-9_\-]/', '', strtolower( $key ) ) ?? '';
	}
}

if ( ! function_exists( 'wp_kses_post' ) ) {
	function wp_kses_post( string $content ): string {
		return $content;
	}
}

if ( ! function_exists( 'wp_slash' ) ) {
	function wp_slash( mixed $value ): mixed {
		return is_string( $value ) ? addslashes( $value ) : $value;
	}
}

if ( ! function_exists( 'clean_post_cache' ) ) {
	function clean_post_cache( int|object $post ): void {
	}
}

if ( ! function_exists( 'did_action' ) ) {
	function did_action( string $hook_name ): int {
		if ( 'elementor/loaded' === $hook_name ) {
			return 1;
		}
		return 0;
	}
}

if ( ! function_exists( 'wp_set_post_categories' ) ) {
	function wp_set_post_categories( int $post_id, array $post_categories = [], bool $append = false ): array|bool {
		return $post_categories;
	}
}

if ( ! function_exists( 'wp_set_post_tags' ) ) {
	function wp_set_post_tags( int $post_id, array|string $tags = [], bool $append = false ): array|bool|null {
		return [];
	}
}

if ( ! function_exists( 'wp_set_object_terms' ) ) {
	function wp_set_object_terms( int $object_id, string|int|array $terms, string $taxonomy, bool $append = false ): array|\WP_Error {
		return (array) $terms;
	}
}

if ( ! function_exists( 'get_edit_post_link' ) ) {
	function get_edit_post_link( int $id = 0, string $context = 'display' ): ?string {
		return 'https://example.test/wp-admin/post.php?post=' . $id . '&action=edit';
	}
}

if ( ! function_exists( 'get_preview_post_link' ) ) {
	function get_preview_post_link( int|object|null $post = null, array $query_args = [], string $preview_link = '' ): string {
		$id = is_object( $post ) ? (int) $post->ID : (int) $post;
		return 'https://example.test/?p=' . $id . '&preview=true';
	}
}

if ( ! function_exists( 'get_post_status' ) ) {
	function get_post_status( int|object $post ): string|false {
		$id = is_object( $post ) ? (int) $post->ID : (int) $post;
		return $GLOBALS['stonewright_test_posts'][ $id ]->post_status ?? 'draft';
	}
}

if ( ! function_exists( 'get_post_type' ) ) {
	function get_post_type( int|object|null $post = null ): string|false {
		$id = is_object( $post ) ? (int) $post->ID : (int) $post;
		return $GLOBALS['stonewright_test_posts'][ $id ]->post_type ?? false;
	}
}

if ( ! function_exists( 'get_permalink' ) ) {
	function get_permalink( int|object $post = 0, bool $leavename = false ): string|false {
		$id = is_object( $post ) ? (int) $post->ID : (int) $post;
		return 'https://example.test/?p=' . $id;
	}
}

if ( ! function_exists( 'sanitize_title' ) ) {
	function sanitize_title( string $title, string $fallback_title = '', string $context = 'save' ): string {
		return sanitize_key( $title );
	}
}

if ( ! function_exists( 'maybe_unserialize' ) ) {
	function maybe_unserialize( mixed $data ): mixed {
		if ( is_string( $data ) && str_starts_with( $data, 'a:' ) ) {
			return @unserialize( $data );
		}
		return $data;
	}
}

if ( ! function_exists( 'wp_parse_url' ) ) {
	function wp_parse_url( string $url, int $component = -1 ): mixed {
		return parse_url( $url, $component );
	}
}

if ( ! function_exists( 'wp_upload_dir' ) ) {
	function wp_upload_dir(): array {
		$base = WP_CONTENT_DIR . '/uploads';
		wp_mkdir_p( $base );
		return [
			'basedir' => $base,
			'baseurl' => 'https://example.test/wp-content/uploads',
		];
	}
}

if ( ! function_exists( 'trailingslashit' ) ) {
	function trailingslashit( string $path ): string {
		return rtrim( $path, '/\\' ) . '/';
	}
}

if ( ! function_exists( 'download_url' ) ) {
	function download_url( string $url, int $timeout = 300, bool $signature_verification = false ): string|\WP_Error {
		$path = tempnam( sys_get_temp_dir(), 'stonewright-download-' );
		if ( false === $path ) {
			return new \WP_Error( 'download_failed', 'Could not create temp file.' );
		}
		file_put_contents( $path, 'test' );
		return $path;
	}
}

if ( ! function_exists( 'media_handle_sideload' ) ) {
	function media_handle_sideload( array $file_array, int $post_id = 0, string $desc = '', array $post_data = [] ): int|\WP_Error {
		$id = (int) $GLOBALS['stonewright_test_next_post_id']++;
		$GLOBALS['stonewright_test_posts'][ $id ] = (object) [
			'ID'             => $id,
			'post_type'      => 'attachment',
			'post_status'    => 'inherit',
			'post_title'     => $file_array['name'] ?? 'attachment',
			'post_content'   => '',
			'post_excerpt'   => '',
			'post_parent'    => $post_id,
			'post_name'      => sanitize_title( (string) ( $file_array['name'] ?? 'attachment' ) ),
			'post_mime_type' => 'text/plain',
			'meta'           => [],
		];
		return $id;
	}
}

if ( ! function_exists( 'wp_get_attachment_url' ) ) {
	function wp_get_attachment_url( int $attachment_id ): string|false {
		return 'https://example.test/wp-content/uploads/attachment-' . $attachment_id . '.txt';
	}
}

if ( ! function_exists( 'get_post_mime_type' ) ) {
	function get_post_mime_type( int|object $post ): string|false {
		$id = is_object( $post ) ? (int) $post->ID : (int) $post;
		return $GLOBALS['stonewright_test_posts'][ $id ]->post_mime_type ?? 'text/plain';
	}
}

if ( ! function_exists( 'wp_get_attachment_metadata' ) ) {
	function wp_get_attachment_metadata( int $attachment_id, bool $unfiltered = false ): array|false {
		return [];
	}
}

if ( ! function_exists( 'get_attached_file' ) ) {
	function get_attached_file( int $attachment_id, bool $unfiltered = false ): string|false {
		$path = WP_CONTENT_DIR . '/uploads/attachment-' . $attachment_id . '.txt';
		wp_mkdir_p( dirname( $path ) );
		file_put_contents( $path, 'test' );
		return $path;
	}
}

if ( ! function_exists( 'wp_generate_attachment_metadata' ) ) {
	function wp_generate_attachment_metadata( int $attachment_id, string $file ): array {
		return [ 'file' => basename( $file ) ];
	}
}

if ( ! function_exists( 'wp_update_attachment_metadata' ) ) {
	function wp_update_attachment_metadata( int $attachment_id, array $data ): bool {
		return true;
	}
}

if ( ! function_exists( 'parse_blocks' ) ) {
	function parse_blocks( string $content ): array {
		return [
			[
				'blockName'    => 'core/paragraph',
				'attrs'        => [],
				'innerHTML'    => $content,
				'innerContent' => [ $content ],
				'innerBlocks'  => [],
			],
		];
	}
}

if ( ! function_exists( 'serialize_blocks' ) ) {
	function serialize_blocks( array $blocks ): string {
		return implode( '', array_map( 'serialize_block', $blocks ) );
	}
}

if ( ! function_exists( 'serialize_block' ) ) {
	/**
	 * Minimal serialize_block stub: produces WordPress block comment delimiters.
	 *
	 * @param array<string, mixed> $block
	 */
	function serialize_block( array $block ): string {
		$name        = (string) ( $block['blockName'] ?? '' );
		$attrs       = $block['attrs'] ?? [];
		$inner_blocks = $block['innerBlocks'] ?? [];
		$inner_html   = (string) ( $block['innerHTML'] ?? '' );

		if ( '' === $name ) {
			return $inner_html;
		}

		$attrs_json = ! empty( $attrs ) ? ' ' . (string) json_encode( $attrs ) : '';

		if ( empty( $inner_blocks ) ) {
			return '<!-- wp:' . $name . $attrs_json . ' -->' . $inner_html . '<!-- /wp:' . $name . ' -->';
		}

		// Build from innerContent: null entries are replaced by serialized inner blocks.
		$inner_content = $block['innerContent'] ?? [ $inner_html ];
		$block_idx     = 0;
		$out           = '';
		foreach ( $inner_content as $piece ) {
			if ( null === $piece ) {
				if ( isset( $inner_blocks[ $block_idx ] ) ) {
					$out .= serialize_block( $inner_blocks[ $block_idx ] );
					$block_idx++;
				}
			} else {
				$out .= (string) $piece;
			}
		}

		return '<!-- wp:' . $name . $attrs_json . ' -->' . $out . '<!-- /wp:' . $name . ' -->';
	}
}

if ( ! function_exists( 'get_posts' ) ) {
	function get_posts( array $args = [] ): array {
		return [];
	}
}

if ( ! function_exists( 'get_block_template' ) ) {
	function get_block_template( string $id, string $template_type = 'wp_template' ): object|null {
		return (object) [
			'id'      => $id,
			'wp_id'   => 1,
			'type'    => $template_type,
			'slug'    => 'contract',
			'theme'   => 'stonewright-theme',
			'content' => '<!-- wp:paragraph --><p>Template</p><!-- /wp:paragraph -->',
		];
	}
}

if ( ! function_exists( 'get_block_templates' ) ) {
	function get_block_templates( array $query = [], string $template_type = 'wp_template' ): array {
		return [];
	}
}

if ( ! function_exists( 'wp_safe_remote_post' ) ) {
	function wp_safe_remote_post( string $url, array $args = [] ): array|\WP_Error {
		$path = (string) parse_url( $url, PHP_URL_PATH );
		$GLOBALS['stonewright_test_companion_requests'] ??= [];
		$GLOBALS['stonewright_test_companion_requests'][] = [
			'path'    => $path,
			'url'     => $url,
			'body'    => isset( $args['body'] ) && is_string( $args['body'] )
				? ( json_decode( $args['body'], true ) ?: [] )
				: [],
			'headers' => (array) ( $args['headers'] ?? [] ),
		];

		// Per-test overrides: $GLOBALS['stonewright_test_companion_responses'][ $path ]
		// A null value falls through to defaults; a WP_Error is returned directly so
		// callers can exercise the is_wp_error($response) propagation path.
		$overrides = $GLOBALS['stonewright_test_companion_responses'] ?? [];
		if ( array_key_exists( $path, $overrides ) && $overrides[ $path ] !== null ) {
			$override = $overrides[ $path ];
			if ( $override instanceof \WP_Error ) {
				return $override;
			}
			return [
				'response' => [ 'code' => 200 ],
				'body'     => wp_json_encode( $override ),
			];
		}

		$body = match ( $path ) {
			'/health' => [
				'status'           => 'ok',
				'contract_version' => '1.0.0',
			],
			'/wp-cli/status' => [
				'ok'          => true,
				'available'   => true,
				'command'     => [ 'wp', 'cli', 'info', '--format=json' ],
				'cwd'         => 'D:/Sites/site',
				'stdout'      => '{"wp_cli_version":"2.12.0"}',
				'stderr'      => '',
				'exit_code'   => 0,
				'duration_ms' => 1,
			],
			'/wp-cli/discover' => [
				'ok'          => true,
				'available'   => true,
				'command'     => [ 'wp', 'cli', 'cmd-dump' ],
				'cwd'         => 'D:/Sites/site',
				'stdout'      => '{"name":"wp"}',
				'stderr'      => '',
				'exit_code'   => 0,
				'duration_ms' => 1,
				'parsed_json' => [ 'name' => 'wp' ],
			],
			'/wp-cli/run' => [
				'ok'          => true,
				'available'   => true,
				'command'     => [ 'wp', 'post', 'list' ],
				'cwd'         => 'D:/Sites/site',
				'stdout'      => '[]',
				'stderr'      => '',
				'exit_code'   => 0,
				'duration_ms' => 1,
			],
			default => [
				'ok'     => true,
				'issues' => [],
			],
		};
		// Use json_encode (not wp_json_encode) so the test's global stub override
		// for wp_json_encode is not consumed here when tests inject false.
		return [
			'response' => [ 'code' => 200 ],
			'body'     => (string) json_encode( $body ),
		];
	}
}

if ( ! function_exists( 'wp_remote_get' ) ) {
	function wp_remote_get( string $url, array $args = [] ): array|\WP_Error {
		return [
			'response' => [ 'code' => 200 ],
			'body'     => wp_json_encode(
				[
					'nodes' => [
						'1:2' => [
							'document' => [
								'id'       => '1:2',
								'name'     => 'Contract Frame',
								'type'     => 'FRAME',
								'children' => [
									[
										'id'         => '1:3',
										'name'       => 'Heading',
										'type'       => 'TEXT',
										'characters' => 'Hello',
									],
								],
							],
						],
					],
				]
			),
		];
	}
}

if ( ! function_exists( 'wp_remote_retrieve_response_code' ) ) {
	function wp_remote_retrieve_response_code( array|\WP_Error $response ): int {
		return is_array( $response ) ? (int) ( $response['response']['code'] ?? 0 ) : 0;
	}
}

if ( ! function_exists( 'wp_remote_retrieve_body' ) ) {
	function wp_remote_retrieve_body( array|\WP_Error $response ): string {
		return is_array( $response ) ? (string) ( $response['body'] ?? '' ) : '';
	}
}

if ( ! function_exists( 'wp_remote_retrieve_header' ) ) {
	function wp_remote_retrieve_header( array|\WP_Error $response, string $header ): string|array {
		if ( ! is_array( $response ) ) {
			return '';
		}
		$headers = $response['headers'] ?? [];
		if ( is_array( $headers ) ) {
			return $headers[ strtolower( $header ) ] ?? '';
		}
		return '';
	}
}

if ( ! function_exists( 'wp_tempnam' ) ) {
	function wp_tempnam( string $filename = '' ): string {
		$tmp = sys_get_temp_dir() . '/stonewright-test-tmp-' . md5( $filename . uniqid() );
		return $tmp;
	}
}

if ( ! function_exists( 'gethostbyname' ) ) {
	// gethostbyname is a PHP built-in; don't redeclare, but we stub it in test
	// using $GLOBALS['stonewright_test_gethostbyname'] override via test-helper closures.
}

// Allow tests to override wp_safe_remote_get (distinct from wp_safe_remote_post).
if ( ! function_exists( 'wp_safe_remote_get' ) ) {
	function wp_safe_remote_get( string $url, array $args = [] ): array|\WP_Error {
		$path = (string) parse_url( $url, PHP_URL_PATH );

		$overrides = $GLOBALS['stonewright_test_companion_responses'] ?? [];
		if ( array_key_exists( $path, $overrides ) && $overrides[ $path ] !== null ) {
			return $overrides[ $path ];
		}

		// Per-URL override for asset sideloading tests.
		$asset_overrides = $GLOBALS['stonewright_test_asset_responses'] ?? [];
		if ( array_key_exists( $url, $asset_overrides ) ) {
			return $asset_overrides[ $url ];
		}

		// Default: return a successful image response.
		return [
			'response' => [ 'code' => 200 ],
			'headers'  => [ 'content-type' => 'image/png' ],
			'body'     => str_repeat( 'x', 100 ),
		];
	}
}

if ( ! class_exists( 'WP_Site_Health' ) ) {
	class WP_Site_Health {
		public static function get_instance(): self {
			return new self();
		}

		public function get_tests(): array {
			return [
				'direct' => [
					'test' => [
						'test' => static fn(): array => [
							'status' => 'good',
							'label'  => 'Test health',
						],
					],
				],
			];
		}
	}
}

if ( ! class_exists( 'WP_Block_Type_Registry' ) ) {
	class WP_Block_Type_Registry {
		public static function get_instance(): self {
			return new self();
		}

		public function get_all_registered(): array {
			if ( isset( $GLOBALS['stonewright_test_registered_blocks'] ) && is_array( $GLOBALS['stonewright_test_registered_blocks'] ) ) {
				return $GLOBALS['stonewright_test_registered_blocks'];
			}

			return [
				'core/paragraph' => (object) [
					'title'       => 'Paragraph',
					'category'    => 'text',
					'description' => '',
					'icon'        => 'editor-paragraph',
					'supports'    => [],
				],
			];
		}

		public function get_registered( string $name ): ?object {
			return $this->get_all_registered()[ $name ] ?? null;
		}
	}
}

if ( ! class_exists( 'WP_Block_Patterns_Registry' ) ) {
	class WP_Block_Patterns_Registry {
		public static function get_instance(): self {
			return new self();
		}

		public function get_all_registered(): array {
			return [];
		}
	}
}

if ( ! function_exists( 'add_action' ) ) {
	/**
	 * WordPress add_action stub — records hooks so tests can inspect them.
	 * In WP core, add_action is an alias for add_filter.
	 */
	function add_action( string $hook_name, callable|array|string $callback, int $priority = 10, int $accepted_args = 1 ): bool {
		$GLOBALS['stonewright_test_actions'][ $hook_name ][] = [
			'callback' => $callback,
			'priority' => $priority,
			'args'     => $accepted_args,
		];
		return true;
	}
}

$GLOBALS['stonewright_test_actions'] ??= [];

if ( ! function_exists( 'add_menu_page' ) ) {
	function add_menu_page(
		string $page_title,
		string $menu_title,
		string $capability,
		string $menu_slug,
		callable|string $callback = '',
		string $icon_url = '',
		?int $position = null
	): string {
		$GLOBALS['stonewright_test_menu_pages'][ $menu_slug ] = [
			'page_title' => $page_title,
			'menu_title' => $menu_title,
			'capability' => $capability,
			'callback'   => $callback,
		];
		return 'toplevel_page_' . $menu_slug;
	}
}

$GLOBALS['stonewright_test_menu_pages']    ??= [];
$GLOBALS['stonewright_test_submenu_pages'] ??= [];

if ( ! function_exists( 'add_submenu_page' ) ) {
	function add_submenu_page(
		string $parent_slug,
		string $page_title,
		string $menu_title,
		string $capability,
		string $menu_slug,
		callable|string $callback = '',
		int $position = 0
	): string|false {
		$GLOBALS['stonewright_test_submenu_pages'][ $menu_slug ] = [
			'parent'     => $parent_slug,
			'page_title' => $page_title,
			'capability' => $capability,
			'callback'   => $callback,
		];
		return 'stonewright_page_' . $menu_slug;
	}
}

if ( ! function_exists( 'register_rest_route' ) ) {
	function register_rest_route( string $namespace, string $route, array $args = [], bool $override = false ): bool {
		$GLOBALS['stonewright_test_rest_routes'][] = [
			'namespace' => $namespace,
			'route'     => $route,
			'args'      => $args,
		];
		return true;
	}
}

$GLOBALS['stonewright_test_rest_routes'] ??= [];

if ( ! function_exists( 'rest_ensure_response' ) ) {
	function rest_ensure_response( mixed $data ): \WP_REST_Response|\WP_Error {
		if ( $data instanceof \WP_Error ) {
			return $data;
		}
		if ( $data instanceof \WP_REST_Response ) {
			return $data;
		}
		return new \WP_REST_Response( $data );
	}
}

if ( ! function_exists( 'wp_die' ) ) {
	function wp_die( string $message = '', string $title = '', array|int $args = [] ): never {
		throw new \RuntimeException( "wp_die: {$message}" );
	}
}

if ( ! function_exists( 'wp_nonce_field' ) ) {
	function wp_nonce_field( string $action = '', string $name = '_wpnonce', bool $referer = true, bool $echo = true ): string {
		$html = '<input type="hidden" name="' . esc_attr( $name ) . '" value="test-nonce-' . esc_attr( $action ) . '" />';
		if ( $echo ) {
			echo $html; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		}
		return $html;
	}
}

if ( ! function_exists( 'wp_verify_nonce' ) ) {
	function wp_verify_nonce( string $nonce, string|int $action = -1 ): int|false {
		// In tests, treat any non-empty nonce as valid for happy-path tests.
		// Tests that need to test nonce failure should override this.
		if ( isset( $GLOBALS['stonewright_test_nonce_invalid'] ) && $GLOBALS['stonewright_test_nonce_invalid'] ) {
			return false;
		}
		return '' !== $nonce ? 1 : false;
	}
}

if ( ! function_exists( 'wp_create_nonce' ) ) {
	function wp_create_nonce( string $action = '' ): string {
		return 'test-nonce-' . md5( $action );
	}
}

if ( ! function_exists( 'check_admin_referer' ) ) {
	function check_admin_referer( string $action = '', string $query_arg = '' ): int|false {
		return 1;
	}
}

if ( ! function_exists( 'wp_safe_redirect' ) ) {
	function wp_safe_redirect( string $location, int $status = 302, string $x_redirect_by = 'WordPress' ): bool {
		$GLOBALS['stonewright_test_last_redirect'] = $location;
		return true;
	}
}

if ( ! function_exists( 'wp_unslash' ) ) {
	function wp_unslash( mixed $value ): mixed {
		if ( is_string( $value ) ) {
			return stripslashes( $value );
		}
		if ( is_array( $value ) ) {
			return array_map( 'wp_unslash', $value );
		}
		return $value;
	}
}

if ( ! function_exists( 'wp_date' ) ) {
	function wp_date( string $format, ?int $timestamp = null ): string|false {
		$ts = $timestamp ?? time();
		return gmdate( $format, $ts );
	}
}

if ( ! function_exists( 'size_format' ) ) {
	function size_format( int $bytes, int $decimals = 0 ): string {
		if ( $bytes >= 1048576 ) {
			return round( $bytes / 1048576, $decimals ) . ' MB';
		}
		if ( $bytes >= 1024 ) {
			return round( $bytes / 1024, $decimals ) . ' KB';
		}
		return $bytes . ' B';
	}
}

if ( ! function_exists( 'add_query_arg' ) ) {
	function add_query_arg( mixed $key, mixed $value = '', string $url = '' ): string {
		if ( is_array( $key ) ) {
			// Real WP: add_query_arg( array $args, string $url = '' )
			// When called as add_query_arg( $arr, $url_string ), $url_string
			// ends up in $value (2nd param). Detect and handle this.
			$base = ( '' !== $url ) ? $url : ( is_string( $value ) ? $value : '' );
			return $base . ( str_contains( $base, '?' ) ? '&' : '?' ) . http_build_query( $key );
		}
		return $url . ( str_contains( $url, '?' ) ? '&' : '?' ) . urlencode( (string) $key ) . '=' . urlencode( (string) $value );
	}
}

if ( ! function_exists( 'wp_generate_password' ) ) {
	function wp_generate_password( int $length = 12, bool $special_chars = true, bool $extra_special_chars = false ): string {
		return bin2hex( random_bytes( (int) ceil( $length / 2 ) ) );
	}
}

if ( ! function_exists( 'settings_fields' ) ) {
	function settings_fields( string $option_group ): void {
	}
}

if ( ! function_exists( 'do_settings_sections' ) ) {
	function do_settings_sections( string $page ): void {
	}
}

if ( ! function_exists( 'register_setting' ) ) {
	function register_setting( string $option_group, string $option_name, array $args = [] ): void {
		$GLOBALS['stonewright_test_registered_settings'][] = [
			'group'  => $option_group,
			'option' => $option_name,
			'args'   => $args,
		];
	}
}

$GLOBALS['stonewright_test_registered_settings'] ??= [];

if ( ! function_exists( 'esc_html__' ) ) {
	function esc_html__( string $text, string $domain = 'default' ): string {
		return htmlspecialchars( $text, ENT_QUOTES, 'UTF-8' );
	}
}

if ( ! function_exists( 'esc_attr_e' ) ) {
	function esc_attr_e( string $text, string $domain = 'default' ): void {
		echo htmlspecialchars( $text, ENT_QUOTES, 'UTF-8' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
	}
}

if ( ! function_exists( 'esc_html_e' ) ) {
	function esc_html_e( string $text, string $domain = 'default' ): void {
		echo htmlspecialchars( $text, ENT_QUOTES, 'UTF-8' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
	}
}

if ( ! class_exists( 'WP_REST_Server' ) ) {
	class WP_REST_Server {
		public const READABLE   = 'GET';
		public const CREATABLE  = 'POST';
		public const EDITABLE   = 'POST, PUT, PATCH';
		public const DELETABLE  = 'DELETE';
		public const ALLMETHODS = 'GET, POST, PUT, PATCH, DELETE';
	}
}

if ( ! class_exists( 'WP_REST_Request' ) ) {
	class WP_REST_Request {
		/** @var array<string, mixed> */
		private array $params = [];

		/** @param array<string, mixed> $params */
		public function __construct( string $method = 'GET', string $route = '', array $params = [] ) {
			$this->params = $params;
		}

		public function get_param( string $key ): mixed {
			return $this->params[ $key ] ?? null;
		}

		/** @param array<string, mixed> $params */
		public function set_params( array $params ): void {
			$this->params = $params;
		}
	}
}

if ( ! class_exists( 'WP_REST_Response' ) ) {
	class WP_REST_Response {
		/** @param mixed $data */
		public function __construct( private mixed $data = null, private int $status = 200 ) {}

		public function get_data(): mixed {
			return $this->data;
		}

		public function get_status(): int {
			return $this->status;
		}
	}
}

if ( ! function_exists( 'wp_enqueue_style' ) ) {
	function wp_enqueue_style( string $handle, string $src = '', array $deps = [], mixed $ver = false, string $media = 'all' ): void {
		$GLOBALS['stonewright_test_enqueued_styles'][] = $handle;
	}
}

if ( ! function_exists( 'wp_enqueue_script' ) ) {
	function wp_enqueue_script( string $handle, string $src = '', array $deps = [], mixed $ver = false, bool $in_footer = false ): void {
		$GLOBALS['stonewright_test_enqueued_scripts'][] = $handle;
	}
}

$GLOBALS['stonewright_test_enqueued_styles']  ??= [];
$GLOBALS['stonewright_test_enqueued_scripts'] ??= [];

if ( ! function_exists( 'get_rest_url' ) ) {
	function get_rest_url( ?int $blog_id = null, string $path = '/' ): string {
		return 'https://example.test/wp-json/' . ltrim( $path, '/' );
	}
}

if ( ! function_exists( 'esc_js' ) ) {
	function esc_js( string $text ): string {
		return addslashes( $text );
	}
}

if ( ! function_exists( 'checked' ) ) {
	function checked( mixed $checked, mixed $current = true, bool $echo = true ): string {
		$result = $checked == $current ? ' checked="checked"' : ''; // phpcs:ignore WordPress.PHP.StrictComparisons.LooseComparison
		if ( $echo ) {
			echo $result; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		}
		return $result;
	}
}

if ( ! function_exists( 'selected' ) ) {
	function selected( mixed $selected, mixed $current = true, bool $echo = true ): string {
		$result = $selected == $current ? ' selected="selected"' : ''; // phpcs:ignore WordPress.PHP.StrictComparisons.LooseComparison
		if ( $echo ) {
			echo $result; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		}
		return $result;
	}
}

if ( ! function_exists( 'submit_button' ) ) {
	function submit_button( string $text = '', string $type = 'primary', string $name = '', bool $wrap = true, mixed $other_attributes = null ): void {
		echo '<input type="submit" value="' . esc_attr( $text ) . '" />'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
	}
}

// ---------------------------------------------------------------------------
// Nav menu stubs — backed by $GLOBALS['stonewright_test_nav_menus'] (a map of
// term_id => stdClass{ term_id, name, slug, items: array }) and the theme-mod
// store $GLOBALS['stonewright_test_theme_mods'] (map of mod_key => mixed).
//
// The shape mirrors what real WordPress returns: wp_get_nav_menus yields
// term-like objects, wp_update_nav_menu_item returns the new item's post_id,
// is_nav_menu checks the term_id exists, etc. The registered-locations side
// is backed by $GLOBALS['stonewright_test_registered_nav_menus'] (slug =>
// label) which tests can override before calling the assign-location ability.
// ---------------------------------------------------------------------------
$GLOBALS['stonewright_test_nav_menus']             ??= [];
$GLOBALS['stonewright_test_theme_mods']            ??= [];
$GLOBALS['stonewright_test_registered_nav_menus']  ??= [];
$GLOBALS['stonewright_test_next_nav_menu_id']      ??= 9001;
$GLOBALS['stonewright_test_next_nav_menu_item_id'] ??= 9101;

if ( ! function_exists( 'wp_create_nav_menu' ) ) {
	function wp_create_nav_menu( string $menu_name ): int|\WP_Error {
		// Honour explicit overrides so tests can force the WP_Error path
		// without crafting a duplicate menu first.
		if ( isset( $GLOBALS['stonewright_test_wp_create_nav_menu_return'] ) ) {
			$ret = $GLOBALS['stonewright_test_wp_create_nav_menu_return'];
			$GLOBALS['stonewright_test_wp_create_nav_menu_return'] = null;
			return $ret;
		}
		$trimmed = trim( $menu_name );
		if ( '' === $trimmed ) {
			return new \WP_Error( 'menu_name_empty', 'Empty menu name.' );
		}
		// Reject duplicate names — mirrors real wp_create_nav_menu behaviour.
		foreach ( (array) $GLOBALS['stonewright_test_nav_menus'] as $existing ) {
			if ( isset( $existing->name ) && (string) $existing->name === $trimmed ) {
				return new \WP_Error( 'menu_exists', 'Menu already exists.' );
			}
		}
		$id   = (int) $GLOBALS['stonewright_test_next_nav_menu_id']++;
		$slug = preg_replace( '/[^a-z0-9_\-]/', '-', strtolower( $trimmed ) ) ?? 'menu';
		$GLOBALS['stonewright_test_nav_menus'][ $id ] = (object) [
			'term_id' => $id,
			'name'    => $trimmed,
			'slug'    => $slug,
			'items'   => [],
		];
		return $id;
	}
}

if ( ! function_exists( 'wp_update_nav_menu_item' ) ) {
	/**
	 * @param array<string, mixed> $menu_item_data
	 */
	function wp_update_nav_menu_item( int $menu_id, int $menu_item_db_id = 0, array $menu_item_data = [], bool $wp_error = false ): int|\WP_Error {
		if ( isset( $GLOBALS['stonewright_test_wp_update_nav_menu_item_return'] ) ) {
			$ret = $GLOBALS['stonewright_test_wp_update_nav_menu_item_return'];
			$GLOBALS['stonewright_test_wp_update_nav_menu_item_return'] = null;
			return $ret;
		}
		if ( ! isset( $GLOBALS['stonewright_test_nav_menus'][ $menu_id ] ) ) {
			return new \WP_Error( 'invalid_menu_id', 'Invalid menu id.' );
		}
		$item_id = 0 === $menu_item_db_id
			? (int) $GLOBALS['stonewright_test_next_nav_menu_item_id']++
			: $menu_item_db_id;

		$GLOBALS['stonewright_test_nav_menus'][ $menu_id ]->items[ $item_id ] = [
			'item_id' => $item_id,
			'data'    => $menu_item_data,
		];
		return $item_id;
	}
}

if ( ! function_exists( 'wp_get_nav_menus' ) ) {
	/**
	 * @param array<string, mixed> $args
	 * @return array<int, object>
	 */
	function wp_get_nav_menus( array $args = [] ): array {
		return array_values( (array) ( $GLOBALS['stonewright_test_nav_menus'] ?? [] ) );
	}
}

if ( ! function_exists( 'wp_delete_nav_menu' ) ) {
	function wp_delete_nav_menu( int|string $menu ): bool|\WP_Error {
		if ( isset( $GLOBALS['stonewright_test_wp_delete_nav_menu_return'] ) ) {
			$ret = $GLOBALS['stonewright_test_wp_delete_nav_menu_return'];
			$GLOBALS['stonewright_test_wp_delete_nav_menu_return'] = null;
			return $ret;
		}
		$id = (int) $menu;
		if ( ! isset( $GLOBALS['stonewright_test_nav_menus'][ $id ] ) ) {
			return false;
		}
		unset( $GLOBALS['stonewright_test_nav_menus'][ $id ] );
		// Cascade: drop the menu from any theme-location assignment so tests
		// can observe the cleanup that real WP performs.
		$mods = $GLOBALS['stonewright_test_theme_mods']['nav_menu_locations'] ?? [];
		if ( is_array( $mods ) ) {
			foreach ( $mods as $loc => $menu_id ) {
				if ( (int) $menu_id === $id ) {
					unset( $mods[ $loc ] );
				}
			}
			$GLOBALS['stonewright_test_theme_mods']['nav_menu_locations'] = $mods;
		}
		return true;
	}
}

if ( ! function_exists( 'is_nav_menu' ) ) {
	function is_nav_menu( int|string|object $menu ): bool {
		if ( is_object( $menu ) ) {
			$menu = $menu->term_id ?? 0;
		}
		return isset( $GLOBALS['stonewright_test_nav_menus'][ (int) $menu ] );
	}
}

if ( ! function_exists( 'wp_get_nav_menu_object' ) ) {
	function wp_get_nav_menu_object( int|string|object $menu ): object|false {
		if ( is_object( $menu ) ) {
			return $menu;
		}
		$id = (int) $menu;
		return $GLOBALS['stonewright_test_nav_menus'][ $id ] ?? false;
	}
}

if ( ! function_exists( 'get_nav_menu_locations' ) ) {
	/** @return array<string, int> */
	function get_nav_menu_locations(): array {
		$locations = $GLOBALS['stonewright_test_theme_mods']['nav_menu_locations'] ?? [];
		return is_array( $locations ) ? $locations : [];
	}
}

if ( ! function_exists( 'get_registered_nav_menus' ) ) {
	/** @return array<string, string> */
	function get_registered_nav_menus(): array {
		return (array) ( $GLOBALS['stonewright_test_registered_nav_menus'] ?? [] );
	}
}

if ( ! function_exists( 'set_theme_mod' ) ) {
	function set_theme_mod( string $name, mixed $value ): void {
		$GLOBALS['stonewright_test_theme_mods'][ $name ] = $value;
	}
}

if ( ! function_exists( 'get_theme_mod' ) ) {
	function get_theme_mod( string $name, mixed $default = false ): mixed {
		if ( array_key_exists( $name, (array) $GLOBALS['stonewright_test_theme_mods'] ) ) {
			return $GLOBALS['stonewright_test_theme_mods'][ $name ];
		}
		return $default;
	}
}

if ( ! class_exists( 'WP_Theme_JSON_Resolver' ) ) {
	class WP_Theme_JSON_Resolver {
		public static function get_merged_data(): object {
			return self::theme_json_data();
		}

		public static function get_theme_data(): object {
			return self::theme_json_data();
		}

		public static function get_user_data(): object {
			return self::theme_json_data();
		}

		public static function get_user_global_styles_post_id(): int {
			$GLOBALS['stonewright_test_posts'][2] ??= (object) [
				'ID'           => 2,
				'post_type'    => 'wp_global_styles',
				'post_status'  => 'publish',
				'post_title'   => 'Global Styles',
				'post_content' => '{"version":3}',
				'post_excerpt' => '',
				'post_parent'  => 0,
				'post_name'    => 'global-styles',
				'meta'         => [],
			];
			return 2;
		}

		private static function theme_json_data(): object {
			return new class() {
				public function get_raw_data(): array {
					return [ 'version' => 3 ];
				}
			};
		}
	}
}
