<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\Abilities\QA;

use Stonewright\WpMcp\Abilities\AbilityKernel;
use Stonewright\WpMcp\Companion\CompanionContract;
use Stonewright\WpMcp\QA\QaArtifactStore;
use Stonewright\WpMcp\Security\Permissions;
use Stonewright\WpMcp\Support\CompanionClient;

/**
 * Run a Lighthouse audit via the companion service.
 * Contract decision: lighthouse is optional — when available=false the companion
 * returns { available: false } and PHP surfaces WP_Error('stonewright_companion_unavailable').
 *
 * @stonewright-status stable
 */
final class Lighthouse extends AbilityKernel {

	public function name(): string {
		return 'stonewright/qa-lighthouse';
	}

	public function label(): string {
		return __( 'QA: Lighthouse Audit', 'stonewright' );
	}

	public function description(): string {
		return __( 'Runs a Lighthouse audit against a URL via the companion service and returns category scores.', 'stonewright' );
	}

	public function category(): string {
		return 'qa';
	}

	public function input_schema(): array {
		return [
			'type'                 => 'object',
			'additionalProperties' => false,
			'properties'           => [
				'url'        => [ 'type' => 'string', 'format' => 'uri' ],
				'categories' => [
					'type'    => 'array',
					'items'   => [ 'type' => 'string' ],
					'default' => [ 'performance', 'accessibility', 'best-practices', 'seo' ],
				],
			],
			'required'             => [ 'url' ],
		];
	}

	public function output_schema(): array {
		return [
			'type'       => 'object',
			'properties' => [
				'ok'            => [ 'type' => 'boolean' ],
				'scores'        => [
					'type'       => 'object',
					'properties' => [
						'perf' => [ 'type' => 'number' ],
						'a11y' => [ 'type' => 'number' ],
						'bp'   => [ 'type' => 'number' ],
						'seo'  => [ 'type' => 'number' ],
					],
				],
				'report_url'    => [ 'type' => 'string' ],
				'audits_failed' => [ 'type' => 'array', 'items' => [ 'type' => 'string' ] ],
			],
			'required'   => [ 'ok', 'scores', 'report_url', 'audits_failed' ],
		];
	}

	public function permission_callback( array $args ): bool|\WP_Error {
		return Permissions::edit_posts();
	}

	public function execute( array $args ): array|\WP_Error {
		return $this->audit(
			$args,
			function ( array $args ) {
				$request_id    = wp_generate_uuid4();
				$artifact_path = QaArtifactStore::reserve( $request_id );

				$body = [
					'request_id'    => $request_id,
					'url'           => $args['url'],
					'categories'    => $args['categories'] ?? [ 'performance', 'accessibility', 'best-practices', 'seo' ],
					'artifact_path' => rtrim( $artifact_path, '/' ),
				];

				// Validate request payload before sending
				$req_check = CompanionContract::validate( 'lighthouse', 'request', $body );
				if ( is_wp_error( $req_check ) ) {
					return $req_check;
				}

				$result = CompanionClient::post( '/lighthouse', $body );
				if ( is_wp_error( $result ) ) {
					return $result;
				}

				// Validate response payload after parsing
				$resp_check = CompanionContract::validate( 'lighthouse', 'response', $result );
				if ( is_wp_error( $resp_check ) ) {
					return $resp_check;
				}

				// Graceful degradation when lighthouse binary is not installed
				if ( false === ( $result['available'] ?? true ) ) {
					return new \WP_Error(
						'stonewright_companion_unavailable',
						__( 'Lighthouse is not installed on the companion host.', 'stonewright' ),
						[ 'status' => 503, 'endpoint' => 'lighthouse' ]
					);
				}

				$scores = $result['scores'] ?? [];

				return $this->ok( [
					'scores'        => [
						'perf' => $scores['performance'] ?? null,
						'a11y' => $scores['accessibility'] ?? null,
						'bp'   => $scores['best-practices'] ?? null,
						'seo'  => $scores['seo'] ?? null,
					],
					'report_url'    => QaArtifactStore::url_for( (string) ( $result['report_url'] ?? '' ) ) ?: ( $result['report_url'] ?? '' ),
					'audits_failed' => $result['audits_failed'] ?? [],
				] );
			}
		);
	}
}
