<?php
/**
 * The comment family in the change ledger: fields and status, with a delete as a full image.
 * SPDX-License-Identifier: GPL-2.0-or-later
 *
 * @package Stonewright
 */

declare( strict_types=1 );

namespace Stonewright\WpMcp\Security\Adapters;

use Stonewright\WpMcp\Security\Permissions;

/**
 * The image of a comment holds the fields of its row: post, author (name, email, url, address, agent),
 * dates, content, karma, approval status, type, parent and user. Comment meta is not imaged.
 *
 * A restore writes the fields back with wp_update_comment(), status included. A comment that was deleted is
 * inserted again with wp_insert_comment(): it has a new id, so replies that pointed at it keep the old id,
 * and the row of a delete says it is partly restorable. The undo of a created comment moves it to the trash
 * and never deletes it, and refuses on a site that keeps no trash.
 */
final class CommentAdapter extends FamilyAdapter {

	public const FAMILY = 'comment';

	public const RECIPE = 'comment';

	protected const ABILITIES = [ 'stonewright/comment-create', 'stonewright/comment-update', 'stonewright/comment-delete' ];

	/** Fields of the comment row, and whether the value is a number. */
	private const FIELDS = [
		'comment_post_ID'      => true,
		'comment_author'       => false,
		'comment_author_email' => false,
		'comment_author_url'   => false,
		'comment_author_IP'    => false,
		'comment_date'         => false,
		'comment_date_gmt'     => false,
		'comment_content'      => false,
		'comment_karma'        => true,
		'comment_approved'     => false,
		'comment_agent'        => false,
		'comment_type'         => false,
		'comment_parent'       => true,
		'user_id'              => true,
	];

	public static function types(): array {
		return [ 'comment' ];
	}

	public static function ledger_family( string $type ): string {
		return self::FAMILY;
	}

	public static function image( string $type, string $id ): ?array {
		if ( 'comment' !== $type || 1 !== preg_match( '/^[1-9][0-9]{0,18}$/D', $id ) ) {
			return null;
		}
		$comment = get_comment( (int) $id );
		if ( ! is_object( $comment ) ) {
			return null;
		}
		$fields = [];
		foreach ( self::FIELDS as $field => $numeric ) {
			$value            = $comment->{$field} ?? ( $numeric ? 0 : '' );
			$fields[ $field ] = $numeric ? (int) $value : (string) $value;
		}
		ksort( $fields, SORT_STRING );
		return [ 'fields' => $fields, 'v' => self::IMAGE_VERSION ];
	}

	public static function watch( string $ability, array $args ): array {
		if ( ! in_array( $ability, [ 'stonewright/comment-update', 'stonewright/comment-delete' ], true ) ) {
			return [];
		}
		$id = isset( $args['id'] ) && is_numeric( $args['id'] ) ? (int) $args['id'] : 0;
		return $id > 0 ? [ [ 'type' => 'comment', 'id' => (string) $id, 'before' => self::image( 'comment', (string) $id ) ] ] : [];
	}

	public static function discover( string $ability, array $context, mixed $result ): array {
		if ( 'stonewright/comment-create' !== $ability || ! is_array( $result ) ) {
			return [];
		}
		$id = isset( $result['id'] ) && is_numeric( $result['id'] ) ? (int) $result['id'] : 0;
		return $id > 0 ? [ [ 'type' => 'comment', 'id' => (string) $id ] ] : [];
	}

	public static function limits( string $ability, string $type, ?array $before, ?array $after ): array {
		if ( null !== $before && null === $after ) {
			return [ 'new id', 'replies keep the old parent id' ];
		}
		return [];
	}

	protected static function subject( string $type, ?array $image, string $id ): string {
		$author = is_array( $image['fields'] ?? null ) ? (string) ( $image['fields']['comment_author'] ?? '' ) : '';
		return 'comment ' . $id . ( '' === $author ? '' : ' by ' . FamilyLedger::clip( $author, 30 ) );
	}

	protected static function permitted( string $type, string $operation ): bool {
		return Permissions::moderate_comments();
	}

	protected static function removed( string $type, ?array $live ): bool {
		return null === $live || 'trash' === ( $live['fields']['comment_approved'] ?? '' );
	}

	protected static function write( string $type, string $id, ?array $target, array $row, array $options, ?array $live ): array {
		if ( null === $target ) {
			return self::trash( (int) $id );
		}
		$data = [];
		foreach ( self::FIELDS as $field => $numeric ) {
			if ( array_key_exists( $field, (array) ( $target['fields'] ?? [] ) ) ) {
				$data[ $field ] = $target['fields'][ $field ];
			}
		}
		if ( null === $live ) {
			$new = wp_insert_comment( wp_slash( $data ) );
			if ( ! $new || $new instanceof \WP_Error ) {
				return self::refused( 'insert_failed' );
			}
			$differences = self::differences( $target, self::image( 'comment', (string) (int) $new ) );
			return self::applied( [] === $differences, [] === $differences ? 'recreated' : 'differences:' . implode( ',', array_slice( $differences, 0, 5 ) ), (string) (int) $new );
		}
		$data['comment_ID'] = (int) $id;
		$updated            = wp_update_comment( wp_slash( $data ) );
		if ( false === $updated || $updated instanceof \WP_Error ) {
			return self::refused( 'update_failed' );
		}
		$differences = self::differences( $target, self::image( 'comment', $id ) );
		return self::applied( [] === $differences, [] === $differences ? 'restored' : 'differences:' . implode( ',', array_slice( $differences, 0, 5 ) ) );
	}

	/**
	 * @return array{ok:bool,applied:bool,detail:string,id:string,limits:list<string>}
	 */
	private static function trash( int $id ): array {
		$days = defined( 'EMPTY_TRASH_DAYS' ) ? (int) constant( 'EMPTY_TRASH_DAYS' ) : 30;
		if ( $days < 1 || ! function_exists( 'wp_trash_comment' ) ) {
			return self::refused( 'trash_disabled' );
		}
		wp_trash_comment( $id );
		$after = self::image( 'comment', (string) $id );
		return self::applied( null !== $after && 'trash' === ( $after['fields']['comment_approved'] ?? '' ), 'trashed' );
	}
}
