<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\Abilities\ElementorV3;

use Stonewright\WpMcp\Abilities\AbilityKernel;
use Stonewright\WpMcp\Abilities\Common\ConfirmationGuard;
use Stonewright\WpMcp\Design\Direction\DesignDirectionService;
use Stonewright\WpMcp\Design\Evidence\Validator as DesignEvidenceValidator;
use Stonewright\WpMcp\Design\Workflow\DesignCheckpoint;
use Stonewright\WpMcp\DesignSpec\Validator;
use Stonewright\WpMcp\Elementor\Renderer;
use Stonewright\WpMcp\Elementor\SpecSectionRecord;
use Stonewright\WpMcp\Elementor\V4\AtomicTreeInspector;
use Stonewright\WpMcp\Elementor\Write\TreeHasher;
use Stonewright\WpMcp\Security\Backup;
use Stonewright\WpMcp\Security\Permissions;
use Stonewright\WpMcp\Security\RemediationHints;
use Stonewright\WpMcp\Support\ElementorData;

/**
 * Contract decision: keep output_schema aligned to the handler response shape.
 *
 * @stonewright-status stable
 */
final class BuildPageFromSpec extends AbilityKernel {
	use ConfirmationGuard;

	private DesignDirectionService $directions;

	private bool $token_already_verified = false;

	public function __construct( ?DesignDirectionService $directions = null ) {
		$this->directions = $directions ?? new DesignDirectionService();
	}

	public function name(): string {
		return 'stonewright/elementor-v3-build-page-from-spec';
	}

	public function label(): string {
		return __( 'Build Elementor page from Stonewright spec', 'stonewright' );
	}

	public function description(): string {
		return __( 'Renders a validated Stonewright Design Spec into Elementor V3 elements and writes it to a post.', 'stonewright' );
	}

	public function category(): string {
		return 'elementor';
	}

	public function input_schema(): array {
		return [
			'type'                 => 'object',
			'additionalProperties' => false,
			'properties'           => [
				'post_id' => [ 'type' => 'integer', 'minimum' => 1 ],
				'spec'               => [ 'type' => 'object' ],
				'design_evidence'    => [ 'type' => 'object', 'description' => 'Required when spec style_policy is strict.' ],
				'replace'            => [ 'type' => 'boolean', 'default' => true ],
				'mode'               => [
					'type'        => 'string',
					'enum'        => [ 'replace', 'append', 'replace_section' ],
					'description' => 'replace rebuilds the page; append adds the sections after the existing ones; replace_section replaces the container recorded for each spec section id and needs an id on every section. replace_section writes nothing and returns an error when the page has no section record, or when an id matches no container or more than one.',
				],
				'expected_tree_hash' => [ 'type' => 'string', 'pattern' => '^[a-f0-9]{64}$' ],
				'dry_run'            => [ 'type' => 'boolean', 'default' => false ],
				'confirmation_token' => [ 'type' => 'string', 'description' => 'Required in production-safe mode for every write that is not a dry run, in every mode. Issue it with stonewright-security-issue-confirmation-token for this ability and these arguments.' ],
				'design_scope'       => [
					'type'        => 'string',
					'enum'        => DesignCheckpoint::scopes(),
					'description' => 'What this build does to the design. Scopes that establish a new visual direction (new_identity, replacement, rebrand) may write one section, then need a checkpoint token. Maintenance scopes (preserve, repair, content_only, responsive_fix) are never gated. Defaults to preserve.',
				],
				'checkpoint_token'   => [
					'type'        => 'string',
					'description' => 'Token from stonewright/design-checkpoint-record proving the user approved the first rendered section. Required to write the remaining sections of a new visual direction.',
				],
			],
			'required'             => [ 'post_id', 'spec' ],
		];
	}

