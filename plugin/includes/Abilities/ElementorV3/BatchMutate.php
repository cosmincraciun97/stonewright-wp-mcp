<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\Abilities\ElementorV3;

use Stonewright\WpMcp\Abilities\AbilityKernel;
use Stonewright\WpMcp\Abilities\Common\ConfirmationGuard;
use Stonewright\WpMcp\Context\ExecutionContext;
use Stonewright\WpMcp\Elementor\ContainerSettings;
use Stonewright\WpMcp\Elementor\ElementorCustomCssGate;
use Stonewright\WpMcp\Elementor\Schema\ContainerSchemaRepository;
use Stonewright\WpMcp\Elementor\Schema\PatchValidator;
use Stonewright\WpMcp\Elementor\Schema\RepeaterPatcher;
use Stonewright\WpMcp\Elementor\Schema\ResponsiveScope;
use Stonewright\WpMcp\Elementor\Schema\SettingsValidator;
use Stonewright\WpMcp\Elementor\Schema\SparseSettingsNormalizer;
use Stonewright\WpMcp\Elementor\Schema\WidgetSchemaRepository;
use Stonewright\WpMcp\Elementor\V4\AtomicTreeInspector;
use Stonewright\WpMcp\Elementor\V4\ArchitectureRouter;
use Stonewright\WpMcp\Elementor\Write\EvidenceValidator;
use Stonewright\WpMcp\Elementor\Write\ElementorWriteReceipt;
use Stonewright\WpMcp\Elementor\Write\IdempotencyStore;
use Stonewright\WpMcp\Elementor\Write\PostWriteLock;
use Stonewright\WpMcp\Elementor\Write\TreeHasher;
use Stonewright\WpMcp\Elementor\Write\V3MutationCompiler;
use Stonewright\WpMcp\Security\Backup;
use Stonewright\WpMcp\Security\IncidentStore;
use Stonewright\WpMcp\Security\Permissions;
use Stonewright\WpMcp\Security\RemediationHints;
use Stonewright\WpMcp\Design\Diagnostics\ThirdPartyControlRiskMap;
use Stonewright\WpMcp\Knowledge\Lifecycle\SchemaRepairLearning;
use Stonewright\WpMcp\Support\ElementorData;

/**
 * Applies multiple Elementor V3 tree mutations in one compact write.
 *
 * @stonewright-status stable
 */
final class BatchMutate extends AbilityKernel {
	use ConfirmationGuard;

	public function name(): string {
		return 'stonewright/elementor-v3-batch-mutate';
	}

	public function label(): string {
		return __( 'Batch mutate Elementor V3 page', 'stonewright' );
	}

	public function description(): string {
		return __( 'Applies many Elementor V3 add/update/move/remove operations to one page in one request, with one read, one snapshot, and one write. Use op_id refs to avoid follow-up reads for generated IDs.', 'stonewright' );
	}

	public function category(): string {
		return 'elementor';
	}

	public function input_schema(): array {
		return [
			'type'                 => 'object',
			'additionalProperties' => false,
			'properties'           => [
				'post_id'            => [ 'type' => 'integer', 'minimum' => 1 ],
				'dry_run'            => [ 'type' => 'boolean', 'default' => false ],
				'idempotency_key'     => [ 'type' => 'string', 'minLength' => 8, 'maxLength' => 128 ],
				'expected_tree_hash'  => [ 'type' => 'string', 'pattern' => '^[a-f0-9]{64}$' ],
				'change_set_id'       => [ 'type' => 'string', 'maxLength' => 96 ],
				'require_evidence'    => [ 'type' => 'boolean', 'default' => false ],
				'responsive_scope'    => [
					'type'        => 'array',
					'items'       => [ 'type' => 'string' ],
					'uniqueItems' => true,
					'description' => 'Breakpoints this batch may change. Defaults to desktop-only. Per-operation responsive_scope or allowed_breakpoints overrides this.',
				],
				'allowed_breakpoints' => [
					'type'        => 'array',
					'items'       => [ 'type' => 'string' ],
					'uniqueItems' => true,
					'description' => 'Alias for batch responsive_scope.',
				],
				'stop_on_error'      => [
					'type'        => 'boolean',
					'description' => 'Defaults to false for dry runs so all invalid operations are reported, and true for writes. A batch with any failure is never persisted.',
				],
				'confirmation_token' => [ 'type' => 'string' ],
				'operations'         => [
					'type'     => 'array',
					'minItems' => 1,
					'maxItems' => 200,
					'items'    => [
						'type'                 => 'object',
						'additionalProperties' => true,
						'properties'           => [
							'action'                 => [
								'type' => 'string',
								'enum' => [ 'add_container', 'add_widget', 'update_element', 'patch_repeater_row', 'move_element', 'remove_element' ],
							],
							'type'                   => [
								'type'        => 'string',
								'description' => 'Compact alias for action: container, widget, update, move, remove.',
							],
							'op'                     => [
								'type'        => 'string',
								'description' => 'Alias for action/type.',
							],
							'op_id'                  => [ 'type' => 'string' ],
							'parent_id'              => [ 'type' => 'string' ],
							'parent_ref'             => [ 'type' => 'string' ],
							'parent'                 => [
								'type'        => 'string',
								'description' => 'Alias for parent_id, or parent_ref when prefixed with @.',
							],
							'element_id'             => [ 'type' => 'string' ],
							'element_ref'            => [ 'type' => 'string' ],
							'target'                 => [
								'type'        => 'string',
								'description' => 'Alias for element_id, or element_ref when prefixed with @.',
							],
							'target_id'              => [ 'type' => 'string' ],
							'target_ref'             => [ 'type' => 'string' ],
							'new_parent_id'          => [ 'type' => 'string' ],
							'new_parent_ref'         => [ 'type' => 'string' ],
							'new_parent'             => [
								'type'        => 'string',
								'description' => 'Alias for new_parent_id, or new_parent_ref when prefixed with @.',
							],
							'position'               => [ 'type' => 'integer' ],
							'widget_type'            => [ 'type' => 'string' ],
							'widget'                 => [ 'type' => 'string' ],
							'settings'               => [ 'type' => 'object' ],
							'repeater_key'            => [ 'type' => 'string', 'maxLength' => 96 ],
							'selector'                => [
								'type'                 => 'object',
								'additionalProperties' => false,
								'properties'           => [ 'custom_id' => [ 'type' => 'string' ], '_id' => [ 'type' => 'string' ] ],
							],
							'row_patch'               => [ 'type' => 'object' ],
							'expected_row_hash'       => [ 'type' => 'string', 'pattern' => '^[a-f0-9]{64}$' ],
							'allow_high_risk_replace' => [ 'type' => 'boolean', 'default' => false ],
							'approved_preservation_hash' => [ 'type' => 'string', 'pattern' => '^[a-f0-9]{64}$' ],
							'settings_evidence'      => [ 'type' => 'object' ],
							'responsive_scope'       => [
								'type'        => 'array',
								'items'       => [ 'type' => 'string' ],
								'uniqueItems' => true,
								'description' => 'Breakpoints this operation may change. Overrides the batch responsive_scope. Defaults to desktop-only.',
							],
							'allowed_breakpoints'    => [
								'type'        => 'array',
								'items'       => [ 'type' => 'string' ],
								'uniqueItems' => true,
								'description' => 'Alias for responsive_scope. Defaults to evidence responsive_scope, then desktop.',
							],
							'mode'                   => [ 'type' => 'string', 'enum' => [ 'merge', 'replace' ], 'default' => 'merge' ],
							'allow_html_widget'      => [ 'type' => 'boolean', 'default' => false ],
							'allow_raw_known_widget' => [ 'type' => 'boolean', 'default' => true ],
						],
					],
				],
			],
			'required'             => [ 'post_id', 'operations' ],
		];
	}

