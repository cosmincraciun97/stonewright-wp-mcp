<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\Abilities\ElementorV3;

use Stonewright\WpMcp\Abilities\AbilityKernel;
use Stonewright\WpMcp\Security\Permissions;
use Stonewright\WpMcp\Support\ElementorData;
use Stonewright\WpMcp\Support\TreeSummary;

/**
 * Contract decision: keep output_schema aligned to the handler response shape.
 *
 * @stonewright-status stable
 */
final class GetPageStructure extends AbilityKernel {

	public function name(): string {
		return 'stonewright/elementor-v3-get-page-structure';
	}

	public function label(): string {
		return __( 'Get Elementor page structure', 'stonewright' );
	}

	public function description(): string {
		return __( 'Returns a compact Elementor V3 page outline by default, or the full element tree when responseMode=full.', 'stonewright' );
	}

	public function category(): string {
		return 'elementor';
	}

	public function input_schema(): array {
		return [
			'type'                 => 'object',
			'additionalProperties' => false,
			'properties'           => [
				'post_id'      => [ 'type' => 'integer', 'minimum' => 1 ],
				'responseMode' => [
					'type'        => 'string',
					'enum'        => [ 'summary', 'full' ],
					'default'     => 'summary',
					'description' => 'Use summary for compact element IDs, paths, widget types, and settings keys; use full only when raw Elementor JSON is required.',
				],
				'maxElements'  => [
					'type'        => 'integer',
					'minimum'     => 1,
					'maximum'     => 500,
					'default'     => 200,
					'description' => 'Maximum outline rows returned in summary mode.',
				],
				'knownHash'    => [
					'type'        => 'string',
					'description' => 'Optional. The hash returned by a previous read of this page. When it still matches, the response is only { post_id, active, hash, unchanged: true } and no outline or tree is built.',
				],
			],
			'required'             => [ 'post_id' ],
		];
	}

	public function output_schema(): array {
		return [ 'type' => 'object' ];
	}

	public function permission_callback( array $args ): bool|\WP_Error {
		$id = (int) ( $args['post_id'] ?? 0 );
		return Permissions::edit_post( $id );
	}

	public function execute( array $args ): array|\WP_Error {
		$post_id = (int) $args['post_id'];
		if ( ! get_post( $post_id ) ) {
			return $this->error( 'not_found', __( 'Post not found.', 'stonewright' ) );
		}

		$tree = ElementorData::read( $post_id );
		$hash = self::tree_hash( $tree );

		$known_hash = isset( $args['knownHash'] ) && is_string( $args['knownHash'] ) ? trim( $args['knownHash'] ) : '';
		if ( '' !== $known_hash && hash_equals( $hash, $known_hash ) ) {
			// The caller already has this document. Answering here skips both the
			// flatten and the outline build, which is the whole cost of the read.
			return [
				'post_id'   => $post_id,
				'active'    => ElementorData::is_active( $post_id ),
				'hash'      => $hash,
				'unchanged' => true,
			];
		}

		$count = count( ElementorData::flatten( $tree ) );
		if ( 'full' === (string) ( $args['responseMode'] ?? 'summary' ) ) {
			return [
				'post_id'       => $post_id,
				'active'        => ElementorData::is_active( $post_id ),
				'response_mode' => 'full',
				'hash'          => $hash,
				'unchanged'     => false,
				'tree'          => $tree,
				'count'         => $count,
			];
		}

		$max_elements = min( 500, max( 1, (int) ( $args['maxElements'] ?? 200 ) ) );
		$summary      = TreeSummary::outline(
			$tree,
			$max_elements,
			static fn( array $element, array $ctx ): array => TreeSummary::default_row( $element, $ctx )
		);

		return [
			'post_id'        => $post_id,
			'active'         => ElementorData::is_active( $post_id ),
			'response_mode'  => 'summary',
			'hash'           => $hash,
			'unchanged'      => false,
			'count'          => $summary['count'],
			'returned_count' => $summary['returned_count'],
			'truncated'      => $summary['truncated'],
			'tree_omitted'   => true,
			'outline'        => $summary['outline'],
			'full_mode_hint' => 'Call with responseMode=full only when raw Elementor JSON is required for the next edit.',
		];
	}

	/**
	 * Fingerprint of the decoded Elementor tree.
	 *
	 * Taken from the decoded tree rather than the raw `_elementor_data` string so
	 * a re-save that only reorders JSON keys or changes escaping does not read as
	 * a content change. It describes the document, not the response, so summary
	 * and full mode report the same value and a caller can compare across modes.
	 *
	 * @param array<int, mixed> $tree
	 */
	private static function tree_hash( array $tree ): string {
		return hash(
			'sha256',
			(string) wp_json_encode(
				self::canonicalize_tree( $tree ),
				JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
			)
		);
	}

	/**
	 * Sort object keys recursively while preserving list order.
	 *
	 * Elementor element order is semantic, so lists must stay untouched. Object
	 * key order is serialization noise and must not invalidate a known hash.
	 */
	private static function canonicalize_tree( mixed $value ): mixed {
		if ( ! is_array( $value ) ) {
			return $value;
		}

		if ( ! array_is_list( $value ) ) {
			ksort( $value );
		}

		foreach ( $value as $key => $item ) {
			$value[ $key ] = self::canonicalize_tree( $item );
		}

		return $value;
	}
}
