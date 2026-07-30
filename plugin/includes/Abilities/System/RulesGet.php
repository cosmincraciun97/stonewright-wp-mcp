<?php
/**
 * Serves the shipped global rule registry to clients.
 *
 * Task start only carries a digest, because rule bodies would eat most of the
 * compact budget. This ability is the other half of that trade: an agent that
 * does not recognize the digest fetches the bodies once and caches them.
 *
 * @package Stonewright\WpMcp
 */

declare( strict_types=1 );

namespace Stonewright\WpMcp\Abilities\System;

use Stonewright\WpMcp\Abilities\AbilityKernel;
use Stonewright\WpMcp\Security\GlobalRules;
use Stonewright\WpMcp\Security\Permissions;

/**
 * Read-only access to the native rule registry.
 *
 * @stonewright-status stable
 */
final class RulesGet extends AbilityKernel {

	public function name(): string {
		return 'stonewright/rules-get';
	}

	public function label(): string {
		return __( 'Get Stonewright rules', 'stonewright' );
	}

	public function description(): string {
		return __(
			'Returns the rules Stonewright enforces for every site: what the rule is, why it exists, and whether a runtime guard blocks violations or the rule is instruction-only. Cache by digest and refetch only when task-start reports a different one.',
			'stonewright'
		);
	}

	public function category(): string {
		return 'system';
	}

	/**
	 * @return array<string, mixed>
	 */
	public function input_schema(): array {
		return [
			'type'                 => 'object',
			'additionalProperties' => false,
			'properties'           => [
				'severity'    => [
					'type'        => 'string',
					'enum'        => GlobalRules::SEVERITIES,
					'description' => __( 'Return only rules at this severity.', 'stonewright' ),
				],
				'scope'       => [
					'type'        => 'string',
					'enum'        => GlobalRules::SCOPES,
					'description' => __( 'Return rules for this scope plus the globally scoped ones.', 'stonewright' ),
				],
				'knownDigest' => [
					'type'        => 'string',
					'description' => __( 'Digest of a previously cached result. When it matches, the rule bodies are omitted.', 'stonewright' ),
				],
			],
		];
	}

	/**
	 * @return array<string, mixed>
	 */
	public function output_schema(): array {
		return [
			'type'       => 'object',
			'properties' => [
				'ok'        => [ 'type' => 'boolean' ],
				'digest'    => [
					'type'        => 'string',
					'description' => __( 'Digest of this filtered result; pass it back as knownDigest.', 'stonewright' ),
				],
				'unchanged' => [
					'type'        => 'boolean',
					'description' => __( 'True when knownDigest matched and rule bodies were omitted.', 'stonewright' ),
				],
				'count'     => [ 'type' => 'integer' ],
				'filters'   => [
					'type'       => 'object',
					'properties' => [
						'severity' => [ 'type' => [ 'string', 'null' ] ],
						'scope'    => [ 'type' => [ 'string', 'null' ] ],
					],
				],
				'rules'     => [
					'type'  => 'array',
					'items' => [
						'type'       => 'object',
						'properties' => [
							'id'          => [ 'type' => 'string' ],
							'severity'    => [ 'type' => 'string', 'enum' => GlobalRules::SEVERITIES ],
							'scope'       => [ 'type' => 'string', 'enum' => GlobalRules::SCOPES ],
							'rule'        => [ 'type' => 'string' ],
							'why'         => [ 'type' => 'string' ],
							'enforcement' => [
								'type'       => 'object',
								'properties' => [
									'kind'  => [ 'type' => 'string', 'enum' => GlobalRules::ENFORCEMENT_KINDS ],
									'guard' => [ 'type' => 'string' ],
								],
							],
						],
					],
				],
			],
			'required'   => [ 'ok', 'digest', 'unchanged', 'count', 'rules' ],
		];
	}

	/**
	 * @param array<string, mixed> $args Ability arguments.
	 * @return bool|\WP_Error
	 */
	public function permission_callback( array $args ): bool|\WP_Error {
		return Permissions::read();
	}

	/**
	 * @param array<string, mixed> $args Ability arguments.
	 * @return array<string, mixed>|\WP_Error
	 */
	public function execute( array $args ): array|\WP_Error {
		$severity = isset( $args['severity'] ) ? (string) $args['severity'] : '';
		$scope    = isset( $args['scope'] ) ? (string) $args['scope'] : '';

		if ( '' !== $severity && ! in_array( $severity, GlobalRules::SEVERITIES, true ) ) {
			return new \WP_Error(
				'stonewright_invalid_severity',
				sprintf(
					/* translators: 1: requested severity, 2: allowed severities */
					__( 'Unknown rule severity "%1$s". Allowed severities: %2$s.', 'stonewright' ),
					$severity,
					implode( ', ', GlobalRules::SEVERITIES )
				),
				[ 'status' => 400, 'retryable' => false ]
			);
		}

		if ( '' !== $scope && ! in_array( $scope, GlobalRules::SCOPES, true ) ) {
			return new \WP_Error(
				'stonewright_invalid_scope',
				sprintf(
					/* translators: 1: requested scope, 2: allowed scopes */
					__( 'Unknown rule scope "%1$s". Allowed scopes: %2$s.', 'stonewright' ),
					$scope,
					implode( ', ', GlobalRules::SCOPES )
				),
				[ 'status' => 400, 'retryable' => false ]
			);
		}

		$rules = GlobalRules::all();

		if ( '' !== $severity ) {
			$rules = array_values(
				array_filter( $rules, static fn( array $rule ): bool => $severity === $rule['severity'] )
			);
		}

		// A scoped request still needs the global rules: they apply everywhere,
		// so omitting them would understate the constraints on the caller.
		if ( '' !== $scope && 'all' !== $scope ) {
			$rules = array_values(
				array_filter(
					$rules,
					static fn( array $rule ): bool => $scope === $rule['scope'] || 'all' === $rule['scope']
				)
			);
		}

		// The digest covers the filtered set, so a client that cached only the
		// hard rules is never told "unchanged" for a different slice.
		$digest      = GlobalRules::digest_of( $rules );
		$known       = isset( $args['knownDigest'] ) ? (string) $args['knownDigest'] : '';
		$unchanged   = '' !== $known && hash_equals( $digest, $known );

		return [
			'ok'        => true,
			'digest'    => $digest,
			'unchanged' => $unchanged,
			'count'     => count( $rules ),
			'filters'   => [
				'severity' => '' === $severity ? null : $severity,
				'scope'    => '' === $scope ? null : $scope,
			],
			'rules'     => $unchanged ? [] : $rules,
		];
	}
}
