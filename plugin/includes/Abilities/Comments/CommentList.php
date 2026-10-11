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

	/** Comments read per query when the user can edit only some of them. */
	private const SCAN_BATCH = 100;

	/** Most comments looked at to build a list for a user who can edit only some of them. */
	private const MAX_SCAN = 2000;

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
				'total' => [ 'type' => 'integer', 'description' => 'Number of comments that match the filters, across all pages. When truncated is true it counts only the comments that were checked.' ],
				'truncated' => [ 'type' => 'boolean', 'description' => 'True when the list was cut at the scan limit, so items and total can be incomplete for a user who can edit only some comments.' ],
				'hint'      => [ 'type' => 'string', 'description' => 'Present when truncated is true: how to narrow the list.' ],
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
				$per_page  = max( 1, min( (int) ( $args['per_page'] ?? 20 ), 50 ) );
				$page      = max( 1, (int) ( $args['page'] ?? 1 ) );
				$truncated = false;

				if ( self::can_edit_every_post() ) {
					// A user who can edit every post can edit every comment on them, so core can count and page.
					$comments = get_comments( $filters + [ 'number' => $per_page, 'paged' => $page ] );
					$count    = get_comments( $filters + [ 'count' => true ] );
					$rows     = array_values( array_filter( (array) $comments, static fn ( $c ): bool => is_object( $c ) && Permissions::edit_comment( (int) $c->comment_ID ) ) );
					$total    = is_int( $count ) ? $count : count( $rows );
				} else {
					// Otherwise every comment is checked, so the total counts only the ones this user may edit.
					$scan      = self::editable_comments( $filters );
					$allowed   = $scan['comments'];
					$truncated = $scan['truncated'];
					$total     = count( $allowed );
					$rows    = array_slice( $allowed, ( $page - 1 ) * $per_page, $per_page );
				}

				$items = [];
				foreach ( $rows as $c ) {
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
				$result = [ 'items' => $items, 'total' => $total, 'truncated' => $truncated ];
				if ( $truncated ) {
					$result['hint'] = sprintf(
						/* translators: %d: number of comments checked. */
						__( 'Only the first %d matching comments were checked, so items and total can be incomplete. Narrow the list with post_id, status or search.', 'stonewright' ),
						self::MAX_SCAN
					);
				}
				return $result;
			}
		);
	}

	/**
	 * Whether the user can edit posts of every author and state, which makes edit_comment true for any
	 * comment on an existing post.
	 */
	private static function can_edit_every_post(): bool {
		return current_user_can( 'edit_others_posts' ) && current_user_can( 'edit_published_posts' ) && current_user_can( 'edit_private_posts' );
	}

	/**
	 * The comments that match the filters and that the user may edit, read in batches up to a bound.
	 *
	 * @param array<string, mixed> $filters Comment query filters.
	 * @return array{comments: list<object>, truncated: bool} Truncated when comments remain after the bound.
	 */
	private static function editable_comments( array $filters ): array {
		$allowed = [];
		$scanned = 0;
		for ( $paged = 1; $scanned < self::MAX_SCAN; ++$paged ) {
			$batch = (array) get_comments( $filters + [ 'number' => self::SCAN_BATCH, 'paged' => $paged ] );
			foreach ( $batch as $c ) {
				if ( is_object( $c ) && Permissions::edit_comment( (int) $c->comment_ID ) ) {
					$allowed[] = $c;
				}
			}
			$scanned += count( $batch );
			if ( count( $batch ) < self::SCAN_BATCH ) {
				return [ 'comments' => $allowed, 'truncated' => false ];
			}
		}
		// The bound was reached with a full last batch: one more comment past it means the list is cut.
		$more = (array) get_comments( $filters + [ 'number' => 1, 'paged' => self::MAX_SCAN + 1 ] );
		return [ 'comments' => $allowed, 'truncated' => [] !== $more ];
	}
}