	public function output_schema(): array {
		return [
			'type'       => 'object',
			'properties' => [
				'ok'          => [ 'type' => 'boolean' ],
				'post_id'     => [ 'type' => 'integer' ],
				'dry_run'     => [ 'type' => 'boolean' ],
				'snapshot_id' => [ 'type' => 'string' ],
				'applied'     => [ 'type' => 'integer' ],
				'failed'      => [ 'type' => 'integer' ],
				'items'       => [ 'type' => 'array' ],
				'refs'          => [ 'type' => 'object' ],
				'elements'      => [ 'type' => 'integer' ],
				'element_count' => [ 'type' => 'integer' ],
				'metrics'       => [ 'type' => 'object' ],
				'preview'       => [ 'type' => 'array' ],
				'before_hash'   => [ 'type' => 'string' ],
				'after_hash'    => [ 'type' => 'string' ],
				'readback_hash' => [ 'type' => 'string' ],
				'idempotent_replay' => [ 'type' => 'boolean' ],
				'learning'      => [ 'type' => 'array', 'items' => [ 'type' => 'object' ] ],
				'post_write'    => [ 'type' => 'object' ],
				'next_step'     => [ 'type' => 'object' ],
				'write_receipt' => [ 'type' => 'object' ],
				'transaction_id' => [ 'type' => 'string' ],
				'change_set_id' => [ 'type' => 'string' ],
				'verification_status' => [ 'type' => 'string' ],
				'rollback_status' => [ 'type' => 'string' ],
			],
		];
	}

	public function permission_callback( array $args ): bool|\WP_Error {
		$id = (int) ( $args['post_id'] ?? 0 );
		return Permissions::edit_post( $id );
	}

