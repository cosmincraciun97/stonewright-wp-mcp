<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\Abilities\Design;

use Stonewright\WpMcp\Abilities\AbilityKernel;
use Stonewright\WpMcp\Design\Direction\DesignDirectionService;
use Stonewright\WpMcp\Design\Quality\QualityEvaluator;
use Stonewright\WpMcp\Design\Quality\QualityReportStore;
use Stonewright\WpMcp\Security\Permissions;

/**
 * Ability: stonewright/design-quality-check
 *
 * Turns measurements taken from a rendered page into a coverage-and-evidence
 * report. The agent supplies what the browser measured; this ability decides
 * what that means against the active design direction.
 *
 * There is no invented score. The result says how many checks ran, how many
 * could not run and why, and for every finding the numbers behind it. A report
 * with nothing checked comes back as `not_checked`, never as a pass, so a thin
 * browser session cannot read as a clean page.
 *
 * The ability has two halves. Evaluating supplied evidence writes nothing and
 * gates on the design read permission. Persisting the report writes a bounded
 * post meta ledger, so it gates on the design write permission, is audited, and
 * refuses when the report cannot be tied to a page and a direction revision.
 *
 * @stonewright-status stable
 */
final class QualityCheck extends AbilityKernel {

	/**
	 * Findings returned inline. The stored report keeps the full bounded set,
	 * retrievable by report id.
	 */
	public const RETURNED_FINDINGS = 20;

	private DesignDirectionService $service;

	public function __construct( ?DesignDirectionService $service = null ) {
		$this->service = $service ?? new DesignDirectionService();
	}

	public function name(): string {
		return 'stonewright/design-quality-check';
	}

	public function label(): string {
		return __( 'Check rendered design quality', 'stonewright' );
	}

	public function description(): string {
		return __( 'Evaluates measured browser evidence for a rendered page against the design direction and returns coverage, findings, and repair hints. Optionally stores a compact report on the post.', 'stonewright' );
	}

	public function category(): string {
		return 'design';
	}

	public function input_schema(): array {
		return [
			'type'                 => 'object',
			'additionalProperties' => false,
			'required'             => [ 'evidence' ],
			'properties'           => [
				'evidence' => [
					'type'        => 'object',
					'description' => 'Measurements taken from the rendered page: viewports, element boxes, resolved colors, fonts, spacing, and interaction states.',
				],
				'id'       => [
					'type'        => 'integer',
					'description' => 'Direction id to measure against. Defaults to the active direction.',
				],
				'slug'     => [
					'type'        => 'string',
					'description' => 'Direction slug to measure against. Defaults to the active direction.',
				],
				'post_id'  => [
					'type'        => 'integer',
					'description' => 'Post the evidence was captured from. Defaults to evidence.target.post_id. Required to persist.',
				],
				'persist'  => [
					'type'        => 'boolean',
					'description' => 'Store the report on the post. Requires design write permission. Defaults to false.',
				],
			],
		];
	}

	public function output_schema(): array {
		return [
			'type'       => 'object',
			'properties' => [
				'ok'                 => [ 'type' => 'boolean' ],
				'status'             => [
					'type' => 'string',
					'enum' => [ 'pass', 'warn', 'fail', 'not_checked' ],
				],
				'coverage'           => [ 'type' => 'object' ],
				'findings'           => [
					'type'  => 'array',
					'items' => [ 'type' => 'object' ],
				],
				'findings_total'     => [ 'type' => 'integer' ],
				'truncated_findings' => [ 'type' => 'integer' ],
				'direction_id'       => [ 'type' => 'integer' ],
				'direction_revision' => [ 'type' => 'integer' ],
				'direction_hash'     => [ 'type' => 'string' ],
				'render_hash'        => [ 'type' => 'string' ],
				'post_id'            => [ 'type' => 'integer' ],
				'persisted'          => [ 'type' => 'boolean' ],
				'report_id'          => [ 'type' => 'string' ],
			],
			'required'   => [ 'ok', 'status', 'coverage', 'findings', 'findings_total', 'direction_id', 'post_id', 'persisted', 'report_id' ],
		];
	}

	public function permission_callback( array $args ): bool|\WP_Error {
		return $this->persists( $args )
			? Permissions::can_manage_design()
			: Permissions::can_view_design();
	}

	public function execute( array $args ): array|\WP_Error {
		if ( ! $this->persists( $args ) ) {
			return $this->run( $args );
		}

		return $this->audit( $args, fn( array $args ): array|\WP_Error => $this->run( $args ) );
	}

