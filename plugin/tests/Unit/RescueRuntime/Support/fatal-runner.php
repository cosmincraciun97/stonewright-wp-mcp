<?php
/**
 * Child process of the rescue runtime tests: a tiny WordPress that loads the real MU-plugin
 * and then lets a theme file raise a real fatal error.
 *
 * Usage: php fatal-runner.php <root> <scenario>
 *
 * The root directory holds wp-content/. Its options come from the RUNNER_OPTIONS environment
 * variable (JSON). The syntax of this file is valid on PHP 7.3 and later, so the runtime can
 * be exercised on every PHP version the MU-plugin supports.
 */

$root     = (string) $argv[1];
$scenario = (string) $argv[2];

define( 'ABSPATH', $root . '/' );
define( 'WP_CONTENT_DIR', $root . '/wp-content' );
define( 'WP_PLUGIN_DIR', WP_CONTENT_DIR . '/plugins' );

$GLOBALS['runner_options'] = json_decode( (string) getenv( 'RUNNER_OPTIONS' ), true );

function get_option( $name, $default = false ) {
	return array_key_exists( $name, $GLOBALS['runner_options'] ) ? $GLOBALS['runner_options'][ $name ] : $default;
}

function add_filter() {
	return true;
}

function add_action() {
	return true;
}

$mu = (string) getenv( 'RUNNER_MU' );
if ( 'extra_notice_first' === $scenario ) {
	// An earlier shutdown function raises a notice, which replaces the fatal error as the last error.
	register_shutdown_function(
		function () {
			trigger_error( 'late notice', E_USER_NOTICE );
		}
	);
}

require $mu;

$theme_file = WP_CONTENT_DIR . '/themes/site-a/functions.php';
switch ( $scenario ) {
	case 'user_error':
	case 'undefined_function':
	case 'uncaught_exception':
	case 'parse_error':
	case 'memory':
	case 'extra_notice_first':
	case 'warning_only':
		include $theme_file;
		break;
	default:
		fwrite( STDERR, "unknown scenario\n" );
		exit( 2 );
}
echo "finished\n";
