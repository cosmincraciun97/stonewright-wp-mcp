<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\Abilities\Gutenberg;

use Stonewright\WpMcp\Abilities\AbilityKernel;
use Stonewright\WpMcp\Abilities\Common\ConfirmationGuard;
use Stonewright\WpMcp\Gutenberg\Finalizer\BlockQueue;
use Stonewright\WpMcp\Gutenberg\Finalizer\BlockSource;
use Stonewright\WpMcp\Security\Backup;
use Stonewright\WpMcp\Security\Permissions;

/**
 * Persists hashed, browser-serialized HTML from the finalizer queue.
 *
 * @stonewright-status stable
 */
final class FinalizeBatch extends AbilityKernel {
	use ConfirmationGuard;

	public function name(): string {
		return 'stonewright/blocks-finalize-batch';
	}

	public function label(): string {
		return __( 'Finalize Gutenberg block batch', 'stonewright' );
	}

	public function description(): string {
		return __( 'Persists hashed serialized HTML produced by the browser block finalizer after snapshot, confirmation, and readback.', 'stonewright' );
	}

	public function category(): string {
		return 'gutenberg';
	}

	public function input_schema(): array {
		return [
			'type'                 => 'object',
			'additionalProperties' => false,
			'properties'           => [
				'change_ids'           => [
					'type'     => 'array',
					'items'    => [ 'type' => 'string' ],
					'maxItems' => 20,
				],
				'post_id'              => [ 'type' => 'integer', 'minimum' => 1 ],
				'html'                 => [ 'type' => 'string' ],
				'dry_run'              => [ 'type' => 'boolean', 'default' => false ],
				'confirmation_token'   => [ 'type' => 'string' ],
			],
		];
	}

	public function output_schema(): array {
		return [
			'type'       => 'object',
			'properties' => [
				'ok'                  => [ 'type' => 'boolean' ],
				'dry_run'             => [ 'type' => 'boolean' ],
				'post_id'             => [ 'type' => 'integer' ],
				'applied'             => [ 'type' => 'integer' ],
				'snapshot_id'         => [ 'type' => 'string' ],
				'change_ids'          => [ 'type' => 'array' ],
				'readback_hash'       => [ 'type' => 'string' ],
				'verification_status' => [ 'type' => 'string' ],
			],
		];
	}

	public function permission_callback( array $args ): bool|\WP_Error {
		$actor = (int) get_current_user_id();
		$ids   = isset( $args['change_ids'] ) && is_array( $args['change_ids'] ) ? $args['change_ids'] : [];
		foreach ( $ids as $id ) {
			$record = BlockQueue::get( (string) $id );
			if ( ! is_array( $record ) ) {
				continue;
			}
			if ( (int) ( $record['owner_user_id'] ?? 0 ) !== $actor ) {
				return false;
			}
			if ( ! Permissions::edit_post( (int) ( $record['post_id'] ?? 0 ) ) ) {
				return false;
			}
		}
		$post_id = (int) ( $args['post_id'] ?? 0 );
		if ( $post_id > 0 ) {
			return Permissions::edit_post( $post_id );
		}
		return Permissions::edit_posts();
	}