	public function output_schema(): array {
		return [
			'type'       => 'object',
			'properties' => [
				'post_id'     => [ 'type' => 'integer' ],
				'snapshot_id' => [ 'type' => 'string' ],
				'elements'      => [ 'type' => 'integer' ],
				'element_count' => [ 'type' => 'integer' ],
				'diagnostics'   => [ 'type' => 'array' ],
				'dry_run'       => [ 'type' => 'boolean' ],
				'mode'          => [ 'type' => 'string' ],
				'metrics'       => [ 'type' => 'object' ],
				'preview'       => [ 'type' => 'array' ],
				'before_hash'   => [ 'type' => 'string' ],
				'after_hash'    => [ 'type' => 'string' ],
				'readback_hash' => [ 'type' => 'string' ],
				'evidence_hash' => [ 'type' => 'string' ],
				'write_receipt' => [ 'type' => 'object' ],
			],
		];
	}

	public function permission_callback( array $args ): bool|\WP_Error {
		$id = (int) ( $args['post_id'] ?? 0 );
		return Permissions::edit_post( $id );
	}

	/**
	 * Runs a write for a caller that has already verified a confirmation token for the
	 * request it serves. The token gate of this ability is skipped; every other gate applies.
	 *
	 * @param array<string, mixed> $args
	 * @return array<string, mixed>|\WP_Error
	 */
	public function execute_with_verified_token( array $args ): array|\WP_Error {
		unset( $args['confirmation_token'] );
		$this->token_already_verified = true;
		try {
			return $this->execute( $args );
		} finally {
			$this->token_already_verified = false;
		}
	}

