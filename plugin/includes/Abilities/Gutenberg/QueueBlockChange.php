<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\Abilities\Gutenberg;

use Stonewright\WpMcp\Abilities\AbilityKernel;
use Stonewright\WpMcp\Gutenberg\AttributeValidator;
use Stonewright\WpMcp\Gutenberg\Finalizer\BlockQueue;
use Stonewright\WpMcp\Gutenberg\Finalizer\FinalizerPage;
use Stonewright\WpMcp\Security\Permissions;

/**
 * Queues a normalized Gutenberg block spec for browser-side serialization.
 *
 * @stonewright-status stable
 */
final class QueueBlockChange extends AbilityKernel {

	public function name(): string {
		return 'stonewright/blocks-queue-change';
	}

	public function label(): string {
		return __( 'Queue Gutenberg block change', 'stonewright' );
	}

	public function description(): string {
		return __( 'Queues a {name, attributes, innerBlocks} spec for the browser block finalizer. Does not persist post content.', 'stonewright' );
	}

	public function category(): string {
		return 'gutenberg';
	}

	public function input_schema(): array {
		return [
			'type'                 => 'object',
			'additionalProperties' => false,
			'properties'           => [
				'confirmation_token' => [ 'type' => 'string' ],
				'post_id'               => [ 'type' => 'integer', 'minimum' => 1 ],
				'expected_content_hash' => [ 'type' => 'string' ],
				'allow_raw_html'        => [ 'type' => 'boolean', 'default' => false ],
				'custom_code_grant'     => [
					'type'        => 'string',
					'description' => 'Required with allow_raw_html when a block payload contains raw CSS. Single-use human-issued grant.',
				],
				'action'                => [ 'type' => 'string', 'enum' => [ 'insert', 'update', 'replace' ] ],
				'path'                  => [ 'type' => 'array', 'items' => [ 'type' => 'integer' ] ],
				'position'              => [ 'type' => 'integer' ],
				'block_spec'            => [ 'type' => 'object' ],
			],
			'required'             => [ 'post_id', 'block_spec' ],
		];
	}

	public function output_schema(): array {
		return [
			'type'       => 'object',
			'properties' => [
				'ok'           => [ 'type' => 'boolean' ],
				'queued'       => [ 'type' => 'boolean' ],
				'change_id'    => [ 'type' => 'string' ],
				'status'       => [ 'type' => 'string' ],
				'post_id'      => [ 'type' => 'integer' ],
				'block_name'    => [ 'type' => 'string' ],
				'finalizer_url' => [ 'type' => 'string' ],
				'warnings'      => [
					'type'  => 'array',
					'items' => [ 'type' => 'object' ],
				],
			],
		];
	}

	public function permission_callback( array $args ): bool|\WP_Error {
		return Permissions::edit_post( (int) ( $args['post_id'] ?? 0 ) );
	}

	public function execute( array $args ): array|\WP_Error {
		return $this->audit_write(
			$args,
			function ( array $args ) {
				$spec = is_array( $args['block_spec'] ?? null ) ? $args['block_spec'] : [];
				$name = (string) ( $spec['name'] ?? $spec['blockName'] ?? '' );
				$attrs = [];
				if ( isset( $spec['attributes'] ) && is_array( $spec['attributes'] ) ) {
					$attrs = $spec['attributes'];
				} elseif ( isset( $spec['attrs'] ) && is_array( $spec['attrs'] ) ) {
					$attrs = $spec['attrs'];
				}
				$inner = isset( $spec['innerBlocks'] ) && is_array( $spec['innerBlocks'] ) ? $spec['innerBlocks'] : [];
				$valid = AttributeValidator::validate_tree( $name, $attrs, $inner, 'finalizer' );
				if ( $valid instanceof \WP_Error ) {
					return $valid;
				}
				$warnings = is_array( $valid ) ? (array) ( $valid['warnings'] ?? [] ) : [];

				$queued = BlockQueue::enqueue( $args );
				if ( $queued instanceof \WP_Error ) {
					return $queued;
				}

				return [
					'ok'            => true,
					'queued'        => true,
					'change_id'     => (string) $queued['id'],
					'status'        => (string) $queued['status'],
					'post_id'       => (int) $queued['post_id'],
					'block_name'    => (string) $queued['block_name'],
					'finalizer_url' => FinalizerPage::url( '', (string) ( $queued['session_id'] ?? '' ) ),
					'warnings'      => $warnings,
				];
			}
		);
	}

	/** @return array<int, string> */
	protected function audit_redacted_keys(): array {
		return array_merge( parent::audit_redacted_keys(), [ 'custom_code_grant' ] );
	}
}
