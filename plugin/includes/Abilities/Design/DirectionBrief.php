<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\Abilities\Design;

use Stonewright\WpMcp\Abilities\AbilityKernel;
use Stonewright\WpMcp\Design\Direction\DesignDirectionService;
use Stonewright\WpMcp\Design\Direction\DialTranslator;
use Stonewright\WpMcp\Design\Direction\DirectionSummary;
use Stonewright\WpMcp\Security\Permissions;

/**
 * Ability: stonewright/design-direction-brief
 *
 * Returns only the active, ready direction data an Elementor compiler needs.
 * It avoids making agents re-read a large stored contract on every section.
 *
 * @stonewright-status stable
 */
final class DirectionBrief extends AbilityKernel {

	private DesignDirectionService $service;

	public function __construct( ?DesignDirectionService $service = null ) {
		$this->service = $service ?? new DesignDirectionService();
	}

	public function name(): string {
		return 'stonewright/design-direction-brief';
	}

	public function label(): string {
		return __( 'Get compact Elementor design brief', 'stonewright' );
	}

	public function description(): string {
		return __( 'Returns the active ready direction as compact tokens, translated Elementor guidance, waivers, and provenance. Read-only.', 'stonewright' );
	}

	public function category(): string {
		return 'design';
	}

	public function input_schema(): array {
		return [
			'type'                 => 'object',
			'additionalProperties' => false,
			'properties'           => [],
		];
	}

	public function output_schema(): array {
		return [
			'type'       => 'object',
			'properties' => [
				'ok'     => [ 'type' => 'boolean' ],
				'active' => [ 'type' => 'boolean' ],
				'ready'  => [ 'type' => 'boolean' ],
				'brief'  => [ 'type' => 'object' ],
			],
			'required'   => [ 'ok', 'active', 'ready', 'brief' ],
		];
	}

	public function permission_callback( array $args ): bool|\WP_Error {
		return Permissions::read();
	}

	public function execute( array $args ): array|\WP_Error {
		$record = $this->service->active();
		if ( ! is_array( $record ) ) {
			return $this->ok(
				[
					'active' => false,
					'ready'  => false,
					'brief'  => (object) [],
				]
			);
		}

		$contract  = is_array( $record['contract'] ?? null ) ? $record['contract'] : [];
		$readiness = is_array( $contract['readiness'] ?? null ) ? $contract['readiness'] : [];
		$ready     = true === ( $readiness['ready'] ?? false ) && 'ready' === ( $record['status'] ?? '' );

		return $this->ok(
			[
				'active' => true,
				'ready'  => $ready,
				'brief'  => [
					'direction'            => DirectionSummary::row( $record, (int) ( $record['id'] ?? 0 ) ),
					'tokens'               => is_array( $contract['tokens'] ?? null ) ? $contract['tokens'] : [],
					'elementor_guidance'   => DialTranslator::translate( $contract ),
					'guidance'             => is_array( $contract['guidance'] ?? null ) ? $contract['guidance'] : [],
					'waivers'              => is_array( $contract['waivers'] ?? null ) ? $contract['waivers'] : [],
					'readiness_issues'     => is_array( $readiness['issues'] ?? null ) ? $readiness['issues'] : [],
					'contract_hash'        => (string) ( $record['contract_hash'] ?? '' ),
				],
			]
		);
	}
}
