<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\Abilities\Expertise;

use Stonewright\WpMcp\Abilities\AbilityKernel;
use Stonewright\WpMcp\Expertise\ExpertiseRegistry;
use Stonewright\WpMcp\Security\Permissions;

/** @stonewright-status experimental */
final class ExpertiseGet extends AbilityKernel {

	public function name(): string {
		return 'stonewright/expertise-get';
	}

	public function label(): string {
		return __( 'Get expertise pack', 'stonewright' );
	}

	public function description(): string {
		return __( 'Loads one compatible expertise pack section on demand. A known matching hash returns only a compact unchanged ref.', 'stonewright' );
	}

	public function category(): string {
		return 'expertise';
	}

	public function input_schema(): array {
		return [
			'type'                 => 'object',
			'additionalProperties' => false,
			'required'             => [ 'id' ],
			'properties'           => [
				'id'         => [ 'type' => 'string' ],
				'section'    => [ 'type' => 'string', 'enum' => [ 'summary', 'body', 'references', 'recipes', 'evals' ], 'default' => 'body' ],
				'known_hash' => [ 'type' => 'string', 'pattern' => '^[a-f0-9]{64}$' ],
			],
		];
	}

	public function output_schema(): array {
		return [ 'type' => 'object', 'additionalProperties' => true, 'required' => [ 'ok', 'id', 'hash', 'unchanged' ], 'properties' => [ 'ok' => [ 'type' => 'boolean' ], 'id' => [ 'type' => 'string' ], 'hash' => [ 'type' => 'string' ], 'unchanged' => [ 'type' => 'boolean' ], 'pack' => [ 'type' => [ 'object', 'null' ] ] ] ];
	}

	public function permission_callback( array $args ): bool|\WP_Error {
		return Permissions::read();
	}

	public function execute( array $args ): array|\WP_Error {
		$pack = ExpertiseRegistry::get( (string) ( $args['id'] ?? '' ) );
		if ( null === $pack || in_array( (string) $pack['status'], [ 'stale', 'retired' ], true ) ) {
			return new \WP_Error( 'stonewright_expertise_unavailable', 'Expertise pack is missing, stale, or retired.' );
		}
		$hash = (string) $pack['hash'];
		if ( '' !== (string) ( $args['known_hash'] ?? '' ) && hash_equals( $hash, (string) $args['known_hash'] ) ) {
			return [ 'ok' => true, 'id' => (string) $pack['id'], 'hash' => $hash, 'unchanged' => true, 'pack' => null ];
		}
		$section = (string) ( $args['section'] ?? 'body' );
		$payload = match ( $section ) {
			'summary'    => array_intersect_key( $pack, array_flip( [ 'id', 'domain', 'capability', 'version', 'status', 'tier', 'trigger', 'supported_versions', 'required_capabilities', 'hash' ] ) ),
			'references' => [ 'official_refs' => $pack['official_refs'], 'schema_refs' => $pack['schema_refs'], 'provenance' => $pack['provenance'] ],
			'recipes'    => [ 'recipes' => $pack['recipes'], 'failure_modes' => $pack['failure_modes'] ],
			'evals'      => [ 'eval_cases' => $pack['eval_cases'] ],
			default      => array_intersect_key( $pack, array_flip( [ 'id', 'domain', 'version', 'status', 'workflow', 'semantic_rules', 'anti_hallucination_gates', 'write_gates', 'dependencies', 'conflicts', 'hash' ] ) ),
		};
		return [ 'ok' => true, 'id' => (string) $pack['id'], 'hash' => $hash, 'unchanged' => false, 'section' => $section, 'pack' => $payload ];
	}
}
