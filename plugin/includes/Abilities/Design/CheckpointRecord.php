<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\Abilities\Design;

use Stonewright\WpMcp\Abilities\AbilityKernel;
use Stonewright\WpMcp\Design\Direction\DesignDirectionService;
use Stonewright\WpMcp\Design\Quality\QualityReportStore;
use Stonewright\WpMcp\Design\Workflow\DesignCheckpoint;
use Stonewright\WpMcp\Security\Permissions;
use Stonewright\WpMcp\Support\ElementorData;

/**
 * Ability: stonewright/design-checkpoint-record
 *
 * Records that a user approved the first rendered section of a build that
 * establishes a new visual direction, and returns the continuation token later
 * section writes are checked against.
 *
 * The approval is a claim about a specific render, so this ability refuses to
 * record one it cannot tie to real state: the section has to exist in the
 * document as it stands right now, and a design direction has to be in force.
 * The token it returns describes what was on the page at this moment, which is
 * why editing the approved section afterwards stops it working.
 *
 * `approved` has to be sent as `true` explicitly. There is no default, because
 * the point of the checkpoint is that a human said yes; an omitted flag is not
 * a yes, and neither is a truthy string.
 *
 * @stonewright-status stable
 */
final class CheckpointRecord extends AbilityKernel {

	private DesignDirectionService $service;

	public function __construct( ?DesignDirectionService $service = null ) {
		$this->service = $service ?? new DesignDirectionService();
	}

	public function name(): string {
		return 'stonewright/design-checkpoint-record';
	}

	public function label(): string {
		return __( 'Record design checkpoint approval', 'stonewright' );
	}

	public function description(): string {
		return __( 'Records explicit user approval of the first rendered section and returns a checkpoint token that unblocks the remaining section writes for that page.', 'stonewright' );
	}

	public function category(): string {
		return 'design';
	}

	public function input_schema(): array {
		return [
			'type'                 => 'object',
			'additionalProperties' => false,
			'required'             => [ 'post_id', 'section_id', 'approved' ],
			'properties'           => [
				'post_id'    => [
					'type'        => 'integer',
					'description' => 'Post whose first section the user approved.',
				],
				'section_id' => [
					'type'        => 'string',
					'description' => 'Element id of the approved top-level section, as written to the document.',
				],
				'approved'   => [
					'type'        => 'boolean',
					'description' => 'Must be true. Records that the user saw the rendered evidence and approved it.',
				],
				'report_id'  => [
					'type'        => 'string',
					'description' => 'Optional quality report id stored for this post, linking the approval to the evidence shown.',
				],
				'id'         => [
					'type'        => 'integer',
					'description' => 'Direction id the section was built against. Defaults to the active direction.',
				],
				'slug'       => [
					'type'        => 'string',
					'description' => 'Direction slug the section was built against. Defaults to the active direction.',
				],
				'notes'      => [
					'type'        => 'string',
					'description' => 'Optional short note about what the user approved.',
				],
			],
		];
	}

	public function output_schema(): array {
		return [
			'type'       => 'object',
			'properties' => [
				'ok'               => [ 'type' => 'boolean' ],
				'checkpoint_token' => [ 'type' => 'string' ],
				'post_id'          => [ 'type' => 'integer' ],
				'section_id'       => [ 'type' => 'string' ],
				'direction_id'     => [ 'type' => 'integer' ],
				'direction_hash'   => [ 'type' => 'string' ],
				'render_hash'      => [ 'type' => 'string' ],
				'report_id'        => [ 'type' => 'string' ],
				'approved_by'      => [ 'type' => 'integer' ],
				'approved_at'      => [ 'type' => 'string' ],
				'expires_at'       => [ 'type' => 'string' ],
				'expires_in'       => [ 'type' => 'integer' ],
				'next_step'        => [ 'type' => 'string' ],
			],
			'required'   => [ 'ok', 'checkpoint_token', 'post_id', 'section_id', 'direction_id', 'direction_hash', 'render_hash', 'approved_by', 'expires_at' ],
		];
	}

	public function permission_callback( array $args ): bool|\WP_Error {
		$post_id = isset( $args['post_id'] ) ? (int) $args['post_id'] : 0;

		if ( ! Permissions::can_manage_design() ) {
			return new \WP_Error(
				'stonewright_forbidden',
				__( 'Recording a design checkpoint requires design management permission.', 'stonewright' ),
				[ 'status' => 403 ]
			);
		}

		if ( ! Permissions::edit_post( $post_id ) ) {
			return new \WP_Error(
				'stonewright_forbidden',
				__( 'You cannot edit that post, so you cannot approve its first section.', 'stonewright' ),
				[ 'status' => 403 ]
			);
		}

		return true;
	}

	public function execute( array $args ): array|\WP_Error {
		return $this->audit( $args, fn( array $args ): array|\WP_Error => $this->run( $args ) );
	}

