<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\Gutenberg\Finalizer;

use Stonewright\WpMcp\Gutenberg\RawHtmlGate;
use Stonewright\WpMcp\Security\AuditLog;
use Stonewright\WpMcp\Security\Permissions;

/**
 * Persistent queue of Gutenberg block specs waiting for browser-side save().
 *
 * Distinct from FseTransactionQueue (FSE snapshot targets). This queue stores
 * `{name, attributes, innerBlocks}` until the hidden finalizer serializes them
 * against the live JS block registry.
 */
final class BlockQueue {

	public const OPTION      = 'stonewright_block_finalizer_queue';
	public const LOCK_OPTION = 'stonewright_block_finalizer_queue_lock';

	public const MAX_BATCH_ITEMS      = 20;
	public const MAX_OPEN_PER_USER    = 20;
	public const MAX_TOTAL_RECORDS    = 200;
	public const MAX_SPEC_BYTES       = 131072;
	public const MAX_TREE_DEPTH       = 32;
	public const MAX_TREE_NODES       = 500;
	public const MAX_SERIALIZED_BYTES = 1048576;
	public const MAX_STATE_BYTES      = 2097152;

	private const SCHEMA_VERSION = 2;
	private const LOCK_TTL       = 15;

	/** @var list<string> */
	private const TERMINAL = [ 'persisted', 'failed', 'cancelled' ];

	private static string $held_lock_token = '';

	/**
	 * Static / third-party blocks need the browser finalizer. Dynamic blocks
	 * (`save: null` / render_callback / is_dynamic) stay on the PHP fast path.
	 */
	public static function requires_finalizer( string $block_name ): bool {
		$block_name = sanitize_text_field( $block_name );
		if ( '' === $block_name ) {
			return true;
		}
		if ( ! class_exists( '\WP_Block_Type_Registry' ) || ! method_exists( '\WP_Block_Type_Registry', 'get_instance' ) ) {
			return true;
		}
		try {
			$registered = \WP_Block_Type_Registry::get_instance()->get_registered( $block_name );
		} catch ( \Throwable $_throwable ) {
			return true;
		}
		if ( ! is_object( $registered ) ) {
			return true;
		}
		if ( ! empty( $registered->is_dynamic ) ) {
			return false;
		}
		if ( isset( $registered->render_callback ) && is_callable( $registered->render_callback ) ) {
			return false;
		}
		if ( method_exists( $registered, 'is_dynamic' ) && $registered->is_dynamic() ) {
			return false;
		}
		return true;
	}

	/**
	 * @param array<string, mixed> $args
	 * @return array<string, mixed>|\WP_Error
	 */
	public static function enqueue( array $args ): array|\WP_Error {
		$queued = self::enqueue_many( [ $args ] );
		if ( $queued instanceof \WP_Error ) {
			return $queued;
		}
		return $queued[0];
	}

