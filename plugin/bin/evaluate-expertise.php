<?php
/**
 * Reproducible P0 expertise curriculum evaluation for CI and maintainers.
 *
 * @package Stonewright\WpMcp
 */

declare( strict_types=1 );

require_once dirname( __DIR__ ) . '/tests/bootstrap.php';

use Stonewright\WpMcp\Expertise\ExpertiseEvaluator;

$report = ExpertiseEvaluator::evaluate_all( false );
$failed     = [];
$unverified = [];
foreach ( $report['packs'] as $pack ) {
	if ( in_array( (string) $pack['pack_status'], [ 'candidate', 'verified', 'stable' ], true ) && ! (bool) $pack['implementation_verified'] ) {
		$unverified[] = (string) $pack['pack_id'];
	}
	if ( ! in_array( (string) $pack['pack_status'], [ 'candidate', 'verified', 'stable' ], true ) ) {
		continue;
	}
	if ( (float) $pack['score'] < 90 || (int) $pack['critical_failures'] > 0 ) {
		$failed[] = (string) $pack['pack_id'];
	}
}
$report['gate_failed_packs'] = $failed;
$report['runtime_evidence_pending'] = $unverified;
$report['gate_passed']       = [] === $failed;
echo wp_json_encode( $report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) . PHP_EOL; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
exit( [] === $failed ? 0 : 1 );
