<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\Abilities\Content;

use Stonewright\WpMcp\Abilities\AbilityKernel;
use Stonewright\WpMcp\Abilities\Common\ConfirmationGuard;
use Stonewright\WpMcp\CustomCode\ContentSurfacePolicy;
use Stonewright\WpMcp\Security\Permissions;
use Stonewright\WpMcp\Security\RescueGuard;

/**
 * Contract decision: keep output_schema aligned to the handler response shape.
 *
 * @stonewright-status stable
 */
final class BulkCreate extends AbilityKernel {

	use ConfirmationGuard;

	public function name(): string {
		return 'stonewright/content-bulk-create';
	}

	public function label(): string {
		return __( 'Bulk create content', 'stonewright' );
	}

	public function description(): string {
		return __( 'Creates multiple posts or pages in a single call. Limited to 50 items per request.', 'stonewright' );
	}

	public function category(): string {
		return 'content';
	}

	public function input_schema(): array {
		return [
			'type'                 => 'object',
			'additionalProperties' => false,
			'properties'           => [
				'items' => [
					'type'     => 'array',
					'minItems' => 1,
					'maxItems' => 50,
					'items'    => [
						'type'       => 'object',
						'properties' => [
							'title'     => [ 'type' => 'string', 'maxLength' => 255 ],
							'content'   => [ 'type' => 'string' ],
							'status'    => [ 'type' => 'string', 'enum' => [ 'draft', 'publish', 'private', 'pending', 'future' ] ],
							'date'      => PostDate::schema(),
							'post_type' => [ 'type' => 'string', 'default' => 'page', 'description' => 'A registered content post type. Attachments, revisions, menu items and other internal types are refused.' ],
						],
						'required'   => [ 'title' ],
					],
				],
				'confirmation_token' => [ 'type' => 'string' ],
			],
			'required'             => [ 'items' ],
		];
	}

	public function output_schema(): array {
		return [
			'type'       => 'object',
			'properties' => [
				'ok'      => [ 'type' => 'boolean' ],
				'created' => [ 'type' => 'array', 'items' => [ 'type' => 'integer' ] ],
				'errors'  => [ 'type' => 'array' ],
			],
		];
	}

	/**
	 * @stonewright-cap create_posts_for($item['post_type']) for each item + publish_posts when status in {publish, private, future}
	 */
	public function permission_callback( array $args ): bool|\WP_Error {
		foreach ( (array) ( $args['items'] ?? [] ) as $i => $item ) {
			$item_post_type = sanitize_key( (string) ( $item['post_type'] ?? 'page' ) );
			$invalid        = PostTypeGuard::error( $item_post_type );
			if ( $invalid instanceof \WP_Error ) {
				return $invalid;
			}
			$blocked = ContentSurfacePolicy::assert_generic_write_allowed( $item_post_type );
			if ( $blocked instanceof \WP_Error ) {
				return $blocked;
			}

			if ( ! Permissions::can_create_post_type( $item_post_type ) ) {
				return new \WP_Error(
					'stonewright_forbidden',
					__( 'Insufficient capability to create posts of this type.', 'stonewright' ),
					[ 'status' => 403, 'failed_index' => $i, 'post_type' => $item_post_type ]
				);
			}

			$item_status = (string) ( $item['status'] ?? 'draft' );
			$publish_cap = Permissions::publish_cap_for_status( $item_post_type, $item_status );
			if ( null !== $publish_cap && ! current_user_can( $publish_cap ) ) {
				return new \WP_Error(
					'stonewright_forbidden',
					__( 'Insufficient capability to publish posts of this type.', 'stonewright' ),
					[ 'status' => 403, 'failed_index' => $i, 'post_type' => $item_post_type ]
				);
			}
		}

		return true;
	}

	public function execute( array $args ): array|\WP_Error {
		return $this->audit(
			$args,
			function ( array $args ) {
				$verify_args = array_filter(
					$args,
					static fn( string $k ) => 'confirmation_token' !== $k,
					ARRAY_FILTER_USE_KEY
				);

				$token_error = $this->confirmation_token_error( $args, $verify_args );
				if ( null !== $token_error ) {
					return $token_error;
				}

				// Check every item before the first write, so a bad item stops the call with nothing created.
				$prepared = [];
				foreach ( (array) ( $args['items'] ?? [] ) as $idx => $item ) {
					$item           = is_array( $item ) ? $item : [];
					$item_post_type = sanitize_key( (string) ( $item['post_type'] ?? 'page' ) );
					$invalid        = PostTypeGuard::error( $item_post_type );
					if ( $invalid instanceof \WP_Error ) {
						$invalid->add_data( [ 'status' => 400, 'failed_index' => $idx, 'post_type' => $item_post_type ] );
						return $invalid;
					}
					$blocked = ContentSurfacePolicy::assert_generic_write_allowed( $item_post_type );
					if ( $blocked instanceof \WP_Error ) {
						return $blocked;
					}
					$status = (string) ( $item['status'] ?? 'draft' );
					$date   = PostDate::resolve( $item['date'] ?? null, $status );
					if ( is_wp_error( $date ) ) {
						$date->add_data( [ 'status' => 400, 'failed_index' => $idx ] );
						return $date;
					}
					$prepared[ $idx ] = [
						'post_title'   => sanitize_text_field( (string) ( $item['title'] ?? '' ) ),
						'post_content' => wp_kses_post( (string) ( $item['content'] ?? '' ) ),
						'post_status'  => $status,
						'post_type'    => $item_post_type,
					] + $date;
				}

				$created = [];
				$errors  = [];
				foreach ( $prepared as $idx => $payload ) {
					$id = wp_insert_post( wp_slash( $payload ), true );

					if ( is_wp_error( $id ) ) {
						$errors[] = [
							'index'   => $idx,
							'code'    => $id->get_error_code(),
							'message' => $id->get_error_message(),
						];
						continue;
					}
					RescueGuard::note_post_created( (int) $id );
					$created[] = (int) $id;
				}
				return [ 'ok' => [] === $errors, 'created' => $created, 'errors' => $errors ];
			}
		);
	}
}