	/**
	 * Atomically queue every finalizer-needing op for one post.
	 *
	 * @param list<array<string, mixed>> $batch
	 * @return list<array<string, mixed>>|\WP_Error
	 */
	public static function enqueue_many( array $batch ): array|\WP_Error {
		if ( [] === $batch ) {
			return new \WP_Error(
				'stonewright_invalid_block_spec',
				__( 'A block spec object with name, attributes, and innerBlocks is required.', 'stonewright' ),
				[ 'status' => 400 ]
			);
		}

		if ( count( $batch ) > self::MAX_BATCH_ITEMS ) {
			return self::count_limit_error(
				'stonewright_finalizer_batch_limit',
				__( 'This finalize batch exceeds the item limit.', 'stonewright' ),
				self::MAX_BATCH_ITEMS,
				count( $batch )
			);
		}

		$owner = (int) get_current_user_id();
		if ( $owner <= 0 ) {
			return self::unauthenticated_error();
		}

		$post_id = (int) ( $batch[0]['post_id'] ?? 0 );
		$post    = $post_id > 0 ? get_post( $post_id ) : null;
		if ( ! $post ) {
			return new \WP_Error(
				'stonewright_not_found',
				__( 'Post not found.', 'stonewright' ),
				[ 'status' => 404 ]
			);
		}

		$current_hash = hash( 'sha256', (string) $post->post_content );
		$prepared     = [];
		$gate_specs   = [];
		$allow_raw    = false;
		$grant        = '';
		foreach ( $batch as $args ) {
			if ( (int) ( $args['post_id'] ?? 0 ) !== $post_id ) {
				return new \WP_Error(
					'stonewright_finalizer_mixed_targets',
					__( 'A finalize batch must target a single post.', 'stonewright' ),
					[ 'status' => 400 ]
				);
			}

			$item_allow = ! empty( $args['allow_raw_html'] );
			$allow_raw  = $allow_raw || $item_allow;
			if ( '' === $grant ) {
				$grant = (string) ( $args['custom_code_grant'] ?? '' );
			}
			$raw_spec = $args['block_spec'] ?? null;
			$too_big  = self::spec_size_error( $raw_spec );
			if ( $too_big instanceof \WP_Error ) {
				return $too_big;
			}
			$nodes = 0;
			$spec  = self::normalize_spec( $raw_spec, $item_allow, 1, $nodes );
			if ( $spec instanceof \WP_Error ) {
				return $spec;
			}
			$too_big = self::spec_size_error( $spec );
			if ( $too_big instanceof \WP_Error ) {
				return $too_big;
			}
			$gate_spec = $spec;
			if ( is_array( $raw_spec ) && isset( $raw_spec['innerHTML'] ) && is_string( $raw_spec['innerHTML'] ) ) {
				$gate_spec['innerHTML'] = $raw_spec['innerHTML'];
			}
			$gate_specs[] = $gate_spec;

			$expected = (string) ( $args['expected_content_hash'] ?? '' );
			if ( '' !== $expected && ! hash_equals( $expected, $current_hash ) ) {
				return new \WP_Error(
					'stonewright_content_conflict',
					__( 'The post content changed after planning; parse it again before queueing.', 'stonewright' ),
					[
						'status'                => 409,
						'expected_content_hash' => $expected,
						'current_content_hash'  => $current_hash,
						'retryable'             => true,
					]
				);
			}

			$prepared[] = [
				'args'     => $args,
				'spec'     => $spec,
				'expected' => $expected,
			];
		}

		$gated = RawHtmlGate::assert_specs( $gate_specs, $allow_raw, $grant, $post_id );
		if ( $gated instanceof \WP_Error ) {
			return $gated;
		}

		$locked = self::with_lock(
			static function () use ( $post_id, $prepared, $owner ) {
				if ( (int) get_current_user_id() !== $owner ) {
					return self::unauthenticated_error();
				}

				$post = get_post( $post_id );
				if ( ! $post ) {
					return new \WP_Error(
						'stonewright_not_found',
						__( 'Post not found.', 'stonewright' ),
						[ 'status' => 404 ]
					);
				}

				$current_hash = hash( 'sha256', (string) $post->post_content );
				foreach ( $prepared as $item ) {
					if ( '' !== $item['expected'] && ! hash_equals( $item['expected'], $current_hash ) ) {
						return new \WP_Error(
							'stonewright_content_conflict',
							__( 'The post content changed after planning; parse it again before queueing.', 'stonewright' ),
							[
								'status'                => 409,
								'expected_content_hash' => $item['expected'],
								'current_content_hash'  => $current_hash,
								'retryable'             => true,
							]
						);
					}
				}

				$pending = self::pending_for_target( $post_id );
				if ( null !== $pending ) {
					if ( (int) ( $pending['owner_user_id'] ?? 0 ) !== $owner ) {
						return new \WP_Error(
							'stonewright_finalizer_pending_change',
							__( 'This post already has a pending block finalizer change.', 'stonewright' ),
							[
								'status'  => 409,
								'post_id' => $post_id,
							]
						);
					}
					return new \WP_Error(
						'stonewright_finalizer_pending_change',
						__( 'This post already has a non-terminal block finalizer change. Finalize or cancel it first.', 'stonewright' ),
						[
							'status'    => 409,
							'change_id' => $pending['id'],
							'post_id'   => $post_id,
						]
					);
				}

				$state = self::state();
				$now   = time();
				[ $changes, $pruned_count ] = self::prune_changes( $state['changes'], $now );
				$state['changes'] = $changes;

				$incoming = count( $prepared );
				$open     = 0;
				foreach ( $changes as $existing ) {
					if ( is_array( $existing ) && (int) ( $existing['owner_user_id'] ?? 0 ) === $owner && self::is_open( $existing ) ) {
						++$open;
					}
				}
				if ( $open + $incoming > self::MAX_OPEN_PER_USER ) {
					return self::count_limit_error(
						'stonewright_finalizer_open_limit',
						__( 'Too many open block finalizer changes for this user.', 'stonewright' ),
						self::MAX_OPEN_PER_USER,
						$open + $incoming
					);
				}
				if ( count( $changes ) + $incoming > self::MAX_TOTAL_RECORDS ) {
					return self::count_limit_error(
						'stonewright_finalizer_total_limit',
						__( 'The block finalizer queue is full.', 'stonewright' ),
						self::MAX_TOTAL_RECORDS,
						count( $changes ) + $incoming
					);
				}

				$session = self::uuid();
				$out     = [];
				foreach ( $prepared as $item ) {
					$args   = $item['args'];
					$id     = self::uuid();
					$record = [
						'id'                    => $id,
						'post_id'               => $post_id,
						'status'                => 'queued',
						'block_spec'            => $item['spec'],
						'action'                => sanitize_key( (string) ( $args['action'] ?? 'insert' ) ),
						'path'                  => isset( $args['path'] ) && is_array( $args['path'] ) ? array_values( array_map( 'intval', $args['path'] ) ) : [],
						'position'              => array_key_exists( 'position', $args ) && null !== $args['position'] ? (int) $args['position'] : null,
						'expected_content_hash' => '' !== $item['expected'] ? $item['expected'] : $current_hash,
						'serialized_html'       => '',
						'serialized_html_hash'  => '',
						'session_id'            => $session,
						'owner_user_id'         => $owner,
						'legacy'                => false,
						'created_at'            => $now,
						'updated_at'            => $now,
						'allow_raw_html'        => ! empty( $args['allow_raw_html'] ),
					];
					$state['changes'][ $id ] = $record;
					$compact                 = self::compact( $record );
					$compact['pruned_count'] = $pruned_count;
					$out[]                   = $compact;
				}
				$saved = self::save( $state );
				if ( $saved instanceof \WP_Error ) {
					return $saved;
				}
				if ( $pruned_count > 0 ) {
					self::audit_prune( $post_id, $pruned_count );
				}

				return $out;
			}
		);

		if ( $locked instanceof \WP_Error ) {
			return $locked;
		}

		return is_array( $locked ) ? $locked : self::forbidden_error();
	}

	/**
	 * @return array<string, mixed>|null Full record including block_spec.
	 */
	public static function get( string $id ): ?array {
		$state  = self::state();
		$record = $state['changes'][ $id ] ?? null;
		return is_array( $record ) ? $record : null;
	}

	/**
	 * Bounded list/status: never includes full block_spec.
	 *
	 * @return list<array<string, mixed>>
	 */
	public static function list(): array {
		$out = [];
		foreach ( self::state()['changes'] as $record ) {
			if ( is_array( $record ) ) {
				$out[] = self::compact( $record );
			}
		}
		return $out;
	}

	/**
	 * Compact records the current viewer may see. Never includes block_spec.
	 *
	 * @return list<array<string, mixed>>
	 */
	public static function list_for_viewer( int $post_id = 0 ): array {
		$actor = (int) get_current_user_id();
		$admin = current_user_can( 'manage_options' );
		$out   = [];
		foreach ( self::state()['changes'] as $record ) {
			if ( ! is_array( $record ) ) {
				continue;
			}
			$record_post = (int) ( $record['post_id'] ?? 0 );
			if ( $post_id > 0 && $record_post !== $post_id ) {
				continue;
			}
			if ( ! Permissions::edit_post( $record_post ) ) {
				continue;
			}
			$owner  = (int) ( $record['owner_user_id'] ?? 0 );
			$legacy = ! empty( $record['legacy'] );
			$own    = $actor > 0 && $owner === $actor && ! $legacy;
			if ( $own || $admin ) {
				$out[] = self::compact( $record );
			}
		}
		return $out;
	}

	public static function pending_count( int $post_id = 0 ): int {
		$count = 0;
		foreach ( self::list_for_viewer( $post_id ) as $item ) {
			if ( self::is_open_status( (string) ( $item['status'] ?? '' ) ) ) {
				++$count;
			}
		}
		return $count;
	}