	public function execute( array $args ): array|\WP_Error {
		return $this->audit(
			$args,
			function ( array $args ) {
				$started_at = microtime( true );
				$post_id = (int) $args['post_id'];
				if ( ! get_post( $post_id ) ) {
					return $this->error( 'not_found', __( 'Post not found.', 'stonewright' ) );
				}

				$validate_started_at = microtime( true );
				$normalized = Validator::validate( (array) $args['spec'] );
				$validate_ms = self::elapsed_ms( $validate_started_at );
				if ( is_wp_error( $normalized ) ) {
					return $normalized;
				}
				$style_policy = (string) ( $normalized['style_policy'] ?? ( $normalized['meta']['style_policy'] ?? '' ) );
				$evidence_hash = '';
				if ( 'strict' === $style_policy ) {
					$evidence = isset( $args['design_evidence'] ) && is_array( $args['design_evidence'] ) ? $args['design_evidence'] : [];
					$validated_evidence = DesignEvidenceValidator::validate( $evidence );
					if ( $validated_evidence instanceof \WP_Error ) {
						return $validated_evidence;
					}
					$evidence_hash = (string) $validated_evidence['evidence_hash'];
				}

				$dry_run = ! empty( $args['dry_run'] );
				$mode    = self::write_mode( $args );
				// In production-safe every write that is not a dry run needs a token bound to its arguments, whatever the mode.
				if ( ! $dry_run && ! $this->token_already_verified ) {
					$verify_args = array_filter(
						$args,
						static fn( string $key ): bool => 'confirmation_token' !== $key,
						ARRAY_FILTER_USE_KEY
					);
					$token_error = $this->confirmation_token_error( $args, $verify_args );
					if ( null !== $token_error ) {
						return $token_error;
					}
				}

				$diagnostics       = [];
				$render_started_at = microtime( true );
				$rendered          = Renderer::render( $normalized, $diagnostics );
				$render_ms         = self::elapsed_ms( $render_started_at );
				$existing     = ElementorData::read( $post_id );
				$before_hash  = TreeHasher::hash( $existing );
				$architecture = (string) ( AtomicTreeInspector::inspect( $existing )['architecture'] ?? 'empty' );
				if ( in_array( $architecture, [ 'v4', 'mixed' ], true ) ) {
					return $this->error(
						'v3_architecture_mismatch',
						__( 'This document contains Elementor V4 Atomic nodes. V3 rendering is blocked to prevent a mixed tree.', 'stonewright' ),
						[
							'status'       => 409,
							'architecture' => $architecture,
							'before_hash'  => $before_hash,
							'repair'       => RemediationHints::for_code( 'stonewright_v3_architecture_mismatch', $this->name() ),
						]
					);
				}
				$expected_hash = isset( $args['expected_tree_hash'] ) ? (string) $args['expected_tree_hash'] : '';
				if ( '' !== $expected_hash && ! hash_equals( $expected_hash, $before_hash ) ) {
					return $this->error( 'tree_conflict', __( 'Elementor page changed after planning; refresh structure before writing.', 'stonewright' ), [ 'status' => 409, 'expected_tree_hash' => $expected_hash, 'current_tree_hash' => $before_hash ] );
				}
				$plan = $this->plan_merge( $post_id, $existing, $rendered, $mode, self::explicit_section_ids( (array) $args['spec'] ) );
				if ( $plan instanceof \WP_Error ) {
					return $plan;
				}
				$tree       = $plan['tree'];
				$after_hash = TreeHasher::hash( $tree );

				// The write runs these checks too; running them here gives a dry run
				// and an apply the same answer before anything is snapshotted.
				$preflight = ElementorData::preflight( $existing, $tree, [ 'force_destructive' => true ] );
				if ( $preflight instanceof \WP_Error ) {
					return $preflight;
				}

				if ( $dry_run ) {
					$element_count = count( ElementorData::flatten( $tree ) );
					return [
						'post_id'       => $post_id,
						'snapshot_id'   => '',
						'elements'      => $element_count,
						'element_count' => $element_count,
						'diagnostics'   => $diagnostics,
						'dry_run'       => true,
						'mode'          => $mode,
						'metrics'       => [
							'elapsed_ms'  => self::elapsed_ms( $started_at ),
							'validate_ms' => $validate_ms,
							'render_ms'   => $render_ms,
							'write_ms'    => 0.0,
						],
						'preview'       => $tree,
						'before_hash'   => $before_hash,
						'after_hash'    => $after_hash,
						'readback_hash' => $after_hash,
						'evidence_hash' => $evidence_hash,
					];
				}

				$checkpoint_error = $this->checkpoint_error( $args, $post_id, $tree, $existing );
				if ( null !== $checkpoint_error ) {
					return $checkpoint_error;
				}

				// Backup before any mutation (AGENTS.md hard rule #3).
				$snapshot_id = Backup::snapshot_post( $post_id );
				if ( '' === $snapshot_id ) {
					return $this->backup_failed_error();
				}

				$write_started_at = microtime( true );
				// Full-page builds can legitimately shrink the previous document after snapshot.
				if ( ! ElementorData::write( $post_id, $tree, [ 'force_destructive' => true ] ) ) {
					$error = ElementorData::write_error_for_ability();
					// A refused concurrent write stored nothing; restoring the snapshot
					// there could overwrite what the other writer saved.
					if ( 'stonewright_elementor_write_busy' === $error->get_error_code() ) {
						return $error;
					}
					$data             = $error->get_error_data();
					$data             = is_array( $data ) ? $data : [];
					$data['restored'] = Backup::restore( $post_id, $snapshot_id );
					$error->add_data( $data );
					return $error;
				}
				$write_ms = self::elapsed_ms( $write_started_at );
				$readback_hash = TreeHasher::hash( ElementorData::read( $post_id ) );
				if ( ! hash_equals( $after_hash, $readback_hash ) ) {
					$restored = Backup::restore( $post_id, $snapshot_id );
					return $this->error( 'readback_mismatch', __( 'Elementor write readback differed from the compiled tree; the snapshot was restored.', 'stonewright' ), [ 'status' => 500, 'expected_hash' => $after_hash, 'readback_hash' => $readback_hash, 'restored' => $restored ] );
				}

				// The write is stored and read back; only now is the section record updated.
				if ( null !== $plan['record'] && ! SpecSectionRecord::store( $post_id, $plan['record'] ) ) {
					$diagnostics[] = [
						'code'    => 'spec_section_record_not_stored',
						'message' => 'The page was written, but the record of which container belongs to which spec section could not be stored; replace_section will not find these sections until the page is rebuilt with mode replace.',
					];
				}

				$element_count = count( ElementorData::flatten( $tree ) );
				return [
					'post_id'       => $post_id,
					'snapshot_id'   => $snapshot_id,
					'elements'      => $element_count,
					'element_count' => $element_count,
					'diagnostics'   => $diagnostics,
					'dry_run'       => false,
					'mode'          => $mode,
					'metrics'       => [
						'elapsed_ms'  => self::elapsed_ms( $started_at ),
						'validate_ms' => $validate_ms,
						'render_ms'   => $render_ms,
						'write_ms'    => $write_ms,
					],
					'before_hash'   => $before_hash,
					'after_hash'    => $after_hash,
					'readback_hash' => $readback_hash,
					'evidence_hash' => $evidence_hash,
				];
			}
		);
	}

