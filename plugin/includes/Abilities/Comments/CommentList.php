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
				'status'   => [ 'type' => 'string', 'enum' => [ 'approve', 'hold', 'spam', 'trash', 'all' ], 'description' => 'Moderation status to list. Without it, approved and pending comments are listed; spam and trash are listed only when asked for by name.' ],
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
				'total' => [ 'type' => 'integer', 'description' => 'Number of comments that match the filters, across all pages.' ],
			],
			'required'   => [ 'items', 'total' ],
		];
	}

	public function permission_callback( array $args ): bool|\WP_Error {
		return Permissions::edit_posts();
	}

	public function execute( array $args ): array|\WP_Error {
		return $this->audit_read(
			$args,
			static function ( array $args ) {
				$filters = [];
				if ( isset( $args['post_id'] ) ) {
					$filters['post_id'] = (int) $args['post_id'];
				}
				if ( isset( $args['status'] ) ) {
					$filters['status'] = (string) $args['status'];
				}
				if ( isset( $args['search'] ) ) {
					$filters['search'] = (string) $args['search'];
				}
				$query    = $filters + [
					'number' => min( (int) ( $args['per_page'] ?? 20 ), 50 ),
					'paged'  => max( 1, (int) ( $args['page'] ?? 1 ) ),
				];
				$comments = get_comments( $query );
				// The total counts every match, not only this page.
				$total = get_comments( $filters + [ 'count' => true ] );
				$items    = [];
				foreach ( (array) $comments as $c ) {
					$content = (string) ( $c->comment_content ?? '' );
					$items[] = [
						'id'          => (int) $c->comment_ID,
						'post'        => (int) ( $c->comment_post_ID ?? 0 ),
						'status'      => CommentStatus::of( $c ),
						'author_name' => (string) ( $c->comment_author ?? '' ),
						'date'        => (string) ( $c->comment_date ?? '' ),
						'excerpt'     => mb_substr( $content, 0, 200 ),
					];
				}
				return [ 'items' => $items, 'total' => is_int( $total ) ? $total : count( $items ) ];
			}
		);
	}
}
