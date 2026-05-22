<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\Abilities\QA;

use Stonewright\WpMcp\Abilities\AbilityKernel;
use Stonewright\WpMcp\Companion\CompanionContract;
use Stonewright\WpMcp\Security\Permissions;
use Stonewright\WpMcp\Support\CompanionClient;

/**
 * Compare a design spec's structural tree against the rendered DOM layout.
 * Contract decision: keep output_schema aligned to the handler response shape.
 *
 * @stonewright-status stable
 */
final class DiffLayout extends AbilityKernel {

	public function name(): string {
		return 'stonewright/qa-diff-layout';
	}

	public function label(): string {
		return __( 'QA: Diff Layout', 'stonewright' );
	}

	public function description(): string {
		return __( 'Compares the structural shape of the rendered DOM to the design spec sections/blocks tree.', 'stonewright' );
	}

	public function category(): string {
		return 'qa';
	}

	public function input_schema(): array {
		return [
			'type'                 => 'object',
			'additionalProperties' => false,
			'properties'           => [
				'spec'    => [ 'type' => 'object' ],
				'post_id' => [ 'type' => 'integer', 'minimum' => 1 ],
			],
			'required'             => [ 'spec', 'post_id' ],
		];
	}

	public function output_schema(): array {
		return [
			'type'       => 'object',
			'properties' => [
				'ok'               => [ 'type' => 'boolean' ],
				'score'            => [ 'type' => 'number' ],
				'missing_sections' => [ 'type' => 'array', 'items' => [ 'type' => 'string' ] ],
				'extra_blocks'     => [ 'type' => 'array', 'items' => [ 'type' => 'string' ] ],
				'alignment_diffs'  => [ 'type' => 'array' ],
			],
			'required'   => [ 'ok', 'score', 'missing_sections', 'extra_blocks', 'alignment_diffs' ],
		];
	}

	public function permission_callback( array $args ): bool|\WP_Error {
		return Permissions::edit_posts();
	}

	public function execute( array $args ): array|\WP_Error {
		return $this->audit(
			$args,
			function ( array $args ) {
				$post_url = get_permalink( (int) $args['post_id'] );
				if ( ! $post_url ) {
					return $this->error( 'invalid_post', __( 'Could not resolve URL for post_id.', 'stonewright' ) );
				}

				$request_id = wp_generate_uuid4();

				$layout_request = [
					'request_id' => $request_id,
					'url'        => $post_url,
				];

				// Validate layout request before sending
				$req_check = CompanionContract::validate( 'layout', 'request', $layout_request );
				if ( is_wp_error( $req_check ) ) {
					return $req_check;
				}

				$layout_result = CompanionClient::post( '/layout', $layout_request );
				if ( is_wp_error( $layout_result ) ) {
					return $layout_result;
				}

				// Validate layout response after parsing
				$resp_check = CompanionContract::validate( 'layout', 'response', $layout_result );
				if ( is_wp_error( $resp_check ) ) {
					return $resp_check;
				}

				$spec           = (array) $args['spec'];
				$spec_sections  = $spec['sections'] ?? [];
				$dom_sections   = $layout_result['sections'] ?? [];
				$spec_names     = array_column( $spec_sections, 'name' );
				$dom_names      = array_column( $dom_sections, 'name' );
				$missing        = array_values( array_diff( $spec_names, $dom_names ) );
				$extra          = array_values( array_diff( $dom_names, $spec_names ) );
				$matched        = count( $spec_names ) - count( $missing );
				$total          = max( count( $spec_names ), 1 );
				$score          = round( $matched / $total, 4 );
				$alignment_diffs = $layout_result['alignment_diffs'] ?? [];

				return $this->ok( [
					'score'            => $score,
					'missing_sections' => $missing,
					'extra_blocks'     => $extra,
					'alignment_diffs'  => $alignment_diffs,
				] );
			}
		);
	}
}
