<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\Context;

/**
 * Persisted operator-authored context prepended into task-start / bootstrap.
 */
final class UserContext {

	public const OPTION         = 'stonewright_user_context';
	public const ENABLED_OPTION = 'stonewright_user_context_enabled';
	public const MAX_STORED     = 4000;
	public const MAX_INJECTED   = 1200;

	/**
	 * @return array{enabled:bool,text:string}
	 */
	public static function get(): array {
		$enabled = (bool) get_option( self::ENABLED_OPTION, false );
		$text    = trim( (string) get_option( self::OPTION, '' ) );
		if ( strlen( $text ) > self::MAX_INJECTED ) {
			$text = mb_substr( $text, 0, self::MAX_INJECTED );
		}

		return [
			'enabled' => $enabled && '' !== $text,
			'text'    => $enabled ? $text : '',
		];
	}

	public static function save( string $text, bool $enabled ): void {
		$text = sanitize_textarea_field( $text );
		if ( strlen( $text ) > self::MAX_STORED ) {
			$text = mb_substr( $text, 0, self::MAX_STORED );
		}

		update_option( self::OPTION, $text, false );
		update_option( self::ENABLED_OPTION, $enabled, false );
	}
}
