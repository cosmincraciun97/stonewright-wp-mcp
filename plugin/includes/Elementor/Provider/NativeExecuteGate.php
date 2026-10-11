<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\Elementor\Provider;

/**
 * Availability gate of stonewright-elementor-native-execute.
 *
 * The tool is available when Elementor's native MCP is available (the `native_elementor` state is
 * `available`) and at least one ability passed certification. It does not depend on the experimental
 * V4 writer flag: the certified abilities carry their own contract, and each write still runs inside
 * the snapshot, lock, readback, rollback and audit closure. Whether a particular ability may run is
 * decided later, per ability, by {@see NativeRoute}.
 */
final class NativeExecuteGate {

	/**
	 * @param array<string,mixed> $report The `native_elementor` report.
	 */
	public static function check( array $report ): bool|\WP_Error {
		$state     = (string) ( $report['state'] ?? 'not_installed' );
		$certified = array_values( (array) ( $report['certified'] ?? [] ) );
		if ( 'available' === $state && [] !== $certified ) {
			return true;
		}
		$issues = [];
		foreach ( (array) ( $report['certification'] ?? [] ) as $entry ) {
			foreach ( (array) ( is_array( $entry ) ? ( $entry['issues'] ?? [] ) : [] ) as $issue ) {
				$issues[ (string) $issue ] = true;
			}
		}
		$guidance = (string) ( $report['guidance'] ?? '' );
		$message  = '' !== $guidance
			? $guidance
			: sprintf(
				/* translators: %s: native Elementor state token. */
				__( 'Elementor\'s native MCP abilities are not available on this site (state: %s), so this tool cannot run. Use the Stonewright V4 writers for V4 documents and the V3 writers for V3, and do not call elementor/* tools on Elementor\'s own MCP server.', 'stonewright' ),
				$state
			);
		return new \WP_Error(
			'stonewright_native_unavailable',
			$message,
			[
				'status'        => 409,
				'state'         => $state,
				'issues'        => array_slice( array_keys( $issues ), 0, 20 ),
				'version_issue' => is_array( $report['version_issue'] ?? null ) ? $report['version_issue'] : null,
				'fallback'      => NativeRoute::all_fallbacks(),
			]
		);
	}
}
