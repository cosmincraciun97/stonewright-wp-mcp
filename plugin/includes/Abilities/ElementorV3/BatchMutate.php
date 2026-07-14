<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\Abilities\ElementorV3;

use Stonewright\WpMcp\Abilities\AbilityKernel;
use Stonewright\WpMcp\Abilities\Common\ConfirmationGuard;
use Stonewright\WpMcp\Elementor\ContainerSettings;
use Stonewright\WpMcp\Elementor\Schema\SettingsValidator;
use Stonewright\WpMcp\Elementor\V4\AtomicTreeInspector;
use Stonewright\WpMcp\Elementor\Write\EvidenceValidator;
use Stonewright\WpMcp\Elementor\Write\IdempotencyStore;
use Stonewright\WpMcp\Elementor\Write\TreeHasher;
use Stonewright\WpMcp\Security\Backup;
use Stonewright\WpMcp\Security\Permissions;
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
				'require_evidence'    => [ 'type' => 'boolean', 'default' => false ],
				'stop_on_error'      => [ 'type' => 'boolean', 'default' => true ],
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
								'enum' => [ 'add_container', 'add_widget', 'update_element', 'move_element', 'remove_element' ],
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
							'settings_evidence'      => [ 'type' => 'object' ],
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
				$dry_run    = ! empty( $args['dry_run'] );
				$require_evidence = ! empty( $args['require_evidence'] );
				$idempotency_key  = isset( $args['idempotency_key'] ) ? trim( (string) $args['idempotency_key'] ) : '';
				$request_hash     = self::request_hash( $post_id, $operations, $args );

				if ( ! get_post( $post_id ) ) {
					return $this->error( 'not_found', __( 'Post not found.', 'stonewright' ) );
				}
				if ( [] === $operations ) {
					return $this->error( 'missing_operations', __( 'At least one batch operation is required.', 'stonewright' ), [ 'status' => 400 ] );
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
				$architecture = (string) ( AtomicTreeInspector::inspect( $tree )['architecture'] ?? 'empty' );
				if ( in_array( $architecture, [ 'v4', 'mixed' ], true ) ) {
					return $this->error(
						'v3_architecture_mismatch',
						__( 'This document contains Elementor V4 Atomic nodes. V3 batch mutation is blocked to prevent a mixed tree.', 'stonewright' ),
						[ 'status' => 409, 'architecture' => $architecture, 'before_hash' => $before_hash, 'repair' => 'Read the atomic tree and use the V4 editor pipeline; never translate V4 nodes to V3 implicitly.' ]
					);
				}
				$expected_tree_hash = isset( $args['expected_tree_hash'] ) ? (string) $args['expected_tree_hash'] : '';
				if ( '' !== $expected_tree_hash && ! hash_equals( $expected_tree_hash, $before_hash ) ) {
					return $this->error(
						'tree_conflict',
						__( 'Elementor page changed after planning; refresh structure before writing.', 'stonewright' ),
						[ 'status' => 409, 'expected_tree_hash' => $expected_tree_hash, 'current_tree_hash' => $before_hash ]
					);
				}
				$items      = [];
				$refs       = [];
				$applied    = 0;
				$failed     = 0;
				$stop       = ! array_key_exists( 'stop_on_error', $args ) || (bool) $args['stop_on_error'];

				foreach ( $operations as $index => $operation ) {
					$operation = is_array( $operation ) ? $operation : [];
					$result    = $this->apply_operation( $tree, $operation, $refs, $require_evidence );

					if ( $result instanceof \WP_Error ) {
						++$failed;
						$items[] = self::error_item( $index, $result );
						if ( $stop ) {
							$cause_code = (string) $result->get_error_code();
							$action     = (string) ( $operation['action'] ?? '' );
							return $this->error(
								'batch_operation_failed',
								sprintf( __( 'Elementor batch operation %1$d (%2$s) failed: %3$s', 'stonewright' ), $index, $action, $result->get_error_message() ),
								[
									'status'           => 400,
									'items'            => $items,
									'applied'          => $applied,
									'failed'           => $failed,
									'failed_index'     => $index,
									'failed_action'    => $action,
									'cause_code'       => $cause_code,
									'before_hash'      => $before_hash,
									'document_state'   => [] === $tree ? 'empty' : 'populated',
									'repair'           => self::repair_hint( $cause_code, $action ),
								]
							);
						}
						continue;
					}

					++$applied;
					$items[] = array_merge(
						[
							'index' => $index,
							'ok'    => true,
						],
						$result
					);
				}

				$snapshot_id = '';
				$write_ms    = 0.0;
				$after_hash  = TreeHasher::hash( $tree );
				$readback_hash = $dry_run ? $after_hash : '';
				if ( ! $dry_run ) {
					$snapshot_id = Backup::snapshot_post( $post_id );
					$write_start = microtime( true );
					if ( ! ElementorData::write( $post_id, $tree ) ) {
						$restored = Backup::restore( $post_id, $snapshot_id );
						return $this->error( 'write_failed', __( 'Could not save Elementor data; the snapshot was restored.', 'stonewright' ), [ 'restored' => $restored ] );
					}
					$write_ms = self::elapsed_ms( $write_start );
					$readback_hash = TreeHasher::hash( ElementorData::read( $post_id ) );
					if ( ! hash_equals( $after_hash, $readback_hash ) ) {
						$restored = Backup::restore( $post_id, $snapshot_id );
						return $this->error(
							'readback_mismatch',
							__( 'Elementor write readback did not match the compiled tree; the snapshot was restored.', 'stonewright' ),
							[ 'status' => 500, 'expected_hash' => $after_hash, 'readback_hash' => $readback_hash, 'restored' => $restored ]
						);
					}
				}

				$element_count = count( ElementorData::flatten( $tree ) );
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
				];

				if ( $dry_run ) {
					$response['preview'] = $tree;
				} else {
					IdempotencyStore::remember( $post_id, $idempotency_key, $request_hash, $response );
				}

				return $response;
			}
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
	 * @param array<string, mixed> $operation
	 * @return array<string, mixed>
	 */
	private static function normalize_operation( array $operation ): array {
		$normalized = $operation;
		$action     = (string) ( $normalized['action'] ?? $normalized['type'] ?? $normalized['op'] ?? '' );
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
	private function apply_operation( array &$tree, array $operation, array &$refs, bool $require_evidence ): array|\WP_Error {
		$action = isset( $operation['action'] ) ? (string) $operation['action'] : '';

		return match ( $action ) {
			'add_container'  => $this->add_container( $tree, $operation, $refs, $require_evidence ),
			'add_widget'     => $this->add_widget( $tree, $operation, $refs, $require_evidence ),
			'update_element' => $this->update_element( $tree, $operation, $refs, $require_evidence ),
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
		$element  = [
			'id'       => ElementorData::generate_id(),
			'elType'   => 'container',
			'isInner'  => [] !== $parent_path,
			'settings' => $settings,
			'elements' => [],
		];

		$position = isset( $operation['position'] ) ? (int) $operation['position'] : PHP_INT_MAX;
		$tree     = ElementorData::insert( $tree, $parent_path, $position, $element );

		return array_merge( $this->created_item( $operation, $refs, $element['id'], 'container' ), [ 'evidence' => $evidence ] );
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
		if ( 'html' === $widget_type && empty( $operation['allow_html_widget'] ) ) {
			return $this->error( 'html_widget_requires_explicit_approval', __( 'Elementor HTML widgets are disabled by default.', 'stonewright' ), [ 'status' => 400 ] );
		}

		$settings = isset( $operation['settings'] ) && is_array( $operation['settings'] ) ? $operation['settings'] : [];
		if ( 'html' !== $widget_type ) {
			$validated = SettingsValidator::validate( $widget_type, $settings );
			if ( $validated instanceof \WP_Error ) {
				return $validated;
			}
			$settings = $validated['settings'];
		}
		$evidence = EvidenceValidator::validate( $widget_type, $settings, self::operation_evidence( $operation ), $require_evidence );
		if ( $evidence instanceof \WP_Error ) {
			return $evidence;
		}

		$parent_path = $this->parent_path( $tree, $operation, $refs, 'parent_id', 'parent_ref' );
		if ( $parent_path instanceof \WP_Error ) {
			return $parent_path;
		}

		$element = [
			'id'         => ElementorData::generate_id(),
			'elType'     => 'widget',
			'widgetType' => $widget_type,
			'settings'   => $settings,
			'elements'   => [],
		];

		$position = isset( $operation['position'] ) ? (int) $operation['position'] : PHP_INT_MAX;
		$tree     = ElementorData::insert( $tree, $parent_path, $position, $element );

		return array_merge( $this->created_item( $operation, $refs, $element['id'], 'widget' ), [ 'evidence' => $evidence ] );
	}

	/**
	 * @param array<int, array<string, mixed>> $tree
	 * @param array<string, mixed>            $operation
	 * @param array<string, string>           $refs
	 * @return array<string, mixed>|\WP_Error
	 */
	private function update_element( array &$tree, array $operation, array $refs, bool $require_evidence ): array|\WP_Error {
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
		if ( in_array( $element_type, [ 'container', 'section', 'column' ], true ) ) {
			$before    = 'container' === $element_type ? ContainerSettings::normalize( $existing ) : $existing;
			$effective_before = $before;
			$settings  = 'container' === $element_type ? ContainerSettings::normalize( $settings ) : $settings;
			$validated = SettingsValidator::validate_container( $settings, $element_type );
			if ( $validated instanceof \WP_Error ) {
				return $validated;
			}
			$settings = $validated['settings'];
			$incoming = self::changed_settings( $before, $settings );
			$evidence_widget_type = $element_type;
		} elseif ( 'widget' === ( $element['elType'] ?? '' ) ) {
			$widget_type = (string) ( $element['widgetType'] ?? '' );
			$validated   = SettingsValidator::validate( $widget_type, $settings );
			if ( $validated instanceof \WP_Error ) {
				return $validated;
			}
			$settings = $validated['settings'];
			$evidence_widget_type = $widget_type;
		} else {
			$evidence_widget_type = 'container';
		}
		$evidence = EvidenceValidator::validate( $evidence_widget_type, $incoming, self::operation_evidence( $operation ), $require_evidence );
		if ( $evidence instanceof \WP_Error ) {
			return $evidence;
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

		$element['settings'] = $settings;
		$tree                = ElementorData::set( $tree, $path, $element );

		return [
			'action'     => 'update_element',
			'element_id' => $element_id,
			'evidence'   => $evidence,
		];
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
			if ( is_array( $operation ) && 'remove_element' === ( $operation['action'] ?? '' ) ) {
				return true;
			}
		}

		return false;
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
				'stop_on_error'      => ! array_key_exists( 'stop_on_error', $args ) || (bool) $args['stop_on_error'],
			]
		);
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

	private static function repair_hint( string $code, string $action ): string {
		return match ( $code ) {
			'stonewright_parent_not_found', 'stonewright_unknown_ref' => 'Read the current page structure. For an empty page, add the root container with no parent; reference later nodes by @op_id.',
			'stonewright_elementor_settings_invalid' => 'Fetch the live widget/container schema and resend only supported settings.',
			'stonewright_no_effective_changes' => 'Remove the no-op update or resend settings from the live schema; Stonewright will not report discarded settings as applied.',
			'stonewright_atomic_widget_in_v3_batch' => 'Use the Elementor V4 editor pipeline; never mix e-* widgets into a V3 tree.',
			default => 'Fix the reported operation and rerun dry_run=true. No page data was written.',
		};
	}
}
