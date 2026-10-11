<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\Security;

/**
 * The end of an admin page or handler that refuses a user.
 *
 * WordPress answers 500 for a wp_die() call that is given no response code. A refusal is 403, so the pages and
 * handlers that refuse a user call this, with the message they always showed.
 *
 * @stonewright-status stable
 */
final class AccessDenied {

	/**
	 * Stops the request with the message and HTTP 403.
	 *
	 * @param string $message Escaped text to show.
	 */
	public static function deny( string $message ): never {
		wp_die( $message, '', [ 'response' => 403 ] ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- The caller escapes the message.
	}
}
