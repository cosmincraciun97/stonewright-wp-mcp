<?php
declare( strict_types=1 );

/**
 * Child process used by ChangeJournalFileTest to write the journal concurrently.
 * SPDX-License-Identifier: GPL-2.0-or-later
 *
 * Usage: php journal-writer.php <journal path> <worker> <count> [pause_ms]
 */

$root = dirname( __DIR__, 3 ) . '/includes/Security/';
require_once $root . 'SensitiveContent.php';
require_once $root . 'ChangeJournalFile.php';

use Stonewright\WpMcp\Security\ChangeJournalFile;

$path   = (string) ( $argv[1] ?? '' );
$worker = (int) ( $argv[2] ?? 0 );
$count  = (int) ( $argv[3] ?? 0 );
$pause  = (int) ( $argv[4] ?? 0 );

if ( '' === $path || $count < 1 ) {
	fwrite( STDERR, "usage: journal-writer.php <path> <worker> <count> [pause_ms]\n" );
	exit( 2 );
}

$file   = new ChangeJournalFile( $path );
$failed = 0;
for ( $i = 0; $i < $count; $i++ ) {
	$result = $file->transaction(
		static function ( array $document ) use ( $worker, $i ): array {
			$document['entries'][] = [
				'id'             => sprintf( 'cs-w%d-%03d', $worker, $i ),
				'ability'        => 'stonewright/theme-file-patch',
				'resource_type'  => 'theme_file',
				'resource_key'   => 'functions.php',
				'recipe'         => [ 'type' => 'theme_backup', 'ref' => 'sw-theme-backup-0000' ],
				'paths'          => [ 'wp-content/themes/site-a/functions.php' ],
				'armed_at'       => time(),
				'probe_deadline' => time() + 120,
				'state'          => 'armed',
				'incident'       => null,
			];
			return $document;
		}
	);
	if ( null === $result ) {
		++$failed;
	}
	if ( $pause > 0 ) {
		usleep( $pause * 1000 );
	}
}

exit( 0 === $failed ? 0 : 3 );
