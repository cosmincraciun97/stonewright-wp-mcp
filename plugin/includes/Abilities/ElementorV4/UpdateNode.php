<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\Abilities\ElementorV4;

use Stonewright\WpMcp\Abilities\AbilityKernel;
use Stonewright\WpMcp\Elementor\ElementorCustomCssGate;
use Stonewright\WpMcp\Elementor\Provider\NativeRoute;
use Stonewright\WpMcp\Elementor\V4\AtomicSchemaRepository;
use Stonewright\WpMcp\Elementor\V4\AtomicTextProp;
use Stonewright\WpMcp\Elementor\V4\AtomicTreeInspector;
use Stonewright\WpMcp\Elementor\V4\AtomicWriteReadback;
use Stonewright\WpMcp\Elementor\V4\V4FeatureGate;
use Stonewright\WpMcp\Elementor\Write\PostWriteLock;
use Stonewright\WpMcp\Elementor\Write\TreeHasher;
use Stonewright\WpMcp\Security\Backup;
use Stonewright\WpMcp\Security\ChangeSet;
use Stonewright\WpMcp\Security\ChangeSetSources;
use Stonewright\WpMcp\Security\Permissions;
use Stonewright\WpMcp\Security\RemediationHints;
use Stonewright\WpMcp\SectionReuse\BatchOperationIds;
use Stonewright\WpMcp\SectionReuse\Builder;
use Stonewright\WpMcp\SectionReuse\ElementorSectionInserter;
use Stonewright\WpMcp\SectionReuse\PortableSection;
use Stonewright\WpMcp\SectionReuse\ReuseSource;
use Stonewright\WpMcp\SectionReuse\SectionReuseSetting;
use Stonewright\WpMcp\Support\ElementorData;

/**
 * Surgical settings patch for one Elementor V4 Atomic node by id.
 *
 * Loads the full document tree (never the inspector projection), merges or
 * replaces settings on a single atomic node, snapshots, then writes through
 * ElementorData::write without integrity bypass.
 *
 * With `operations` it applies a batch to the document in memory: `insert_section`
 * copies a section returned by section-reuse-extract under fresh ids, and `update_node`
 * adapts a node, the new ones included. The batch is one dry run and one apply: one
 * snapshot, one write, and a recursive readback of the whole document. A raw Atomic
 * tree cannot be carried by Elementor's native composition without losing settings it
 * does not know, so the batch always runs on this writer; the response says so.
 *
 * @stonewright-status experimental
 */
final class UpdateNode extends AbilityKernel {

	public function name(): string {
		return 'stonewright/elementor-v4-update-node';
	}

	public function label(): string {
		return __( 'Update Elementor V4 atomic node', 'stonewright' );
	}

	public function description(): string {
		return __( 'Patches settings of one atomic Elementor node by id. Snapshots before write. Use dry_run first. With operations it applies a batch of insert_section (copy a section returned by stonewright-section-reuse-extract under fresh ids and remapped local style ids) and update_node operations to the document in one dry run and one apply; address new nodes as @op_id.placeholder.', 'stonewright' );
	}

	public function category(): string {
		return 'elementor';
	}

	public function meta(): array {
		return [ 'experimental' => true ];
	}

	public function input_schema(): array {
		return [
			'type'                 => 'object',
			'additionalProperties' => false,
			'properties'           => [
				'post_id'    => [ 'type' => 'integer', 'minimum' => 1 ],
				'element_id' => [ 'type' => 'string', 'minLength' => 1 ],
				'settings'   => [
					'type'                 => 'object',
					'additionalProperties' => true,
					'description'          => 'Persisted atomic settings keys (e.g. title, tag, classes) with typed $$type envelopes — not DesignSpec prop names.',
				],
				'mode'       => [
					'type'    => 'string',
					'enum'    => [ 'merge', 'replace' ],
					'default' => 'merge',
				],
				'dry_run'    => [
					'type'    => 'boolean',
					'default' => false,
				],
				'repair_of'  => ChangeSet::input_properties()['repair_of'],
				'supersedes' => ChangeSet::input_properties()['supersedes'],
				'operations' => [
					'type'        => 'array',
					'minItems'    => 1,
					'maxItems'    => 50,
					'description' => 'Batch mode, instead of element_id and settings. One dry run and one apply cover every operation.',
					'items'       => [
						'type'                 => 'object',
						'additionalProperties' => false,
						'properties'           => [
							'action'      => [ 'type' => 'string', 'enum' => [ 'insert_section', 'update_node' ] ],
							'op_id'       => [ 'type' => 'string', 'maxLength' => 64 ],
							'parent_id'   => [ 'type' => 'string', 'description' => 'insert_section: the Atomic container to insert into; omit for the document root.' ],
							'parent_ref'  => [ 'type' => 'string' ],
							'position'    => [ 'type' => 'integer', 'minimum' => 0 ],
							'section'     => [ 'type' => 'object', 'description' => 'insert_section: the SectionPortableV1 payload returned by stonewright-section-reuse-extract for an elementor-v4 section.' ],
							'element_id'  => [ 'type' => 'string' ],
							'element_ref' => [ 'type' => 'string', 'description' => 'update_node: a node of an earlier insert_section as op_id.placeholder, or an earlier op_id.' ],
							'settings'    => [ 'type' => 'object', 'additionalProperties' => true ],
							'mode'        => [ 'type' => 'string', 'enum' => [ 'merge', 'replace' ], 'default' => 'merge' ],
						],
						'required'             => [ 'action' ],
					],
				],
			],
			'required'             => [ 'post_id' ],
		];
	}