	public function execute( array $args ): array|\WP_Error {
		return $this->audit(
			$args,
			function ( array $args ) {
				if ( isset( $args['html'] ) && '' !== (string) $args['html'] && empty( $args['change_ids'] ) ) {
					return $this->error(
						'finalizer_queue_required',
						__( 'Finalize only persists hashed serialized HTML from a queue record. Do not pass raw HTML.', 'stonewright' ),
						[ 'status' => 400 ]
					);
				}

				$dry_run = ! empty( $args['dry_run'] );
				$ids     = isset( $args['change_ids'] ) && is_array( $args['change_ids'] ) ? array_values( $args['change_ids'] ) : [];
				if ( $dry_run ) {
					return [
						'ok'                  => true,
						'dry_run'             => true,
						'post_id'             => (int) ( $args['post_id'] ?? 0 ),
						'applied'             => 0,
						'snapshot_id'         => '',
						'change_ids'          => $ids,
						'readback_hash'       => '',
						'verification_status' => 'planned',
					];
				}

				if ( [] === $ids ) {
					return $this->error(
						'finalizer_queue_required',
						__( 'change_ids from the block finalizer queue are required to persist.', 'stonewright' ),
						[ 'status' => 400 ]
					);
				}

				$token_error = $this->confirmation_token_error( $args, $args );
				if ( null !== $token_error ) {
					return $token_error;
				}

				$records = [];
				$actor   = (int) get_current_user_id();
				foreach ( $ids as $id ) {
					$record = BlockQueue::get( (string) $id );
					if ( null === $record ) {
						return $this->error( 'finalizer_not_found', __( 'Finalizer change not found.', 'stonewright' ), [ 'status' => 404, 'change_id' => (string) $id ] );
					}
					if ( (int) ( $record['owner_user_id'] ?? 0 ) !== $actor || ! empty( $record['legacy'] ) ) {
						return $this->error(
							'finalizer_forbidden',
							__( 'This block finalizer change is outside the current session.', 'stonewright' ),
							[ 'status' => 403 ]
						);
					}
					if ( 'serialized' !== (string) $record['status'] ) {
						return $this->error(
							'finalizer_not_serialized',
							__( 'The queue record has not been serialized by the browser finalizer.', 'stonewright' ),
							[ 'status' => 409, 'change_id' => (string) $id, 'queue_status' => (string) $record['status'] ]
						);
					}
					$html = (string) ( $record['serialized_html'] ?? '' );
					$hash = (string) ( $record['serialized_html_hash'] ?? '' );
					if ( '' === $html || ! hash_equals( hash( 'sha256', $html ), $hash ) ) {
						return $this->error(
							'finalizer_hash_mismatch',
							__( 'Queued serialized HTML failed its integrity hash.', 'stonewright' ),
							[ 'status' => 400, 'change_id' => (string) $id ]
						);
					}
					$records[] = $record;
				}

				$post_id = (int) $records[0]['post_id'];
				foreach ( $records as $record ) {
					if ( (int) $record['post_id'] !== $post_id ) {
						return $this->error(
							'finalizer_mixed_targets',
							__( 'A finalize batch must target a single post.', 'stonewright' ),
							[ 'status' => 400 ]
						);
					}
				}

				$post = get_post( $post_id );
				if ( ! $post ) {
					return $this->error( 'not_found', __( 'Post not found.', 'stonewright' ), [ 'status' => 404 ] );
				}

				$current_hash = hash( 'sha256', (string) $post->post_content );
				foreach ( $records as $record ) {
					$expected = (string) ( $record['expected_content_hash'] ?? '' );
					if ( '' !== $expected && ! hash_equals( $expected, $current_hash ) ) {
						return $this->error(
							'content_conflict',
							__( 'The post content changed after queueing; serialize it again.', 'stonewright' ),
							[
								'status'                => 409,
								'expected_content_hash' => $expected,
								'current_content_hash'  => $current_hash,
								'retryable'             => true,
							]
						);
					}
				}

				$snapshot_id = Backup::snapshot_post( $post_id );
				if ( '' === $snapshot_id ) {
					return $this->error( 'snapshot_failed', __( 'The Gutenberg post could not be snapshotted; no content was written.', 'stonewright' ), [ 'status' => 500 ] );
				}

				$content = (string) $post->post_content;
				foreach ( $records as $record ) {
					$html    = (string) $record['serialized_html'];
					$content = BlockSource::apply( $content, $record, $html );
					if ( $content instanceof \WP_Error ) {
						$data = (array) $content->get_error_data();
						$code = preg_replace( '/^stonewright_/', '', (string) $content->get_error_code() );
						return $this->error(
							is_string( $code ) && '' !== $code ? $code : 'invalid_path',
							$content->get_error_message(),
							$data
						);
					}
				}

				$written = wp_update_post(
					[
						'ID'           => $post_id,
						'post_content' => $content,
					],
					true
				);
				if ( is_wp_error( $written ) ) {
					return $written;
				}

				$fresh    = get_post( $post_id );
				$readback = $fresh ? hash( 'sha256', (string) $fresh->post_content ) : '';
				$expect   = hash( 'sha256', $content );
				if ( ! hash_equals( $expect, $readback ) ) {
					Backup::restore( $post_id, $snapshot_id );
					return $this->error(
						'readback_mismatch',
						__( 'Gutenberg readback did not match the finalized content.', 'stonewright' ),
						[ 'status' => 500, 'expected_hash' => $expect, 'readback_hash' => $readback ]
					);
				}

				foreach ( $records as $record ) {
					$persisted = BlockQueue::mark_persisted( (string) $record['id'] );
					if ( $persisted instanceof \WP_Error ) {
						return $persisted;
					}
				}

				return [
					'ok'                  => true,
					'dry_run'             => false,
					'post_id'             => $post_id,
					'applied'             => count( $records ),
					'snapshot_id'         => $snapshot_id,
					'change_ids'          => $ids,
					'readback_hash'       => $readback,
					'verification_status' => 'verified',
				];
			}
		);
	}
}