	/**
	 * @param array<string, mixed> $args Ability arguments.
	 * @return array<string, mixed>|\WP_Error
	 */
	private function run( array $args ): array|\WP_Error {
		$evidence = is_array( $args['evidence'] ?? null ) ? $args['evidence'] : null;
		if ( null === $evidence ) {
			return new \WP_Error(
				'stonewright_quality_evidence_invalid',
				__( 'The evidence must be an object describing what the browser measured.', 'stonewright' ),
				[ 'status' => 400 ]
			);
		}

		$persist = $this->persists( $args );
		$record  = $this->direction( $args );
		if ( $record instanceof \WP_Error ) {
			return $record;
		}

		$contract = is_array( $record['contract'] ?? null ) ? $record['contract'] : [];
		$report   = QualityEvaluator::evaluate( $evidence, $contract );
		if ( $report instanceof \WP_Error ) {
			return $report;
		}

		$post_id     = $this->post_id( $args, $evidence );
		$render_hash = $this->render_hash( $evidence );

		/** @var list<array<string,mixed>> $findings */
		$findings  = $report['findings'];
		$report_id = '';

		if ( $persist ) {
			$stored = QualityReportStore::save(
				$post_id,
				(int) ( $record['id'] ?? 0 ),
				[
					'schema_version'     => (string) $report['schema_version'],
					'status'             => (string) $report['status'],
					'coverage'           => $report['coverage'],
					'findings'           => $findings,
					'truncated_findings' => (int) $report['truncated_findings'],
					'direction_revision' => (int) ( $record['revision'] ?? 0 ),
					'direction_hash'     => (string) ( $record['contract_hash'] ?? '' ),
					'render_hash'        => $render_hash,
				]
			);
			if ( $stored instanceof \WP_Error ) {
				return $stored;
			}

			$report_id = $stored;
		}

		return $this->ok(
			[
				'status'             => (string) $report['status'],
				'coverage'           => $report['coverage'],
				'findings'           => array_slice( $findings, 0, self::RETURNED_FINDINGS ),
				'findings_total'     => count( $findings ),
				'truncated_findings' => (int) $report['truncated_findings'],
				'direction_id'       => (int) ( $record['id'] ?? 0 ),
				'direction_revision' => (int) ( $record['revision'] ?? 0 ),
				'direction_hash'     => (string) ( $record['contract_hash'] ?? '' ),
				'render_hash'        => $render_hash,
				'post_id'            => $post_id,
				'persisted'          => $persist,
				'report_id'          => $report_id,
			]
		);
	}

	/**
	 * Resolves the direction the evidence is measured against.
	 *
	 * Evaluation without a direction is allowed and honest: the rules that
	 * compare a render against direction tokens report as not_checked rather
	 * than guessing a scale. Persisting without one is refused, because a stored
	 * report that names no revision cannot be verified later.
	 *
	 * @param array<string, mixed> $args Ability arguments.
	 * @return array<string, mixed>|\WP_Error
	 */
	private function direction( array $args ) {
		$id   = isset( $args['id'] ) ? (int) $args['id'] : 0;
		$slug = isset( $args['slug'] ) && is_string( $args['slug'] ) ? trim( $args['slug'] ) : '';

		$record = null;
		if ( $id > 0 ) {
			$record = $this->service->get( $id );
		} elseif ( '' !== $slug ) {
			$record = $this->service->find_by_slug( $slug );
		} else {
			$record = $this->service->active();
		}

		if ( null === $record ) {
			if ( $this->persists( $args ) || $id > 0 || '' !== $slug ) {
				return new \WP_Error(
					'stonewright_direction_not_found',
					__( 'No design direction was found for this check. A stored report must name the direction revision it was measured against.', 'stonewright' ),
					[ 'status' => 404 ]
				);
			}

			return [];
		}

		return $record;
	}

	/**
	 * @param array<string, mixed> $args     Ability arguments.
	 * @param array<string, mixed> $evidence Untrusted evidence payload.
	 */
	private function post_id( array $args, array $evidence ): int {
		$explicit = isset( $args['post_id'] ) ? (int) $args['post_id'] : 0;
		if ( $explicit > 0 ) {
			return $explicit;
		}

		$target = is_array( $evidence['target'] ?? null ) ? $evidence['target'] : [];

		return isset( $target['post_id'] ) ? (int) $target['post_id'] : 0;
	}

	/**
	 * @param array<string, mixed> $evidence Untrusted evidence payload.
	 */
	private function render_hash( array $evidence ): string {
		$target = is_array( $evidence['target'] ?? null ) ? $evidence['target'] : [];
		$hash   = isset( $target['render_hash'] ) && is_string( $target['render_hash'] ) ? $target['render_hash'] : '';

		return 1 === preg_match( '/^[0-9a-f]{64}$/D', $hash ) ? $hash : '';
	}

	/**
	 * @param array<string, mixed> $args Ability arguments.
	 */
	private function persists( array $args ): bool {
		return isset( $args['persist'] ) && true === filter_var( $args['persist'], FILTER_VALIDATE_BOOLEAN );
	}
}