	public function output_schema(): array {
		return [
			'type'       => 'object',
			'properties' => [
				'post_id'     => [ 'type' => 'integer' ],
				'snapshot_id' => [ 'type' => 'string' ],
				'element_id'  => [ 'type' => 'string' ],
				'dry_run'     => [ 'type' => 'boolean' ],
				'settings'    => [ 'type' => 'object' ],
				'architecture' => [ 'type' => 'string', 'enum' => [ 'empty', 'v3', 'v4', 'mixed' ] ],
				'warnings'     => [ 'type' => 'array', 'items' => [ 'type' => 'string' ] ],
				'readback'     => [ 'type' => 'object' ],
				'items'        => [ 'type' => 'array', 'items' => [ 'type' => 'object' ] ],
				'refs'         => [ 'type' => 'object' ],
				'route'        => [ 'type' => 'object' ],
				'before_hash'  => [ 'type' => 'string' ],
				'after_hash'   => [ 'type' => 'string' ],
				'next_step'    => [ 'type' => 'object' ],
				'verification_status' => [ 'type' => 'string' ],
				'change_set'   => ChangeSet::output_property(),
			],
		];
	}

	public function permission_callback( array $args ): bool|\WP_Error {
		$dry_run = ! empty( $args['dry_run'] );
		$gate    = V4FeatureGate::check( ! $dry_run );
		if ( is_wp_error( $gate ) ) {
			return $gate;
		}
		return Permissions::edit_post( (int) ( $args['post_id'] ?? 0 ) );
	}

	public function execute( array $args ): array|\WP_Error {
		return $this->audit(
			$args,
			function ( array $args ) {
				if ( isset( $args['operations'] ) ) {
					return $this->execute_batch( $args );
				}
				if ( ! isset( $args['element_id'], $args['settings'] ) ) {
					return $this->error( 'missing_element_id', __( 'Send element_id and settings, or operations.', 'stonewright' ), [ 'status' => 400 ] );
				}
				$post_id    = (int) $args['post_id'];
				$element_id = (string) $args['element_id'];
				$dry_run    = ! empty( $args['dry_run'] );
				$mode       = isset( $args['mode'] ) ? (string) $args['mode'] : 'merge';
				$incoming   = isset( $args['settings'] ) && is_array( $args['settings'] ) ? $args['settings'] : null;

				if ( ! get_post( $post_id ) ) {
					return $this->error( 'not_found', __( 'Post not found.', 'stonewright' ) );
				}
				if ( null === $incoming ) {
					return $this->error( 'invalid_settings', __( 'settings must be an object of atomic setting patches.', 'stonewright' ) );
				}
				if ( ! in_array( $mode, [ 'merge', 'replace' ], true ) ) {
					return $this->error( 'invalid_mode', __( 'mode must be merge or replace.', 'stonewright' ) );
				}

				// Full document only — never write AtomicTreeInspector::atomic_tree back.
				$tree    = ElementorData::read( $post_id );
				$inspect = AtomicTreeInspector::inspect( $tree );
				$architecture = (string) ( $inspect['architecture'] ?? 'empty' );

				if ( in_array( $architecture, [ 'v3', 'empty' ], true ) ) {
					return $this->error(
						'v4_architecture_mismatch',
						__( 'This document has no Elementor V4 Atomic nodes. Use V3 element update or batch-mutate instead.', 'stonewright' ),
						[
							'status'       => 409,
							'architecture' => $architecture,
							'repair'       => RemediationHints::for_code( 'stonewright_v4_architecture_mismatch', $this->name() ),
						]
					);
				}

				$path = ElementorData::find_path( $tree, $element_id );
				if ( null === $path ) {
					$live_ids = $this->collect_atomic_ids(
						isset( $inspect['atomic_tree'] ) && is_array( $inspect['atomic_tree'] ) ? $inspect['atomic_tree'] : [],
						20
					);
					return $this->error(
						'element_not_found',
						__( 'Atomic element not found in the document tree.', 'stonewright' ),
						[
							'status'           => 404,
							'element_id'       => $element_id,
							'live_atomic_ids'  => $live_ids,
							'repair'           => RemediationHints::for_code( 'stonewright_element_not_found', $this->name() ),
						]
					);
				}

				$existing = $this->resolve( $tree, $path );
				if ( null === $existing ) {
					return $this->error( 'element_not_found', __( 'Atomic element not found in the document tree.', 'stonewright' ) );
				}

				if ( ! $this->is_atomic_node( $existing ) ) {
					return $this->error(
						'non_atomic_target',
						__( 'Target element is not an Elementor V4 Atomic node (elType/widgetType must start with e-).', 'stonewright' ),
						[
							'status'      => 400,
							'element_id'  => $element_id,
							'elType'      => (string) ( $existing['elType'] ?? '' ),
							'widgetType'  => (string) ( $existing['widgetType'] ?? '' ),
							'repair'      => 'Call stonewright/elementor-v4-read-atomic-tree to list atomic ids, or use stonewright/elementor-v3-update-element for classic V3 nodes.',
						]
					);
				}

				// Settings-only: never mutate id / elType / widgetType / version / styles tree.
				$current_settings = isset( $existing['settings'] ) && is_array( $existing['settings'] )
					? $existing['settings']
					: [];

				$atomic_type = $this->atomic_type( $existing );
				$validated   = $this->validate_atomic_settings( $atomic_type, $current_settings, $incoming );
				if ( $validated instanceof \WP_Error ) {
					return $validated;
				}

				/** @var array{settings: array<string, mixed>, warnings: list<string>} $validated */
				$next     = 'replace' === $mode
					? $validated['settings']
					: array_merge( $current_settings, $validated['settings'] );
				$warnings = $validated['warnings'];

				if ( $dry_run ) {
					return [
						'post_id'      => $post_id,
						'snapshot_id'  => '',
						'element_id'   => $element_id,
						'dry_run'      => true,
						'settings'     => $next,
						'architecture' => $architecture,
						'warnings'     => $warnings,
					];
				}

				$existing['settings'] = $next;
				$new_tree             = ElementorData::set( $tree, $path, $existing );

				$snapshot_id = Backup::snapshot_post( $post_id );
				if ( '' === $snapshot_id ) {
					return $this->error( 'backup_failed', __( 'Backup snapshot failed; write aborted.', 'stonewright' ) );
				}

				if ( ! ElementorData::write( $post_id, $new_tree ) ) {
					return ElementorData::write_error_for_ability();
				}
				$readback = AtomicWriteReadback::verify_tree( $post_id, $new_tree, $snapshot_id, 'update_node', [ 'ids' => [ $element_id ], 'before' => $tree ] );
				if ( $readback instanceof \WP_Error ) {
					return $readback;
				}
				self::clear_cached_local_styles( $post_id );

				return [
					'post_id'      => $post_id,
					'snapshot_id'  => $snapshot_id,
					'element_id'   => $element_id,
					'dry_run'      => false,
					'settings'     => $next,
					'architecture' => $architecture,
					'warnings'     => $warnings,
					'readback'     => $readback,
				];
			}
		);
	}

