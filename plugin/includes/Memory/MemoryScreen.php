<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\Memory;

use Stonewright\WpMcp\SkillLibrary\Site\InstructionScreen;

/**
 * Screens memory text for instructions that would turn an agent against the plugin's own rules.
 *
 * Memory is read back to agents as context, so a stored line that says "ignore the previous
 * instructions" is an instruction. It goes through the screen that imported skills go through. A match is
 * stored with the status needs_review, which task start and the context resources never offer, and only a
 * person clears it (the Approve button of the Memory page). It is never dropped without a word.
 */
final class MemoryScreen {

	/** Status of an entry whose text matched the screen. */
	public const NEEDS_REVIEW = 'needs_review';

	/**
	 * The rules a text breaks as an instruction. A prohibition ("never ignore the system prompt") and a
	 * mention of credentials are warnings in the screen and do not count here.
	 *
	 * @param mixed $value The entry value: a string, or an array whose strings are all read.
	 * @return list<string> Rule names, without repeats.
	 */
	public static function blocking_rules( string $name, mixed $value ): array {
		$rules = [];
		foreach ( InstructionScreen::findings( $name, self::text( $value ) ) as $finding ) {
			if ( 'error' === $finding['severity'] && ! in_array( $finding['rule'], $rules, true ) ) {
				$rules[] = $finding['rule'];
			}
		}
		return $rules;
	}

	/** Every string of a value, one per line, in order. */
	private static function text( mixed $value ): string {
		if ( is_string( $value ) ) {
			return $value;
		}
		if ( ! is_array( $value ) ) {
			return '';
		}
		$lines = [];
		foreach ( $value as $part ) {
			$text = self::text( $part );
			if ( '' !== $text ) {
				$lines[] = $text;
			}
		}
		return implode( "\n", $lines );
	}
}
