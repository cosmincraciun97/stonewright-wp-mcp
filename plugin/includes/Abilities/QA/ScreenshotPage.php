<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\Abilities\QA;

use Stonewright\WpMcp\Abilities\AbilityKernel;
use Stonewright\WpMcp\Companion\CompanionContract;
use Stonewright\WpMcp\QA\QaArtifactStore;
use Stonewright\WpMcp\Security\Permissions;
use Stonewright\WpMcp\Support\CompanionClient;

/**
 * Take a screenshot of a page via the companion Playwright service.
 * Contract decision: keep output_schema aligned to the handler response shape.
 *
 * @stonewright-status stable
 */
final class ScreenshotPage extends AbilityKernel {

	public function name(): string {
		return 'stonewright/qa-screenshot-page';
	}

	public function label(): string {
		return __( 'QA: Screenshot Page', 'stonewright' );
	}

	public function description(): string {
		return __( 'Captures a full-page (or viewport) screenshot of a post/URL via the companion Playwright service.', 'stonewright' );
	}

	public function category(): string {
		return 'qa';
	}

	public function input_schema(): array {
		return [
			'type'                 => 'object',
			'additionalProperties' => false,
			'properties'           => [
				'post_id'       => [ 'type' => 'integer', 'minimum' => 1 ],
				'url'           => [ 'type' => 'string', 'format' => 'uri' ],
				'viewport'      => [
					'type'                 => 'object',
					'additionalProperties' => false,
					'properties'           => [
						'width'  => [ 'type' => 'integer', 'minimum' => 1 ],
						'height' => [ 'type' => 'integer', 'minimum' => 1 ],
					],
				],
				'full_page'     => [ 'type' => 'boolean', 'default' => true ],
				'wait_for'      => [ 'type' => 'string' ],
				'wait_ms'       => [ 'type' => 'integer', 'minimum' => 0, 'default' => 500 ],
			],
		];
	}

	public function output_schema(): array {
		return [
			'type'                 => 'object',
			'additionalProperties' => true,
			'properties'           => [
				'ok'          => [ 'type' => 'boolean' ],
				'artifact_id' => [ 'type' => 'string' ],
				'path'        => [ 'type' => 'string' ],
				'image_url'   => [ 'type' => 'string' ],
				'width'       => [ 'type' => 'integer' ],
				'height'      => [ 'type' => 'integer' ],
				'request_id'  => [ 'type' => 'string' ],
				'url'         => [ 'type' => 'string' ],
				'viewport'    => [ 'type' => 'object' ],
				'created_at'  => [ 'type' => 'string' ],
			],
			'required'   => [ 'ok', 'image_url', 'width', 'height' ],
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

				$request_id    = wp_generate_uuid4();
				$artifact_path = QaArtifactStore::reserve( $request_id );

				$body = [
					'request_id'    => $request_id,
					'url'           => $target_url,
					'artifact_path' => rtrim( $artifact_path, '/' ),
					'viewport'      => $args['viewport'] ?? [ 'width' => 1440, 'height' => 900 ],
					'full_page'     => $args['full_page'] ?? true,
					'wait_ms'       => $args['wait_ms'] ?? 500,
				];
				if ( ! empty( $args['wait_for'] ) ) {
					$body['wait_for'] = $args['wait_for'];
				}

				// Validate request payload before sending
				$req_check = CompanionContract::validate( 'screenshot', 'request', $body );
				if ( is_wp_error( $req_check ) ) {
					return $req_check;
				}

				$result = CompanionClient::post( '/screenshot', $body );
				if ( is_wp_error( $result ) ) {
					return $result;
				}

				// Validate response payload after parsing
				$resp_check = CompanionContract::validate( 'screenshot', 'response', $result );
				if ( is_wp_error( $resp_check ) ) {
					return $resp_check;
				}

				$image_url = QaArtifactStore::url_for( (string) ( $result['path'] ?? '' ) ) ?: ( $result['url'] ?? '' );

				return $this->ok( array_merge( $result, [ 'image_url' => $image_url ] ) );
			}
		);
	}
}