	/**
	 * The batch mode: insert_section and update_node operations applied to the document in memory, then
	 * one snapshot, one write and one recursive readback for all of them. A batch with any failing
	 * operation writes nothing.
	 *
	 * @param array<string, mixed> $args
	 * @return array<string, mixed>|\WP_Error
	 */
	private function execute_batch( array $args ): array|\WP_Error {
		$post_id    = (int) $args['post_id'];
		$dry_run    = ! empty( $args['dry_run'] );
		$operations = is_array( $args['operations'] ) ? array_values( $args['operations'] ) : [];
		if ( ! get_post( $post_id ) ) {
			return $this->error( 'not_found', __( 'Post not found.', 'stonewright' ) );
		}
		if ( [] === $operations || count( $operations ) > 50 ) {
			return $this->error( 'missing_operations', __( 'Send one to fifty operations.', 'stonewright' ), [ 'status' => 400 ] );
		}
		$duplicate = BatchOperationIds::duplicate( $operations );
		if ( null !== $duplicate ) {
			return $duplicate;
		}
		// A section of the other builder is a mistake of the caller; it is reported as such before the custom CSS gate reads it.
		$mismatch = SectionReuseSetting::is_enabled() ? PortableSection::operations_builder_mismatch( $operations, Builder::ELEMENTOR_V4 ) : null;
		if ( null !== $mismatch ) {
			return $mismatch;
		}
		// Custom CSS or HTML in a copied section needs the same approval as when it is written by hand. An
		// Atomic style variant always carries a `custom_css` key, null when it has none, so only a key that
		// holds something is custom CSS.
		$css_gate = ElementorCustomCssGate::assert_incoming( [ 'operations' => ElementorCustomCssGate::without_empty_css_keys( $operations ) ], $args );
		if ( $css_gate instanceof \WP_Error ) {
			return $css_gate;
		}

		$original    = ElementorData::read( $post_id );
		$tree        = $original;
		$before_hash = TreeHasher::hash( $original );
		$refs        = [];
		$items       = [];
		$failed      = 0;
		$first       = -1;
		$route       = null;
		$inserted    = 0;
		foreach ( $operations as $index => $operation ) {
			$operation = is_array( $operation ) ? $operation : [];
			$action    = (string) ( $operation['action'] ?? '' );
			$result    = match ( $action ) {
				'insert_section' => $this->batch_insert_section( $tree, $operation, $refs, $route, $inserted ),
				'update_node'    => $this->batch_update_node( $tree, $operation, $refs ),
				default          => $this->error( 'invalid_action', __( 'Use insert_section or update_node.', 'stonewright' ), [ 'status' => 400, 'action' => $action ] ),
			};
			if ( $result instanceof \WP_Error ) {
				++$failed;
				$first   = $first < 0 ? $index : $first;
				$items[] = [
					'index' => $index,
					'ok'    => false,
					'error' => [ 'code' => $result->get_error_code(), 'message' => $result->get_error_message(), 'data' => (array) $result->get_error_data() ],
				];
				if ( ! $dry_run ) {
					break;
				}
				continue;
			}
			$items[] = array_merge( [ 'index' => $index, 'ok' => true ], $result );
		}
		if ( $failed > 0 ) {
			return $this->error(
				'batch_operation_failed',
				sprintf( /* translators: 1: operation index, 2: action */ __( 'Elementor V4 batch operation %1$d (%2$s) failed. No page data was written.', 'stonewright' ), $first, (string) ( $operations[ $first ]['action'] ?? '' ) ) . SectionReuseSetting::refusal_note( (string) ( $items[ $first ]['error']['code'] ?? '' ) ) . ( ElementorSectionInserter::UNSUPPORTED_DROP_CODE === (string) ( $items[ $first ]['error']['code'] ?? '' ) ? ' ' . (string) $items[ $first ]['error']['message'] : '' ),
				array_merge(
					[
						'status'        => 400,
						'items'         => $items,
						'failed'        => $failed,
						'failed_index'  => $first,
						'write_blocked' => true,
						'retryable'     => true,
						'before_hash'   => $before_hash,
						'repair'        => 'Fix the reported operation and rerun the dry run. No partial batch is persisted.',
					],
					SectionReuseSetting::refusal_flags( (string) ( $items[ $first ]['error']['code'] ?? '' ) )
				)
			);
		}

		$after_hash = TreeHasher::hash( $tree );
		$touched    = [];
		foreach ( $items as $item ) {
			if ( isset( $item['element_id'] ) ) {
				$touched[] = (string) $item['element_id'];
			}
			foreach ( is_array( $item['element_ids'] ?? null ) ? $item['element_ids'] : [] as $copied_id ) {
				$touched[] = (string) $copied_id;
			}
		}
		$touched      = array_values( array_unique( $touched ) );
		// Text that the live widget would render empty is refused here, in the dry run too, not reported verified afterwards.
		$unrendered = AtomicTextProp::problems( $tree, $touched, $original );
		if ( [] !== $unrendered ) {
			return AtomicTextProp::error( $unrendered );
		}
		$architecture = (string) ( AtomicTreeInspector::inspect( $tree )['architecture'] ?? 'empty' );
		$response     = [
			'post_id'      => $post_id,
			'dry_run'      => $dry_run,
			'snapshot_id'  => '',
			'architecture' => $architecture,
			'items'        => $items,
			'refs'         => $refs,
			'route'        => $route ?? [ 'route' => NativeRoute::FALLBACK, 'reason' => 'node_patch' ],
			'before_hash'  => $before_hash,
			'after_hash'   => $after_hash,
			'warnings'     => [],
		];
		if ( $dry_run ) {
			return $response + [ 'verification_status' => 'planned', 'element_count' => count( ElementorData::flatten( $tree ) ) ];
		}
		if ( hash_equals( $before_hash, $after_hash ) ) {
			return $response + [ 'verification_status' => 'unchanged', 'unchanged' => true ];
		}

		$owner = 'v4-batch-' . substr( $after_hash, 0, 24 );
		$lease = PostWriteLock::acquire( $post_id, $owner );
		if ( $lease instanceof \WP_Error ) {
			return $lease;
		}
		try {
			$current = TreeHasher::hash( ElementorData::read( $post_id ) );
			if ( ! hash_equals( $before_hash, $current ) ) {
				return $this->error(
					'elementor_revision_conflict',
					__( 'The page changed before the batch took its write lock; read it again and retry.', 'stonewright' ),
					[ 'status' => 409, 'expected_tree_hash' => $before_hash, 'current_tree_hash' => $current, 'retryable' => true ]
				);
			}
			$snapshot_id = Backup::snapshot_post( $post_id );
			if ( '' === $snapshot_id ) {
				return $this->error( 'backup_failed', __( 'Backup snapshot failed; write aborted.', 'stonewright' ) );
			}
			if ( ! ElementorData::write( $post_id, $tree, [ 'touched_ids' => $touched, 'lock_owner' => $owner ] ) ) {
				return ElementorData::write_error_for_ability();
			}
			$readback = AtomicWriteReadback::verify_tree( $post_id, $tree, $snapshot_id, 'section_batch', [ 'ids' => $touched, 'before' => $original ] );
			if ( $readback instanceof \WP_Error ) {
				return $readback;
			}
			self::clear_cached_local_styles( $post_id );
		} finally {
			PostWriteLock::release( $post_id, $owner );
		}

		return array_merge(
			$response,
			[
				'snapshot_id'         => $snapshot_id,
				'readback'            => $readback,
				'verification_status' => 'verified',
				'next_step'           => [
					'tool'        => 'stonewright/elementor-css-regenerate',
					'post_id'     => $post_id,
					'then'        => 'stonewright/elementor-post-write-verify',
					'element_ids' => $touched,
				],
			]
		);
	}

