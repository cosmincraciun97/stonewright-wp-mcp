<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\Abilities\QA;

use Stonewright\WpMcp\Abilities\AbilityKernel;
use Stonewright\WpMcp\Security\Permissions;

/**
 * Composite QA report: runs requested checks in sequence and returns one structured document.
 * Contract decision: keep output_schema aligned to the handler response shape.
 *
 * @stonewright-status stable
 */
final class Report extends AbilityKernel {

	public function name(): string {
		return 'stonewright/qa-report';
	}

	public function label(): string {
		return __( 'QA: Full Report', 'stonewright' );
	}

	public function description(): string {
		return __( 'Runs the requested QA checks (screenshot, responsive, accessibility, lighthouse) and returns a unified report.', 'stonewright' );
	}

	public function category(): string {
		return 'qa';
	}

	public function input_schema(): array {
		return [
			'type'                 => 'object',
			'additionalProperties' => false,
			'properties'           => [
				'post_id'  => [ 'type' => 'integer', 'minimum' => 1 ],
				'sections' => [
					'type'    => 'array',
					'items'   => [
						'type' => 'string',
						'enum' => [ 'screenshot', 'responsive', 'accessibility', 'lighthouse' ],
					],
					'default' => [ 'screenshot', 'responsive', 'accessibility', 'lighthouse' ],
				],
			],
			'required'             => [ 'post_id' ],
		];
	}

	public function output_schema(): array {
		return [
			'type'       => 'object',
			'properties' => [
				'ok'           => [ 'type' => 'boolean' ],
				'post_id'      => [ 'type' => 'integer' ],
				'generated_at' => [ 'type' => 'string' ],
				'sections'     => [ 'type' => 'object' ],
			],
			'required'   => [ 'ok', 'post_id', 'generated_at', 'sections' ],
		];
	}

	public function permission_callback( array $args ): bool|\WP_Error {
		return Permissions::edit_posts();
	}

	public function execute( array $args ): array|\WP_Error {
		return $this->audit(
			$args,
			function ( array $args ) {
				$post_id      = (int) $args['post_id'];
				$sections_req = $args['sections'] ?? [ 'screenshot', 'responsive', 'accessibility', 'lighthouse' ];
				$post_url     = get_permalink( $post_id );
				$sections_out = [];

				if ( ! $post_url ) {
					return $this->error( 'invalid_post', __( 'Could not resolve URL for post_id.', 'stonewright' ) );
				}

				$child_args = [
					'post_id' => $post_id,
					'url'     => $post_url,
				];

				/**
				 * Child abilities are invoked via $ability->execute() which bypasses each
				 * child's own permission_callback. This is intentional: Report itself has
				 * already called Permissions::edit_posts() before reaching this closure,
				 * and all four child abilities (ScreenshotPage, ResponsiveCheck,
				 * AccessibilityCheck, Lighthouse) share the same `edit_posts` capability
				 * requirement. If any child ability's required capability ever diverges
				 * from `edit_posts`, its permission_callback MUST be called explicitly here
				 * rather than relying on the parent gate.
				 */
				if ( in_array( 'screenshot', $sections_req, true ) ) {
					$ability             = new ScreenshotPage();
					$sections_out['screenshot'] = $ability->execute( $child_args );
				}

				if ( in_array( 'responsive', $sections_req, true ) ) {
					$ability              = new ResponsiveCheck();
					$sections_out['responsive'] = $ability->execute( $child_args );
				}

				if ( in_array( 'accessibility', $sections_req, true ) ) {
					$ability                   = new AccessibilityCheck();
					$sections_out['accessibility'] = $ability->execute( $child_args );
				}

				if ( in_array( 'lighthouse', $sections_req, true ) ) {
					$ability                = new Lighthouse();
					// $child_args already contains url => $post_url, no merge needed.
					$sections_out['lighthouse'] = $ability->execute( $child_args );
				}

				return $this->ok( [
					'post_id'      => $post_id,
					'generated_at' => gmdate( 'c' ),
					'sections'     => $sections_out,
				] );
			}
		);
	}
}
