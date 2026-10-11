<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\Abilities\Comments;

use Stonewright\WpMcp\Abilities\AbilityKernel;
use Stonewright\WpMcp\Security\Permissions;

/**
 * Gets a single comment including content.
 *
 * @stonewright-status stable
 */
final class CommentGet extends AbilityKernel {

	public function name(): string {
		return 'stonewright/comment-get';
	}

	public function label(): string {
		return __( 'Comment: Get', 'stonewright' );
	}

	public function description(): string {
		return __( 'Gets a single comment including content.', 'stonewright' );
	}

	public function category(): string {
		return 'comments';
	}

	public function input_schema(): array {
		return [
			'type'                 => 'object',
			'additionalProperties' => false,
			'properties'           => [
				'id' => [ 'type' => 'integer', 'minimum' => 1 ],
			],
			'required'             => [ 'id' ],
		];
	}

	public function output_schema(): array {
		return [
			'type'                 => 'object',
			'additionalProperties' => true,
			'properties'           => [
				'id' => [ 'type' => 'integer' ],
			],
			'required'             => [ 'id' ],
		];
	}

	public function permission_callback( array $args ): bool|\WP_Error {
		if ( ! Permissions::edit_posts() ) {
			return false;
		}
		return CommentAccess::refusal( (int) ( $args['id'] ?? 0 ) ) ?? true;
	}

	public function execute( array $args ): array|\WP_Error {
		return $this->audit_read(
			$args,
			static function ( array $args ) {
				$id      = (int) $args['id'];
				$refusal = CommentAccess::refusal( $id );
				if ( null !== $refusal ) {
					return $refusal;
				}
				$c = get_comment( $id );
				return [
					'id'          => (int) $c->comment_ID,
					'post'        => (int) $c->comment_post_ID,
					'status'      => CommentStatus::of( $c ),
					'author_name' => (string) $c->comment_author,
					'date'        => (string) $c->comment_date,
					'content'     => (string) $c->comment_content,
				];
			}
		);
	}
}