	/**
	 * Elementor keeps the rendered local styles of a post until it is told they changed; a save through its own
	 * editor tells it, a write through the document meta does not.
	 */
	private static function clear_cached_local_styles( int $post_id ): void {
		do_action( 'elementor/atomic-widgets/styles/clear', [ 'local', $post_id ] ); // phpcs:ignore WordPress.NamingConventions.ValidHookName.UseUnderscores -- Elementor's own hook name.
	}

	/**
	 * Copies a portable section into the document in memory.
	 *
	 * @param array<int, array<string, mixed>> $tree
	 * @param array<string, mixed>             $operation
	 * @param array<string, string>            $refs
	 * @param array<string, mixed>|null        $route
	 * @param int                              $inserted Elements the earlier insert_section operations of the batch added.
	 * @return array<string, mixed>|\WP_Error
	 */
	private function batch_insert_section( array &$tree, array $operation, array &$refs, ?array &$route, int &$inserted ): array|\WP_Error {
		// The live option, not the tool list: a client may keep a stale list.
		if ( ! SectionReuseSetting::is_enabled() ) {
			return SectionReuseSetting::off_error();
		}
		$unsupported = ElementorSectionInserter::unsupported_drop_settings( $operation, 'an Elementor V4 section' );
		if ( null !== $unsupported ) {
			return $unsupported;
		}
		$payload = PortableSection::validate( $operation['section'] ?? null, Builder::ELEMENTOR_V4 );
		if ( $payload instanceof \WP_Error ) {
			return $payload;
		}
		$budget = ElementorSectionInserter::within_batch_budget( $inserted, $payload );
		if ( $budget instanceof \WP_Error ) {
			return $budget;
		}
		$source = ReuseSource::of_payload( $payload );
		if ( null !== $source ) {
			$access = ReuseSource::check( $source );
			if ( $access instanceof \WP_Error ) {
				return $access;
			}
		}
		$parent_id = isset( $operation['parent_ref'] ) && is_string( $operation['parent_ref'] ) && '' !== $operation['parent_ref']
			? (string) ( $refs[ $operation['parent_ref'] ] ?? '' )
			: (string) ( $operation['parent_id'] ?? '' );
		if ( isset( $operation['parent_ref'] ) && '' === $parent_id ) {
			return $this->error( 'unknown_ref', __( 'The operation refers to an op_id that is not in this batch yet.', 'stonewright' ), [ 'status' => 400, 'ref' => (string) $operation['parent_ref'] ] );
		}
		$parent_path = [];
		$parent      = null;
		if ( '' !== $parent_id ) {
			$found = ElementorData::find_path( $tree, $parent_id );
			if ( null === $found ) {
				return $this->error( 'parent_not_found', __( 'Parent element not found.', 'stonewright' ), [ 'status' => 404, 'parent_id' => $parent_id ] );
			}
			$parent_path = $found;
			$parent      = $this->resolve( $tree, $found );
		}
		$kind         = null === $parent ? 'document' : ( $this->is_atomic_node( $parent ) && 'widget' !== (string) ( $parent['elType'] ?? '' ) ? 'atomic' : 'v3' );
		$architecture = (string) ( AtomicTreeInspector::inspect( $tree )['architecture'] ?? 'empty' );
		$document     = NativeRoute::for_document( $architecture, $kind );
		if ( NativeRoute::NATIVE !== $document['route'] ) {
			return $this->error(
				NativeRoute::STONEWRIGHT_V3 === $document['route'] ? 'v4_architecture_mismatch' : 'native_route_refused',
				__( 'A V4 section can only go to the document root of a V4 or empty document, or under an Atomic container. A V3 element is never converted.', 'stonewright' ),
				[ 'status' => 409, 'architecture' => $architecture, 'document_route' => $document ]
			);
		}
		$built = ElementorSectionInserter::instantiate( $payload, Builder::ELEMENTOR_V4, $tree, $parent_path );
		if ( $built instanceof \WP_Error ) {
			return $built;
		}

		$position = isset( $operation['position'] ) ? (int) $operation['position'] : PHP_INT_MAX;
		$tree     = ElementorData::insert( $tree, $parent_path, $position, $built['element'] );
		$inserted += count( $built['id_map'] );
		$root_id  = (string) $built['element']['id'];
		if ( isset( $operation['op_id'] ) && is_string( $operation['op_id'] ) && '' !== $operation['op_id'] ) {
			$refs[ $operation['op_id'] ] = $root_id;
			foreach ( $built['id_map'] as $placeholder => $new_id ) {
				$refs[ $operation['op_id'] . '.' . $placeholder ] = $new_id;
			}
		}
		$route ??= [
			'route'          => NativeRoute::FALLBACK,
			'reason'         => 'portable_section_is_a_raw_tree',
			'document_route' => $document,
		];

		return array_merge(
			[
				'action'       => 'insert_section',
				'element_id'   => $root_id,
				'element_ids'  => array_values( $built['id_map'] ),
				'placeholders' => count( $built['id_map'] ),
			],
			null === $source ? [] : [ 'reuse_source' => $source ],
			[] === $built['warnings'] ? [] : [ 'warnings' => $built['warnings'] ]
		);
	}

