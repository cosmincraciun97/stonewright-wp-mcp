<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\Abilities\Gutenberg;

use Stonewright\WpMcp\Abilities\AbilityKernel;
use Stonewright\WpMcp\Gutenberg\Finalizer\BlockQueue;
use Stonewright\WpMcp\Gutenberg\Finalizer\FinalizerPage;
use Stonewright\WpMcp\Security\Permissions;

/**
 * Compact finalizer runtime status. Never includes full block_spec.
 *
 * @stonewright-status stable
 */
final class GetFinalizerRuntime extends AbilityKernel {

	public function name(): string {
		return 'stonewright/blocks-finalizer-runtime';
	}

	public function label(): string {
		return __( 'Block finalizer runtime', 'stonewright' );
	}

	public function description(): string {
		return __( 'Returns whether the Block Editor Queue tab is online, the queued-change count, the finalizer URL, and per-target editor frame URLs.', 'stonewright' );
	}

	public function category(): string {
		return 'gutenberg';
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
				'url'           => [ 'type' => 'string' ],
				'finalizer_url' => [ 'type' => 'string' ],
				'queued_count'  => [ 'type' => 'integer' ],
				'pending_count' => [ 'type' => 'integer' ],
				'failed_count'  => [ 'type' => 'integer' ],
				'keep_open'     => [ 'type' => 'boolean' ],
				'session_id'    => [ 'type' => 'string' ],
				'scripts'       => [ 'type' => 'array' ],
				'online'        => [ 'type' => 'boolean' ],
				'targets'       => [
					'type'  => 'array',
					'items' => [
						'type'       => 'object',
						'properties' => [
							'post_id'          => [ 'type' => 'integer' ],
							'change_id'        => [ 'type' => 'string' ],
							'status'           => [ 'type' => 'string' ],
							'pending_count'    => [ 'type' => 'integer' ],
							'failed_count'     => [ 'type' => 'integer' ],
							'editor_frame_url' => [ 'type' => 'string' ],
							'queue_url'        => [ 'type' => 'string' ],
						],
					],
				],
				'sessions'      => [
					'type'  => 'array',
					'items' => [
						'type'       => 'object',
						'properties' => [
							'session_id'   => [ 'type' => 'string' ],
							'post_id'      => [ 'type' => 'integer' ],
							'queued_count' => [ 'type' => 'integer' ],
							'failed_count' => [ 'type' => 'integer' ],
							'queue_url'    => [ 'type' => 'string' ],
						],
					],
				],
			],
		];
	}

	public function permission_callback( array $args ): bool|\WP_Error {
		return Permissions::edit_posts();
	}

	public function execute( array $args ): array|\WP_Error {
		$sessions = [];
		$open     = null;
		foreach ( BlockQueue::owned_sessions() as $session ) {
			if ( (int) $session['queued_count'] <= 0 ) {
				continue;
			}
			$issued = BlockQueue::issue_token( (string) $session['session_id'] );
			if ( $issued instanceof \WP_Error ) {
				continue;
			}
			$row = [
				'session_id'   => (string) $session['session_id'],
				'post_id'      => (int) $session['post_id'],
				'queued_count' => (int) $session['queued_count'],
				'failed_count' => (int) $session['failed_count'],
				'queue_url'    => FinalizerPage::url( $issued['token'] ),
			];
			$sessions[] = $row;
			if ( null === $open ) {
				$open = $row;
			}
		}
		$first = $open;
		$url   = is_array( $first ) ? (string) $first['queue_url'] : FinalizerPage::url( '' );
		$count = BlockQueue::pending_count();
		return [
			'url'           => $url,
			'finalizer_url' => $url,
			'queued_count'  => $count,
			'pending_count' => $count,
			'failed_count'  => BlockQueue::failed_count(),
			'keep_open'     => true,
			'session_id'    => is_array( $first ) ? (string) $first['session_id'] : '',
			'scripts'       => [ 'wp-blocks', 'wp-block-editor', 'wp-data', 'wp-api-fetch', 'wp-element', 'wp-block-library' ],
			'online'        => FinalizerPage::is_online(),
			'targets'       => FinalizerPage::pending_targets(),
			'sessions'      => $sessions,
		];
	}

	protected function audit_redacted_keys(): array {
		return array_merge( parent::audit_redacted_keys(), [ 'url', 'finalizer_url', 'queue_url' ] );
	}
}
