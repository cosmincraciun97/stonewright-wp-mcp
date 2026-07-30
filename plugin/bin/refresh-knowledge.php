<?php
/**
 * Bulk knowledge refresh CLI.
 *
 * Iterates docs/knowledge/elementor/_links.json and lists URLs whose
 * matching `.md` file is older than the configured threshold
 * (`stonewright_knowledge_max_age_days`, default 30).
 *
 * Intended consumers:
 *   1. Operators running `php plugin/bin/refresh-knowledge.php --dry-run`
 *      to see what would be refreshed.
 *   2. The companion harvester (`companion/scripts/scrape-all.js`)
 *      taking the URL list, doing real browser fetches, and POSTing
 *      each body back to `stonewright/elementor-knowledge-refresh`.
 *
 * The script itself does NOT call wp_remote_get — that is the ability's
 * job. This is just the planner / orchestrator.
 *
 * Usage:
 *   php plugin/bin/refresh-knowledge.php           # human report
 *   php plugin/bin/refresh-knowledge.php --json    # JSON for tools
 *   php plugin/bin/refresh-knowledge.php --max-age-days=14
 *   php plugin/bin/refresh-knowledge.php --hub=widgets
 */
declare(strict_types=1);

$repo_root      = realpath( __DIR__ . '/../..' );
$knowledge_dir  = $repo_root . '/docs/knowledge/elementor';
$links_path     = $knowledge_dir . '/_links.json';

$args = self_parse_argv( $argv ?? [] );
$max_age_days = isset( $args['max-age-days'] ) ? (int) $args['max-age-days'] : 30;
$hub_filter   = isset( $args['hub'] ) ? (string) $args['hub'] : '';
$as_json      = isset( $args['json'] );
$dry_run      = isset( $args['dry-run'] );

if ( ! is_file( $links_path ) ) {
	fwrite( STDERR, "ERROR: _links.json not found at $links_path\n" );
	exit( 1 );
}

$raw = (string) file_get_contents( $links_path );
if ( substr( $raw, 0, 3 ) === "\xEF\xBB\xBF" ) {
	$raw = substr( $raw, 3 );
}
$links = json_decode( $raw, true );
if ( ! is_array( $links ) || empty( $links['hubs'] ) ) {
	fwrite( STDERR, "ERROR: _links.json is missing 'hubs' key.\n" );
	exit( 1 );
}

$threshold = time() - ( $max_age_days * 86400 );

$report = [
	'now'              => gmdate( 'c' ),
	'max_age_days'     => $max_age_days,
	'hub_filter'       => $hub_filter,
	'dry_run'          => $dry_run,
	'total_inspected'  => 0,
	'needs_refresh'    => 0,
	'fresh'            => 0,
	'missing_locally'  => 0,
	'tombstones'       => 0,
	'entries'          => [],
];

foreach ( $links['hubs'] as $hub_key => $hub_entries ) {
	if ( $hub_filter !== '' && $hub_filter !== $hub_key ) {
		continue;
	}
	$hub_dir = $knowledge_dir . '/' . preg_replace( '/_/', '-', (string) $hub_key );
	foreach ( (array) $hub_entries as $entry ) {
		if ( ! is_array( $entry ) ) {
			continue;
		}
		$url  = (string) ( $entry['url']  ?? '' );
		$slug = (string) ( $entry['slug'] ?? '' );
		if ( $url === '' || $slug === '' ) {
			continue;
		}
		$file = $hub_dir . '/' . $slug . '.md';
		$report['total_inspected']++;

		if ( ! is_file( $file ) ) {
			$report['missing_locally']++;
			$report['entries'][] = [
				'url'  => $url,
				'hub'  => $hub_key,
				'slug' => $slug,
				'file' => self_relative( $file, $repo_root ),
				'status' => 'missing',
			];
			continue;
		}

		$content    = (string) file_get_contents( $file );
		$is_tombstone = (bool) preg_match( '/^tombstone:\s*true\s*$/m', $content );
		$fetched_at_raw = self_extract_field( $content, 'fetched_at' );
		$fetched_at = $fetched_at_raw !== null ? strtotime( $fetched_at_raw ) : 0;

		if ( $is_tombstone ) {
			$report['tombstones']++;
			$report['entries'][] = [
				'url'        => $url,
				'hub'        => $hub_key,
				'slug'       => $slug,
				'file'       => self_relative( $file, $repo_root ),
				'fetched_at' => $fetched_at_raw,
				'status'     => 'tombstone',
			];
			continue;
		}

		if ( $fetched_at < $threshold ) {
			$report['needs_refresh']++;
			$report['entries'][] = [
				'url'         => $url,
				'hub'         => $hub_key,
				'slug'        => $slug,
				'file'        => self_relative( $file, $repo_root ),
				'fetched_at'  => $fetched_at_raw,
				'age_days'    => $fetched_at > 0 ? (int) floor( ( time() - $fetched_at ) / 86400 ) : null,
				'status'      => 'stale',
			];
		} else {
			$report['fresh']++;
		}
	}
}

if ( $as_json ) {
	echo json_encode( $report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ) . "\n";
	exit( 0 );
}

echo "Stonewright knowledge-base refresh report\n";
echo "  generated:        " . $report['now'] . "\n";
echo "  max age:          {$max_age_days} days\n";
echo "  hub filter:       " . ( $hub_filter !== '' ? $hub_filter : '(none)' ) . "\n";
echo "  total inspected:  " . $report['total_inspected'] . "\n";
echo "  fresh:            " . $report['fresh'] . "\n";
echo "  needs refresh:    " . $report['needs_refresh'] . "\n";
echo "  missing locally:  " . $report['missing_locally'] . "\n";
echo "  tombstones:       " . $report['tombstones'] . "\n";
echo "\n";
echo "Next step: hand `entries[status=stale|missing].url` to the companion\n";
echo "harvester (companion/scripts/scrape-page.js) and POST each rendered\n";
echo "body back to `stonewright/elementor-knowledge-refresh` with the same\n";
echo "url + hub.\n";

function self_parse_argv( array $argv ): array {
	$out = [];
	foreach ( array_slice( $argv, 1 ) as $a ) {
		if ( ! str_starts_with( $a, '--' ) ) {
			continue;
		}
		$a = substr( $a, 2 );
		if ( str_contains( $a, '=' ) ) {
			[ $k, $v ] = explode( '=', $a, 2 );
			$out[ $k ] = $v;
		} else {
			$out[ $a ] = true;
		}
	}
	return $out;
}

function self_extract_field( string $content, string $field ): ?string {
	if ( ! preg_match( '/^---\s*\R(.*?)\R---/s', $content, $m ) ) {
		return null;
	}
	if ( preg_match( '/^' . preg_quote( $field, '/' ) . ':\s*(.+)$/m', $m[1], $fm ) ) {
		return trim( $fm[1] );
	}
	return null;
}

function self_relative( string $path, string $root ): string {
	$norm = str_replace( '\\', '/', $path );
	$root = str_replace( '\\', '/', $root );
	if ( str_starts_with( $norm, $root . '/' ) ) {
		return substr( $norm, strlen( $root ) + 1 );
	}
	return $norm;
}