	/**
	 * Patches the settings of one Atomic node of the document in memory.
	 *
	 * @param array<int, array<string, mixed>> $tree
	 * @param array<string, mixed>             $operation
	 * @param array<string, string>            $refs
	 * @return array<string, mixed>|\WP_Error
	 */
	private function batch_update_node( array &$tree, array $operation, array $refs ): array|\WP_Error {
		$element_id = isset( $operation['element_ref'] ) && is_string( $operation['element_ref'] )
			? (string) ( $refs[ $operation['element_ref'] ] ?? '' )
			: (string) ( $operation['element_id'] ?? '' );
		if ( '' === $element_id ) {
			return $this->error( 'unknown_ref', __( 'update_node needs element_id, or an element_ref from an earlier operation of this batch.', 'stonewright' ), [ 'status' => 400, 'ref' => (string) ( $operation['element_ref'] ?? '' ) ] );
		}
		$incoming = isset( $operation['settings'] ) && is_array( $operation['settings'] ) ? $operation['settings'] : null;
		$mode     = isset( $operation['mode'] ) ? (string) $operation['mode'] : 'merge';
		if ( null === $incoming || ! in_array( $mode, [ 'merge', 'replace' ], true ) ) {
			return $this->error( 'invalid_settings', __( 'update_node needs settings, and a mode of merge or replace.', 'stonewright' ), [ 'status' => 400 ] );
		}
		$path = ElementorData::find_path( $tree, $element_id );
		if ( null === $path ) {
			return $this->error( 'element_not_found', __( 'Atomic element not found in the document tree.', 'stonewright' ), [ 'status' => 404, 'element_id' => $element_id ] );
		}
		$existing = $this->resolve( $tree, $path );
		if ( null === $existing || ! $this->is_atomic_node( $existing ) ) {
			return $this->error( 'non_atomic_target', __( 'Target element is not an Elementor V4 Atomic node (elType/widgetType must start with e-).', 'stonewright' ), [ 'status' => 400, 'element_id' => $element_id ] );
		}
		$current   = isset( $existing['settings'] ) && is_array( $existing['settings'] ) ? $existing['settings'] : [];
		$validated = $this->validate_atomic_settings( $this->atomic_type( $existing ), $current, $incoming );
		if ( $validated instanceof \WP_Error ) {
			return $validated;
		}
		$next     = 'replace' === $mode ? $validated['settings'] : array_merge( $current, $validated['settings'] );
		$unchanged = $next === $current;
		if ( ! $unchanged ) {
			$existing['settings'] = $next;
			$tree                 = ElementorData::set( $tree, $path, $existing );
		}

		return array_merge(
			[ 'action' => 'update_node', 'element_id' => $element_id ],
			$unchanged ? [ 'unchanged' => true ] : [],
			[] === $validated['warnings'] ? [] : [ 'warnings' => $validated['warnings'] ]
		);
	}