	public static function failed_count( int $post_id = 0 ): int {
		$count = 0;
		foreach ( self::list_for_viewer( $post_id ) as $item ) {
			if ( 'failed' === (string) ( $item['status'] ?? '' ) ) {
				++$count;
			}
		}
		return $count;
	}

	/**
	 * @param array<string, mixed> $verified
	 * @return array{queued:int,failed:int}
	 */
	public static function counts_for_scope( array $verified ): array {
		$scope  = self::normalize_scope( $verified );
		$queued = 0;
		$failed = 0;
		if ( null === $scope ) {
			return [
				'queued' => 0,
				'failed' => 0,
			];
		}
		foreach ( self::state()['changes'] as $record ) {
			if ( ! self::record_matches_scope( $record, $scope ) ) {
				continue;
			}
			$status = (string) ( $record['status'] ?? '' );
			if ( 'failed' === $status ) {
				++$failed;
			} elseif ( self::is_open_status( $status ) ) {
				++$queued;
			}
		}
		return [
			'queued' => $queued,
			'failed' => $failed,
		];
	}

	/**
	 * True when this spec or any descendant needs the browser finalizer.
	 *
	 * @param array<string, mixed> $spec
	 */
	public static function tree_requires_finalizer( array $spec ): bool {
		$nodes = 0;
		return self::tree_requires_finalizer_bounded( $spec, 1, $nodes );
	}

