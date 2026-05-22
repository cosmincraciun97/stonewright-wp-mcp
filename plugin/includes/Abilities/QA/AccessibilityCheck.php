<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\Abilities\QA;

use Stonewright\WpMcp\Abilities\AbilityKernel;
use Stonewright\WpMcp\Companion\CompanionContract;
use Stonewright\WpMcp\Security\Permissions;
use Stonewright\WpMcp\Support\CompanionClient;

/**
 * Run an axe-core accessibility audit via the companion service.
 * Contract decision: keep output_schema aligned to the handler response shape.
 *
 * @stonewright-status stable
 */
final class AccessibilityCheck extends AbilityKernel {

	public function name(): string {
		return 'stonewright/qa-accessibility-check';
	}

	public function label(): string {
		return __( 'QA: Accessibility Check', 'stonewright' );
	}

	public function description(): string {
		return __( 'Runs an axe-core accessibility audit against a post or URL via the companion service.', 'stonewright' );
	}

	public function category(): string {
		return 'qa';
	}

	public function input_schema(): array {
		return [
			'type'                 => 'object',
			'additionalProperties' => false,
			'properties'           => [
				'post_id' => [ 'type' => 'integer', 'minimum' => 1 ],
				'url'     => [ 'type' => 'string', 'format' => 'uri' ],
				'ruleset' => [ 'type' => 'string', 'default' => 'wcag2aa' ],
			],
		];
	}

	public function output_schema(): array {
		return [
			'type'                 => 'object',
			'additionalProperties' => true,
			'properties'           => [
				'ok'               => [ 'type' => 'boolean' ],
				'request_id'       => [ 'type' => 'string' ],
				'violations'       => [
					'type'  => 'array',
					'items' => [
						'type'       => 'object',
						'properties' => [
							'rule'   => [ 'type' => 'string' ],
							'impact' => [ 'type' => 'string' ],
							'nodes'  => [ 'type' => 'array' ],
							'help'   => [ 'type' => 'string' ],
						],
					],
				],
				'passes_count'     => [ 'type' => 'integer' ],
				'incomplete_count' => [ 'type' => 'integer' ],
			],
			'required'   => [ 'ok', 'violations', 'passes_count' ],
		];
	}

	public function permission_callback( array $args ): bool|\WP_Error {
		return Permissions::edit_posts();
	}

	public function execute( array $args ): array|\WP_Error {
		return $this->audit(
			$args,
			function ( array $args ) {
				if ( empty( $args['post_id'] ) && empty( $args['url'] ) ) {
					return $this->error( 'missing_target', __( 'Either post_id or url is required.', 'stonewright' ) );
				}

				$target_url = ! empty( $args['url'] )
					? $args['url']
					: get_permalink( (int) $args['post_id'] );

				if ( ! $target_url ) {
					return $this->error( 'invalid_post', __( 'Could not resolve URL for post_id.', 'stonewright' ) );
				}

				$request_id = wp_generate_uuid4();

				$body = [
					'request_id' => $request_id,
					'url'        => $target_url,
					'ruleset'    => $args['ruleset'] ?? 'wcag2aa',
				];

				// Validate request payload before sending
				$req_check = CompanionContract::validate( 'axe', 'request', $body );
				if ( is_wp_error( $req_check ) ) {
					return $req_check;
				}

				$result = CompanionClient::post( '/axe', $body );
				if ( is_wp_error( $result ) ) {
					return $result;
				}

				// Validate response payload after parsing
				$resp_check = CompanionContract::validate( 'axe', 'response', $result );
				if ( is_wp_error( $resp_check ) ) {
					return $resp_check;
				}

				return $this->ok( $result );
			}
		);
	}
}