	/**
	 * ChangeSetV1 inputs of a batch: one planned change per operation, the new nodes counted as part of the plan,
	 * and the source of every reused section.
	 *
	 * @param array<string, mixed>           $args
	 * @param array<string, mixed>|\WP_Error $result
	 * @return array<string, mixed>
	 */
	private function batch_change_set_inputs( array $args, array|\WP_Error $result, string $status ): array {
		$data      = ChangeSetSources::data( $result );
		$items     = array_values( (array) ( $data['items'] ?? [] ) );
		$planned   = [];
		$effective = [];
		$touched   = [];
		$sources   = [];
		foreach ( array_values( (array) ( $args['operations'] ?? [] ) ) as $index => $operation ) {
			$operation = is_array( $operation ) ? $operation : [];
			$item      = is_array( $items[ $index ] ?? null ) ? $items[ $index ] : [];
			$ref       = (string) ( $item['element_id'] ?? $operation['element_id'] ?? '' );
			if ( '' === $ref ) {
				$ref = isset( $operation['element_ref'] ) ? '@' . $operation['element_ref'] : '@' . (string) ( $operation['op_id'] ?? 'op-' . $index );
			}
			$entry     = ChangeSet::entry( 'element', $ref, (string) ( $operation['action'] ?? '' ), $index );
			$planned[] = $entry;
			if ( ! empty( $item['unchanged'] ) ) {
				continue;
			}
			$effective[] = $entry;
			$touched[]   = $ref;
			foreach ( is_array( $item['element_ids'] ?? null ) ? $item['element_ids'] : [] as $copied_id ) {
				$touched[] = (string) $copied_id;
			}
			if ( is_array( $item['reuse_source'] ?? null ) ) {
				$sources[] = $item['reuse_source'];
			}
		}
		$post_id  = (int) ( $args['post_id'] ?? 0 );
		$receipt  = is_array( $data['write_receipt'] ?? null ) ? $data['write_receipt'] : [];
		$snapshot = (string) ( $data['snapshot_id'] ?? $receipt['snapshot_id'] ?? '' );
		$inputs   = ChangeSetSources::receipt(
			$args,
			$result,
			$status,
			$planned,
			$effective,
			[
				'unchanged'  => 'ok' === $status && [] !== $planned && [] === $effective,
				'unexpected' => static fn (): array => ChangeSetSources::elements_outside_plan( $post_id, $snapshot, $touched ),
				'seed'       => [ TreeHasher::hash( $args['operations'] ?? [] ) ],
			]
		);
		if ( [] !== $sources ) {
			$inputs['extensions'] = [ 'reuse_source' => ReuseSource::for_change_set( $sources ) ];
		}

		return $inputs;
	}