	/**
	 * Stops a build that is inventing a look from writing a whole page before the
	 * user has seen one section of it.
	 *
	 * The gate reads the caller's declared `design_scope`. Maintenance work is not
	 * gated at all, so repairs, copy fixes, and responsive corrections keep working
	 * exactly as before. A gated scope may write the document down to a single
	 * top-level section; going beyond that needs a token proving the user approved
	 * what the first section looked like.
	 *
	 * The approved section is re-hashed from the stored document, not from the tree
	 * about to be written, because the question the token answers is whether what
	 * the user approved is still what is on the page.
	 *
	 * @param array<string, mixed>             $args     Ability arguments.
	 * @param array<int, array<string, mixed>> $tree     Tree about to be written.
	 * @param array<int, array<string, mixed>> $existing Stored tree before the write.
	 */
	private function checkpoint_error( array $args, int $post_id, array $tree, array $existing ): ?\WP_Error {
		$scope = isset( $args['design_scope'] ) && is_string( $args['design_scope'] )
			? $args['design_scope']
			: DesignCheckpoint::DEFAULT_SCOPE;

		if ( ! DesignCheckpoint::required( $scope ) ) {
			return null;
		}

		if ( count( $tree ) <= DesignCheckpoint::FIRST_SECTION_LIMIT ) {
			return null;
		}

		$token = isset( $args['checkpoint_token'] ) && is_string( $args['checkpoint_token'] ) ? trim( $args['checkpoint_token'] ) : '';
		if ( '' === $token ) {
			return new \WP_Error(
				DesignCheckpoint::ERROR_REQUIRED,
				DesignCheckpoint::reason( $scope ),
				[
					'status'              => 409,
					'design_scope'        => $scope,
					'first_section_limit' => DesignCheckpoint::FIRST_SECTION_LIMIT,
					'sections_requested'  => count( $tree ),
					'next_action'         => DesignCheckpoint::continuation_action(),
					'loop'                => DesignCheckpoint::loop(),
				]
			);
		}

		$section_id = DesignCheckpoint::bound_section_id( $token );

		return $this->to_error(
			DesignCheckpoint::verify(
				$token,
				[
					'post_id'        => $post_id,
					'section_id'     => $section_id,
					'direction_hash' => DesignCheckpoint::active_direction_hash( $this->directions ),
					'render_hash'    => DesignCheckpoint::section_render_hash( $existing, $section_id ),
				]
			)
		);
	}

	private function to_error( bool|\WP_Error $verified ): ?\WP_Error {
		return $verified instanceof \WP_Error ? $verified : null;
	}

	/**
	 * @param array<string, mixed> $args
	 */
	private static function write_mode( array $args ): string {
		if ( isset( $args['mode'] ) && in_array( $args['mode'], [ 'replace', 'append', 'replace_section' ], true ) ) {
			return (string) $args['mode'];
		}

		$replace = ! isset( $args['replace'] ) || (bool) $args['replace'];
		return $replace ? 'replace' : 'append';
	}

