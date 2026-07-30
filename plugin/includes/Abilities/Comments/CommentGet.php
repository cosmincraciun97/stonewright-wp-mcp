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
		return Permissions::edit_posts();
	}

	public function execute( array $args ): array|\WP_Error {
		return $this->audit(
			$args,
			static function ( array $args ) {
				$id = (int) $args['id'];
				$c  = get_comment( $id );
				if ( null === $c ) {
					return new \WP_Error( 'stonewright_comment_not_found', 'Comment not found.', [ 'id' => $id ] );
				}
				return [
					'id'          => (int) $c->comment_ID,
					'post'        => (int) $c->comment_post_ID,
					'status'      => (string) $c->comment_approved,
					'author_name' => (string) $c->comment_author,
					'date'        => (string) $c->comment_date,
					'content'     => (string) $c->comment_content,
				];
			}
		);
	}
}
