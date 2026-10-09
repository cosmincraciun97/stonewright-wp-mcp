<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\Context;

/**
 * Persisted operator-authored context prepended into task-start / bootstrap.
 *
 * The text is plain text. It is stored as typed, with tags removed and no HTML entities, and agents receive that
 * same text. It is escaped only where a page prints it as HTML. A value stored earlier with entities in it is
 * read as the plain text it stands for; reading never rewrites what is stored.
 */
final class UserContext {

	public const OPTION         = 'stonewright_user_context';
	public const ENABLED_OPTION = 'stonewright_user_context_enabled';
	public const MAX_STORED     = 4000;

	/** Characters of the text that full task-start and context-bootstrap carry. */
	public const MAX_INJECTED = 1200;

	/** Characters of the combined user context and custom instructions that compact task-start carries. */
	public const MAX_COMPACT = 400;

	/** Entity decoding stops at a fixed point; this bounds a pathological, deeply nested input. */
	private const MAX_PASSES = 8;

	/**
	 * @return array{enabled:bool,text:string}
	 */
	public static function get(): array {
		$enabled = (bool) get_option( self::ENABLED_OPTION, false );
		$text    = self::stored();
		if ( mb_strlen( $text ) > self::MAX_INJECTED ) {
			$text = mb_substr( $text, 0, self::MAX_INJECTED );
		}

		return [
			'enabled' => $enabled && '' !== $text,
			'text'    => $enabled ? $text : '',
		];
	}

	/**
	 * The stored text as plain text: what the editor shows and what agents are given.
	 */
	public static function stored(): string {
		return self::plain( (string) get_option( self::OPTION, '' ) );
	}

	/**
	 * Plain text for storage and for agents: entities decoded to the characters they stand for, tags removed.
	 *
	 * Idempotent: plain( plain( $x ) ) === plain( $x ), so saving the editor's own content back never changes it.
	 */
	public static function plain( string $text ): string {
		$text = (string) mb_convert_encoding( $text, 'UTF-8', 'UTF-8' );
		$text = str_replace( [ "\r\n", "\r" ], "\n", $text );
		$text = (string) preg_replace( '/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', '', $text );

		// Decode until nothing changes, so a doubly encoded value ends where a singly encoded one does.
		for ( $pass = 0; $pass < self::MAX_PASSES; $pass++ ) {
			$decoded = html_entity_decode( $text, ENT_QUOTES | ENT_HTML5, 'UTF-8' );
			if ( $decoded === $text ) {
				break;
			}
			$text = $decoded;
		}

		// Decoding can spell a tag, so tags are removed afterwards, until none is left to remove.
		for ( $pass = 0; $pass < self::MAX_PASSES; $pass++ ) {
			$stripped = wp_strip_all_tags( $text );
			if ( $stripped === $text ) {
				break;
			}
			$text = $stripped;
		}

		return trim( $text );
	}

	public static function save( string $text, bool $enabled ): void {
		$text = self::plain( $text );
		if ( mb_strlen( $text ) > self::MAX_STORED ) {
			$text = trim( mb_substr( $text, 0, self::MAX_STORED ) );
		}

		update_option( self::OPTION, $text, false );
		update_option( self::ENABLED_OPTION, $enabled, false );
	}

	/**
	 * How much of a stored text each task-start mode carries.
	 *
	 * @param int $stored Characters stored.
	 * @return array{stored:int,compact:int,full:int}
	 */
	public static function reach( int $stored ): array {
		$stored = max( 0, $stored );

		return [
			'stored'  => $stored,
			'compact' => min( $stored, self::MAX_COMPACT ),
			'full'    => min( $stored, self::MAX_INJECTED ),
		];
	}
}
