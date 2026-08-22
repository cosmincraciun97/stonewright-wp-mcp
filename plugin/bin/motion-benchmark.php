<?php
/**
 * Motion pipeline benchmark (plan Task 14).
 *
 * Runs deterministic scenarios through the read-only pipeline and reports
 * wall time, output bytes, and operation counts. Tokenization differs between
 * clients, so this measures BYTES; token estimates use a declared ~4
 * chars/token heuristic and are labeled as estimates.
 *
 * Usage: php bin/motion-benchmark.php [--json]
 */
declare( strict_types=1 );

require_once __DIR__ . '/../tests/bootstrap.php';

use Stonewright\WpMcp\Design\Motion\MotionPlanCompiler;

$GLOBALS['stonewright_test_user_caps']['edit_posts'] = true;

function make_spec( int $targets ): array {
	$blocks = [];
	$motion = [];
	for ( $i = 0; $i < $targets; $i++ ) {
		$id      = "card-{$i}";
		$blocks[] = [ 'id' => $id, 'type' => 'paragraph', 'text' => "Card number {$i} real copy." ];
		$motion[] = [
			'id'             => 'enter-' . $id,
			'purpose'        => 'reveal',
			'target_id'      => $id,
			'trigger'        => 'viewport-enter',
			'effect'         => 'fade-up',
			'playback'       => 'once',
			'engine'         => 'auto',
			'reduced_motion' => 'replace-with-fade',
		];
	}

	return [
		'version'  => '2.0.0',
		'page'     => [ 'title' => 'Benchmark page' ],
		// motion_list caps at 24 items per section; spread targets accordingly.
		'sections' => chunk_sections( $blocks, $motion ),
	];
}

function chunk_sections( array $blocks, array $motion ): array {
	$sections = [];
	$per      = 24;
	$count    = max( count( $blocks ), count( $motion ) );
	for ( $offset = 0, $si = 0; $offset < $count; $offset += $per, $si++ ) {
		$sections[] = [
			'id'     => 'section-' . $si,
			'role'   => 0 === $si ? 'hero' : 'features',
			'blocks' => array_slice( $blocks, $offset, $per ),
			'motion' => array_slice( $motion, $offset, $per ),
		];
	}
	return $sections;
}

$scenarios = [
	'gutenberg-1-target'    => static fn(): array|WP_Error => MotionPlanCompiler::compile( make_spec( 1 ), [ 'renderer' => 'gutenberg-fse' ] ),
	'gutenberg-10-targets'  => static fn(): array|WP_Error => MotionPlanCompiler::compile( make_spec( 10 ), [ 'renderer' => 'gutenberg-fse' ] ),
	'gutenberg-50-targets'  => static fn(): array|WP_Error => MotionPlanCompiler::compile( make_spec( 50 ), [ 'renderer' => 'gutenberg-fse' ] ),
	'v3-10-native-evidence' => static fn(): array|WP_Error => MotionPlanCompiler::compile( make_spec( 10 ), [ 'renderer' => 'elementor-v3' ] ),
	'mixed-renderer-reject' => static fn(): array|WP_Error => MotionPlanCompiler::compile( make_spec( 2 ), [ 'renderer' => 'framer' ] ),
	'drift-invalid-spec'    => static function (): array|WP_Error {
		$spec                                       = make_spec( 2 );
		$spec['sections'][0]['motion'][0]['effect'] = 'unknown-effect';
		return MotionPlanCompiler::compile( $spec, [ 'renderer' => 'gutenberg-fse' ] );
	},
];

$rows    = [];
$is_json = in_array( '--json', $argv ?? [], true );

foreach ( $scenarios as $name => $fn ) {
	$start  = microtime( true );
	$result = $fn();
	$ms     = ( microtime( true ) - $start ) * 1000;

	$json       = wp_json_encode( is_wp_error( $result ) ? [ 'error' => $result->get_error_code() ] : $result ) ?: '';
	$error_code = is_wp_error( $result ) ? $result->get_error_code() : '';

	$rows[] = [
		'scenario'         => $name,
		'wall_ms'          => round( $ms, 2 ),
		'response_bytes'   => strlen( $json ),
		'estimated_tokens' => (int) ceil( strlen( $json ) / 4 ), // declared heuristic.
		'operations'       => is_array( $result ) && ! is_wp_error( $result ) ? count( $result['operations'] ?? [] ) : 0,
		'unsupported'      => is_array( $result ) && ! is_wp_error( $result ) ? count( $result['unsupported'] ?? [] ) : 0,
		'outcome'          => $error_code ?: 'ok',
	];
}

if ( $is_json ) {
	echo wp_json_encode( [ 'rows' => $rows ] ), PHP_EOL;
	exit( 0 );
}

printf( "%-24s %9s %9s %9s %7s %7s\n", 'scenario', 'wall_ms', 'bytes', '~tokens', 'ops', 'unsup' );
foreach ( $rows as $row ) {
	printf(
		"%-24s %9.2f %9d %9d %7d %7d\n",
		$row['scenario'],
		$row['wall_ms'],
		$row['response_bytes'],
		$row['estimated_tokens'],
		$row['operations'],
		$row['unsupported']
	);
}
echo "Token estimates use a declared ~4 chars/token heuristic; per-client tokenizer counts differ.\n";
exit( 0 );