	/**
	 * @param array<string, mixed> $spec
	 */
	private static function tree_requires_finalizer_bounded( array $spec, int $depth, int &$nodes ): bool {
		if ( $depth > self::MAX_TREE_DEPTH ) {
			return true;
		}
		++$nodes;
		if ( $nodes > self::MAX_TREE_NODES ) {
			return true;
		}
		$name = sanitize_text_field( (string) ( $spec['name'] ?? $spec['blockName'] ?? '' ) );
		if ( self::requires_finalizer( $name ) ) {
			return true;
		}
		$inner = $spec['innerBlocks'] ?? [];
		if ( ! is_array( $inner ) ) {
			return false;
		}
		foreach ( $inner as $child ) {
			if ( is_array( $child ) && self::tree_requires_finalizer_bounded( $child, $depth + 1, $nodes ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * @return array<string, mixed>|null
	 */
	public static function pending_for_target( int $post_id ): ?array {
		foreach ( self::state()['changes'] as $record ) {
			if ( is_array( $record ) && (int) ( $record['post_id'] ?? 0 ) === $post_id && self::is_open( $record ) ) {
				return $record;
			}
		}
		return null;
	}

	/**
	 * @param array<string, mixed> $verified
	 * @return list<array<string, mixed>>
	 */
	public static function pending_for_scope( array $verified, bool $include_spec = false ): array {
		$scope = self::normalize_scope( $verified );
		if ( null === $scope ) {
			return [];
		}
		$out = [];
		foreach ( self::state()['changes'] as $record ) {
			if ( ! self::record_matches_scope( $record, $scope ) ) {
				continue;
			}
			if ( 'queued' !== (string) ( $record['status'] ?? '' ) ) {
				continue;
			}
			$out[] = $include_spec ? $record : self::compact( $record );
		}
		return $out;
	}

	/**
	 * @return bool|\WP_Error
	 */
	public static function store_serialized( string $id, string $html, string $hash, ?array $scope = null ): bool|\WP_Error {
		$html_bytes = strlen( $html );
		if ( $html_bytes > self::MAX_SERIALIZED_BYTES ) {
			return self::size_limit_error(
				'stonewright_finalizer_html_too_large',
				__( 'Serialized HTML exceeds the size limit.', 'stonewright' ),
				self::MAX_SERIALIZED_BYTES,
				$html_bytes
			);
		}

		$locked = self::with_lock(
			static function () use ( $id, $html, $hash, $scope ) {
				$state  = self::state();
				$record = $state['changes'][ $id ] ?? null;
				if ( ! is_array( $record ) ) {
					return self::not_found_error();
				}
				$denied = self::assert_write_scope( $record, $scope );
				if ( $denied instanceof \WP_Error ) {
					return $denied;
				}
				if ( 'queued' !== (string) ( $record['status'] ?? '' ) ) {
					return self::terminal_error();
				}
				$expect = hash( 'sha256', $html );
				if ( '' === $hash || ! hash_equals( $expect, $hash ) ) {
					return new \WP_Error(
						'stonewright_finalizer_hash_mismatch',
						__( 'Serialized HTML hash does not match the payload.', 'stonewright' ),
						[ 'status' => 400 ]
					);
				}
				$state['changes'][ $id ]['serialized_html']      = $html;
				$state['changes'][ $id ]['serialized_html_hash'] = $expect;
				$state['changes'][ $id ]['status']               = 'serialized';
				$state['changes'][ $id ]['updated_at']           = time();
				$saved = self::save( $state );
				if ( $saved instanceof \WP_Error ) {
					return $saved;
				}
				return true;
			}
		);

		return $locked instanceof \WP_Error ? $locked : (bool) $locked;
	}

	/**
	 * @return bool|\WP_Error
	 */
	public static function mark_persisted( string $id ): bool|\WP_Error {
		$locked = self::with_lock(
			static function () use ( $id ) {
				$state  = self::state();
				$record = $state['changes'][ $id ] ?? null;
				if ( ! is_array( $record ) ) {
					return self::not_found_error();
				}
				$owner = (int) ( $record['owner_user_id'] ?? 0 );
				$actor = (int) get_current_user_id();
				if ( $owner <= 0 || $actor !== $owner || ! empty( $record['legacy'] ) ) {
					return self::forbidden_error();
				}
				$state['changes'][ $id ]['status']     = 'persisted';
				$state['changes'][ $id ]['updated_at'] = time();
				$saved = self::save( $state );
				if ( $saved instanceof \WP_Error ) {
					return $saved;
				}
				return true;
			}
		);

		return $locked instanceof \WP_Error ? $locked : (bool) $locked;
	}

	/**
	 * @return bool|\WP_Error
	 */
	public static function mark_failed( string $id, string $message = '', string $html = '', string $code = '', ?array $scope = null ): bool|\WP_Error {
		$locked = self::with_lock(
			static function () use ( $id, $message, $html, $code, $scope ) {
				$state  = self::state();
				$record = $state['changes'][ $id ] ?? null;
				if ( ! is_array( $record ) ) {
					return self::not_found_error();
				}
				$denied = self::assert_write_scope( $record, $scope );
				if ( $denied instanceof \WP_Error ) {
					return $denied;
				}
				if ( 'queued' !== (string) ( $record['status'] ?? '' ) ) {
					return self::terminal_error();
				}
				$state['changes'][ $id ]['status']     = 'failed';
				$state['changes'][ $id ]['error']      = sanitize_text_field( $message );
				$state['changes'][ $id ]['updated_at'] = time();
				if ( strlen( $html ) > self::MAX_SERIALIZED_BYTES ) {
					$html = '';
					if ( '' === $code ) {
						$code = 'html_too_large';
					}
				}
				if ( '' !== $code ) {
					$state['changes'][ $id ]['error_code'] = sanitize_key( $code );
				}
				if ( '' !== $html ) {
					$state['changes'][ $id ]['serialized_html'] = $html;
				}
				$saved = self::save( $state );
				if ( $saved instanceof \WP_Error ) {
					return $saved;
				}
				return true;
			}
		);

		return $locked instanceof \WP_Error ? $locked : (bool) $locked;
	}

	/**
	 * Unique sorted ids for token binding and cancel. Explicit ids only.
	 *
	 * @param mixed $raw
	 * @return list<string>|\WP_Error
	 */
	public static function canonicalize_change_ids( mixed $raw ): array|\WP_Error {
		if ( ! is_array( $raw ) ) {
			return self::invalid_change_ids_error();
		}

		$unique = [];
		foreach ( $raw as $id ) {
			if ( ! is_string( $id ) ) {
				return self::invalid_change_ids_error();
			}
			$id = trim( $id );
			if ( '' === $id ) {
				return self::invalid_change_ids_error();
			}
			$unique[ $id ] = true;
		}

		$ids = array_keys( $unique );
		if ( [] === $ids ) {
			return self::invalid_change_ids_error();
		}
		if ( count( $ids ) > self::MAX_BATCH_ITEMS ) {
			return self::count_limit_error(
				'stonewright_finalizer_batch_limit',
				__( 'This finalize batch exceeds the item limit.', 'stonewright' ),
				self::MAX_BATCH_ITEMS,
				count( $ids )
			);
		}

		sort( $ids, SORT_STRING );
		return array_values( $ids );
	}

	/**
	 * Delete queued, serialized, or failed records by explicit id.
	 *
	 * Unknown or unauthorized ids fail closed with a generic 404 and no
	 * partial delete. Persisted records are refused.
	 *
	 * @param list<string> $change_ids
	 * @return array<string, mixed>|\WP_Error
	 */
	public static function cancel( array $change_ids, bool $dry_run, int $actor_id ): array|\WP_Error {
		$ids = self::canonicalize_change_ids( $change_ids );
		if ( $ids instanceof \WP_Error ) {
			return $ids;
		}
		if ( $actor_id <= 0 ) {
			return self::unauthenticated_error();
		}

		$locked = self::with_lock(
			static function () use ( $ids, $dry_run, $actor_id ) {
				if ( $actor_id !== (int) get_current_user_id() ) {
					return self::unauthenticated_error();
				}

				$state   = self::state();
				$changes = $state['changes'];
				$admin   = Permissions::manage_options();

				foreach ( $ids as $id ) {
					if ( ! isset( $changes[ $id ] ) || ! is_array( $changes[ $id ] ) ) {
						return self::not_found_error();
					}
				}

				foreach ( $ids as $id ) {
					$record   = $changes[ $id ];
					$post_id  = (int) ( $record['post_id'] ?? 0 );
					$owner    = (int) ( $record['owner_user_id'] ?? 0 );
					$owns     = $owner === $actor_id && empty( $record['legacy'] );
					$editable = Permissions::edit_post( $post_id );
					if ( ! $owns && ! ( $admin && $editable ) ) {
						return self::not_found_error();
					}
					if ( ! $editable ) {
						return self::forbidden_error();
					}
				}

				$statuses = [];
				$post_ids = [];
				foreach ( $ids as $id ) {
					$record = $changes[ $id ];
					$status = (string) ( $record['status'] ?? '' );
					if ( 'persisted' === $status ) {
						return new \WP_Error(
							'stonewright_finalizer_already_persisted',
							__( 'This finalizer change has already been persisted and cannot be cancelled.', 'stonewright' ),
							[
								'status'       => 409,
								'change_id'    => $id,
								'queue_status' => 'persisted',
							]
						);
					}
					if ( ! in_array( $status, [ 'queued', 'serialized', 'failed' ], true ) ) {
						return new \WP_Error(
							'stonewright_finalizer_not_cancellable',
							__( 'This finalizer change cannot be cancelled.', 'stonewright' ),
							[
								'status'       => 409,
								'change_id'    => $id,
								'queue_status' => $status,
							]
						);
					}
					$statuses[] = $status;
					$post_ids[] = (int) ( $record['post_id'] ?? 0 );
				}

				if ( $dry_run ) {
					return self::cancel_receipt( true, 0, $ids, $statuses, $post_ids, 'planned', false );
				}

				foreach ( $ids as $id ) {
					unset( $state['changes'][ $id ] );
				}
				$saved = self::save( $state );
				if ( $saved instanceof \WP_Error ) {
					return $saved;
				}

				$fresh = self::state();
				foreach ( $ids as $id ) {
					if ( isset( $fresh['changes'][ $id ] ) ) {
						return new \WP_Error(
							'stonewright_finalizer_cancel_incomplete',
							__( 'The selected finalizer changes could not be fully cancelled.', 'stonewright' ),
							[
								'status'              => 500,
								'verification_status' => 'failed',
								'effect_verified'     => false,
							]
						);
					}
				}

				return self::cancel_receipt( false, count( $ids ), $ids, $statuses, $post_ids, 'verified', true );
			}
		);

		if ( $locked instanceof \WP_Error ) {
			return $locked;
		}

		return is_array( $locked ) ? $locked : self::forbidden_error();
	}

	/**
	 * @param callable(): mixed $callback
	 * @return mixed
	 */
	public static function with_lock( callable $callback ): mixed {
		if ( '' !== self::$held_lock_token ) {
			return $callback();
		}

		$lease = self::acquire_lock();
		if ( $lease instanceof \WP_Error ) {
			return $lease;
		}

		try {
			return $callback();
		} finally {
			self::release_lock( (string) $lease['token'] );
			self::$held_lock_token = '';
		}
	}

	/**
	 * @return list<array{session_id:string,post_id:int,owner_user_id:int,queued_count:int,failed_count:int}>
	 */
	public static function owned_sessions(): array {
		$actor    = (int) get_current_user_id();
		$sessions = [];
		if ( $actor <= 0 ) {
			return [];
		}
		foreach ( self::state()['changes'] as $record ) {
			if ( ! is_array( $record ) ) {
				continue;
			}
			if ( ! empty( $record['legacy'] ) ) {
				continue;
			}
			if ( (int) ( $record['owner_user_id'] ?? 0 ) !== $actor ) {
				continue;
			}
			$session_id = (string) ( $record['session_id'] ?? '' );
			if ( '' === $session_id ) {
				continue;
			}
			if ( ! isset( $sessions[ $session_id ] ) ) {
				$sessions[ $session_id ] = [
					'session_id'    => $session_id,
					'post_id'       => (int) ( $record['post_id'] ?? 0 ),
					'owner_user_id' => $actor,
					'queued_count'  => 0,
					'failed_count'  => 0,
				];
			}
			$status = (string) ( $record['status'] ?? '' );
			if ( 'failed' === $status ) {
				++$sessions[ $session_id ]['failed_count'];
			} elseif ( self::is_open_status( $status ) ) {
				++$sessions[ $session_id ]['queued_count'];
			}
		}
		$sessions = array_values( $sessions );
		usort(
			$sessions,
			static function ( array $left, array $right ): int {
				$queued = (int) $right['queued_count'] <=> (int) $left['queued_count'];
				if ( 0 !== $queued ) {
					return $queued;
				}
				return (int) $left['post_id'] <=> (int) $right['post_id'];
			}
		);
		return $sessions;
	}

	public static function viewer_has_foreign_records(): bool {
		$actor = (int) get_current_user_id();
		foreach ( self::state()['changes'] as $record ) {
			if ( ! is_array( $record ) || ! self::is_open( $record ) ) {
				continue;
			}
			$post_id = (int) ( $record['post_id'] ?? 0 );
			if ( ! Permissions::edit_post( $post_id ) ) {
				continue;
			}
			$owner = (int) ( $record['owner_user_id'] ?? 0 );
			if ( ! empty( $record['legacy'] ) || $owner !== $actor ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * @return array{token:string,session_id:string,post_id:int,expires_at:int}|\WP_Error
	 */
	public static function issue_token( string $session_id = '' ): array|\WP_Error {
		$actor = (int) get_current_user_id();
		if ( $actor <= 0 ) {
			return self::forbidden_error();
		}

		$scope = '' !== $session_id ? self::session_scope( $session_id ) : self::first_owned_session();
		if ( null === $scope ) {
			return self::forbidden_error();
		}
		if ( (int) $scope['owner_user_id'] !== $actor || ! empty( $scope['legacy'] ) || (int) $scope['post_id'] <= 0 ) {
			return self::forbidden_error();
		}

		$exp     = time() + ( defined( 'HOUR_IN_SECONDS' ) ? (int) HOUR_IN_SECONDS : 3600 );
		$payload = wp_json_encode(
			[
				's' => $scope['session_id'],
				'u' => $actor,
				'p' => (int) $scope['post_id'],
				'e' => $exp,
			],
			JSON_UNESCAPED_SLASHES
		);
		$body = rtrim( strtr( base64_encode( (string) $payload ), '+/', '-_' ), '=' );
		$sig  = hash_hmac( 'sha256', $body, wp_salt( 'auth' ) );
		return [
			'token'      => $body . '.' . $sig,
			'session_id' => $scope['session_id'],
			'post_id'    => (int) $scope['post_id'],
			'expires_at' => $exp,
		];
	}

	/**
	 * @return array{s:string,u:int,p:int,e:int,session_id:string,owner_user_id:int,post_id:int,expires_at:int}|\WP_Error
	 */
	public static function verify_token( string $token ): array|\WP_Error {
		$forbidden = self::forbidden_error();
		$parts     = explode( '.', $token, 2 );
		if ( 2 !== count( $parts ) || '' === $parts[0] || '' === $parts[1] ) {
			return $forbidden;
		}
		$expect = hash_hmac( 'sha256', $parts[0], wp_salt( 'auth' ) );
		if ( ! hash_equals( $expect, $parts[1] ) ) {
			return $forbidden;
		}
		$padded = strtr( $parts[0], '-_', '+/' );
		$pad    = strlen( $padded ) % 4;
		if ( 0 !== $pad ) {
			$padded .= str_repeat( '=', 4 - $pad );
		}
		$decoded = base64_decode( $padded, true );
		$data    = is_string( $decoded ) ? json_decode( $decoded, true ) : null;
		if ( ! is_array( $data ) || empty( $data['s'] ) || empty( $data['e'] ) || empty( $data['p'] ) ) {
			return $forbidden;
		}
		if ( (int) $data['e'] < time() ) {
			return $forbidden;
		}
		$uid = (int) ( $data['u'] ?? 0 );
		if ( $uid <= 0 || $uid !== (int) get_current_user_id() ) {
			return $forbidden;
		}
		$session = (string) $data['s'];
		$post_id = (int) $data['p'];
		return [
			's'             => $session,
			'u'             => $uid,
			'p'             => $post_id,
			'e'             => (int) $data['e'],
			'session_id'    => $session,
			'owner_user_id' => $uid,
			'post_id'       => $post_id,
			'expires_at'    => (int) $data['e'],
		];
	}

	/**
	 * @param array<string, mixed> $record
	 * @return array<string, mixed>
	 */
	public static function compact( array $record ): array {
		$out = [
			'id'                    => (string) ( $record['id'] ?? '' ),
			'post_id'               => (int) ( $record['post_id'] ?? 0 ),
			'status'                => (string) ( $record['status'] ?? '' ),
			'block_name'            => (string) ( $record['block_spec']['name'] ?? '' ),
			'action'                => (string) ( $record['action'] ?? 'insert' ),
			'expected_content_hash' => (string) ( $record['expected_content_hash'] ?? '' ),
			'session_id'            => (string) ( $record['session_id'] ?? '' ),
			'created_at'            => (int) ( $record['created_at'] ?? 0 ),
		];
		if ( 'failed' === $out['status'] ) {
			$message = (string) ( $record['error'] ?? '' );
			$code    = (string) ( $record['error_code'] ?? '' );
			if ( '' === $code && '' !== $message ) {
				$parts = explode( ':', $message, 2 );
				$code  = sanitize_key( $parts[0] );
			}
			$out['error'] = [
				'code'    => $code,
				'message' => $message,
			];
		}
		return $out;
	}

	/**
	 * @return array<string, mixed>|\WP_Error
	 */
	private static function normalize_spec( mixed $raw, bool $allow_raw_html, int $depth, int &$nodes ): array|\WP_Error {
		if ( $depth > self::MAX_TREE_DEPTH ) {
			return new \WP_Error(
				'stonewright_finalizer_tree_too_deep',
				__( 'The block spec exceeds the maximum tree depth.', 'stonewright' ),
				[
					'status' => 413,
					'limit'  => self::MAX_TREE_DEPTH,
					'depth'  => $depth,
				]
			);
		}
		++$nodes;
		if ( $nodes > self::MAX_TREE_NODES ) {
			return new \WP_Error(
				'stonewright_finalizer_tree_too_large',
				__( 'The block spec exceeds the maximum node count.', 'stonewright' ),
				[
					'status' => 413,
					'limit'  => self::MAX_TREE_NODES,
					'nodes'  => $nodes,
				]
			);
		}

		if ( is_string( $raw ) || ( is_array( $raw ) && self::is_raw_html_spec( $raw ) ) ) {
			if ( ! $allow_raw_html ) {
				return new \WP_Error(
					'stonewright_raw_html_refused',
					__( 'Top-level raw HTML is refused unless allow_raw_html is true. Queue {name, attributes, innerBlocks} instead.', 'stonewright' ),
					[ 'status' => 400 ]
				);
			}
			$html = is_string( $raw ) ? $raw : (string) ( $raw['innerHTML'] ?? $raw['html'] ?? '' );
			return [
				'name'        => 'core/html',
				'attributes'  => [ 'content' => $html ],
				'innerBlocks' => [],
			];
		}
		if ( ! is_array( $raw ) ) {
			return new \WP_Error(
				'stonewright_invalid_block_spec',
				__( 'A block spec object with name, attributes, and innerBlocks is required.', 'stonewright' ),
				[ 'status' => 400 ]
			);
		}

		$name = (string) ( $raw['name'] ?? $raw['blockName'] ?? '' );
		if ( '' === $name ) {
			return new \WP_Error(
				'stonewright_invalid_block_spec',
				__( 'A block spec requires a block name.', 'stonewright' ),
				[ 'status' => 400 ]
			);
		}

		$attributes = [];
		if ( isset( $raw['attributes'] ) && is_array( $raw['attributes'] ) ) {
			$attributes = $raw['attributes'];
		} elseif ( isset( $raw['attrs'] ) && is_array( $raw['attrs'] ) ) {
			$attributes = $raw['attrs'];
		}

		$spec = [
			'name'       => sanitize_text_field( $name ),
			'attributes' => $attributes,
		];
		if ( ! array_key_exists( 'innerBlocks', $raw ) || null === $raw['innerBlocks'] ) {
			return $spec;
		}
		if ( ! is_array( $raw['innerBlocks'] ) ) {
			return new \WP_Error(
				'stonewright_invalid_block_spec',
				__( 'A block spec object with name, attributes, and innerBlocks is required.', 'stonewright' ),
				[ 'status' => 400 ]
			);
		}

		$children = [];
		foreach ( $raw['innerBlocks'] as $child ) {
			$normalized = self::normalize_spec( $child, $allow_raw_html, $depth + 1, $nodes );
			if ( $normalized instanceof \WP_Error ) {
				return $normalized;
			}
			$children[] = $normalized;
		}
		$spec['innerBlocks'] = $children;

		return $spec;
	}

	/** @param array<string, mixed> $raw */
	private static function is_raw_html_spec( array $raw ): bool {
		$name = (string) ( $raw['name'] ?? $raw['blockName'] ?? '' );
		if ( '' !== $name ) {
			return false;
		}
		$html = (string) ( $raw['innerHTML'] ?? $raw['html'] ?? '' );
		return '' !== $html || str_contains( (string) wp_json_encode( $raw ), '<!-- wp:' );
	}

	/** @param array<string, mixed> $record */
	private static function is_open( array $record ): bool {
		return self::is_open_status( (string) ( $record['status'] ?? '' ) );
	}

	private static function is_open_status( string $status ): bool {
		return ! in_array( $status, self::TERMINAL, true );
	}

	/**
	 * @return array{schema_version:int,changes:array<string, array<string, mixed>>}
	 */
	private static function state(): array {
		$stored = get_option( self::OPTION, [] );
		if ( ! is_array( $stored ) ) {
			$stored = [];
		}
		if ( ! self::needs_migration( $stored ) ) {
			$changes = isset( $stored['changes'] ) && is_array( $stored['changes'] ) ? $stored['changes'] : [];
			return [
				'schema_version' => self::SCHEMA_VERSION,
				'changes'        => $changes,
			];
		}

		$migrate = static function () {
			$stored = get_option( self::OPTION, [] );
			if ( ! is_array( $stored ) ) {
				$stored = [];
			}
			$migrated = self::migrate_state( $stored );
			self::save( $migrated );
			return $migrated;
		};

		if ( '' !== self::$held_lock_token ) {
			return $migrate();
		}

		$locked = self::with_lock( $migrate );
		if ( $locked instanceof \WP_Error ) {
			return self::migrate_state( $stored );
		}

		return is_array( $locked ) ? $locked : self::migrate_state( $stored );
	}

	/**
	 * @param array{schema_version?:int,changes?:array<string, array<string, mixed>>} $state
	 * @return true|\WP_Error
	 */
	private static function save( array $state ): bool|\WP_Error {
		$payload = [
			'schema_version' => self::SCHEMA_VERSION,
			'changes'        => isset( $state['changes'] ) && is_array( $state['changes'] ) ? $state['changes'] : [],
		];
		$encoded = maybe_serialize( $payload );
		$bytes   = is_string( $encoded ) ? strlen( $encoded ) : PHP_INT_MAX;
		if ( $bytes > self::MAX_STATE_BYTES ) {
			return self::size_limit_error(
				'stonewright_finalizer_state_too_large',
				__( 'The block finalizer queue state exceeds the size limit.', 'stonewright' ),
				self::MAX_STATE_BYTES,
				is_string( $encoded ) ? $bytes : 0
			);
		}
		update_option( self::OPTION, $payload, false );
		return true;
	}

	/** @param array<string, mixed> $stored */
	private static function needs_migration( array $stored ): bool {
		if ( [] === $stored ) {
			return false;
		}
		if ( self::SCHEMA_VERSION !== (int) ( $stored['schema_version'] ?? 0 ) ) {
			return true;
		}
		if ( array_key_exists( 'session_id', $stored ) ) {
			return true;
		}
		$changes = isset( $stored['changes'] ) && is_array( $stored['changes'] ) ? $stored['changes'] : [];
		foreach ( $changes as $record ) {
			if ( ! is_array( $record ) ) {
				continue;
			}
			if ( ! array_key_exists( 'owner_user_id', $record ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * @param array<string, mixed> $stored
	 * @return array{schema_version:int,changes:array<string, array<string, mixed>>}
	 */
	private static function migrate_state( array $stored ): array {
		$changes  = isset( $stored['changes'] ) && is_array( $stored['changes'] ) ? $stored['changes'] : [];
		$migrated = [];
		foreach ( $changes as $id => $record ) {
			if ( ! is_array( $record ) ) {
				continue;
			}
			$owner   = (int) ( $record['owner_user_id'] ?? 0 );
			$session = (string) ( $record['session_id'] ?? '' );
			$unbound = $owner <= 0 || '' === $session;
			if ( $unbound ) {
				$record['legacy']        = true;
				$record['owner_user_id'] = 0;
				if ( self::is_open( $record ) ) {
					$record['status']     = 'failed';
					$record['error_code'] = 'legacy_session_unbound';
					$record['error']      = sanitize_text_field(
						__( 'This queued change has no owner session and cannot be serialized.', 'stonewright' )
					);
				}
			} else {
				$record['legacy']        = ! empty( $record['legacy'] );
				$record['owner_user_id'] = $owner;
			}
			$record['session_id']  = $session;
			$record['updated_at']  = (int) ( $record['updated_at'] ?? $record['created_at'] ?? time() );
			$migrated[ (string) $id ] = $record;
		}

		return [
			'schema_version' => self::SCHEMA_VERSION,
			'changes'        => $migrated,
		];
	}

	/**
	 * @return array{token:string,owner_user_id:int,expires_at:int}|\WP_Error
	 */
	private static function acquire_lock(): array|\WP_Error {
		$now   = time();
		$lease = [
			'token'         => self::uuid(),
			'owner_user_id' => (int) get_current_user_id(),
			'expires_at'    => $now + self::LOCK_TTL,
		];
		self::$held_lock_token = (string) $lease['token'];
		if ( add_option( self::LOCK_OPTION, $lease, '', false ) ) {
			return $lease;
		}
		self::$held_lock_token = '';

		$current = get_option( self::LOCK_OPTION, [] );
		if ( is_array( $current ) && (int) ( $current['expires_at'] ?? 0 ) <= $now && self::delete_lock_if_unchanged( $current ) ) {
			self::$held_lock_token = (string) $lease['token'];
			// Second claim after compare-and-delete of an expired lock; PHPStan cannot model the option store.
			// @phpstan-ignore-next-line
			if ( add_option( self::LOCK_OPTION, $lease, '', false ) ) {
				return $lease;
			}
			self::$held_lock_token = '';
			$current = get_option( self::LOCK_OPTION, [] );
		}

		$expires_at  = is_array( $current ) ? (int) ( $current['expires_at'] ?? $now + 5 ) : $now + 5;
		$retry_after = max( 1, min( 120, $expires_at - $now ) );
		return new \WP_Error(
			'stonewright_finalizer_busy',
			__( 'The block finalizer queue is busy. Retry shortly.', 'stonewright' ),
			[
				'status'              => 409,
				'retryable'           => true,
				'retry_after'         => $retry_after,
				'retry_after_seconds' => $retry_after,
			]
		);
	}

	private static function release_lock( string $token ): void {
		if ( '' === $token ) {
			return;
		}
		$current = get_option( self::LOCK_OPTION, [] );
		if ( ! is_array( $current ) || ! hash_equals( (string) ( $current['token'] ?? '' ), $token ) ) {
			return;
		}
		self::delete_lock_if_unchanged( $current );
	}

	/** @param array<string, mixed> $observed */
	private static function delete_lock_if_unchanged( array $observed ): bool {
		global $wpdb;
		// The option name and its exact serialized lease form a compare-and-delete
		// guard, so an expired observer cannot remove a newer owner's live lock.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$deleted = $wpdb->delete(
			$wpdb->options,
			[
				'option_name'  => self::LOCK_OPTION,
				'option_value' => maybe_serialize( $observed ),
			],
			[ '%s', '%s' ]
		);
		wp_cache_delete( self::LOCK_OPTION, 'options' );

		return 1 === $deleted;
	}

	/**
	 * @return array{session_id:string,post_id:int,owner_user_id:int,legacy:bool}|null
	 */
	private static function session_scope( string $session_id ): ?array {
		if ( '' === $session_id ) {
			return null;
		}
		foreach ( self::state()['changes'] as $record ) {
			if ( ! is_array( $record ) || (string) ( $record['session_id'] ?? '' ) !== $session_id ) {
				continue;
			}
			return [
				'session_id'    => $session_id,
				'post_id'       => (int) ( $record['post_id'] ?? 0 ),
				'owner_user_id' => (int) ( $record['owner_user_id'] ?? 0 ),
				'legacy'        => ! empty( $record['legacy'] ),
			];
		}
		return null;
	}

	/**
	 * @return array{session_id:string,post_id:int,owner_user_id:int,legacy:bool}|null
	 */
	private static function first_owned_session(): ?array {
		foreach ( self::owned_sessions() as $session ) {
			if ( (int) $session['queued_count'] <= 0 ) {
				continue;
			}
			return [
				'session_id'    => $session['session_id'],
				'post_id'       => $session['post_id'],
				'owner_user_id' => $session['owner_user_id'],
				'legacy'        => false,
			];
		}
		return null;
	}

	/**
	 * @param array<string, mixed>|null $scope
	 * @return array{session_id:string,owner_user_id:int,post_id:int}|null
	 */
	private static function normalize_scope( ?array $scope ): ?array {
		if ( null === $scope ) {
			return null;
		}
		$session = (string) ( $scope['session_id'] ?? $scope['s'] ?? '' );
		$owner   = (int) ( $scope['owner_user_id'] ?? $scope['u'] ?? 0 );
		$post_id = (int) ( $scope['post_id'] ?? $scope['p'] ?? 0 );
		if ( '' === $session || $owner <= 0 || $post_id <= 0 ) {
			return null;
		}
		return [
			'session_id'    => $session,
			'owner_user_id' => $owner,
			'post_id'       => $post_id,
		];
	}

	/**
	 * @param array<string, mixed>                        $record
	 * @param array{session_id:string,owner_user_id:int,post_id:int} $scope
	 */
	private static function record_matches_scope( mixed $record, array $scope ): bool {
		if ( ! is_array( $record ) || ! empty( $record['legacy'] ) ) {
			return false;
		}
		return (string) ( $record['session_id'] ?? '' ) === $scope['session_id']
			&& (int) ( $record['owner_user_id'] ?? 0 ) === $scope['owner_user_id']
			&& (int) ( $record['post_id'] ?? 0 ) === $scope['post_id'];
	}

	/**
	 * @param array<string, mixed> $record
	 * @param array<string, mixed>|null $scope
	 */
	private static function assert_write_scope( array $record, ?array $scope ): ?\WP_Error {
		$owner = (int) ( $record['owner_user_id'] ?? 0 );
		$actor = (int) get_current_user_id();
		if ( $owner <= 0 || ! empty( $record['legacy'] ) || $actor !== $owner ) {
			return self::forbidden_error();
		}
		$normalized = self::normalize_scope( $scope );
		if ( null === $scope ) {
			return null;
		}
		if ( null === $normalized || ! self::record_matches_scope( $record, $normalized ) ) {
			return self::forbidden_error();
		}
		return null;
	}

	private static function uuid(): string {
		return function_exists( 'wp_generate_uuid4' ) ? wp_generate_uuid4() : substr( hash( 'sha256', uniqid( 'finalizer-', true ) ), 0, 36 );
	}

	private static function forbidden_error(): \WP_Error {
		return new \WP_Error(
			'stonewright_finalizer_forbidden',
			__( 'This block finalizer change is outside the current session.', 'stonewright' ),
			[ 'status' => 403 ]
		);
	}

	private static function unauthenticated_error(): \WP_Error {
		return new \WP_Error(
			'stonewright_finalizer_unauthenticated',
			__( 'A logged-in user is required to queue block finalizer changes.', 'stonewright' ),
			[ 'status' => 403 ]
		);
	}

	private static function not_found_error(): \WP_Error {
		return new \WP_Error(
			'stonewright_finalizer_not_found',
			__( 'Finalizer change not found.', 'stonewright' ),
			[ 'status' => 404 ]
		);
	}

	private static function invalid_change_ids_error(): \WP_Error {
		return new \WP_Error(
			'stonewright_invalid_change_ids',
			__( 'change_ids must be 1 to 20 non-empty strings.', 'stonewright' ),
			[ 'status' => 400 ]
		);
	}

	/**
	 * @param list<string> $change_ids
	 * @param list<string> $previous_statuses
	 * @param list<int>    $post_ids
	 * @return array<string, mixed>
	 */
	private static function cancel_receipt(
		bool $dry_run,
		int $removed_count,
		array $change_ids,
		array $previous_statuses,
		array $post_ids,
		string $verification_status,
		bool $effect_verified
	): array {
		return [
			'ok'                  => true,
			'dry_run'             => $dry_run,
			'removed_count'       => $removed_count,
			'change_ids'          => array_values( $change_ids ),
			'previous_statuses'   => array_values( $previous_statuses ),
			'post_ids'            => array_values( $post_ids ),
			'verification_status' => $verification_status,
			'effect_verified'     => $effect_verified,
		];
	}

	private static function terminal_error(): \WP_Error {
		return new \WP_Error(
			'stonewright_finalizer_terminal',
			__( 'This finalizer change is no longer open for serialization.', 'stonewright' ),
			[ 'status' => 409 ]
		);
	}

	/**
	 * @param array<string, mixed> $changes
	 * @return array{0: array<string, mixed>, 1: int}
	 */
	private static function prune_changes( array $changes, int $now ): array {
		$kept             = [];
		$pruned_count     = 0;
		$persisted_before = $now - DAY_IN_SECONDS;
		$failed_before    = $now - ( 7 * DAY_IN_SECONDS );
		foreach ( $changes as $id => $record ) {
			if ( ! is_array( $record ) ) {
				continue;
			}
			$status = (string) ( $record['status'] ?? '' );
			$when   = (int) ( $record['updated_at'] ?? $record['created_at'] ?? 0 );
			$drop   = false;
			if ( in_array( $status, [ 'persisted', 'cancelled' ], true ) && $when > 0 && $when < $persisted_before ) {
				$drop = true;
			} elseif ( 'failed' === $status && $when > 0 && $when < $failed_before ) {
				$drop = true;
			}
			if ( $drop ) {
				++$pruned_count;
				continue;
			}
			$kept[ (string) $id ] = $record;
		}

		return [ $kept, $pruned_count ];
	}

	private static function spec_bytes( mixed $spec ): int {
		if ( is_string( $spec ) ) {
			return strlen( $spec );
		}
		$encoded = wp_json_encode( $spec );
		return is_string( $encoded ) ? strlen( $encoded ) : PHP_INT_MAX;
	}

	private static function spec_size_error( mixed $spec ): ?\WP_Error {
		$bytes = self::spec_bytes( $spec );
		if ( $bytes <= self::MAX_SPEC_BYTES ) {
			return null;
		}
		return self::size_limit_error(
			'stonewright_finalizer_spec_too_large',
			__( 'The block spec exceeds the size limit.', 'stonewright' ),
			self::MAX_SPEC_BYTES,
			PHP_INT_MAX === $bytes ? 0 : $bytes
		);
	}

	private static function count_limit_error( string $code, string $message, int $limit, int $count ): \WP_Error {
		return new \WP_Error(
			$code,
			$message,
			[
				'status'    => 429,
				'limit'     => $limit,
				'count'     => $count,
				'retryable' => true,
			]
		);
	}

	private static function size_limit_error( string $code, string $message, int $max_bytes, int $bytes ): \WP_Error {
		return new \WP_Error(
			$code,
			$message,
			[
				'status'    => 413,
				'bytes'     => $bytes,
				'max_bytes' => $max_bytes,
			]
		);
	}

	private static function audit_prune( int $post_id, int $pruned_count ): void {
		AuditLog::record(
			'stonewright/blocks-queue-change',
			[
				'pruned_count' => $pruned_count,
				'post_id'      => $post_id,
			],
			'ok'
		);
	}
}
