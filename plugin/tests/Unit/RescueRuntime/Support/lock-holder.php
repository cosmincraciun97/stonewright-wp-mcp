<?php
declare( strict_types=1 );

/**
 * Child process of the rescue runtime tests: holds the journal lock the way a writer does.
 *
 * Usage: php lock-holder.php <journal path> <hold ms> [id of an entry to append before it unlocks] ["incident"]
 *
 * With "incident" as the fourth argument it also records an incident (line 99) on the entry cs-1, as a
 * second request that hit the same fatal error would.
 *
 * It prints "locked" once it owns the lock, so the parent knows when to start.
 */

$journal = (string) ( $argv[1] ?? '' );
$hold    = (int) ( $argv[2] ?? 0 );
$append  = (string) ( $argv[3] ?? '' );
$mark    = 'incident' === ( $argv[4] ?? '' );

$lock = fopen( $journal . '.lock', 'c' );
if ( false === $lock || ! flock( $lock, LOCK_EX ) ) {
	fwrite( STDERR, "could not lock\n" );
	exit( 2 );
}
fwrite( STDOUT, "locked\n" );
fflush( STDOUT );
usleep( $hold * 1000 );

if ( '' !== $append || $mark ) {
	$document = json_decode( (string) file_get_contents( $journal ) );
	if ( $mark ) {
		$document->entries[0]->state    = 'incident';
		$document->entries[0]->incident = (object) [
			'recorded_at'    => time(),
			'file'           => 'wp-content/themes/site-a/functions.php',
			'line'           => 99,
			'type'           => 1,
			'message_sha256' => str_repeat( 'b', 64 ),
			'source'         => 'shutdown',
		];
	}
}
if ( '' !== $append ) {
	$document->entries[] = (object) [
		'id'             => $append,
		'ability'        => 'stonewright/theme-file-patch',
		'resource_type'  => 'theme_file',
		'resource_key'   => 'style.css',
		'recipe'         => (object) [ 'type' => 'theme_backup', 'ref' => 'r' ],
		'paths'          => [ 'wp-content/themes/site-b/style.css' ],
		'armed_at'       => time(),
		'probe_deadline' => time() + 60,
		'state'          => 'armed',
		'incident'       => null,
	];
}
if ( '' !== $append || $mark ) {
	$temp = $journal . '.tmp-holder';
	file_put_contents( $temp, json_encode( $document, JSON_UNESCAPED_SLASHES ) );
	rename( $temp, $journal );
}

flock( $lock, LOCK_UN );
fclose( $lock );
exit( 0 );
