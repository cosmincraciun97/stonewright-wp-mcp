<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\Abilities\Comments;

/**
 * One vocabulary for comment moderation status.
 *
 * Core stores approved as '1' and pending as '0' and names the other two states, and it takes approve and
 * hold as input. The comment abilities take and return approve, hold, spam and trash.
 */
final class CommentStatus {

	/** The values the comment abilities accept as a status. */
	public const INPUT = [ 'approve', 'hold', 'spam', 'trash' ];

	/**
	 * The ability status for a stored `comment_approved` value.
	 */
	public static function normalize( string $stored ): string {
		return match ( $stored ) {
			'1', 'approve', 'approved'   => 'approve',
			'0', 'hold', 'unapproved'    => 'hold',
			'post-trashed'               => 'trash',
			default                      => $stored,
		};
	}

	/**
	 * The status of a comment object, or an empty string when there is none.
	 */
	public static function of( ?object $comment ): string {
		return null === $comment ? '' : self::normalize( (string) ( $comment->comment_approved ?? '' ) );
	}
}
