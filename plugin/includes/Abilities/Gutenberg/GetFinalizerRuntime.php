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
		return __( 'Returns the hidden block-finalizer URL, queued-change count, and editor scripts to keep loaded.', 'stonewright' );
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
				'url'          => [ 'type' => 'string' ],
				'queued_count' => [ 'type' => 'integer' ],
				'keep_open'    => [ 'type' => 'boolean' ],
				'session_id'   => [ 'type' => 'string' ],
				'scripts'      => [ 'type' => 'array' ],
			],
		];
	}

	public function permission_callback( array $args ): bool|\WP_Error {
		return Permissions::edit_posts();
	}

	public function execute( array $args ): array|\WP_Error {
		$issued = BlockQueue::issue_token();
		return [
			'url'          => FinalizerPage::url( $issued['token'] ),
			'queued_count' => BlockQueue::pending_count(),
			'keep_open'    => true,
			'session_id'   => $issued['session_id'],
			'scripts'      => [ 'wp-blocks', 'wp-block-editor', 'wp-data', 'wp-api-fetch', 'wp-element' ],
		];
	}

	protected function audit_redacted_keys(): array {
		return array_merge( parent::audit_redacted_keys(), [ 'url' ] );
	}
}