	/**
	 * ChangeSetV1 of the node patch: one planned settings change on the node, read
	 * from the request and the write receipt. After a verified write the live
	 * document is compared with the snapshot taken before it, so a change to any
	 * other element is reported as unexpected.
	 *
	 * @param array<string, mixed>           $args
	 * @param array<string, mixed>|\WP_Error $result
	 * @return array<string, mixed>
	 */
	protected function change_set_inputs( array $args, array|\WP_Error $result, string $status ): ?array {
		if ( isset( $args['operations'] ) ) {
			return $this->batch_change_set_inputs( $args, $result, $status );
		}
		$data       = ChangeSetSources::data( $result );
		$post_id    = (int) ( $args['post_id'] ?? 0 );
		$element_id = (string) ( $args['element_id'] ?? '' );
		$entry      = ChangeSet::entry( 'element', $element_id, 'update_settings', 0 );
		$receipt    = is_array( $data['write_receipt'] ?? null ) ? $data['write_receipt'] : [];
		$snapshot   = (string) ( $data['snapshot_id'] ?? $receipt['snapshot_id'] ?? '' );
		$before     = (string) ( $receipt['before_hash'] ?? '' );
		$after      = (string) ( $receipt['readback_hash'] ?? $receipt['after_hash'] ?? '' );

		return ChangeSetSources::receipt(
			$args,
			$result,
			$status,
			[ $entry ],
			[ $entry ],
			[
				'unchanged'  => 'ok' === $status && empty( $args['dry_run'] ) && '' !== $before && $before === $after,
				'unexpected' => static fn (): array => ChangeSetSources::elements_outside_plan( $post_id, $snapshot, [ $element_id ] ),
				'seed'       => [ $element_id, TreeHasher::hash( $args['settings'] ?? [] ), (string) ( $args['mode'] ?? 'merge' ) ],
			]
		);
	}

	/**
	 * @param array<string, mixed> $element
	 */
	private function is_atomic_node( array $element ): bool {
		$atomic_type = $this->atomic_type( $element );
		return '' !== $atomic_type && str_starts_with( $atomic_type, 'e-' );
	}

	/**
	 * @param array<string, mixed> $element
	 */
	private function atomic_type( array $element ): string {
		$el_type     = (string) ( $element['elType'] ?? '' );
		$widget_type = (string) ( $element['widgetType'] ?? '' );
		return 'widget' === $el_type ? $widget_type : $el_type;
	}

	/**
	 * Light ability-layer validation for atomic settings patches.
	 *
	 * @param array<string, mixed> $current Existing node settings.
	 * @param array<string, mixed> $incoming Patch keys.
	 * @return array{settings: array<string, mixed>, warnings: list<string>}|\WP_Error
	 */
	private function validate_atomic_settings( string $atomic_type, array $current, array $incoming ): array|\WP_Error {
		$warnings = [];
		$schema   = AtomicSchemaRepository::for_atomic_type( $atomic_type );
		if ( null === $schema ) {
			return $this->error(
				'atomic_provider_not_certified',
				__( 'This Atomic type has no trusted, certified write schema. The settings mutation was blocked before validation.', 'stonewright' ),
				[
					'status'      => 409,
					'atomic_type' => $atomic_type,
					'repair'      => 'Use a schema shipped in Stonewright\'s immutable bundled or verified-official authority before writing this Atomic type.',
				]
			);
		}
		$reverse = $this->reverse_prop_map( $schema );

		$out = [];
		foreach ( $incoming as $key => $value ) {
			$key = (string) $key;
			if ( '' === $key ) {
				return $this->error( 'invalid_settings_key', __( 'Settings keys must be non-empty strings.', 'stonewright' ) );
			}

			// classes is a documented special envelope on atomic nodes.
			$known = isset( $reverse[ $key ] ) || 'classes' === $key;
			$on_node = array_key_exists( $key, $current );

			if ( $known ) {
				if ( ! $this->is_typed_envelope( $value ) ) {
					return $this->error(
						'invalid_settings_envelope',
						sprintf(
							/* translators: %s: settings key */
							__( 'Atomic setting "%s" must be a typed envelope with $$type and value.', 'stonewright' ),
							$key
						),
						[
							'status'       => 400,
							'settings_key' => $key,
							'expected'     => [ '$$type' => 'string', 'value' => 'mixed' ],
						]
					);
				}
				$expected_type = (string) ( $reverse[ $key ] ?? '' );
				// classes uses a dedicated envelope; raw-json accepts any runtime envelope type.
				if (
					'' !== $expected_type
					&& 'raw-json' !== $expected_type
					&& (string) $value['$$type'] !== $expected_type
				) {
					return $this->error(
						'invalid_settings_envelope_type',
						sprintf(
							/* translators: 1: settings key, 2: expected $$type, 3: actual $$type */
							__( 'Atomic setting "%1$s" expects $$type "%2$s", got "%3$s".', 'stonewright' ),
							$key,
							$expected_type,
							(string) $value['$$type']
						),
						[
							'status'        => 400,
							'settings_key'  => $key,
							'expected_type' => $expected_type,
							'actual_type'   => (string) $value['$$type'],
						]
					);
				}
				// A text of the right type whose value is not the shape of that type is stored and read back, and renders empty.
				if ( AtomicTextProp::is_text_type( $expected_type ) && ! AtomicTextProp::shape_valid( $value ) ) {
					return $this->error(
						'invalid_settings_envelope_value',
						sprintf(
							/* translators: 1: settings key, 2: $$type */
							__( 'Atomic setting "%1$s" is a "%2$s" text, but its value is not the shape of that type, so it would render empty.', 'stonewright' ),
							$key,
							$expected_type
						),
						[
							'status'        => 400,
							'settings_key'  => $key,
							'expected_type' => $expected_type,
							'example'       => AtomicTextProp::envelope( $expected_type, 'Text' ),
						]
					);
				}
				$out[ $key ] = $value;
				continue;
			}

			if ( $on_node ) {
				// Preserve unknown keys already on the node (parity with preserve_unknown).
				if ( is_array( $value ) && $this->looks_like_partial_envelope( $value ) && ! $this->is_typed_envelope( $value ) ) {
					return $this->error(
						'invalid_settings_envelope',
						sprintf(
							/* translators: %s: settings key */
							__( 'Settings key "%s" looks like a typed envelope but is incomplete (needs both $$type and value).', 'stonewright' ),
							$key
						),
						[ 'status' => 400, 'settings_key' => $key ]
					);
				}
				$out[ $key ] = $value;
				$warnings[]  = sprintf(
					/* translators: %s: settings key */
					__( 'Preserved unknown settings key already present on node: %s.', 'stonewright' ),
					$key
				);
				continue;
			}

			// New key: require schema reverse map (handled above) or typed envelope.
			if ( $this->is_typed_envelope( $value ) ) {
				$out[ $key ] = $value;
				$warnings[]  = sprintf(
					/* translators: %s: settings key */
					__( 'Accepted new settings key with typed envelope (not in reverse prop map): %s.', 'stonewright' ),
					$key
				);
				continue;
			}

			return $this->error(
				'unknown_settings_key',
				sprintf(
					/* translators: 1: settings key, 2: atomic type */
					__( 'Unknown settings key "%1$s" for atomic type "%2$s". Use a schema key with a $$type envelope, or patch a key already present on the node.', 'stonewright' ),
					$key,
					$atomic_type
				),
				[
					'status'        => 400,
					'settings_key'  => $key,
					'atomic_type'   => $atomic_type,
					'known_keys'    => array_values( array_unique( array_merge( array_keys( $reverse ), [ 'classes' ] ) ) ),
				]
			);
		}

		return [
			'settings' => $out,
			'warnings' => $warnings,
		];
	}

