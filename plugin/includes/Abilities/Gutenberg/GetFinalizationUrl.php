<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\Abilities\Gutenberg;

use Stonewright\WpMcp\Abilities\AbilityKernel;
use Stonewright\WpMcp\Gutenberg\Finalizer\FinalizerPage;
use Stonewright\WpMcp\Gutenberg\Finalizer\BlockQueue;
use Stonewright\WpMcp\Security\Permissions;

/**
 * Returns the hidden block-finalizer admin URL.
 *
 * @stonewright-status stable
 */
final class GetFinalizationUrl extends AbilityKernel {

	public function name(): string {
		return 'stonewright/blocks-finalizer-url';
	}

	public function label(): string {
		return __( 'Block finalizer URL', 'stonewright' );
	}

	public function description(): string {
		return __( 'Returns the hidden stonewright-block-finalizer admin URL for the current session.', 'stonewright' );
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
			],
		];
	}

	public function permission_callback( array $args ): bool|\WP_Error {
		return Permissions::edit_posts();
	}

	public function execute( array $args ): array|\WP_Error {
		return [
			'url'          => FinalizerPage::url(),
			'queued_count' => BlockQueue::pending_count(),
		];
	}

	protected function audit_redacted_keys(): array {
		return array_merge( parent::audit_redacted_keys(), [ 'url' ] );
	}
}