	/**
	 * The ids the caller gave the spec sections, by position; null where a section has no id of its own.
	 * The default id the validator assigns (`section_N`) names a position, not a section, so it never counts.
	 *
	 * @param array<string, mixed> $spec Spec as the caller sent it.
	 * @return array<int, string|null>
	 */
	private static function explicit_section_ids( array $spec ): array {
		$ids = [];
		foreach ( array_values( isset( $spec['sections'] ) && is_array( $spec['sections'] ) ? $spec['sections'] : [] ) as $section ) {
			$id    = is_array( $section ) && isset( $section['id'] ) && is_scalar( $section['id'] ) ? trim( (string) $section['id'] ) : '';
			$ids[] = '' === $id ? null : $id;
		}
		return $ids;
	}

	/**
	 * Merges the rendered sections into the stored document and works out the section record to store after the write.
	 *
	 * `replace_section` replaces the container recorded for the section id and no other. It refuses, before anything
	 * is written, when a section has no id, when the page has no record, or when a section matches no container or
	 * more than one. It never falls back to the element id or to the position.
	 *
	 * @param array<int, array<string, mixed>> $existing
	 * @param array<int, array<string, mixed>> $rendered One container per spec section, in spec order.
	 * @param array<int, string|null>          $section_ids Explicit section ids by position.
	 * @return array{tree:array<int, array<string, mixed>>,record:array<string, list<string>>|null}|\WP_Error Record null: leave it as it is.
	 */
	private function plan_merge( int $post_id, array $existing, array $rendered, string $mode, array $section_ids ): array|\WP_Error {
		$record = SpecSectionRecord::read( $post_id );

		if ( 'append' === $mode ) {
			$tree   = array_merge( $existing, self::with_unused_ids( $rendered, $existing ) );
			$record = SpecSectionRecord::pruned( $record, $existing );
			foreach ( array_values( $rendered ) as $position => $_container ) {
				$section_id = $section_ids[ $position ] ?? null;
				if ( null !== $section_id ) {
					$record[ $section_id ][] = (string) $tree[ count( $existing ) + $position ]['id'];
				}
			}
			return [ 'tree' => $tree, 'record' => $record ];
		}

		if ( 'replace_section' !== $mode ) {
			$record = [];
			foreach ( array_values( $rendered ) as $position => $container ) {
				$section_id = $section_ids[ $position ] ?? null;
				if ( null !== $section_id ) {
					$record[ $section_id ][] = (string) ( $container['id'] ?? '' );
				}
			}
			return [ 'tree' => $rendered, 'record' => $record ];
		}

		if ( [] === $rendered ) {
			return [ 'tree' => $existing, 'record' => null ];
		}

		$seen = [];
		foreach ( array_values( $rendered ) as $position => $_container ) {
			$section_id = $section_ids[ $position ] ?? null;
			if ( null === $section_id ) {
				return $this->error(
					'replace_section_id_required',
					__( 'replace_section needs an id on every spec section: the id names the section to replace. A section without an id is never matched by its position.', 'stonewright' ),
					[ 'status' => 400, 'section_index' => $position ]
				);
			}
			if ( isset( $seen[ $section_id ] ) ) {
				return $this->error(
					'replace_section_target_ambiguous',
					/* translators: %s: spec section id */
					sprintf( __( 'The spec lists section "%s" more than once, so there is no single section to replace.', 'stonewright' ), $section_id ),
					[ 'status' => 400, 'section_id' => $section_id, 'element_ids' => [] ]
				);
			}
			$seen[ $section_id ] = true;
		}

		if ( [] === $record ) {
			return $this->error(
				'replace_section_unrecorded',
				__( 'This page has no record of which container was built from which spec section: it was built before sections were recorded, or by another tool. replace_section never guesses a container from its position or element id, so nothing was changed.', 'stonewright' ),
				[
					'status' => 409,
					'repair' => 'Rebuild the page once with mode "replace" (sections that have an id are recorded from then on), or change the existing container with stonewright/elementor-v3-batch-mutate.',
				]
			);
		}

		$targets = [];
		foreach ( array_values( $rendered ) as $position => $_container ) {
			$section_id = (string) $section_ids[ $position ];
			$found      = SpecSectionRecord::containers( $record, $existing, $section_id );
			if ( [] === $found ) {
				return $this->error(
					'replace_section_target_missing',
					/* translators: %s: spec section id */
					sprintf( __( 'No container on this page is recorded as built from section "%s", so nothing was replaced.', 'stonewright' ), $section_id ),
					[
						'status'     => 409,
						'section_id' => $section_id,
						'repair'     => 'Add the section with mode "append", or rebuild the page with mode "replace".',
					]
				);
			}
			if ( count( $found ) > 1 ) {
				return $this->error(
					'replace_section_target_ambiguous',
					/* translators: %s: spec section id */
					sprintf( __( 'More than one container on this page was built from section "%s"; replace_section does not choose between them, so nothing was replaced.', 'stonewright' ), $section_id ),
					[
						'status'      => 409,
						'section_id'  => $section_id,
						'element_ids' => array_values( $found ),
						'repair'      => 'Remove the extra container, or rebuild the page with mode "replace".',
					]
				);
			}
			$targets[ $position ] = (int) array_key_first( $found );
		}

		// The replacement takes the id of the container it replaces. Its other elements keep the ids the
		// renderer derived unless another part of the page already uses them.
		$tree = $existing;
		foreach ( $targets as $position => $index ) {
			$replacement       = array_values( $rendered )[ $position ];
			$replacement['id'] = (string) $tree[ $index ]['id'];
			$others            = $tree;
			unset( $others[ $index ] );
			$tree[ $index ] = self::with_unused_ids( [ $replacement ], array_values( $others ) )[0];
		}

		return [ 'tree' => $tree, 'record' => null ];
	}