	/**
	 * Build settings-key → envelope-type map from AtomicSchemaRepository props.
	 *
	 * @param array<string, mixed> $schema
	 * @return array<string, string>
	 */
	private function reverse_prop_map( array $schema ): array {
		$map   = [ 'classes' => 'classes' ];
		$props = isset( $schema['props'] ) && is_array( $schema['props'] ) ? $schema['props'] : [];
		foreach ( $props as $design_name => $prop ) {
			if ( ! is_array( $prop ) ) {
				continue;
			}
			$key = (string) ( $prop['key'] ?? $design_name );
			if ( '' === $key ) {
				continue;
			}
			$map[ $key ] = (string) ( $prop['type'] ?? '' );
		}
		return $map;
	}

	/**
	 * @param mixed $value
	 */
	private function is_typed_envelope( mixed $value ): bool {
		return is_array( $value )
			&& array_key_exists( '$$type', $value )
			&& array_key_exists( 'value', $value )
			&& is_string( $value['$$type'] )
			&& '' !== $value['$$type'];
	}

	/**
	 * @param array<string, mixed> $value
	 */
	private function looks_like_partial_envelope( array $value ): bool {
		return array_key_exists( '$$type', $value ) || array_key_exists( 'value', $value );
	}

	/**
	 * @param list<array<string, mixed>> $atomic_tree
	 * @return list<string>
	 */
	private function collect_atomic_ids( array $atomic_tree, int $cap ): array {
		$ids = [];
		$this->walk_ids( $atomic_tree, $ids, $cap );
		return $ids;
	}

	/**
	 * @param list<mixed>|array<int, mixed> $nodes
	 * @param list<string>                  $ids
	 */
	private function walk_ids( array $nodes, array &$ids, int $cap ): void {
		foreach ( $nodes as $node ) {
			if ( count( $ids ) >= $cap ) {
				return;
			}
			if ( ! is_array( $node ) ) {
				continue;
			}
			if ( isset( $node['id'] ) && is_scalar( $node['id'] ) && '' !== (string) $node['id'] ) {
				$ids[] = (string) $node['id'];
			}
			if ( isset( $node['elements'] ) && is_array( $node['elements'] ) ) {
				$this->walk_ids( $node['elements'], $ids, $cap );
			}
		}
	}

	/**
	 * @param array<int, mixed> $tree
	 * @param list<int>         $path
	 * @return array<string, mixed>|null
	 */
	private function resolve( array $tree, array $path ): ?array {
		$current = null;
		foreach ( $path as $index ) {
			if ( ! isset( $tree[ $index ] ) || ! is_array( $tree[ $index ] ) ) {
				return null;
			}
			$current = $tree[ $index ];
			$tree    = isset( $current['elements'] ) && is_array( $current['elements'] ) ? $current['elements'] : [];
		}
		return $current;
	}
}
