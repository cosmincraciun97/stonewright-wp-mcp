<?php
/**
 * Compare two generated Elementor widget catalog indexes without loading shards.
 *
 * Usage: php bin/elementor-schema-diff.php <before-index.php> <after-index.php>
 *
 * @package Stonewright\WpMcp
 */

declare( strict_types=1 );

if ( PHP_SAPI !== 'cli' ) {
	exit( 1 );
}

$before_path = isset( $argv[1] ) ? (string) $argv[1] : '';
$after_path  = isset( $argv[2] ) ? (string) $argv[2] : '';
if ( ! is_file( $before_path ) || ! is_file( $after_path ) ) {
	fwrite( STDERR, "Both catalog index paths must exist.\n" );
	exit( 2 );
}

$before = include $before_path;
$after  = include $after_path;
if ( ! is_array( $before ) || ! is_array( $after ) ) {
	fwrite( STDERR, "Both catalog indexes must return arrays.\n" );
	exit( 2 );
}

$before_widgets = is_array( $before['widgets'] ?? null ) ? $before['widgets'] : [];
$after_widgets  = is_array( $after['widgets'] ?? null ) ? $after['widgets'] : [];
$added          = array_values( array_diff( array_keys( $after_widgets ), array_keys( $before_widgets ) ) );
$removed        = array_values( array_diff( array_keys( $before_widgets ), array_keys( $after_widgets ) ) );
$changed        = [];
foreach ( array_intersect( array_keys( $before_widgets ), array_keys( $after_widgets ) ) as $slug ) {
	if ( ( $before_widgets[ $slug ]['hash'] ?? null ) !== ( $after_widgets[ $slug ]['hash'] ?? null ) ) {
		$changed[] = $slug;
	}
}
sort( $added );
sort( $removed );
sort( $changed );

fwrite(
	STDOUT,
	(string) json_encode(
		[
			'added'   => $added,
			'removed' => $removed,
			'changed' => $changed,
			'counts'  => [
				'added'   => count( $added ),
				'removed' => count( $removed ),
				'changed' => count( $changed ),
			],
		],
		JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES
	) . "\n"
);