	/**
	 * Gives every element of $rendered whose id is already used in $existing a
	 * new id. The renderer derives ids from the position in the spec, so a
	 * second build repeats the ids of the first. Elements that do not collide
	 * keep their id, and $existing is never changed.
	 *
	 * @param array<int, array<string, mixed>> $rendered
	 * @param array<int, array<string, mixed>> $existing
	 * @return array<int, array<string, mixed>>
	 */
	private static function with_unused_ids( array $rendered, array $existing ): array {
		$used = [];
		ElementorData::walk(
			$existing,
			static function ( array $element ) use ( &$used ): void {
				$used[ (string) ( $element['id'] ?? '' ) ] = true;
			}
		);
		$reserved = $used;
		ElementorData::walk(
			$rendered,
			static function ( array $element ) use ( &$reserved ): void {
				$reserved[ (string) ( $element['id'] ?? '' ) ] = true;
			}
		);

		$reassign = static function ( array $nodes ) use ( &$reassign, $used, &$reserved ): array {
			foreach ( $nodes as $index => $node ) {
				$id = (string) ( $node['id'] ?? '' );
				if ( '' !== $id && isset( $used[ $id ] ) ) {
					$attempt = 0;
					do {
						$candidate = substr( hash( 'sha256', $id . '|append|' . $attempt ), 0, 7 );
						++$attempt;
					} while ( isset( $reserved[ $candidate ] ) );
					$reserved[ $candidate ] = true;
					$node['id']             = $candidate;
				}
				if ( isset( $node['elements'] ) && is_array( $node['elements'] ) && [] !== $node['elements'] ) {
					$node['elements'] = $reassign( $node['elements'] );
				}
				$nodes[ $index ] = $node;
			}
			return $nodes;
		};

		return $reassign( $rendered );
	}

	private static function elapsed_ms( float $start ): float {
		return round( ( microtime( true ) - $start ) * 1000, 3 );
	}
}