	/**
	 * @param array<string, mixed> $args Ability arguments.
	 * @return array<string, mixed>|\WP_Error
	 */
	private function run( array $args ): array|\WP_Error {
		if ( true !== ( $args['approved'] ?? null ) ) {
			return new \WP_Error(
				'stonewright_design_checkpoint_not_approved',
				__( 'A checkpoint records a human decision, so approved must be sent as true. Show the rendered evidence, ask the user, and record the answer they gave.', 'stonewright' ),
				[ 'status' => 400 ]
			);
		}

		$post_id = isset( $args['post_id'] ) ? (int) $args['post_id'] : 0;
		if ( $post_id <= 0 || null === get_post( $post_id ) ) {
			return new \WP_Error(
				'stonewright_post_not_found',
				sprintf(
					/* translators: %d: post id */
					__( 'Post %d does not exist, so its first section cannot be approved.', 'stonewright' ),
					$post_id
				),
				[ 'status' => 404 ]
			);
		}

		$section_id = isset( $args['section_id'] ) && is_string( $args['section_id'] ) ? trim( $args['section_id'] ) : '';
		if ( '' === $section_id ) {
			return new \WP_Error(
				'stonewright_design_checkpoint_section_missing',
				__( 'The approval must name the section it covers.', 'stonewright' ),
				[ 'status' => 400 ]
			);
		}

		$render_hash = DesignCheckpoint::section_render_hash( ElementorData::read( $post_id ), $section_id );
		if ( '' === $render_hash ) {
			return new \WP_Error(
				'stonewright_design_checkpoint_section_missing',
				sprintf(
					/* translators: 1: section element id, 2: post id */
					__( 'Section %1$s is not in post %2$d as it stands now. Approve a section that exists in the document the user was shown.', 'stonewright' ),
					$section_id,
					$post_id
				),
				[ 'status' => 404 ]
			);
		}

		$record = $this->direction( $args );
		if ( $record instanceof \WP_Error ) {
			return $record;
		}

		$report_id = isset( $args['report_id'] ) && is_string( $args['report_id'] ) ? trim( $args['report_id'] ) : '';
		if ( '' !== $report_id && null === QualityReportStore::find( $post_id, $report_id ) ) {
			return new \WP_Error(
				'stonewright_quality_report_not_found',
				sprintf(
					/* translators: 1: report id, 2: post id */
					__( 'Quality report %1$s is not stored for post %2$d, so the approval cannot cite it as evidence.', 'stonewright' ),
					$report_id,
					$post_id
				),
				[ 'status' => 404 ]
			);
		}

		$direction_hash = (string) ( $record['contract_hash'] ?? '' );
		$issued         = DesignCheckpoint::issue( $post_id, $section_id, $direction_hash, $render_hash );

		return $this->ok(
			[
				'checkpoint_token' => (string) $issued['token'],
				'post_id'          => $post_id,
				'section_id'       => $section_id,
				'direction_id'     => (int) ( $record['id'] ?? 0 ),
				'direction_hash'   => $direction_hash,
				'render_hash'      => $render_hash,
				'report_id'        => $report_id,
				'approved_by'      => (int) $issued['approved_by'],
				'approved_at'      => (string) $issued['approved_at'],
				'expires_at'       => (string) $issued['expires_at'],
				'expires_in'       => (int) $issued['expires_in'],
				'next_step'        => __( 'Pass checkpoint_token to every remaining section write for this post. Editing the approved section or the design direction invalidates it.', 'stonewright' ),
			]
		);
	}

	/**
	 * Resolves the direction the approved section was built against.
	 *
	 * A checkpoint without a direction revision cannot be checked later, so this
	 * refuses rather than approving against nothing.
	 *
	 * @param array<string, mixed> $args Ability arguments.
	 * @return array<string, mixed>|\WP_Error
	 */
	private function direction( array $args ): array|\WP_Error {
		$id   = isset( $args['id'] ) ? (int) $args['id'] : 0;
		$slug = isset( $args['slug'] ) && is_string( $args['slug'] ) ? trim( $args['slug'] ) : '';

		if ( $id > 0 ) {
			$record = $this->service->get( $id );
		} elseif ( '' !== $slug ) {
			$record = $this->service->find_by_slug( $slug );
		} else {
			$record = $this->service->active();
		}

		if ( null === $record ) {
			return new \WP_Error(
				'stonewright_direction_not_found',
				__( 'No design direction was found for this approval. A checkpoint must name the direction revision the user approved.', 'stonewright' ),
				[ 'status' => 404 ]
			);
		}

		return $record;
	}

	/**
	 * @param array<string, mixed>           $args   Ability arguments.
	 * @param array<string, mixed>|\WP_Error $result Ability result.
	 * @return array<string, scalar|null>
	 */
	protected function audit_metadata( array $args, array|\WP_Error $result, int $elapsed_ms ): array {
		$metadata = [
			'elapsed_ms'  => $elapsed_ms,
			'approved_by' => get_current_user_id(),
		];

		if ( is_array( $result ) ) {
			$metadata['section_id']     = (string) ( $result['section_id'] ?? '' );
			$metadata['direction_id']   = (int) ( $result['direction_id'] ?? 0 );
			$metadata['render_hash']    = (string) ( $result['render_hash'] ?? '' );
			$metadata['report_id']      = (string) ( $result['report_id'] ?? '' );
			$metadata['expires_at']     = (string) ( $result['expires_at'] ?? '' );
			$metadata['operation_class'] = 'design_checkpoint_approval';
		}

		return $metadata;
	}
}
