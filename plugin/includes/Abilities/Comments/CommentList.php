<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\Abilities\Comments;

use Stonewright\WpMcp\Abilities\AbilityKernel;
use Stonewright\WpMcp\Security\Permissions;

/**
 * Lists comments with compact fields for agent workflows.
 *
 * @stonewright-status stable
 */
final class CommentList extends AbilityKernel {

	public function name(): string {
		return 'stonewright/comment-list';
	}

	public function label(): string {
		return __( 'Comment: List', 'stonewright' );
	}

	public function description(): string {
		return __( 'Lists comments with compact fields for agent workflows.', 'stonewright' );
	}

	public function category(): string {
		return 'comments';
	}

	public function input_schema(): array {
		return [
			'type'                 => 'object',
			'additionalProperties' => false,
			'properties'           => [
				'post_id'  => [ 'type' => 'integer', 'minimum' => 1 ],
				'status'   => [ 'type' => 'string' ],
				'search'   => [ 'type' => 'string' ],
				'per_page' => [ 'type' => 'integer', 'minimum' => 1, 'maximum' => 50, 'default' => 20 ],
				'page'     => [ 'type' => 'integer', 'minimum' => 1, 'default' => 1 ],
			],
		];
	}

	public function output_schema(): array {
		return [
			'additionalProperties' => true,
			'type'       => 'object',
			'properties' => [
				'items' => [ 'type' => 'array' ],
				'total' => [ 'type' => 'integer' ],
			],
			'required'   => [ 'items', 'total' ],
		];
	}

	public function permission_callback( array $args ): bool|\WP_Error {
		return Permissions::edit_posts();
	}

	public function execute( array $args ): array|\WP_Error {
		return $this->audit(
			$args,
			static function ( array $args ) {
				$query = [
					'number' => min( (int) ( $args['per_page'] ?? 20 ), 50 ),
					'paged'  => max( 1, (int) ( $args['page'] ?? 1 ) ),
				];
				if ( isset( $args['post_id'] ) ) {
					$query['post_id'] = (int) $args['post_id'];
				}
				if ( isset( $args['status'] ) ) {
					$query['status'] = (string) $args['status'];
				}
				if ( isset( $args['search'] ) ) {
					$query['search'] = (string) $args['search'];
				}
				$comments = get_comments( $query );
				$items    = [];
				foreach ( (array) $comments as $c ) {
					$content = (string) ( $c->comment_content ?? '' );
					$items[] = [
						'id'          => (int) $c->comment_ID,
						'post'        => (int) ( $c->comment_post_ID ?? 0 ),
						'status'      => (string) ( $c->comment_approved ?? '' ),
						'author_name' => (string) ( $c->comment_author ?? '' ),
						'date'        => (string) ( $c->comment_date ?? '' ),
						'excerpt'     => mb_substr( $content, 0, 200 ),
					];
				}
				return [ 'items' => $items, 'total' => count( $items ) ];
			}
		);
	}
}
