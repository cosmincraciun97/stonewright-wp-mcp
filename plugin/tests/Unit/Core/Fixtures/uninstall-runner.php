<?php
/**
 * Includes the real uninstall.php the way WordPress does: no Composer autoloader, no plugin
 * bootstrap, only the files uninstall.php loads itself and the few WordPress functions the
 * removal calls. A class that uninstall.php needs but does not load ends the run with a fatal error.
 *
 * Usage: php uninstall-runner.php <plugin dir> <content dir> <uploads dir> <keep|remove>
 * Prints one JSON line that says which classes were loaded.
 */

declare( strict_types=1 );

$plugin  = rtrim( str_replace( '\\', '/', $argv[1] ), '/' );
$content = rtrim( str_replace( '\\', '/', $argv[2] ), '/' );
$uploads = rtrim( str_replace( '\\', '/', $argv[3] ), '/' );

define( 'ABSPATH', dirname( $content ) . '/' );
define( 'WP_CONTENT_DIR', $content );
define( 'WP_UNINSTALL_PLUGIN', 'stonewright/stonewright.php' );
if ( 'remove' === $argv[4] ) {
	define( 'STONEWRIGHT_REMOVE_ALL_DATA', true );
}

$GLOBALS['uninstall_runner_uploads'] = $uploads;

// The smallest WordPress that the removal calls.
class wpdb {

	public string $prefix  = 'wp_';
	public string $options = 'wp_options';

	public function query( string $sql ): int {
		return 0;
	}

	public function esc_like( string $text ): string {
		return $text;
	}

	public function prepare( string $query, mixed ...$args ): string {
		return $query;
	}

	/** @return list<string> */
	public function get_col( string $query ): array {
		return [];
	}
}

function is_multisite(): bool {
	return false;
}

function wp_clear_scheduled_hook( string $hook ): int {
	return 0;
}

function delete_option( string $name ): bool {
	return true;
}

function wp_cache_flush(): bool {
	return true;
}

/** @return array<string, mixed> */
function wp_upload_dir( ?string $time = null, bool $create_dir = true ): array {
	return [
		'basedir' => $GLOBALS['uninstall_runner_uploads'],
		'baseurl' => 'https://example.test/wp-content/uploads',
		'error'   => false,
	];
}

$GLOBALS['wpdb'] = new wpdb();

include $plugin . '/uninstall.php';

echo json_encode(
	[
		'installer'   => class_exists( 'Stonewright\WpMcp\Core\RescueInstaller', false ),
		'uninstaller' => class_exists( 'Stonewright\WpMcp\Core\Uninstaller', false ),
		'journal'     => class_exists( 'Stonewright\WpMcp\Security\ChangeJournal', false ),
		'journal_file' => class_exists( 'Stonewright\WpMcp\Security\ChangeJournalFile', false ),
		'blob_store'  => class_exists( 'Stonewright\WpMcp\Security\BlobStore', false ),
		'autoloaders' => count( spl_autoload_functions() ?: [] ),
	]
) . "\n";