	public function execute( array $args ): array|\WP_Error {
		return $this->audit(
			$args,
			function ( array $args ) {
				$start      = microtime( true );
				$post_id    = (int) $args['post_id'];
				$operations = isset( $args['operations'] ) && is_array( $args['operations'] ) ? self::normalize_operations( array_values( $args['operations'] ) ) : [];
				$operations = self::apply_batch_responsive_scope( $operations, $args );
				$dry_run    = ! empty( $args['dry_run'] );
				$require_evidence = ! empty( $args['require_evidence'] );
				$idempotency_key  = isset( $args['idempotency_key'] ) ? trim( (string) $args['idempotency_key'] ) : '';
				$change_set_id    = isset( $args['change_set_id'] ) ? trim( (string) $args['change_set_id'] ) : '';
				$request_hash     = self::request_hash( $post_id, $operations, $args );

				if ( ! get_post( $post_id ) ) {
					return $this->error( 'not_found', __( 'Post not found.', 'stonewright' ) );
				}
				if ( [] === $operations ) {
					return $this->error( 'missing_operations', __( 'At least one batch operation is required.', 'stonewright' ), [ 'status' => 400 ] );
				}

				$css_gate = ElementorCustomCssGate::assert_incoming( [ 'operations' => $operations ], $args );
				if ( $css_gate instanceof \WP_Error ) {
					return $css_gate;
				}

				if ( ! $dry_run && self::contains_destructive_operation( $operations ) ) {
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

				if ( ! $dry_run ) {
					$replay = IdempotencyStore::lookup( $post_id, $idempotency_key, $request_hash );
					if ( $replay instanceof \WP_Error || is_array( $replay ) ) {
						return $replay;
					}
				}

				$read_start = microtime( true );
				$tree       = ElementorData::read( $post_id );
				$read_ms    = self::elapsed_ms( $read_start );
				$before_hash = TreeHasher::hash( $tree );
				$targeted_ids = self::operation_target_ids( $operations );
				$architecture_digest = ArchitectureRouter::digest( $tree, $targeted_ids );
				$architecture = (string) ( $architecture_digest['architecture'] ?? 'empty' );
				$receipt      = new ElementorWriteReceipt( $post_id, $architecture, $targeted_ids, $dry_run, $change_set_id !== '' ? $change_set_id : 'cs-' . substr( $request_hash, 0, 24 ) );
				$receipt->set( 'architecture_digest', $architecture_digest );
				$receipt->set_hashes( $before_hash, '' );
				if ( 'mixed' === $architecture && self::contains_unparented_add( $operations ) ) {
					return $this->error(
						'mixed_root_add_blocked',
						__( 'Mixed Elementor documents require every added V3 node to name an existing V3-only parent.', 'stonewright' ),
						[
							'status'       => 409,
							'architecture' => $architecture,
							'before_hash'  => $before_hash,
							'repair'       => 'Run elementor-document-health, read the current structure, then set parent_id or parent_ref to a V3-only container.',
							'write_receipt' => $receipt->fail( $this->error( 'mixed_root_add_blocked', '', [] ) )->to_array(),
						]
					);
				}
				$blocking     = [];
				foreach ( $targeted_ids as $target_id ) {
					$subtree = AtomicTreeInspector::subtree_architecture( $tree, $target_id );
					if ( in_array( $subtree, [ 'v4', 'mixed' ], true ) ) {
						$blocking[ $target_id ] = $subtree;
					}
				}
				if ( [] !== $blocking ) {
					return $this->error(
						'v3_architecture_mismatch',
						__( 'A targeted Elementor element is or contains V4 Atomic nodes. V3 batch mutation of that element is blocked.', 'stonewright' ),
						[
							'status'          => 409,
							'architecture'    => $architecture,
							'blocked_targets' => $blocking,
							'v3_safe_roots'   => AtomicTreeInspector::v3_safe_roots( $tree, 100 ),
							'before_hash'     => $before_hash,
							'repair'          => 'Target one returned v3_safe_root with a surgical batch, or use a typed V4 ability. Never rewrite the mixed document root.',
							'write_receipt'   => $receipt->fail( $this->error( 'v3_architecture_mismatch', '', [] ) )->to_array(),
						]
					);
				}
				$expected_tree_hash = isset( $args['expected_tree_hash'] ) ? (string) $args['expected_tree_hash'] : '';
				if ( '' !== $expected_tree_hash && ! hash_equals( $expected_tree_hash, $before_hash ) ) {
					return $this->error(
						'tree_conflict',
						__( 'Elementor page changed after planning; refresh structure before writing.', 'stonewright' ),
							[ 'status' => 409, 'expected_tree_hash' => $expected_tree_hash, 'current_tree_hash' => $before_hash, 'write_receipt' => $receipt->fail( $this->error( 'tree_conflict', '', [] ) )->to_array() ]
					);
				}
				$items      = [];
				$refs       = [];
				$applied    = 0;
				$failed     = 0;
				$allow_unknown_setting_removal = false;
				$stop       = array_key_exists( 'stop_on_error', $args ) ? (bool) $args['stop_on_error'] : ! $dry_run;
				$task_hash  = ExecutionContext::task_hash();

				foreach ( $operations as $index => $operation ) {
					$operation = is_array( $operation ) ? $operation : [];
					$result    = $this->apply_operation( $tree, $operation, $refs, $require_evidence, $dry_run );

					if ( $result instanceof \WP_Error ) {
						if ( 'add_widget' === (string) ( $operation['action'] ?? '' ) ) {
							SchemaRepairLearning::observe_compilation_error(
								(string) ( $operation['widget_type'] ?? '' ),
								is_array( $operation['settings'] ?? null ) ? $operation['settings'] : [],
								$result,
								$task_hash
							);
						}
						++$failed;
						$items[] = self::error_item( $index, $result );
						if ( $stop ) {
							$cause_code = (string) $result->get_error_code();
							$action     = (string) ( $operation['action'] ?? '' );
							return $this->error(
								'batch_operation_failed',
								sprintf( __( 'Elementor batch operation %1$d (%2$s) failed: %3$s', 'stonewright' ), $index, $action, $result->get_error_message() ),
								self::batch_failure_data( $items, $applied, $failed, $index, $action, $cause_code, $before_hash, $tree, $result, $receipt )
							);
						}
						continue;
					}

					++$applied;
					if ( ! empty( $result['unknown_setting_removal_approved'] ) ) {
						$allow_unknown_setting_removal = true;
					}
					$items[] = array_merge(
						[
							'index' => $index,
							'ok'    => true,
						],
						$result
					);
				}

				if ( 0 < $failed ) {
					$first_failed = array_values( array_filter( $items, static fn( array $item ): bool => empty( $item['ok'] ) ) )[0];
					$failed_index = (int) $first_failed['index'];
					$failed_action = (string) ( $operations[ $failed_index ]['action'] ?? '' );
					$cause_code = (string) ( $first_failed['error']['code'] ?? '' );
					return $this->error(
						'batch_operation_failed',
						sprintf( __( 'Elementor batch validation failed for %d operation(s). No page data was written.', 'stonewright' ), $failed ),
						self::batch_failure_data(
							$items,
							$applied,
							$failed,
							$failed_index,
							$failed_action,
							$cause_code,
							$before_hash,
							$tree,
							new \WP_Error( $cause_code, 'Batch validation failed.', is_array( $first_failed['error']['data'] ?? null ) ? $first_failed['error']['data'] : [] ),
							$receipt,
							' Fix every reported operation before retrying; no partial batch is persisted.'
						)
					);
				}

				$touched_ids = $targeted_ids;
				foreach ( $items as $item ) {
					if ( isset( $item['element_id'] ) && is_scalar( $item['element_id'] ) ) {
						$touched_ids[] = (string) $item['element_id'];
					}
				}
				$touched_ids = array_values( array_unique( array_filter( $touched_ids ) ) );

				$snapshot_id = '';
				$write_ms    = 0.0;
				$after_hash  = TreeHasher::hash( $tree );
				$readback_hash = $dry_run ? $after_hash : '';
				if ( ! $dry_run ) {
					$lock_owner = 'batch-' . substr( $request_hash, 0, 24 );
					$lease      = PostWriteLock::acquire( $post_id, $lock_owner );
					if ( $lease instanceof \WP_Error ) {
						$lease_data = (array) $lease->get_error_data();
						$receipt->set_lock(
							[
								'status'      => 'busy',
								'fingerprint' => $lease_data['lock_fingerprint'] ?? '',
								'age_seconds' => $lease_data['lock_age_seconds'] ?? 0,
								'retry_after' => $lease_data['retry_after'] ?? 0,
								'expires_at'  => $lease_data['lock_expires_at'] ?? 0,
							]
						);
						$receipt->fail( $lease, 'lock.acquire' );
						return new \WP_Error( $lease->get_error_code(), $lease->get_error_message(), array_merge( $lease_data, [ 'write_receipt' => $receipt->to_array() ] ) );
					}
					$receipt->set_lock( [ 'status' => 'acquired', 'owner' => $lock_owner, 'expires_at' => $lease['expires_at'], 'age_seconds' => 0 ] );
					try {
						$current_tree_hash = TreeHasher::hash( ElementorData::read( $post_id ) );
						if ( ! hash_equals( $before_hash, $current_tree_hash ) ) {
							$conflict = $this->error(
								'tree_conflict',
								__( 'Elementor page changed before the batch acquired its write lock; refresh structure before retrying.', 'stonewright' ),
								[
									'status'            => 409,
									'expected_tree_hash'=> $before_hash,
									'current_tree_hash' => $current_tree_hash,
									'retryable'         => true,
								]
							);
							$receipt->fail( $conflict, 'lock.recheck' );
							return new \WP_Error( $conflict->get_error_code(), $conflict->get_error_message(), array_merge( (array) $conflict->get_error_data(), [ 'write_receipt' => $receipt->to_array() ] ) );
						}
						$snapshot_id = Backup::snapshot_post( $post_id );
						if ( '' === $snapshot_id || null === Backup::get_snapshot( $post_id, $snapshot_id ) ) {
							$snapshot_error = $this->error(
								'backup_failed',
								__( 'Elementor write blocked because the pre-write snapshot could not be persisted.', 'stonewright' ),
								[ 'status' => 500, 'post_id' => $post_id, 'retryable' => false ]
							);
							$receipt->fail( $snapshot_error, 'backup.snapshot' );
							return new \WP_Error(
								$snapshot_error->get_error_code(),
								$snapshot_error->get_error_message(),
								array_merge( (array) $snapshot_error->get_error_data(), [ 'write_receipt' => $receipt->to_array() ] )
							);
						}
						$receipt->set_snapshot( $snapshot_id );
						$write_start = microtime( true );
						// Surgical batch may remove large subtrees; force_destructive is bound to this
						// intentional, snapshotted write (not an accidental silent collapse).
						if ( ! ElementorData::write( $post_id, $tree, [ 'force_destructive' => true, 'touched_ids' => $touched_ids, 'lock_owner' => $lock_owner, 'defer_rollback' => true, 'allow_unknown_setting_removal' => $allow_unknown_setting_removal ] ) ) {
							$err      = ElementorData::write_error_for_ability();
							$err_data = (array) $err->get_error_data();
							$needs_rollback = 'pending' === (string) ( $err_data['rollback_status'] ?? '' );
							$restored = null;
							if ( $needs_rollback ) {
								$restored = Backup::restore( $post_id, $snapshot_id );
								$receipt->rollback( $restored ? 'succeeded' : 'failed', [ 'snapshot_id' => $snapshot_id, 'primary_error_code' => $err->get_error_code() ] );
							} else {
								$receipt->rollback( 'not_needed' );
							}
							$receipt->fail( $err, 'write.persist' );
							// Preserve restore info for the agent without losing gate codes/fix hints.
							return new \WP_Error(
								$err->get_error_code(),
								$err->get_error_message(),
								array_merge( $err_data, [ 'restored' => $restored, 'rollback_status' => $receipt->to_array()['rollback_status'], 'write_receipt' => $receipt->to_array() ] )
							);
						}
						$write_ms      = self::elapsed_ms( $write_start );
						$readback_hash = TreeHasher::hash( ElementorData::read( $post_id ) );
						if ( ! hash_equals( $after_hash, $readback_hash ) ) {
							$restored = Backup::restore( $post_id, $snapshot_id );
							$readback_error = $this->error(
								'readback_mismatch',
								__( 'Elementor write readback did not match the compiled tree; the snapshot was restored.', 'stonewright' ),
								[ 'status' => 500, 'expected_hash' => $after_hash, 'readback_hash' => $readback_hash, 'restored' => $restored ]
							);
							$receipt->set_hashes( $before_hash, $after_hash, $after_hash, $readback_hash )->rollback( $restored ? 'succeeded' : 'failed', [ 'snapshot_id' => $snapshot_id ] )->fail( $readback_error, 'verify.readback' );
							return new \WP_Error( $readback_error->get_error_code(), $readback_error->get_error_message(), array_merge( (array) $readback_error->get_error_data(), [ 'rollback_status' => $receipt->to_array()['rollback_status'], 'write_receipt' => $receipt->to_array() ] ) );
						}
						$receipt->set_hashes( $before_hash, $after_hash, $after_hash, $readback_hash )->verified();
					} finally {
						PostWriteLock::release( $post_id, $lock_owner );
					}
				}

				$element_count = count( ElementorData::flatten( $tree ) );
				if ( $dry_run ) {
					$receipt->set_hashes( $before_hash, $after_hash, $after_hash, $after_hash )->verified( 'planned' );
				}
				$response      = [
					'ok'            => 0 === $failed,
					'post_id'       => $post_id,
					'dry_run'       => $dry_run,
					'snapshot_id'   => $snapshot_id,
					'applied'       => $applied,
					'failed'        => $failed,
					'items'         => $items,
					'refs'          => $refs,
					'elements'      => $element_count,
					'element_count' => $element_count,
					'metrics'       => [
						'elapsed_ms' => self::elapsed_ms( $start ),
						'read_ms'    => $read_ms,
						'write_ms'   => $write_ms,
					],
					'before_hash'   => $before_hash,
					'after_hash'    => $after_hash,
					'readback_hash' => $readback_hash,
					'idempotent_replay' => false,
					'learning'      => [],
					'post_write'    => $dry_run ? [] : ElementorData::last_write_receipt(),
					'next_step'     => $dry_run
						? [
							'tool'               => 'stonewright/elementor-v3-batch-mutate',
							'expected_tree_hash' => $before_hash,
							'then'               => 'stonewright/elementor-post-write-verify',
						]
						: [
							'tool'        => 'stonewright/elementor-post-write-verify',
							'post_id'     => $post_id,
							'element_ids' => $touched_ids,
							'required_before_browser_acceptance' => true,
						],
					'write_receipt' => $receipt->to_array(),
					'transaction_id' => $receipt->to_array()['transaction_id'],
					'change_set_id'  => $receipt->to_array()['change_set_id'],
				];
				$response['verification_status'] = (string) ( $receipt->to_array()['verification_status'] ?? '' );
				$response['rollback_status']     = (string) ( $receipt->to_array()['rollback_status'] ?? 'not_needed' );

				if ( $dry_run ) {
					$response['preview'] = $tree;
				} else {
					$learning = [];
					foreach ( $operations as $operation ) {
						if ( 'add_widget' !== (string) ( $operation['action'] ?? '' ) ) {
							continue;
						}
						$widget_type = (string) ( $operation['widget_type'] ?? '' );
						$schema      = WidgetSchemaRepository::get( $widget_type );
						if ( $schema instanceof \WP_Error ) {
							continue;
						}
						$learning = array_merge(
							$learning,
							SchemaRepairLearning::observe_verified(
								$widget_type,
								is_array( $operation['settings'] ?? null ) ? $operation['settings'] : [],
								$schema,
								$task_hash
							)
						);
					}
					$response['learning'] = self::learning_summary( $learning );
					IdempotencyStore::remember( $post_id, $idempotency_key, $request_hash, $response );
				}

				return $response;
			}
		);
	}

	/**
	 * @param list<array<string, mixed>> $rows
	 * @return list<array{id:int,status:string,verification_count:int}>
	 */
	private static function learning_summary( array $rows ): array {
		return array_values(
			array_map(
				static fn( array $row ): array => [
					'id'                 => (int) ( $row['candidate']['id'] ?? 0 ),
					'status'             => (string) ( $row['candidate']['status'] ?? '' ),
					'verification_count' => (int) ( $row['candidate']['verification_count'] ?? 0 ),
				],
				$rows
			)
		);
	}

	/**
	 * @param array<int, mixed> $operations
	 * @return array<int, array<string, mixed>>
	 */
	private static function normalize_operations( array $operations ): array {
		return array_map(
			static fn( mixed $operation ): array => self::normalize_operation( is_array( $operation ) ? $operation : [] ),
			$operations
		);
	}

	/**
	 * @param array<int, mixed> $operations
	 * @return list<string>
	 */
	private static function operation_target_ids( array $operations ): array {
		$ids = [];
		foreach ( $operations as $operation ) {
			if ( ! is_array( $operation ) ) {
				continue;
			}
			foreach ( [ 'element_id', 'target_id', 'parent_id', 'new_parent_id', 'container_id', 'reference_id' ] as $key ) {
				if ( isset( $operation[ $key ] ) && is_scalar( $operation[ $key ] ) ) {
					$value = trim( (string) $operation[ $key ] );
					if ( '' !== $value ) {
						$ids[] = $value;
					}
				}
			}
		}
		return array_values( array_unique( $ids ) );
	}

	/**
	 * @param array<string, mixed> $operation
	 * @return array<string, mixed>
	 */
	private static function normalize_operation( array $operation ): array {
		$normalized = $operation;
		$action     = (string) ( $normalized['action'] ?? $normalized['operation'] ?? $normalized['type'] ?? $normalized['op'] ?? '' );
		if ( '' !== $action ) {
			$normalized['action'] = self::normalize_action( $action );
		}

		self::copy_ref_or_id_alias( $normalized, 'parent', 'parent_ref', 'parent_id' );
		self::copy_ref_or_id_alias( $normalized, 'target', 'element_ref', 'element_id' );
		self::copy_ref_or_id_alias( $normalized, 'new_parent', 'new_parent_ref', 'new_parent_id' );

		if ( isset( $normalized['target_id'] ) && ! isset( $normalized['element_id'] ) ) {
			$normalized['element_id'] = (string) $normalized['target_id'];
		}
		if ( isset( $normalized['target_ref'] ) && ! isset( $normalized['element_ref'] ) ) {
			$normalized['element_ref'] = (string) $normalized['target_ref'];
		}
		if ( isset( $normalized['widget'] ) && ! isset( $normalized['widget_type'] ) ) {
			$normalized['widget_type'] = (string) $normalized['widget'];
		}

		return $normalized;
	}

	private static function normalize_action( string $action ): string {
		return match ( $action ) {
			'container' => 'add_container',
			'widget'    => 'add_widget',
			'update'    => 'update_element',
			'move'      => 'move_element',
			'remove', 'delete' => 'remove_element',
			'update-element' => 'update_element',
			'patch-repeater-row' => 'patch_repeater_row',
			'move-element'   => 'move_element',
			'remove-element' => 'remove_element',
			default     => $action,
		};
	}

	/**
	 * @param array<string, mixed> $operation
	 */
	private static function copy_ref_or_id_alias( array &$operation, string $alias, string $ref_key, string $id_key ): void {
		if ( ! isset( $operation[ $alias ] ) || isset( $operation[ $ref_key ] ) || isset( $operation[ $id_key ] ) ) {
			return;
		}

		$value = (string) $operation[ $alias ];
		if ( str_starts_with( $value, '@' ) ) {
			$operation[ $ref_key ] = substr( $value, 1 );
			return;
		}
		$operation[ $id_key ] = $value;
	}

	/**
	 * @param array<int, array<string, mixed>> $tree
	 * @param array<string, mixed>            $operation
	 * @param array<string, string>           $refs
	 * @return array<string, mixed>|\WP_Error
	 */
	private function apply_operation( array &$tree, array $operation, array &$refs, bool $require_evidence, bool $dry_run ): array|\WP_Error {
		$css_gate = ElementorCustomCssGate::assert_incoming( $operation, $operation, (string) ( $operation['widget_type'] ?? '' ) );
		if ( $css_gate instanceof \WP_Error ) {
			return $css_gate;
		}
		$action = isset( $operation['action'] ) ? (string) $operation['action'] : '';

		return match ( $action ) {
			'add_container'  => $this->add_container( $tree, $operation, $refs, $require_evidence ),
			'add_widget'     => $this->add_widget( $tree, $operation, $refs, $require_evidence ),
			'update_element' => $this->update_element( $tree, $operation, $refs, $require_evidence, $dry_run ),
			'patch_repeater_row' => $this->patch_repeater_row( $tree, $operation, $refs, $require_evidence ),
			'move_element'   => $this->move_element( $tree, $operation, $refs ),
			'remove_element' => $this->remove_element( $tree, $operation, $refs ),
			default          => $this->error( 'invalid_action', __( 'Unsupported Elementor batch action.', 'stonewright' ), [ 'action' => $action ] ),
		};
	}

	/**
	 * @param array<int, array<string, mixed>> $tree
	 * @param array<string, mixed>            $operation
	 * @param array<string, string>           $refs
	 * @return array<string, mixed>|\WP_Error
	 */
	private function add_container( array &$tree, array $operation, array &$refs, bool $require_evidence ): array|\WP_Error {
		$parent_path = $this->parent_path( $tree, $operation, $refs, 'parent_id', 'parent_ref' );
		if ( $parent_path instanceof \WP_Error ) {
			return $parent_path;
		}

		$settings  = isset( $operation['settings'] ) && is_array( $operation['settings'] ) ? $operation['settings'] : [];
		$scope     = self::validate_responsive_scope( $operation, $settings, 'container' );
		if ( $scope instanceof \WP_Error ) {
			return $scope;
		}
		$settings  = ContainerSettings::normalize( $settings );
		$validated = SettingsValidator::validate_container( $settings );
		if ( $validated instanceof \WP_Error ) {
			return $validated;
		}
		$settings = $validated['settings'];
		$evidence = EvidenceValidator::validate( 'container', $settings, self::operation_evidence( $operation ), $require_evidence );
		if ( $evidence instanceof \WP_Error ) {
			return $evidence;
		}
		$settings = SparseSettingsNormalizer::for_new_write( $settings, 'container', $settings );
		$element  = [
			'id'       => ElementorData::generate_id(),
			'elType'   => 'container',
			'isInner'  => [] !== $parent_path,
			'settings' => $settings,
			'elements' => [],
		];

		$position = isset( $operation['position'] ) ? (int) $operation['position'] : PHP_INT_MAX;
		$tree     = ElementorData::insert( $tree, $parent_path, $position, $element );

		return array_merge(
			$this->created_item( $operation, $refs, $element['id'], 'container' ),
			[ 'evidence' => $evidence, 'allowed_breakpoints' => $scope ],
			[] !== $validated['warnings'] ? [ 'normalization_warnings' => $validated['warnings'] ] : []
		);
	}

	/**
	 * @param array<int, array<string, mixed>> $tree
	 * @param array<string, mixed>            $operation
	 * @param array<string, string>           $refs
	 * @return array<string, mixed>|\WP_Error
	 */
	private function add_widget( array &$tree, array $operation, array &$refs, bool $require_evidence ): array|\WP_Error {
		$widget_type = isset( $operation['widget_type'] ) ? (string) $operation['widget_type'] : '';
		if ( '' === $widget_type ) {
			return $this->error( 'missing_widget_type', __( 'add_widget requires widget_type.', 'stonewright' ) );
		}
		if ( str_starts_with( $widget_type, 'e-' ) ) {
			return $this->error( 'atomic_widget_in_v3_batch', __( 'Atomic e-* widgets cannot be added to an Elementor V3 tree.', 'stonewright' ), [ 'status' => 409, 'widget_type' => $widget_type, 'repair' => 'Use the Elementor V4 editor pipeline.' ] );
		}
		if ( \Stonewright\WpMcp\Elementor\HtmlWidgetPolicy::is_html_type( $widget_type ) ) {
			$policy = \Stonewright\WpMcp\Elementor\HtmlWidgetPolicy::allowed( $operation );
			if ( $policy instanceof \WP_Error ) {
				return $policy;
			}
		}
		$settings = isset( $operation['settings'] ) && is_array( $operation['settings'] ) ? $operation['settings'] : [];
		$css_gate = ElementorCustomCssGate::assert_incoming( $settings, $operation, $widget_type );
		if ( $css_gate instanceof \WP_Error ) {
			return $css_gate;
		}
		$scope    = self::validate_responsive_scope( $operation, $settings, $widget_type );
		if ( $scope instanceof \WP_Error ) {
			return $scope;
		}
		$warnings = [];
		if ( 'html' !== $widget_type ) {
			$validated = SettingsValidator::validate( $widget_type, $settings );
			if ( $validated instanceof \WP_Error ) {
				return $validated;
			}
			$settings = $validated['settings'];
			$warnings = $validated['warnings'];
		}
		$evidence = EvidenceValidator::validate( $widget_type, $settings, self::operation_evidence( $operation ), $require_evidence );
		if ( $evidence instanceof \WP_Error ) {
			return $evidence;
		}
		if ( 'html' !== $widget_type ) {
			$settings = SparseSettingsNormalizer::for_new_write( $settings, $widget_type, $settings );
		}

		if ( isset( $operation['parent_ref'] ) ) {
			$parent_ref = (string) $operation['parent_ref'];
			if ( ! isset( $refs[ $parent_ref ] ) ) {
				return $this->error( 'unknown_ref', __( 'Batch operation references an unknown op_id.', 'stonewright' ), [ 'ref' => $parent_ref ] );
			}
			$operation['parent_id'] = $refs[ $parent_ref ];
			unset( $operation['parent_ref'] );
		}
		$operation['settings'] = $settings;
		$compiled = ( new V3MutationCompiler() )->compile( $tree, [ $operation ] );
		if ( $compiled instanceof \WP_Error ) {
			return $compiled;
		}
		$tree       = $compiled['tree'];
		$element_id = (string) ( $compiled['items'][0]['element_id'] ?? '' );

		return array_merge(
			$this->created_item( $operation, $refs, $element_id, 'widget' ),
			[ 'evidence' => $evidence, 'allowed_breakpoints' => $scope ],
			[] !== $warnings ? [ 'normalization_warnings' => $warnings ] : []
		);
	}

	/**
	 * @param array<int, array<string, mixed>> $tree
	 * @param array<string, mixed>            $operation
	 * @param array<string, string>           $refs
	 * @return array<string, mixed>|\WP_Error
	 */
	private function update_element( array &$tree, array $operation, array $refs, bool $require_evidence, bool $dry_run ): array|\WP_Error {
		$element_id = $this->element_id( $operation, $refs );
		if ( $element_id instanceof \WP_Error ) {
			return $element_id;
		}

		$path = ElementorData::find_path( $tree, $element_id );
		if ( null === $path ) {
			return $this->error( 'element_not_found', __( 'Element not found.', 'stonewright' ), [ 'element_id' => $element_id ] );
		}

		$element = self::resolve( $tree, $path );
		if ( null === $element ) {
			return $this->error( 'element_not_found', __( 'Element not found.', 'stonewright' ), [ 'element_id' => $element_id ] );
		}

		$incoming = isset( $operation['settings'] ) && is_array( $operation['settings'] ) ? $operation['settings'] : [];
		$existing = isset( $element['settings'] ) && is_array( $element['settings'] ) ? $element['settings'] : [];
		$mode     = isset( $operation['mode'] ) ? (string) $operation['mode'] : 'merge';
		$settings = 'replace' === $mode ? $incoming : array_merge( $existing, $incoming );
		$element_type = (string) ( $element['elType'] ?? '' );
		$effective_before = $existing;
		$warnings = [];
		$third_party_risk = null;
		$unknown_setting_removal_approved = false;
		$scope_widget_type = 'widget' === $element_type ? (string) ( $element['widgetType'] ?? '' ) : $element_type;
		$scope = self::validate_responsive_scope( $operation, $incoming, $scope_widget_type );
		if ( $scope instanceof \WP_Error ) {
			return $scope;
		}
		$non_target_before = ResponsiveScope::hash_non_target_breakpoints( $existing, $scope );
		$empty_non_target  = ResponsiveScope::hash_non_target_breakpoints( [], $scope );
		if ( 'replace' === $mode && ! hash_equals( $empty_non_target, $non_target_before ) ) {
			return $this->error(
				'responsive_scope_violation',
				__( 'Replace mode would delete settings outside the authorized breakpoint scope. Use merge mode.', 'stonewright' ),
				[
					'status'                 => 400,
					'element_id'             => $element_id,
					'allowed_breakpoints'    => $scope,
					'non_target_before_hash' => $non_target_before,
				]
			);
		}
		if ( in_array( $element_type, [ 'container', 'section', 'column' ], true ) ) {
			$container_alias_warnings = self::container_alias_warnings( $incoming );
			$incoming = 'container' === $element_type ? ContainerSettings::normalize( $incoming ) : $incoming;
			$before    = 'container' === $element_type ? ContainerSettings::normalize( $existing ) : $existing;
			$effective_before = $before;
			$settings  = 'container' === $element_type ? ContainerSettings::normalize( $settings ) : $settings;
			$validated = PatchValidator::container( $before, $incoming, $element_type, $mode );
			if ( $validated instanceof \WP_Error ) {
				return $validated;
			}
			$settings = $validated['settings'];
			$warnings = array_merge( $container_alias_warnings, $validated['warnings'] );
			$incoming = self::changed_settings( $before, $settings );
			$evidence_widget_type = $element_type;
		} elseif ( 'widget' === ( $element['elType'] ?? '' ) ) {
			$widget_type = (string) ( $element['widgetType'] ?? '' );
			if ( 'replace' === $mode || array_key_exists( 'form_fields', $incoming ) ) {
				$live_schema = WidgetSchemaRepository::get( $widget_type );
				if ( $live_schema instanceof \WP_Error ) {
					return $live_schema;
				}
				$known_controls = array_map( 'strval', array_keys( is_array( $live_schema['controls'] ?? null ) ? $live_schema['controls'] : [] ) );
				$third_party_risk = ThirdPartyControlRiskMap::analyze(
					$existing,
					$incoming,
					[
						'known_controls' => $known_controls,
						'operation_mode' => $mode,
						'actions'        => $existing['actions_after_submit'] ?? $existing['submit_actions'] ?? [],
					]
				);
				if ( array_key_exists( 'form_fields', $incoming ) && isset( $existing['form_fields'] ) && $incoming['form_fields'] !== $existing['form_fields'] ) {
					$third_party_risk['destructive_replace_risk'] = true;
				}
				if ( ! empty( $third_party_risk['destructive_replace_risk'] ) ) {
					$approved_hash = trim( (string) ( $operation['approved_preservation_hash'] ?? '' ) );
					$required_hash = (string) ( $third_party_risk['preservation_hash_before'] ?? '' );
					if ( empty( $operation['allow_high_risk_replace'] ) || ( ! $dry_run && ( '' === $approved_hash || ! hash_equals( $required_hash, $approved_hash ) ) ) ) {
						return $this->error(
							'third_party_replace_blocked',
							__( 'A full Elementor settings/repeater replacement would risk third-party or form controls.', 'stonewright' ),
							[
								'status'                     => 409,
								'element_id'                 => $element_id,
								'risk'                       => $third_party_risk,
								'required_preservation_hash' => $required_hash,
								'repair'                     => 'Use patch_repeater_row for one form field. For an unavoidable full replace, run dry_run=true with allow_high_risk_replace=true, review the risk map, then send its preservation hash as approved_preservation_hash.',
							]
						);
					}
					$warnings[] = [
						'code'              => $dry_run ? 'high_risk_replace_dry_run' : 'high_risk_replace_approved',
						'preservation_hash' => $required_hash,
					];
					$unknown_setting_removal_approved = ! $dry_run
						&& '' !== $approved_hash
						&& hash_equals( $required_hash, $approved_hash );
				}
			}
			$validated   = PatchValidator::widget( $widget_type, $effective_before, $incoming, $mode );
			if ( $validated instanceof \WP_Error ) {
				return $validated;
			}
			$settings = $validated['settings'];
			$warnings = array_merge( $warnings, $validated['warnings'] );
			$incoming = self::changed_settings( $effective_before, $settings );
			$evidence_widget_type = $widget_type;
		} else {
			$evidence_widget_type = 'container';
		}
		$evidence = EvidenceValidator::validate( $evidence_widget_type, $incoming, self::operation_evidence( $operation ), $require_evidence );
		if ( $evidence instanceof \WP_Error ) {
			return $evidence;
		}
		$schema = in_array( $evidence_widget_type, [ 'container', 'section', 'column' ], true )
			? ContainerSchemaRepository::get( $evidence_widget_type )
			: WidgetSchemaRepository::get( $evidence_widget_type );
		if ( is_array( $schema ) ) {
			$settings = SparseSettingsNormalizer::for_write(
				$settings,
				(array) ( $schema['controls'] ?? [] ),
				$incoming,
				$effective_before,
				array_map( 'strval', (array) ( $schema['required_for_render'] ?? [] ) )
			);
		}
		if ( $settings === $effective_before ) {
			return $this->error(
				'no_effective_changes',
				__( 'The requested Elementor update produced no effective setting changes.', 'stonewright' ),
				[
					'element_id' => $element_id,
					'repair'     => 'Read the live container/widget schema and send settings that survive validation unchanged.',
				]
			);
		}
		$non_target_after = ResponsiveScope::hash_non_target_breakpoints( $settings, $scope );
		if ( ! hash_equals( $non_target_before, $non_target_after ) ) {
			return $this->error(
				'responsive_scope_violation',
				__( 'The requested Elementor update would change a non-target breakpoint. No write was performed.', 'stonewright' ),
				[
					'status'                 => 400,
					'element_id'             => $element_id,
					'allowed_breakpoints'    => $scope,
					'non_target_before_hash' => $non_target_before,
					'non_target_after_hash'  => $non_target_after,
				]
			);
		}

		$element['settings'] = $settings;
		$tree                = ElementorData::set( $tree, $path, $element );

		return array_merge( [
			'action'     => 'update_element',
			'element_id' => $element_id,
			'evidence'   => $evidence,
			'allowed_breakpoints'    => $scope,
			'non_target_before_hash' => $non_target_before,
			'non_target_after_hash'  => $non_target_after,
		], [] !== $warnings ? [ 'normalization_warnings' => $warnings ] : [], is_array( $third_party_risk ) ? [ 'third_party_risk' => $third_party_risk ] : [], $unknown_setting_removal_approved ? [ 'unknown_setting_removal_approved' => true ] : [] );
	}

	/**
	 * Patch one repeater row selected by stable identity. This path never
	 * replaces the repeater list and returns preservation hashes for review.
	 *
	 * @param array<int,array<string,mixed>> $tree
	 * @param array<string,mixed>            $operation
	 * @param array<string,string>           $refs
	 * @return array<string,mixed>|\WP_Error
	 */
	private function patch_repeater_row( array &$tree, array $operation, array $refs, bool $require_evidence ): array|\WP_Error {
		$element_id = $this->element_id( $operation, $refs );
		if ( $element_id instanceof \WP_Error ) {
			return $element_id;
		}
		$path = ElementorData::find_path( $tree, $element_id );
		$element = null === $path ? null : self::resolve( $tree, $path );
		if ( null === $path || ! is_array( $element ) || 'widget' !== (string) ( $element['elType'] ?? '' ) ) {
			return $this->error( 'element_not_found', __( 'Repeater patches require an existing Elementor widget.', 'stonewright' ), [ 'element_id' => $element_id ] );
		}

		$widget_type  = (string) ( $element['widgetType'] ?? '' );
		$repeater_key = (string) ( $operation['repeater_key'] ?? '' );
		$selector     = is_array( $operation['selector'] ?? null ) ? $operation['selector'] : [];
		$row_patch    = is_array( $operation['row_patch'] ?? null ) ? $operation['row_patch'] : [];
		$existing     = is_array( $element['settings'] ?? null ) ? $element['settings'] : [];
		$schema       = WidgetSchemaRepository::get( $widget_type );
		if ( $schema instanceof \WP_Error ) {
			return $schema;
		}
		$control = is_array( $schema['controls'][ $repeater_key ] ?? null ) ? $schema['controls'][ $repeater_key ] : [];
		if ( 'repeater' !== (string) ( $control['type'] ?? '' ) ) {
			return $this->error( 'repeater_schema_missing', __( 'The requested setting is not a repeater in the live Elementor schema.', 'stonewright' ), [ 'repeater_key' => $repeater_key, 'widget_type' => $widget_type ] );
		}
		$known_fields = array_map( 'strval', array_keys( is_array( $control['fields'] ?? null ) ? $control['fields'] : [] ) );
		foreach ( array_keys( $row_patch ) as $field ) {
			if ( ! in_array( (string) $field, $known_fields, true ) ) {
				return $this->error( 'repeater_field_unknown', __( 'The repeater row patch contains a field absent from the live schema.', 'stonewright' ), [ 'path' => $repeater_key . '.' . (string) $field, 'known_fields' => $known_fields ] );
			}
		}

		$patched = RepeaterPatcher::patch( $existing, $repeater_key, $selector, $row_patch, (string) ( $operation['expected_row_hash'] ?? '' ) );
		if ( $patched instanceof \WP_Error ) {
			return $patched;
		}
		$settings_patch = [ $repeater_key => $patched['settings'][ $repeater_key ] ];
		$validated = PatchValidator::widget( $widget_type, $existing, $settings_patch, 'merge' );
		if ( $validated instanceof \WP_Error ) {
			return $validated;
		}
		$evidence = EvidenceValidator::validate( $widget_type, $settings_patch, self::operation_evidence( $operation ), $require_evidence );
		if ( $evidence instanceof \WP_Error ) {
			return $evidence;
		}
		$element['settings'] = $validated['settings'];
		$tree = ElementorData::set( $tree, $path, $element );

		return array_merge(
			[
				'action'       => 'patch_repeater_row',
				'element_id'   => $element_id,
				'evidence'     => $evidence,
				'repeater_key' => $patched['repeater_key'],
				'row_index'    => $patched['row_index'],
				'selector'     => $patched['selector'],
				'changed_paths'=> $patched['changed_paths'],
				'preservation' => [
					'row_hash_before' => $patched['row_hash_before'],
					'row_hash_after'  => $patched['row_hash_after'],
					'unknown_fields_hash_before' => $patched['unknown_fields_hash_before'],
					'unknown_fields_hash_after'  => $patched['unknown_fields_hash_after'],
					'actions_after_submit_hash_before' => $patched['actions_after_submit_hash_before'],
					'actions_after_submit_hash_after'  => $patched['actions_after_submit_hash_after'],
				],
			],
			[] !== $validated['warnings'] ? [ 'normalization_warnings' => $validated['warnings'] ] : []
		);
	}

	/**
	 * @param array<int, array<string, mixed>> $tree
	 * @param array<string, mixed>            $operation
	 * @param array<string, string>           $refs
	 * @return array<string, mixed>|\WP_Error
	 */
	private function move_element( array &$tree, array $operation, array $refs ): array|\WP_Error {
		$element_id = $this->element_id( $operation, $refs );
		if ( $element_id instanceof \WP_Error ) {
			return $element_id;
		}

		$src_path = ElementorData::find_path( $tree, $element_id );
		if ( null === $src_path ) {
			return $this->error( 'element_not_found', __( 'Element not found.', 'stonewright' ), [ 'element_id' => $element_id ] );
		}

		$element = self::resolve( $tree, $src_path );
		if ( null === $element ) {
			return $this->error( 'element_not_found', __( 'Element not found.', 'stonewright' ), [ 'element_id' => $element_id ] );
		}

		$tree        = ElementorData::set( $tree, $src_path, null );
		$parent_path = $this->parent_path( $tree, $operation, $refs, 'new_parent_id', 'new_parent_ref' );
		if ( $parent_path instanceof \WP_Error ) {
			return $parent_path;
		}

		$position = isset( $operation['position'] ) ? (int) $operation['position'] : PHP_INT_MAX;
		$tree     = ElementorData::insert( $tree, $parent_path, $position, $element );

		return [
			'action'     => 'move_element',
			'element_id' => $element_id,
		];
	}

	/**
	 * @param array<int, array<string, mixed>> $tree
	 * @param array<string, mixed>            $operation
	 * @param array<string, string>           $refs
	 * @return array<string, mixed>|\WP_Error
	 */
	private function remove_element( array &$tree, array $operation, array $refs ): array|\WP_Error {
		$element_id = $this->element_id( $operation, $refs );
		if ( $element_id instanceof \WP_Error ) {
			return $element_id;
		}

		$path = ElementorData::find_path( $tree, $element_id );
		if ( null === $path ) {
			return $this->error( 'element_not_found', __( 'Element not found.', 'stonewright' ), [ 'element_id' => $element_id ] );
		}

		$tree = ElementorData::set( $tree, $path, null );

		return [
			'action'     => 'remove_element',
			'element_id' => $element_id,
		];
	}

	/**
	 * @param array<int, array<string, mixed>> $tree
	 * @param array<string, mixed>            $operation
	 * @param array<string, string>           $refs
	 * @return array<int, int>|\WP_Error
	 */
	private function parent_path( array $tree, array $operation, array $refs, string $id_key, string $ref_key ): array|\WP_Error {
		$parent_id = '';
		if ( isset( $operation[ $ref_key ] ) && is_string( $operation[ $ref_key ] ) ) {
			$ref = $operation[ $ref_key ];
			if ( ! isset( $refs[ $ref ] ) ) {
				return $this->error( 'unknown_ref', __( 'Batch operation references an unknown op_id.', 'stonewright' ), [ 'ref' => $ref ] );
			}
			$parent_id = $refs[ $ref ];
		} elseif ( isset( $operation[ $id_key ] ) ) {
			$parent_id = (string) $operation[ $id_key ];
		}

		if ( '' === $parent_id ) {
			return [];
		}

		$path = ElementorData::find_path( $tree, $parent_id );
		if ( null === $path ) {
			return $this->error( 'parent_not_found', __( 'Parent element not found.', 'stonewright' ), [ 'parent_id' => $parent_id ] );
		}

		return $path;
	}

	/**
	 * @param array<string, mixed>  $operation
	 * @param array<string, string> $refs
	 * @return string|\WP_Error
	 */
	private function element_id( array $operation, array $refs ): string|\WP_Error {
		if ( isset( $operation['element_ref'] ) && is_string( $operation['element_ref'] ) ) {
			$ref = $operation['element_ref'];
			if ( ! isset( $refs[ $ref ] ) ) {
				return $this->error( 'unknown_ref', __( 'Batch operation references an unknown op_id.', 'stonewright' ), [ 'ref' => $ref ] );
			}
			return $refs[ $ref ];
		}

		$element_id = isset( $operation['element_id'] ) ? (string) $operation['element_id'] : '';
		if ( '' === $element_id ) {
			return $this->error( 'missing_element_id', __( 'Operation requires element_id or element_ref.', 'stonewright' ) );
		}

		return $element_id;
	}

	/**
	 * @param array<string, mixed>  $operation
	 * @param array<string, string> $refs
	 * @return array<string, mixed>
	 */
	private function created_item( array $operation, array &$refs, string $element_id, string $kind ): array {
		if ( isset( $operation['op_id'] ) && is_string( $operation['op_id'] ) && '' !== $operation['op_id'] ) {
			$refs[ $operation['op_id'] ] = $element_id;
		}

		return [
			'action'     => 'add_' . $kind,
			'element_id' => $element_id,
		];
	}

	/**
	 * @param array<int, array<string, mixed>> $operations
	 */
	private static function contains_destructive_operation( array $operations ): bool {
		foreach ( $operations as $operation ) {
			if ( is_array( $operation ) && ( 'remove_element' === ( $operation['action'] ?? '' ) || 'replace' === ( $operation['mode'] ?? '' ) ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * @param array<int, array<string, mixed>> $operations
	 */
	private static function contains_unparented_add( array $operations ): bool {
		foreach ( $operations as $operation ) {
			$action = (string) ( $operation['action'] ?? '' );
			if ( ! in_array( $action, [ 'add_container', 'add_widget' ], true ) ) {
				continue;
			}
			$parent_id  = trim( (string) ( $operation['parent_id'] ?? '' ) );
			$parent_ref = trim( (string) ( $operation['parent_ref'] ?? '' ) );
			if ( '' === $parent_id && '' === $parent_ref ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Record legacy container aliases as warnings instead of silently losing
	 * the caller's intent during canonicalization.
	 *
	 * @param array<string,mixed> $settings
	 * @return list<array<string,string>>
	 */
	private static function container_alias_warnings( array $settings ): array {
		$warnings = [];
		foreach ( [ 'layout' => 'container_type', 'direction' => 'flex_direction' ] as $alias => $canonical ) {
			if ( array_key_exists( $alias, $settings ) ) {
				$warnings[] = [
					'code'      => 'settings_alias_applied',
					'alias'     => $alias,
					'canonical' => $canonical,
				];
			}
		}
		return $warnings;
	}

	/**
	 * @param array<int, array<string, mixed>> $tree
	 * @param array<int, int>                  $path
	 * @return array<string, mixed>|null
	 */
	private static function resolve( array $tree, array $path ): ?array {
		$current = null;
		foreach ( $path as $index ) {
			if ( ! isset( $tree[ $index ] ) ) {
				return null;
			}
			$current = $tree[ $index ];
			$tree    = isset( $current['elements'] ) && is_array( $current['elements'] ) ? $current['elements'] : [];
		}
		return $current;
	}

	private static function elapsed_ms( float $start ): float {
		return round( ( microtime( true ) - $start ) * 1000, 3 );
	}

	/**
	 * @param array<string, mixed> $operation
	 * @return array<string, mixed>
	 */
	private static function operation_evidence( array $operation ): array {
		return isset( $operation['settings_evidence'] ) && is_array( $operation['settings_evidence'] )
			? $operation['settings_evidence']
			: [];
	}

	/**
	 * @param array<string, mixed> $operation
	 * @param array<string, mixed> $settings
	 * @return list<string>|\WP_Error
	 */
	private static function validate_responsive_scope( array $operation, array $settings, string $element_type ): array|\WP_Error {
		$allowed = self::allowed_breakpoints( $operation );
		if ( $allowed instanceof \WP_Error ) {
			return $allowed;
		}

		$schema = in_array( $element_type, [ 'container', 'section', 'column' ], true )
			? ContainerSchemaRepository::get( $element_type )
			: WidgetSchemaRepository::get( $element_type );
		if ( $schema instanceof \WP_Error ) {
			return $schema;
		}
		$controls = isset( $schema['controls'] ) && is_array( $schema['controls'] ) ? $schema['controls'] : [];
		$valid    = ResponsiveScope::assert_settings_in_scope( $settings, $allowed, $controls, $element_type );
		return $valid instanceof \WP_Error ? $valid : $allowed;
	}

	/**
	 * @param array<string, mixed> $operation
	 * @return list<string>|\WP_Error
	 */
	private static function allowed_breakpoints( array $operation ): array|\WP_Error {
		$requested = ResponsiveScope::requested_names( $operation['allowed_breakpoints'] ?? null );
		if ( [] === $requested ) {
			$requested = ResponsiveScope::requested_names( $operation['responsive_scope'] ?? null );
		}
		if ( [] === $requested ) {
			foreach ( self::operation_evidence( $operation ) as $row ) {
				if ( ! is_array( $row ) ) {
					continue;
				}
				$requested = array_merge( $requested, ResponsiveScope::requested_names( $row['responsive_scope'] ?? null ) );
			}
		}
		if ( [] === $requested ) {
			$requested = [ 'desktop' ];
		}

		$allowed = [];
		$known   = array_keys( ResponsiveScope::breakpoint_suffixes() );
		foreach ( $requested as $breakpoint ) {
			if ( ! is_scalar( $breakpoint ) ) {
				return new \WP_Error( 'stonewright_responsive_scope_invalid', __( 'Responsive breakpoint scope must contain only breakpoint names.', 'stonewright' ), [ 'status' => 400 ] );
			}
			$name = strtolower( trim( (string) $breakpoint ) );
			if ( ! in_array( $name, $known, true ) ) {
				return new \WP_Error(
					'stonewright_responsive_scope_invalid',
					__( 'Responsive breakpoint scope contains an unsupported breakpoint.', 'stonewright' ),
					[ 'status' => 400, 'breakpoint' => $name, 'known_breakpoints' => $known ]
				);
			}
			$allowed[] = 'base' === $name ? 'desktop' : $name;
		}
		return array_values( array_unique( $allowed ) );
	}

	/**
	 * @param array<string, mixed> $before
	 * @param array<string, mixed> $after
	 * @return array<string, mixed>
	 */
	private static function changed_settings( array $before, array $after ): array {
		return array_filter(
			$after,
			static fn( mixed $value, string|int $key ): bool => ! array_key_exists( (string) $key, $before ) || $before[ (string) $key ] !== $value,
			ARRAY_FILTER_USE_BOTH
		);
	}

	/**
	 * @param array<int, array<string, mixed>> $operations
	 * @param array<string, mixed>             $args
	 */
	private static function request_hash( int $post_id, array $operations, array $args ): string {
		return TreeHasher::hash(
			[
				'post_id'            => $post_id,
				'operations'         => $operations,
				'expected_tree_hash' => (string) ( $args['expected_tree_hash'] ?? '' ),
				'require_evidence'   => ! empty( $args['require_evidence'] ),
				'stop_on_error'      => array_key_exists( 'stop_on_error', $args ) ? (bool) $args['stop_on_error'] : empty( $args['dry_run'] ),
				'responsive_scope'   => $args['responsive_scope'] ?? $args['allowed_breakpoints'] ?? [],
			]
		);
	}

	/**
	 * Copy batch-level scope onto operations that did not name their own.
	 *
	 * @param list<array<string, mixed>> $operations
	 * @param array<string, mixed>       $args
	 * @return list<array<string, mixed>>
	 */
	private static function apply_batch_responsive_scope( array $operations, array $args ): array {
		$batch = ResponsiveScope::requested_names( $args['allowed_breakpoints'] ?? null );
		if ( [] === $batch ) {
			$batch = ResponsiveScope::requested_names( $args['responsive_scope'] ?? null );
		}
		if ( [] === $batch ) {
			return $operations;
		}
		foreach ( $operations as $index => $operation ) {
			if ( ! is_array( $operation ) ) {
				continue;
			}
			$own = ResponsiveScope::requested_names( $operation['allowed_breakpoints'] ?? null );
			if ( [] === $own ) {
				$own = ResponsiveScope::requested_names( $operation['responsive_scope'] ?? null );
			}
			if ( [] !== $own ) {
				continue;
			}
			$operations[ $index ]['responsive_scope'] = $batch;
		}

		return $operations;
	}

	/**
	 * @param list<array<string, mixed>>          $items
	 * @param array<int, array<string, mixed>>    $tree
	 * @return array<string, mixed>
	 */
	private static function batch_failure_data(
		array $items,
		int $applied,
		int $failed,
		int $failed_index,
		string $failed_action,
		string $cause_code,
		string $before_hash,
		array $tree,
		\WP_Error $result,
		ElementorWriteReceipt $receipt,
		string $repair_suffix = ''
	): array {
		$data = [
			'status'          => 400,
			'items'           => $items,
			'applied'         => $applied,
			'failed'          => $failed,
			'failed_index'    => $failed_index,
			'failed_action'   => $failed_action,
			'cause_code'      => $cause_code,
			'root_error_code' => $cause_code,
			'before_hash'     => $before_hash,
			'document_state'  => [] === $tree ? 'empty' : 'populated',
			'retryable'       => true,
			'write_blocked'   => true,
			'schema_requests' => self::schema_requests( $items ),
			'repair'          => self::repair_hint( $cause_code, $failed_action ) . $repair_suffix,
			'write_receipt'   => $receipt->fail( $result, 'operations.' . $failed_index )->to_array(),
		];
		if ( IncidentStore::is_input_shape_code( $cause_code ) ) {
			$data['execution_status'] = 'blocked';
		}

		return $data;
	}

	/**
	 * @return array<string, mixed>
	 */
	private static function error_item( int $index, \WP_Error $error ): array {
		return [
			'index' => $index,
			'ok'    => false,
			'error' => [
				'code'    => $error->get_error_code(),
				'message' => $error->get_error_message(),
				'data'    => (array) $error->get_error_data(),
			],
		];
	}

	/**
	 * @param list<array<string, mixed>> $items
	 * @return list<array<string, mixed>>
	 */
	private static function schema_requests( array $items ): array {
		$requests = [];
		foreach ( $items as $item ) {
			$request = $item['error']['data']['schema_request'] ?? null;
			if ( ! is_array( $request ) ) {
				continue;
			}
			$requests[ wp_json_encode( $request ) ] = $request;
		}
		return array_values( $requests );
	}

	private static function repair_hint( string $code, string $action ): string {
		return match ( $code ) {
			'stonewright_parent_not_found', 'stonewright_unknown_ref' => 'Read the current page structure. For an empty page, add the root container with no parent; reference later nodes by @op_id.',
			'stonewright_invalid_action' => 'Use exactly one supported action: add_container, add_widget, update_element, patch_repeater_row, move_element, or remove_element.',
			'stonewright_ambiguous_repeater_row' => 'Refresh the widget and select exactly one stable custom_id, falling back to _id only when custom_id is unavailable.',
			'stonewright_third_party_replace_blocked' => 'Use patch_repeater_row. If a full replace is unavoidable, review one explicit high-risk dry-run and bind the apply to its preservation hash.',
			'stonewright_missing_widget_type', 'stonewright_unknown_widget' => 'List the live Elementor widget registry, then send its exact widget_type with action=add_widget.',
			'stonewright_elementor_settings_invalid' => 'Execute every schema_request in the response once. Keep unknown existing settings, replace only rejected values, include settings_evidence, and rerun one consolidated dry-run.',
			'stonewright_elementor_evidence_invalid' => 'Execute every schema_request in the response, then resend settings_evidence for each planned setting. Direction-brief provenance is accepted for token-derived color, typography, and spacing when a design direction is active.',
			'stonewright_no_effective_changes' => 'Remove the no-op update or resend settings from the live schema; Stonewright will not report discarded settings as applied.',
			'stonewright_atomic_widget_in_v3_batch' => 'Use the Elementor V4 editor pipeline; never mix e-* widgets into a V3 tree.',
			default => 'Fix the reported operation and rerun dry_run=true. No page data was written.',
		};
	}
}
