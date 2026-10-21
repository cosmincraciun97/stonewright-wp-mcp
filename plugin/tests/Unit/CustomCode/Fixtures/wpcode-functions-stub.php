<?php
declare( strict_types=1 );

/**
 * The functions WpCodeProvider calls on a site with WPCode when it runs without a test backend,
 * none of which the test bootstrap provides:
 * - wpcode(), the plugin object with its snippet cache;
 * - wpcode_get_snippet(), which returns the snippet of a `wpcode` post;
 * - post_type_exists(), backed by $GLOBALS['stonewright_test_post_types'] like the bootstrap's
 *   get_post_type_object(); WPCode registers the `wpcode` post type.
 *
 * A function cannot be declared twice or unloaded: load this file with require_once in a test that
 * runs in a separate process.
 */

use Stonewright\WpMcp\Tests\Unit\CustomCode\Fixtures\FakeWpCodePlugin;
use Stonewright\WpMcp\Tests\Unit\CustomCode\Fixtures\FakeWpCodeSnippet;

if ( ! defined( 'WPCODE_VERSION' ) ) {
	define( 'WPCODE_VERSION', '2.4.0' );
}

function post_type_exists( string $post_type ): bool {
	return isset( $GLOBALS['stonewright_test_post_types'][ $post_type ] );
}

function wpcode(): FakeWpCodePlugin {
	static $plugin = null;
	if ( null === $plugin ) {
		$plugin = new FakeWpCodePlugin();
	}
	return $plugin;
}

function wpcode_get_snippet( int $snippet ): FakeWpCodeSnippet {
	return new FakeWpCodeSnippet( $snippet );
}
