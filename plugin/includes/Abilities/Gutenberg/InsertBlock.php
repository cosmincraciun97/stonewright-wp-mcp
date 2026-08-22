<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\Abilities\Gutenberg;

use Stonewright\WpMcp\Abilities\AbilityKernel;
use Stonewright\WpMcp\Gutenberg\AttributeValidator;
use Stonewright\WpMcp\Gutenberg\Finalizer\BlockQueue;
use Stonewright\WpMcp\Gutenberg\Finalizer\FinalizerPage;
use Stonewright\WpMcp\Gutenberg\RawHtmlGate;
use Stonewright\WpMcp\Security\Backup;
use Stonewright\WpMcp\Security\Permissions;
use Stonewright\WpMcp\Support\BlockSerializer;
use Stonewright\WpMcp\Support\BlockTree;

/**
 * Contract decision: keep output_schema aligned to the handler response shape.
 *
 * @stonewright-status stable
 */
final class InsertBlock extends AbilityKernel {

	public function name(): string {
		return 'stonewright/blocks-insert';
	}

	public function label(): string {
		return __( 'Insert block', 'stonewright' );
	}

	public function description(): string {
		return __( 'Inserts a dynamic block immediately, or queues a static/third-party spec for the browser block finalizer.', 'stonewright' );
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
				'post_id'  => [ 'type' => 'integer', 'minimum' => 1 ],
				'block'    => [
					'type'       => 'object',
					'properties' => [
						'name'        => [ 'type' => 'string' ],
						'attrs'       => [ 'type' => 'object' ],
						'attributes'  => [ 'type' => 'object' ],
						'innerHTML'   => [ 'type' => 'string' ],
						'innerBlocks' => [ 'type' => 'array' ],
					],
					'required'   => [ 'name' ],
				],
				'path'     => [ 'type' => 'array', 'items' => [ 'type' => 'integer' ] ],
				'position'       => [ 'type' => 'integer' ],
				'allow_raw_html' => [ 'type' => 'boolean', 'default' => false ],
				'custom_code_grant' => [
					'type'        => 'string',
					'description' => 'Required with allow_raw_html when the block payload contains raw CSS.',
				],
			],
			'required'             => [ 'post_id', 'block' ],
		];
	}

	public function output_schema(): array {
		return [
			'type'       => 'object',
			'properties' => [
				'post_id'       => [ 'type' => 'integer' ],
				'snapshot_id'   => [ 'type' => 'string' ],
				'path'          => [ 'type' => 'array' ],
				'queued'        => [ 'type' => 'boolean' ],
				'change_id'     => [ 'type' => 'string' ],
				'status'        => [ 'type' => 'string' ],
				'finalizer_url' => [ 'type' => 'string' ],
			],
		];
	}

	public function permission_callback( array $args ): bool|\WP_Error {
		$id = (int) ( $args['post_id'] ?? 0 );
		return Permissions::edit_post( $id );
	}

	public function execute( array $args ): array|\WP_Error {
		return $this->audit_write(
			$args,
			function ( array $args ) {
				$post_id     = (int) $args['post_id'];
				$post        = get_post( $post_id );
				if ( ! $post ) {
					return $this->error( 'not_found', __( 'Post not found.', 'stonewright' ) );
				}

				$input    = (array) ( $args['block'] ?? [] );
				$name     = $this->input_name( $input );
				$attrs    = $this->input_attrs( $input );
				$inner    = isset( $input['innerBlocks'] ) && is_array( $input['innerBlocks'] ) ? $input['innerBlocks'] : [];
				$valid    = AttributeValidator::validate_tree( $name, $attrs, $inner );
				if ( $valid instanceof \WP_Error ) {
					return $valid;
				}

				$blocks   = parse_blocks( $post->post_content );
				$path     = isset( $args['path'] ) ? array_map( 'intval', (array) $args['path'] ) : [];
				$position = isset( $args['position'] ) ? (int) $args['position'] : count( $blocks );
				$spec     = [
					'name'        => $name,
					'attributes'  => $attrs,
					'innerBlocks' => $inner,
				];
				if ( isset( $input['innerHTML'] ) && is_string( $input['innerHTML'] ) ) {
					$spec['innerHTML'] = $input['innerHTML'];
				}
				$allow_raw = ! empty( $args['allow_raw_html'] );
				$grant     = (string) ( $args['custom_code_grant'] ?? '' );

				if ( BlockQueue::tree_requires_finalizer( $spec ) ) {
					$queued = BlockQueue::enqueue(
						[
							'post_id'               => $post_id,
							'expected_content_hash' => hash( 'sha256', (string) $post->post_content ),
							'allow_raw_html'        => $allow_raw,
							'custom_code_grant'     => $grant,
							'action'                => 'insert',
							'path'                  => $path,
							'position'              => $position,
							'block_spec'            => $spec,
						]
					);
					if ( $queued instanceof \WP_Error ) {
						return $queued;
					}
					return [
						'post_id'       => $post_id,
						'snapshot_id'   => '',
						'path'          => array_merge( $path, [ $position ] ),
						'queued'        => true,
						'change_id'     => (string) $queued['id'],
						'status'        => (string) $queued['status'],
						'finalizer_url' => FinalizerPage::url( '', (string) ( $queued['session_id'] ?? '' ) ),
					];
				}

				$gated = RawHtmlGate::assert_spec( $spec, $allow_raw, $grant, $post_id );
				if ( $gated instanceof \WP_Error ) {
					return $gated;
				}

				$snapshot_id = Backup::snapshot_post( $post_id );
				$new_block   = $this->normalize_input_block( $input );

				$mutated = BlockTree::insert( $blocks, $path, $position, $new_block );
				$html    = BlockSerializer::serialize( $mutated );

				$result = wp_update_post(
					[
						'ID'           => $post_id,
						'post_content' => $html,
					],
					true
				);
				if ( is_wp_error( $result ) ) {
					return $result;
				}

				return [
					'post_id'     => $post_id,
					'snapshot_id' => $snapshot_id,
					'path'        => array_merge( $path, [ $position ] ),
				];
			}
		);
	}

	private function normalize_input_block( array $block ): array {
		$attrs       = $this->input_attrs( $block );
		$inner       = array_values(
			array_map(
				[ $this, 'normalize_input_block' ],
				array_values( array_filter( (array) ( $block['innerBlocks'] ?? [] ), 'is_array' ) )
			)
		);
		$inner_html  = (string) ( $block['innerHTML'] ?? '' );
		$inner_content = $block['innerContent'] ?? null;
		if ( ! is_array( $inner_content ) || ( [] !== $inner && ! in_array( null, $inner_content, true ) ) ) {
			$inner_content = '' !== $inner_html ? [ $inner_html ] : [];
			$inner_content = array_merge( $inner_content, array_fill( 0, count( $inner ), null ) );
		}

		return [
			'blockName'    => $this->input_name( $block ),
			'attrs'        => $attrs,
			'innerHTML'    => $inner_html,
			'innerContent' => $inner_content,
			'innerBlocks'  => $inner,
		];
	}

	/** @param array<string, mixed> $block */
	private function input_name( array $block ): string {
		return sanitize_text_field( (string) ( $block['name'] ?? $block['blockName'] ?? '' ) );
	}

	/**
	 * @param array<string, mixed> $block
	 * @return array<string, mixed>
	 */
	private function input_attrs( array $block ): array {
		foreach ( [ 'attributes', 'attrs' ] as $key ) {
			if ( ! isset( $block[ $key ] ) ) {
				continue;
			}
			if ( is_array( $block[ $key ] ) ) {
				return $block[ $key ];
			}
			if ( is_object( $block[ $key ] ) ) {
				return (array) $block[ $key ];
			}
		}
		return [];
	}

	/** @return array<int, string> */
	protected function audit_redacted_keys(): array {
		return array_merge( parent::audit_redacted_keys(), [ 'custom_code_grant' ] );
	}
}
