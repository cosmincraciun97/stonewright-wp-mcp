<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\Abilities\Gutenberg;

use Stonewright\WpMcp\Abilities\AbilityKernel;
use Stonewright\WpMcp\Abilities\Common\ConfirmationGuard;
use Stonewright\WpMcp\Security\Backup;
use Stonewright\WpMcp\Security\Permissions;
use Stonewright\WpMcp\Support\BlockSerializer;
use Stonewright\WpMcp\Support\BlockTree;

/**
 * Transactional, optimistic-hash batch mutation for Gutenberg post content.
 */
final class BlocksBatchMutate extends AbilityKernel {
	use ConfirmationGuard;

	public function name(): string {
		return 'stonewright/blocks-batch-mutate';
	}

	public function label(): string {
		return __( 'Batch mutate Gutenberg blocks', 'stonewright' );
	}

	public function description(): string {
		return __( 'Parses one post, applies insert/update/move/remove block operations in memory, then snapshots, writes, readbacks, and rolls back once on failure.', 'stonewright' );
	}

	public function category(): string {
		return 'gutenberg';
	}

	public function input_schema(): array {
		return [
			'type'                 => 'object',
			'additionalProperties' => false,
			'properties'           => [
				'post_id'              => [ 'type' => 'integer', 'minimum' => 1 ],
				'dry_run'              => [
					'type'        => 'boolean',
					'default'     => false,
					'description' => 'Set true to compile without snapshotting or writing.',
				],
				'expected_content_hash' => [
					'type'        => 'string',
					'pattern'     => '^[a-f0-9]{64}$',
					'description' => 'Required when dry_run=false. Use before_hash from the latest dry run.',
				],
				'include_full'         => [
					'type'        => 'boolean',
					'default'     => false,
					'description' => 'Dry runs are compact by default. Set true to include the complete preview tree within max_preview_blocks.',
				],
				'max_preview_blocks'   => [
					'type'        => 'integer',
					'minimum'     => 1,
					'maximum'     => 500,
					'default'     => 100,
					'description' => 'Safety limit for include_full dry-run previews. Full preview fails closed when the compiled tree exceeds it.',
				],
				'change_set_id'        => [ 'type' => 'string', 'maxLength' => 96 ],
				'confirmation_token'   => [ 'type' => 'string' ],
				'operations'           => [
					'type'    => 'array',
					'minItems' => 1,
					'maxItems' => 100,
					'items'   => [
						'type'                 => 'object',
						'additionalProperties' => true,
						'properties'           => [
							'action'      => [ 'type' => 'string', 'enum' => [ 'insert', 'update', 'move', 'remove' ] ],
							'path'        => [ 'type' => 'array', 'items' => [ 'type' => 'integer', 'minimum' => 0 ] ],
							'position'    => [ 'type' => 'integer', 'minimum' => 0 ],
							'before_path' => [ 'type' => 'array', 'items' => [ 'type' => 'integer', 'minimum' => 0 ] ],
							'after_path'  => [ 'type' => 'array', 'items' => [ 'type' => 'integer', 'minimum' => 0 ] ],
							'block'       => [ 'type' => 'object' ],
							'attrs'       => [ 'type' => 'object' ],
							'innerHTML'   => [ 'type' => 'string' ],
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
				'ok'                  => [ 'type' => 'boolean' ],
				'post_id'             => [ 'type' => 'integer' ],
				'dry_run'             => [ 'type' => 'boolean' ],
				'before_hash'         => [ 'type' => 'string' ],
				'after_hash'          => [ 'type' => 'string' ],
				'readback_hash'       => [ 'type' => 'string' ],
				'snapshot_id'         => [ 'type' => 'string' ],
				'applied'             => [ 'type' => 'integer' ],
				'items'               => [ 'type' => 'array' ],
				'write_receipt'       => [ 'type' => 'object' ],
				'verification_status' => [ 'type' => 'string' ],
				'rollback_status'     => [ 'type' => 'string' ],
				'preview_omitted'     => [ 'type' => 'boolean' ],
				'preview_summary'     => [ 'type' => 'object' ],
				'full_mode_hint'      => [ 'type' => 'string' ],
				'preview'             => [ 'type' => 'array' ],
			],
		];
	}

	public function permission_callback( array $args ): bool|\WP_Error {
		return Permissions::edit_post( (int) ( $args['post_id'] ?? 0 ) );
	}

	public function execute( array $args ): array|\WP_Error {
		return $this->audit(
			$args,
			function ( array $args ): array|\WP_Error {
				$post_id = (int) ( $args['post_id'] ?? 0 );
				$post    = get_post( $post_id );
				if ( ! $post ) {
					return $this->error( 'not_found', __( 'Post not found.', 'stonewright' ), [ 'status' => 404 ] );
				}

				$operations = isset( $args['operations'] ) && is_array( $args['operations'] ) ? array_values( $args['operations'] ) : [];
				if ( [] === $operations ) {
					return $this->error( 'missing_operations', __( 'At least one block operation is required.', 'stonewright' ), [ 'status' => 400 ] );
				}

				$dry_run      = ! empty( $args['dry_run'] );
				$include_full = ! empty( $args['include_full'] );
				$before_hash = hash( 'sha256', (string) $post->post_content );
				$expected    = (string) ( $args['expected_content_hash'] ?? '' );
				if ( ! $dry_run && '' === $expected ) {
					return $this->error(
						'missing_expected_content_hash',
						__( 'A write requires expected_content_hash from the latest dry run.', 'stonewright' ),
						[
							'status'               => 400,
							'current_content_hash' => $before_hash,
							'retryable'            => true,
						]
					);
				}
				if ( '' !== $expected && ! hash_equals( $expected, $before_hash ) ) {
					return $this->error(
						'content_conflict',
						__( 'The post content changed after planning; parse it again before writing.', 'stonewright' ),
						[
							'status'                => 409,
							'expected_content_hash' => $expected,
							'current_content_hash'  => $before_hash,
							'retryable'            => true,
						]
					);
				}
				if ( ! $dry_run && $this->has_remove( $operations ) ) {
					$token_error = $this->confirmation_token_error( $args, $args );
					if ( null !== $token_error ) {
						return $token_error;
					}
				}

				$parsed  = parse_blocks( (string) $post->post_content );
				$working = is_array( $parsed ) ? $parsed : [];
				$items   = [];
				foreach ( $operations as $index => $raw ) {
					$operation = is_array( $raw ) ? $raw : [];
					$result    = $this->apply_operation( $working, $operation );
					if ( $result instanceof \WP_Error ) {
						$items[] = [
							'index' => $index,
							'ok'    => false,
							'error' => [
								'code'    => $result->get_error_code(),
								'message' => $result->get_error_message(),
								'data'    => (array) $result->get_error_data(),
							],
						];
						return $this->error(
							'batch_operation_failed',
							__( 'Gutenberg batch validation failed. No post content was written.', 'stonewright' ),
							[
								'status'          => 400,
								'items'           => $items,
								'failed_index'    => $index,
								'retryable'       => true,
								'before_sha256'   => $before_hash,
								'root_error_code' => $result->get_error_code(),
							]
						);
					}
					$items[] = [
						'index' => $index,
						'ok'    => true,
						'action' => (string) ( $operation['action'] ?? '' ),
						'path'  => $result['path'] ?? [],
					];
				}

				$after_html = BlockSerializer::serialize( $working );
				$after_hash = hash( 'sha256', $after_html );
				$preview_count = self::block_count( $working );
				$preview_limit = max( 1, min( 500, (int) ( $args['max_preview_blocks'] ?? 100 ) ) );
				if ( $dry_run && $include_full && $preview_count > $preview_limit ) {
					return $this->error(
						'full_preview_limit_exceeded',
						__( 'The compiled block tree exceeds max_preview_blocks; use the compact dry-run response or raise the bounded limit.', 'stonewright' ),
						[
							'status'             => 400,
							'block_count'        => $preview_count,
							'max_preview_blocks' => $preview_limit,
							'retryable'          => true,
						]
					);
				}
				$receipt    = $this->receipt( $post_id, $args, $dry_run, $before_hash, $after_hash );
				$response   = [
					'ok'                  => true,
					'post_id'             => $post_id,
					'dry_run'             => $dry_run,
					'before_hash'         => $before_hash,
					'after_hash'          => $after_hash,
					'readback_hash'       => '',
					'snapshot_id'         => '',
					'applied'             => count( $items ),
					'items'               => $items,
					'verification_status' => $dry_run ? 'planned' : 'pending',
					'rollback_status'     => 'not_needed',
					'write_receipt'       => $receipt,
					'preview_omitted'     => ! $dry_run || ! $include_full,
					'preview_summary'     => [
						'root_block_count' => count( $working ),
						'block_count'      => $preview_count,
						'block_names'      => self::block_names( $working, 25 ),
					],
					'full_mode_hint'      => $dry_run && ! $include_full ? 'Set include_full=true to return the complete preview tree, bounded by max_preview_blocks.' : '',
				];
				if ( $dry_run ) {
					if ( $include_full ) {
						$response['preview'] = $working;
					}
					return $response;
				}

				$snapshot_id = Backup::snapshot_post( $post_id );
				if ( '' === $snapshot_id ) {
					$receipt['root_error_code'] = 'stonewright_snapshot_failed';
					$receipt['verification_status'] = 'failed';
					return $this->error( 'snapshot_failed', __( 'The Gutenberg post could not be snapshotted; no content was written.', 'stonewright' ), [ 'status' => 500, 'write_receipt' => $receipt ] );
				}
				$response['snapshot_id'] = $snapshot_id;
				$receipt['snapshot_id'] = $snapshot_id;

				if ( function_exists( 'clean_post_cache' ) ) {
					clean_post_cache( $post_id );
				}
				$pre_write_post = get_post( $post_id );
				$pre_write_hash = $pre_write_post ? hash( 'sha256', (string) $pre_write_post->post_content ) : '';
				if ( '' === $pre_write_hash || ! hash_equals( $expected, $pre_write_hash ) ) {
					$conflict = $this->error(
						'content_conflict',
						__( 'The post content changed immediately before persistence; no content was written.', 'stonewright' ),
						[
							'status'                => 409,
							'expected_content_hash' => $expected,
							'current_content_hash'  => $pre_write_hash,
							'retryable'             => true,
						]
					);
					$receipt['root_error_code']     = $conflict->get_error_code();
					$receipt['root_error_path']     = 'write.cas_recheck';
					$receipt['verification_status'] = 'failed';
					$receipt['retryable']           = true;
					return new \WP_Error(
						$conflict->get_error_code(),
						$conflict->get_error_message(),
						array_merge( (array) $conflict->get_error_data(), [ 'write_receipt' => $receipt ] )
					);
				}

				$written = wp_update_post( [ 'ID' => $post_id, 'post_content' => $after_html ], true );
				if ( is_wp_error( $written ) ) {
					$rollback = $this->restore_and_verify( $post_id, $snapshot_id, $before_hash );
					$receipt  = $this->failed_receipt( $receipt, $written, $rollback, 'write.persist' );
					return new \WP_Error(
						$written->get_error_code(),
						$written->get_error_message(),
						array_merge(
							(array) $written->get_error_data(),
							[
								'restored'        => $rollback['restored'],
								'rollback_status' => $receipt['rollback_status'],
								'rollback_readback_hash' => $rollback['readback_hash'],
								'write_receipt'   => $receipt,
							]
						)
					);
				}

				$current         = get_post( $post_id );
				$readback_hash   = $current ? hash( 'sha256', (string) $current->post_content ) : '';
				$response['readback_hash'] = $readback_hash;
				if ( ! hash_equals( $after_hash, $readback_hash ) ) {
					$rollback = $this->restore_and_verify( $post_id, $snapshot_id, $before_hash );
					$failure  = $this->error(
						'readback_mismatch',
						__( 'Gutenberg readback did not match the planned content; snapshot rollback was attempted and verified separately.', 'stonewright' ),
						[
							'status'         => 500,
							'expected_hash'   => $after_hash,
							'readback_hash'   => $readback_hash,
							'restored'       => $rollback['restored'],
							'rollback_status' => $rollback['status'],
							'rollback_readback_hash' => $rollback['readback_hash'],
						]
					);
					$receipt = $this->failed_receipt( $receipt, $failure, $rollback, 'verify.readback' );
					return new \WP_Error( $failure->get_error_code(), $failure->get_error_message(), array_merge( (array) $failure->get_error_data(), [ 'write_receipt' => $receipt ] ) );
				}

				$receipt['after_hash']         = $after_hash;
				$receipt['readback_hash']      = $readback_hash;
				$receipt['verification_status'] = 'verified';
				$response['verification_status'] = 'verified';
				$response['write_receipt'] = $receipt;
				return $response;
			}
		);
	}

	/** @param array<int,mixed> $operations */
	private function has_remove( array $operations ): bool {
		foreach ( $operations as $operation ) {
			if ( is_array( $operation ) && 'remove' === (string) ( $operation['action'] ?? '' ) ) {
				return true;
			}
		}
		return false;
	}

	/** @param array<int,array<string,mixed>> $blocks @param array<string,mixed> $operation @return array<string,mixed>|\WP_Error */
	private function apply_operation( array &$blocks, array $operation ): array|\WP_Error {
		$action = sanitize_key( (string) ( $operation['action'] ?? '' ) );
		$path   = self::path( $operation['path'] ?? [] );
		if ( 'insert' === $action ) {
			$target = $this->insert_target( $blocks, $path, $operation );
			if ( $target instanceof \WP_Error ) {
				return $target;
			}
			[ $parent_path, $position ] = $target;
			if ( [] !== $parent_path && null === BlockTree::get( $blocks, $parent_path ) ) {
				return $this->error( 'invalid_path', __( 'Insert parent path not found.', 'stonewright' ), [ 'status' => 400 ] );
			}
			$block = $this->normalize_block( is_array( $operation['block'] ?? null ) ? $operation['block'] : [], 'operations.insert.block' );
			if ( $block instanceof \WP_Error ) {
				return $block;
			}
			if ( '' === (string) ( $block['blockName'] ?? '' ) ) {
				return $this->error( 'invalid_block', __( 'Inserted block requires a block name.', 'stonewright' ), [ 'status' => 400 ] );
			}
			$position = min( $position, self::sibling_count( $blocks, $parent_path ) );
			$next = $this->tree_insert( $blocks, $parent_path, $position, $block );
			if ( $next instanceof \WP_Error ) {
				return $next;
			}
			$blocks = $next;
			return [ 'path' => array_merge( $parent_path, [ max( 0, $position ) ] ) ];
		}

		if ( 'move' === $action ) {
			if ( [] === $path ) {
				return $this->error( 'invalid_path', __( 'A moved block must have a source path.', 'stonewright' ), [ 'status' => 400 ] );
			}
			$source = BlockTree::get( $blocks, $path );
			if ( null === $source ) {
				return $this->error( 'invalid_path', __( 'Moved block path not found.', 'stonewright' ), [ 'status' => 400 ] );
			}
			$parent_path = array_slice( $path, 0, -1 );
			$position    = max( 0, (int) ( $operation['position'] ?? 0 ) );
			$position    = min( $position, max( 0, self::sibling_count( $blocks, $parent_path ) - 1 ) );
			$moved       = $this->tree_move( $blocks, $path, $position );
			if ( $moved instanceof \WP_Error ) {
				return $moved;
			}
			$blocks = $moved;
			return [ 'path' => array_merge( $parent_path, [ $position ] ) ];
		}

		if ( 'update' === $action ) {
			$existing = BlockTree::get( $blocks, $path );
			if ( null === $existing ) {
				return $this->error( 'invalid_path', __( 'Block update path not found.', 'stonewright' ), [ 'status' => 400 ] );
			}
			if ( array_key_exists( 'attrs', $operation ) && ! is_array( $operation['attrs'] ) ) {
				return $this->error( 'invalid_block_attributes', __( 'Block attrs must be an object.', 'stonewright' ), [ 'status' => 400, 'path' => 'operations.update.attrs' ] );
			}
			if ( array_key_exists( 'innerHTML', $operation ) && ! is_string( $operation['innerHTML'] ) ) {
				return $this->error( 'invalid_block', __( 'Block innerHTML must be a string.', 'stonewright' ), [ 'status' => 400, 'path' => 'operations.update.innerHTML' ] );
			}
			$mutation = [];
			if ( isset( $operation['attrs'] ) && is_array( $operation['attrs'] ) ) {
				$mutation['attrs'] = array_merge( is_array( $existing['attrs'] ?? null ) ? $existing['attrs'] : [], $operation['attrs'] );
			}
			if ( array_key_exists( 'innerHTML', $operation ) ) {
				if ( ! empty( $existing['innerBlocks'] ) ) {
					return $this->error(
						'unsafe_nested_inner_html',
						__( 'innerHTML cannot be replaced on a block that contains innerBlocks; mutate the child blocks instead.', 'stonewright' ),
						[ 'status' => 400, 'path' => $path ]
					);
				}
				$mutation['innerHTML'] = $operation['innerHTML'];
				$mutation['innerContent'] = self::inner_content( $operation['innerHTML'], [] );
			}
			if ( [] === $mutation ) {
				return $this->error( 'empty_update', __( 'Block update has no attrs or innerHTML changes.', 'stonewright' ), [ 'status' => 400 ] );
			}
			$candidate = array_merge( $existing, $mutation );
			$shape = $this->safe_inner_content( $candidate, 'operations.update.block' );
			if ( $shape instanceof \WP_Error ) {
				return $shape;
			}
			$schema_error = $this->validate_block_schema( $candidate, 'operations.update.block' );
			if ( null !== $schema_error ) {
				return $schema_error;
			}
			$next = $this->tree_update( $blocks, $path, $mutation );
			if ( $next instanceof \WP_Error ) {
				return $next;
			}
			$blocks = $next;
			return [ 'path' => $path ];
		}

		if ( 'remove' === $action ) {
			$next = $this->tree_remove( $blocks, $path );
			if ( $next instanceof \WP_Error ) {
				return $next;
			}
			$blocks = $next;
			return [ 'path' => $path ];
		}

		return $this->error( 'invalid_action', __( 'Use insert, update, move, or remove for Gutenberg block batches.', 'stonewright' ), [ 'status' => 400 ] );
	}

	/** @return array{0:list<int>,1:int}|\WP_Error */
	private function insert_target( array $blocks, array $path, array $operation ): array|\WP_Error {
		if ( array_key_exists( 'before_path', $operation ) && array_key_exists( 'after_path', $operation ) ) {
			return $this->error( 'ambiguous_anchor', __( 'Use either before_path or after_path, not both.', 'stonewright' ), [ 'status' => 400 ] );
		}
		$position = max( 0, (int) ( $operation['position'] ?? PHP_INT_MAX ) );
		foreach ( [ 'before_path' => 0, 'after_path' => 1 ] as $key => $offset ) {
			if ( ! array_key_exists( $key, $operation ) ) {
				continue;
			}
			$anchor = self::path( $operation[ $key ] ?? [] );
			if ( [] === $anchor || null === BlockTree::get( $blocks, $anchor ) ) {
				return $this->error(
					'invalid_anchor',
					__( 'The requested Gutenberg insertion anchor does not exist.', 'stonewright' ),
					[ 'status' => 400, 'anchor' => $key, 'path' => $anchor ]
				);
			}
			$parent_path = array_slice( $anchor, 0, -1 );
			$position    = (int) ( $anchor[ count( $anchor ) - 1 ] ?? 0 ) + $offset;
			return [ $parent_path, $position ];
		}
		return [ $path, $position ];
	}

	/** @param list<int> $parent_path */
	private static function sibling_count( array $blocks, array $parent_path ): int {
		if ( [] === $parent_path ) {
			return count( $blocks );
		}
		$parent = BlockTree::get( $blocks, $parent_path );
		return is_array( $parent ) && is_array( $parent['innerBlocks'] ?? null ) ? count( $parent['innerBlocks'] ) : 0;
	}

	/** @return list<int> */
	private static function path( mixed $path ): array {
		if ( ! is_array( $path ) ) {
			return [];
		}
		return array_values( array_map( 'intval', $path ) );
	}

	/** @param mixed $children @return list<string|null> */
	private static function inner_content( string $html, mixed $children ): array {
		$content = '' !== $html ? [ $html ] : [];
		if ( is_array( $children ) ) {
			foreach ( $children as $_ ) {
				$content[] = null;
			}
		}
		return $content;
	}

	/** @param array<int,array<string,mixed>> $blocks */
	private static function block_count( array $blocks ): int {
		$count = 0;
		foreach ( $blocks as $block ) {
			++$count;
			$children = isset( $block['innerBlocks'] ) && is_array( $block['innerBlocks'] ) ? $block['innerBlocks'] : [];
			$count   += self::block_count( $children );
		}
		return $count;
	}

	/** @param array<int,array<string,mixed>> $blocks @return list<string> */
	private static function block_names( array $blocks, int $limit ): array {
		$names = [];
		foreach ( $blocks as $block ) {
			if ( count( $names ) >= $limit ) {
				break;
			}
			$names[]  = (string) ( $block['blockName'] ?? '' );
			$children = isset( $block['innerBlocks'] ) && is_array( $block['innerBlocks'] ) ? $block['innerBlocks'] : [];
			$remaining = $limit - count( $names );
			if ( $remaining > 0 ) {
				$names = array_merge( $names, self::block_names( $children, $remaining ) );
			}
		}
		return $names;
	}

	/**
	 * Insert without rebuilding an ancestor's interleaved HTML fragments.
	 *
	 * @param array<int,array<string,mixed>> $blocks
	 * @param list<int>                      $parent_path
	 * @param array<string,mixed>            $block
	 * @return array<int,array<string,mixed>>|\WP_Error
	 */
	private function tree_insert( array $blocks, array $parent_path, int $position, array $block ): array|\WP_Error {
		if ( [] === $parent_path ) {
			array_splice( $blocks, max( 0, min( $position, count( $blocks ) ) ), 0, [ $block ] );
			return array_values( $blocks );
		}

		$head = array_shift( $parent_path );
		if ( ! isset( $blocks[ $head ] ) ) {
			return $this->error( 'invalid_path', __( 'Insert parent path not found.', 'stonewright' ), [ 'status' => 400 ] );
		}
		$parent = $blocks[ $head ];
		$shape  = $this->safe_inner_content( $parent, 'operations.insert.parent' );
		if ( $shape instanceof \WP_Error ) {
			return $shape;
		}
		$children = isset( $parent['innerBlocks'] ) && is_array( $parent['innerBlocks'] ) ? array_values( $parent['innerBlocks'] ) : [];

		if ( [] === $parent_path ) {
			$position = max( 0, min( $position, count( $children ) ) );
			$content  = $this->insert_child_placeholder( $shape, $position, count( $children ), (string) ( $parent['innerHTML'] ?? '' ) );
			if ( $content instanceof \WP_Error ) {
				return $content;
			}
			array_splice( $children, $position, 0, [ $block ] );
			$blocks[ $head ]['innerBlocks']  = array_values( $children );
			$blocks[ $head ]['innerContent'] = $content;
			return $blocks;
		}

		$next = $this->tree_insert( $children, $parent_path, $position, $block );
		if ( $next instanceof \WP_Error ) {
			return $next;
		}
		$blocks[ $head ]['innerBlocks']  = $next;
		$blocks[ $head ]['innerContent'] = $shape;
		return $blocks;
	}

	/**
	 * Update without replacing ancestor innerContent wrappers.
	 *
	 * @param array<int,array<string,mixed>> $blocks
	 * @param list<int>                      $path
	 * @param array<string,mixed>            $mutation
	 * @return array<int,array<string,mixed>>|\WP_Error
	 */
	private function tree_update( array $blocks, array $path, array $mutation ): array|\WP_Error {
		if ( [] === $path ) {
			return $this->error( 'invalid_path', __( 'Block update path not found.', 'stonewright' ), [ 'status' => 400 ] );
		}
		$head = array_shift( $path );
		if ( ! isset( $blocks[ $head ] ) ) {
			return $this->error( 'invalid_path', __( 'Block update path not found.', 'stonewright' ), [ 'status' => 400 ] );
		}
		if ( [] === $path ) {
			$blocks[ $head ] = array_merge( $blocks[ $head ], $mutation );
			return $blocks;
		}

		$shape = $this->safe_inner_content( $blocks[ $head ], 'operations.update.ancestor' );
		if ( $shape instanceof \WP_Error ) {
			return $shape;
		}
		$children = isset( $blocks[ $head ]['innerBlocks'] ) && is_array( $blocks[ $head ]['innerBlocks'] ) ? array_values( $blocks[ $head ]['innerBlocks'] ) : [];
		$next     = $this->tree_update( $children, $path, $mutation );
		if ( $next instanceof \WP_Error ) {
			return $next;
		}
		$blocks[ $head ]['innerBlocks']  = $next;
		$blocks[ $head ]['innerContent'] = $shape;
		return $blocks;
	}

	/**
	 * Remove the matching child placeholder while preserving surrounding HTML.
	 *
	 * @param array<int,array<string,mixed>> $blocks
	 * @param list<int>                      $path
	 * @return array<int,array<string,mixed>>|\WP_Error
	 */
	private function tree_remove( array $blocks, array $path ): array|\WP_Error {
		if ( [] === $path ) {
			return $this->error( 'invalid_path', __( 'Block removal path not found.', 'stonewright' ), [ 'status' => 400 ] );
		}
		$head = array_shift( $path );
		if ( ! isset( $blocks[ $head ] ) ) {
			return $this->error( 'invalid_path', __( 'Block removal path not found.', 'stonewright' ), [ 'status' => 400 ] );
		}
		if ( [] === $path ) {
			array_splice( $blocks, $head, 1 );
			return array_values( $blocks );
		}

		$shape = $this->safe_inner_content( $blocks[ $head ], 'operations.remove.parent' );
		if ( $shape instanceof \WP_Error ) {
			return $shape;
		}
		$children   = isset( $blocks[ $head ]['innerBlocks'] ) && is_array( $blocks[ $head ]['innerBlocks'] ) ? array_values( $blocks[ $head ]['innerBlocks'] ) : [];
		$child_head = $path[0] ?? -1;
		if ( 1 === count( $path ) ) {
			if ( ! isset( $children[ $child_head ] ) ) {
				return $this->error( 'invalid_path', __( 'Block removal path not found.', 'stonewright' ), [ 'status' => 400 ] );
			}
			$content = $this->remove_child_placeholder( $shape, $child_head );
			if ( $content instanceof \WP_Error ) {
				return $content;
			}
			array_splice( $children, $child_head, 1 );
			$blocks[ $head ]['innerBlocks']  = array_values( $children );
			$blocks[ $head ]['innerContent'] = $content;
			return $blocks;
		}

		$next = $this->tree_remove( $children, $path );
		if ( $next instanceof \WP_Error ) {
			return $next;
		}
		$blocks[ $head ]['innerBlocks']  = $next;
		$blocks[ $head ]['innerContent'] = $shape;
		return $blocks;
	}

	/**
	 * Move only among siblings; the placeholder layout therefore stays intact.
	 *
	 * @param array<int,array<string,mixed>> $blocks
	 * @param list<int>                      $path
	 * @return array<int,array<string,mixed>>|\WP_Error
	 */
	private function tree_move( array $blocks, array $path, int $position ): array|\WP_Error {
		if ( [] === $path ) {
			return $this->error( 'invalid_path', __( 'Moved block path not found.', 'stonewright' ), [ 'status' => 400 ] );
		}
		$head = array_shift( $path );
		if ( ! isset( $blocks[ $head ] ) ) {
			return $this->error( 'invalid_path', __( 'Moved block path not found.', 'stonewright' ), [ 'status' => 400 ] );
		}
		if ( [] === $path ) {
			$node = $blocks[ $head ];
			array_splice( $blocks, $head, 1 );
			array_splice( $blocks, max( 0, min( $position, count( $blocks ) ) ), 0, [ $node ] );
			return array_values( $blocks );
		}

		$shape = $this->safe_inner_content( $blocks[ $head ], 'operations.move.parent' );
		if ( $shape instanceof \WP_Error ) {
			return $shape;
		}
		$children = isset( $blocks[ $head ]['innerBlocks'] ) && is_array( $blocks[ $head ]['innerBlocks'] ) ? array_values( $blocks[ $head ]['innerBlocks'] ) : [];
		$next     = $this->tree_move( $children, $path, $position );
		if ( $next instanceof \WP_Error ) {
			return $next;
		}
		$blocks[ $head ]['innerBlocks']  = $next;
		$blocks[ $head ]['innerContent'] = $shape;
		return $blocks;
	}

	/** @param array<string,mixed> $block @return list<string|null>|\WP_Error */
	private function safe_inner_content( array $block, string $context ): array|\WP_Error {
		$children = isset( $block['innerBlocks'] ) && is_array( $block['innerBlocks'] ) ? $block['innerBlocks'] : [];
		$content  = $block['innerContent'] ?? null;
		if ( ! is_array( $content ) ) {
			return $this->error( 'unsafe_nested_structure', __( 'The block has no usable innerContent map for a nested mutation.', 'stonewright' ), [ 'status' => 400, 'path' => $context ] );
		}
		$strings = '';
		$nulls   = 0;
		foreach ( $content as $piece ) {
			if ( null === $piece ) {
				++$nulls;
				continue;
			}
			if ( ! is_string( $piece ) ) {
				return $this->error( 'unsafe_nested_structure', __( 'innerContent may contain only strings and child placeholders.', 'stonewright' ), [ 'status' => 400, 'path' => $context ] );
			}
			$strings .= $piece;
		}
		if ( $nulls !== count( $children ) || $strings !== (string) ( $block['innerHTML'] ?? '' ) ) {
			return $this->error( 'unsafe_nested_structure', __( 'innerContent does not match innerBlocks and innerHTML.', 'stonewright' ), [ 'status' => 400, 'path' => $context ] );
		}
		return array_values( $content );
	}

	/** @param list<string|null> $content @return list<string|null>|\WP_Error */
	private function insert_child_placeholder( array $content, int $position, int $child_count, string $inner_html ): array|\WP_Error {
		if ( 0 === $child_count ) {
			if ( '' !== $inner_html ) {
				return $this->error(
					'unsafe_nested_structure',
					__( 'A child cannot be inserted into non-empty wrapper HTML without an explicit placeholder.', 'stonewright' ),
					[ 'status' => 400, 'path' => 'operations.insert.parent.innerContent' ]
				);
			}
			return [ null ];
		}

		$seen = 0;
		foreach ( $content as $index => $piece ) {
			if ( null !== $piece ) {
				continue;
			}
			if ( $seen === $position ) {
				array_splice( $content, $index, 0, [ null ] );
				return array_values( $content );
			}
			++$seen;
			if ( $position === $child_count && $seen === $child_count ) {
				array_splice( $content, $index + 1, 0, [ null ] );
				return array_values( $content );
			}
		}
		return $this->error( 'unsafe_nested_structure', __( 'The insertion placeholder could not be placed safely.', 'stonewright' ), [ 'status' => 400 ] );
	}

	/** @param list<string|null> $content @return list<string|null>|\WP_Error */
	private function remove_child_placeholder( array $content, int $position ): array|\WP_Error {
		$seen = 0;
		foreach ( $content as $index => $piece ) {
			if ( null !== $piece ) {
				continue;
			}
			if ( $seen === $position ) {
				array_splice( $content, $index, 1 );
				return array_values( $content );
			}
			++$seen;
		}
		return $this->error( 'unsafe_nested_structure', __( 'The child placeholder could not be removed safely.', 'stonewright' ), [ 'status' => 400 ] );
	}

	/** @param array<string,mixed> $args @return array<string,mixed> */
	private function receipt( int $post_id, array $args, bool $dry_run, string $before_hash, string $planned_hash ): array {
		$change_set_id = sanitize_text_field( (string) ( $args['change_set_id'] ?? '' ) );
		if ( '' === $change_set_id ) {
			$change_set_id = 'cs-' . substr( hash( 'sha256', $post_id . '|' . $before_hash . '|' . $planned_hash . '|' . (string) wp_json_encode( $args['operations'] ?? [] ) ), 0, 24 );
		}
		$transaction_id = function_exists( 'wp_generate_uuid4' ) ? wp_generate_uuid4() : substr( hash( 'sha256', uniqid( 'blocks-', true ) ), 0, 36 );
		return [
			'transaction_id'      => $transaction_id,
			'change_set_id'       => $change_set_id,
			'post_id'             => $post_id,
			'architecture'        => 'gutenberg',
			'dry_run'             => $dry_run,
			'snapshot_id'         => '',
			'before_hash'         => $before_hash,
			'planned_hash'        => $planned_hash,
			'after_hash'          => '',
			'readback_hash'       => '',
			'verification_status' => $dry_run ? 'planned' : 'pending',
			'rollback_attempted'  => false,
			'rollback_status'     => 'not_needed',
			'rollback_readback_hash' => '',
			'root_error_code'     => '',
			'root_error_path'     => '',
			'retryable'           => false,
			'retry_after_seconds' => 0,
			'recovery_tool'       => '',
			'warnings'            => [],
		];
	}

	/** @param array<string,mixed> $receipt @param array{restored:bool,status:string,readback_hash:string} $rollback */
	private function failed_receipt( array $receipt, \WP_Error $error, array $rollback, string $path ): array {
		$data = (array) $error->get_error_data();
		$receipt['root_error_code'] = sanitize_key( (string) $error->get_error_code() );
		$receipt['root_error_path'] = sanitize_text_field( $path );
		$receipt['verification_status'] = 'failed';
		$receipt['rollback_attempted'] = true;
		$receipt['rollback_status'] = $rollback['status'];
		$receipt['rollback_readback_hash'] = $rollback['readback_hash'];
		$receipt['retryable'] = ! empty( $data['retryable'] );
		$receipt['retry_after_seconds'] = max( 0, (int) ( $data['retry_after_seconds'] ?? 0 ) );
		$receipt['recovery_tool'] = 'stonewright/backup-restore';
		return $receipt;
	}

	/** @return array{restored:bool,status:string,readback_hash:string} */
	private function restore_and_verify( int $post_id, string $snapshot_id, string $expected_hash ): array {
		$restore_reported = Backup::restore( $post_id, $snapshot_id );
		if ( function_exists( 'clean_post_cache' ) ) {
			clean_post_cache( $post_id );
		}
		$readback      = get_post( $post_id );
		$readback_hash = $readback ? hash( 'sha256', (string) $readback->post_content ) : '';
		$restored      = $restore_reported && '' !== $readback_hash && hash_equals( $expected_hash, $readback_hash );
		return [
			'restored'      => $restored,
			'status'        => $restored ? 'succeeded' : 'failed',
			'readback_hash' => $readback_hash,
		];
	}

	/** @param array<string,mixed> $block */
	private function validate_block_schema( array $block, string $context ): ?\WP_Error {
		if ( ! class_exists( '\WP_Block_Type_Registry' ) ) {
			return null;
		}

		if ( ! method_exists( '\WP_Block_Type_Registry', 'get_instance' ) ) {
			return $this->error( 'block_registry_unavailable', __( 'The registered block schema could not be loaded safely.', 'stonewright' ), [ 'status' => 500, 'path' => $context ] );
		}
		try {
			$registry = \WP_Block_Type_Registry::get_instance();
		} catch ( \Throwable $throwable ) {
			return $this->error(
				'block_registry_unavailable',
				__( 'The registered block schema could not be loaded safely.', 'stonewright' ),
				[ 'status' => 500, 'path' => $context, 'detail' => $throwable->getMessage() ]
			);
		}
		if ( ! is_object( $registry ) || ! method_exists( $registry, 'get_registered' ) ) {
			return $this->error( 'block_registry_unavailable', __( 'The registered block schema could not be loaded safely.', 'stonewright' ), [ 'status' => 500, 'path' => $context ] );
		}
		$name = (string) ( $block['blockName'] ?? '' );
		try {
			$registered = $registry->get_registered( $name );
		} catch ( \Throwable $throwable ) {
			return $this->error( 'block_registry_unavailable', __( 'The registered block schema could not be loaded safely.', 'stonewright' ), [ 'status' => 500, 'path' => $context, 'detail' => $throwable->getMessage() ] );
		}

		if ( ! is_object( $registered ) ) {
			return $this->error( 'unregistered_block', __( 'The block type is not registered on this site.', 'stonewright' ), [ 'status' => 400, 'path' => $context, 'block_name' => $name ] );
		}
		$attribute_schemas = isset( $registered->attributes ) && is_array( $registered->attributes ) ? $registered->attributes : [];
		$attributes        = isset( $block['attrs'] ) && is_array( $block['attrs'] ) ? $block['attrs'] : [];
		foreach ( $attributes as $attribute => $value ) {
			if ( ! is_string( $attribute ) || ! array_key_exists( $attribute, $attribute_schemas ) || ! is_array( $attribute_schemas[ $attribute ] ) ) {
				return $this->error(
					'invalid_block_attributes',
					__( 'A block attribute is not declared by the registered block schema.', 'stonewright' ),
					[ 'status' => 400, 'path' => $context . '.attrs.' . (string) $attribute, 'block_name' => $name ]
				);
			}
			$schema = $attribute_schemas[ $attribute ];
			if ( ! self::schema_type_matches( $value, $schema['type'] ?? null ) || ( isset( $schema['enum'] ) && is_array( $schema['enum'] ) && ! in_array( $value, $schema['enum'], true ) ) ) {
				return $this->error(
					'invalid_block_attributes',
					__( 'A block attribute does not match the registered block schema.', 'stonewright' ),
					[ 'status' => 400, 'path' => $context . '.attrs.' . $attribute, 'block_name' => $name ]
				);
			}
			if ( ! function_exists( 'rest_validate_value_from_schema' ) ) {
				return $this->error( 'block_schema_validator_unavailable', __( 'WordPress block schema validation is unavailable.', 'stonewright' ), [ 'status' => 500, 'path' => $context ] );
			}
			try {
				$valid = rest_validate_value_from_schema( $value, $schema, $context . '.attrs.' . $attribute );
			} catch ( \Throwable $throwable ) {
				return $this->error( 'invalid_block_attributes', __( 'A block attribute could not be validated safely.', 'stonewright' ), [ 'status' => 400, 'path' => $context . '.attrs.' . $attribute, 'detail' => $throwable->getMessage() ] );
			}
			$validation_code = $valid instanceof \WP_Error ? $valid->get_error_code() : '';
			if ( true !== $valid ) {
				return $this->error(
					'invalid_block_attributes',
					__( 'A block attribute does not match the registered block schema.', 'stonewright' ),
					[
						'status'          => 400,
						'path'            => $context . '.attrs.' . $attribute,
						'block_name'      => $name,
						'validation_code' => $validation_code,
					]
				);
			}
		}
		return null;
	}

	private static function schema_type_matches( mixed $value, mixed $type ): bool {
		if ( is_array( $type ) ) {
			foreach ( $type as $candidate ) {
				if ( self::schema_type_matches( $value, $candidate ) ) {
					return true;
				}
			}
			return false;
		}
		return match ( $type ) {
			'null'    => null === $value,
			'boolean' => is_bool( $value ),
			'integer' => is_int( $value ),
			'number'  => is_int( $value ) || is_float( $value ),
			'string'  => is_string( $value ),
			'array'   => is_array( $value ) && array_is_list( $value ),
			'object'  => is_array( $value ) || is_object( $value ),
			default   => true,
		};
	}

	/** @param array<string,mixed> $block @return array<string,mixed>|\WP_Error */
	private function normalize_block( array $block, string $context ): array|\WP_Error {
		$raw_name = $block['blockName'] ?? $block['name'] ?? '';
		if ( ! is_string( $raw_name ) ) {
			return $this->error( 'invalid_block', __( 'Block name must be a string.', 'stonewright' ), [ 'status' => 400, 'path' => $context . '.blockName' ] );
		}
		if ( array_key_exists( 'attrs', $block ) && ! is_array( $block['attrs'] ) ) {
			return $this->error( 'invalid_block', __( 'Block attrs must be an object.', 'stonewright' ), [ 'status' => 400, 'path' => $context . '.attrs' ] );
		}
		if ( array_key_exists( 'innerBlocks', $block ) && ! is_array( $block['innerBlocks'] ) ) {
			return $this->error( 'invalid_block', __( 'Block innerBlocks must be an array.', 'stonewright' ), [ 'status' => 400, 'path' => $context . '.innerBlocks' ] );
		}
		if ( array_key_exists( 'innerHTML', $block ) && ! is_string( $block['innerHTML'] ) ) {
			return $this->error( 'invalid_block', __( 'Block innerHTML must be a string.', 'stonewright' ), [ 'status' => 400, 'path' => $context . '.innerHTML' ] );
		}
		$children = isset( $block['innerBlocks'] ) && is_array( $block['innerBlocks'] ) ? $block['innerBlocks'] : [];
		$normalized_children = [];
		foreach ( $children as $index => $child ) {
			if ( ! is_array( $child ) ) {
				return $this->error( 'invalid_block', __( 'Every inner block must be an object.', 'stonewright' ), [ 'status' => 400, 'path' => $context . '.innerBlocks.' . $index ] );
			}
			$normalized = $this->normalize_block( $child, $context . '.innerBlocks.' . $index );
			if ( $normalized instanceof \WP_Error ) {
				return $normalized;
			}
			$normalized_children[] = $normalized;
		}
		$inner_html = isset( $block['innerHTML'] ) ? (string) $block['innerHTML'] : '';
		if ( array_key_exists( 'innerContent', $block ) ) {
			if ( ! is_array( $block['innerContent'] ) ) {
				return $this->error( 'unsafe_nested_structure', __( 'innerContent must be an array of strings and child placeholders.', 'stonewright' ), [ 'status' => 400, 'path' => $context . '.innerContent' ] );
			}
			$inner_content = array_values( $block['innerContent'] );
		} elseif ( [] === $normalized_children ) {
			$inner_content = self::inner_content( $inner_html, [] );
		} elseif ( '' === $inner_html ) {
			$inner_content = array_fill( 0, count( $normalized_children ), null );
		} else {
			return $this->error(
				'unsafe_nested_structure',
				__( 'Nested block HTML requires an explicit innerContent placeholder map.', 'stonewright' ),
				[ 'status' => 400, 'path' => $context . '.innerContent' ]
			);
		}
		$normalized = [
			'blockName'    => sanitize_text_field( $raw_name ),
			'attrs'        => isset( $block['attrs'] ) && is_array( $block['attrs'] ) ? $block['attrs'] : [],
			'innerHTML'    => $inner_html,
			'innerContent' => $inner_content,
			'innerBlocks'  => $normalized_children,
		];
		$shape = $this->safe_inner_content( $normalized, $context );
		if ( $shape instanceof \WP_Error ) {
			return $shape;
		}
		$normalized['innerContent'] = $shape;
		$schema_error = $this->validate_block_schema( $normalized, $context );
		return null === $schema_error ? $normalized : $schema_error;
	}
}
