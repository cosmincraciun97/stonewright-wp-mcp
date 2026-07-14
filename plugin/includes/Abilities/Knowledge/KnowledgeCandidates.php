<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\Abilities\Knowledge;

use Stonewright\WpMcp\Abilities\AbilityKernel;
use Stonewright\WpMcp\Knowledge\Lifecycle\CandidateRepository;
use Stonewright\WpMcp\Security\Permissions;

/**
 * Lists compact knowledge candidate refs or loads one full candidate.
 *
 * @stonewright-status experimental
 */
final class KnowledgeCandidates extends AbilityKernel {

	public function name(): string {
		return 'stonewright/knowledge-candidates';
	}

	public function label(): string {
		return __( 'Read knowledge candidates', 'stonewright' );
	}

	public function description(): string {
		return __( 'Lists compact site knowledge candidates or returns one full candidate with provenance, versions, verification, expiry, and status.', 'stonewright' );
	}

	public function category(): string {
		return 'knowledge';
	}

	public function input_schema(): array {
		return [
			'type'                 => 'object',
			'additionalProperties' => false,
			'properties'           => [
				'id'           => [ 'type' => 'integer', 'minimum' => 1 ],
				'status'       => [ 'type' => 'string', 'enum' => [ 'candidate', 'verified', 'approved', 'stale', 'rejected' ] ],
				'topic'        => [ 'type' => 'string' ],
				'limit'        => [ 'type' => 'integer', 'minimum' => 1, 'maximum' => 100, 'default' => 20 ],
				'include_body' => [ 'type' => 'boolean', 'default' => false ],
			],
		];
	}

	public function output_schema(): array {
		return [
			'type'                 => 'object',
			'additionalProperties' => true,
			'properties'           => [
				'ok'         => [ 'type' => 'boolean' ],
				'candidate'  => [ 'type' => [ 'object', 'null' ] ],
				'candidates' => [ 'type' => 'array' ],
				'count'      => [ 'type' => 'integer' ],
			],
			'required'             => [ 'ok' ],
		];
	}

	public function permission_callback( array $args ): bool|\WP_Error {
		return Permissions::manage_options();
	}

	public function execute( array $args ): array|\WP_Error {
		if ( isset( $args['id'] ) ) {
			$candidate = CandidateRepository::get( (int) $args['id'] );
			return [ 'ok' => true, 'candidate' => $candidate, 'found' => null !== $candidate ];
		}

		$include_body = (bool) ( $args['include_body'] ?? false );
		$rows         = CandidateRepository::list( $args );
		if ( ! $include_body ) {
			$rows = array_map(
				static function ( array $row ): array {
					$row['fact_length']   = strlen( (string) ( $row['fact'] ?? '' ) );
					$row['recipe_length'] = strlen( (string) ( $row['recipe'] ?? '' ) );
					unset( $row['fact'], $row['recipe'], $row['verification_task_ids_json'], $row['verified_fingerprints_json'] );
					return $row;
				},
				$rows
			);
		}
		return [ 'ok' => true, 'candidates' => $rows, 'count' => count( $rows ) ];
	}
}
