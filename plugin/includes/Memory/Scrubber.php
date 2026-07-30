<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\Memory;

/**
 * Turns site-specific memory rows into portable lessons.
 *
 * Memory accumulates the site it was learned on: hostnames, permalinks, post
 * ids, contact addresses. The lesson ("this client hates cramped hero padding")
 * usually travels; the identifiers never do, and leaving them in means every
 * later session reads instructions about a site it is not working on.
 *
 * Everything here is pure: no database, no options, no WordPress state. That is
 * what makes a batched, resumable sweep safe to reason about — the same row
 * always produces the same plan, and re-running the scrubber on its own output
 * is a no-op.
 */
final class Scrubber {

	/** Host every identifying domain collapses to. */
	public const HOST_PLACEHOLDER = 'example.com';

	/** Address every identifying mailbox collapses to. */
	public const EMAIL_PLACEHOLDER = 'user@example.com';

	/** Stand-in for a concrete WordPress object id inside prose. */
	public const ID_PLACEHOLDER = '{{id}}';

	/** Longest before/after preview handed back to a caller. */
	public const PREVIEW_MAX_LENGTH = 240;

	/**
	 * Types whose lessons are about the agent's behavior, not about one site,
	 * and so belong in `_global` once de-identified.
	 *
	 * `project` and `reference` rows describe a specific installation. Scrubbing
	 * their identifiers is right; promoting them to `_global` would restate a
	 * single site's layout as a fact about every site.
	 *
	 * @var list<string>
	 */
	private const GLOBALIZABLE_TYPES = [ 'feedback', 'generic', 'user' ];

	/** Global scope key used by the memory store. */
	private const GLOBAL_SCOPE = '_global';

	/**
	 * Alphanumeric characters a scrubbed row must retain to still say something.
	 * Below this, all that was ever stored was the site's own identity.
	 */
	private const SUBSTANCE_MIN_LENGTH = 24;

	/**
	 * Suffixes that make a key hold an object id rather than a measurement.
	 */
	private const IDENTIFIER_KEY_PATTERN = '~(?:^|_)ids?$~';

	/**
	 * Public suffixes recognised in bare (scheme-less) hostnames.
	 *
	 * An allowlist, not a general pattern: `class-wp-query.php` and
	 * `_elementor_data.` also look like `label.tld`, and rewriting those would
	 * destroy the lesson instead of de-identifying it.
	 */
	private const HOST_SUFFIXES = 'ro|com|net|org|io|co|dev|eu|uk|de|fr|it|es|nl|pl|hu|bg|info|biz|shop|store|site|online|app|cloud|tech|agency|studio|media|xyz|me|tv|ai';

	/**
	 * Words that mark the number after them as a WordPress object id.
	 */
	private const ID_KEYWORDS = 'post|page|attachment|term|taxonomy|user|author|element|widget|container|menu|kit|template|revision|comment|order|product|id';

	/**
	 * Strip site identity from a single string, leaving the lesson intact.
	 */
	public static function scrub_text( string $text ): string {
		if ( '' === $text ) {
			return '';
		}

		// Mailboxes first: an address contains a host, and collapsing the host
		// on its own would leave a half-anonymized address behind.
		$text = (string) preg_replace(
			'~[\w.%+-]+@[\w-]+(?:\.[\w-]+)*\.(?:' . self::HOST_SUFFIXES . ')\b~i',
			self::EMAIL_PLACEHOLDER,
			$text
		);

		// Absolute URLs: replace authority (host and any port), keep the path so
		// "/wp-json" or "/wp-admin" still carries meaning.
		$text = (string) preg_replace(
			'~\b(https?)://[^\s/?#\'"<>]+~i',
			'$1://' . self::HOST_PLACEHOLDER,
			$text
		);

		// Bare hostnames written as prose or fused into a slug. The lookbehind
		// keeps this off hosts the URL and mailbox passes already handled.
		//
		// Labels allow at most one hyphen. `hero-spacing-acme-flights.ro` is a
		// syntactically valid hostname in full, so a greedy pattern would eat
		// the descriptive prefix too and collapse unrelated keys onto the same
		// placeholder. Capping the label trades a possible leftover slug
		// fragment for keys that stay distinguishable, which is the safer
		// failure: a fragment is not an identifier, a key collision is data loss.
		$text = (string) preg_replace_callback(
			'~(?<![\w/@.])(?:[a-z0-9]+(?:-[a-z0-9]+)?\.)+(?:' . self::HOST_SUFFIXES . ')\b~i',
			static function ( array $matches ): string {
				// A slug already fused to the placeholder ("spacing-example.com")
				// is itself a syntactically valid host. Rewriting it would eat one
				// more slug segment on every pass, so already-scrubbed runs are
				// returned untouched — that is what makes the scrubber idempotent.
				return self::is_scrubbed_host( (string) $matches[0] ) ? (string) $matches[0] : self::HOST_PLACEHOLDER;
			},
			$text
		);

		// Object ids referenced in prose or as inline assignments.
		$text = (string) preg_replace(
			'~\b(' . self::ID_KEYWORDS . ')(_id|s)?\b([ =:#-]*)\d+\b~i',
			'$1$2$3' . self::ID_PLACEHOLDER,
			$text
		);

		return $text;
	}

	/**
	 * Strip site identity from a decoded memory value of any shape.
	 *
	 * @param mixed  $value Decoded value, scalar or nested array.
	 * @param string $key   Key the value was stored under, used to recognise ids.
	 * @return mixed Same shape, de-identified.
	 */
	public static function scrub_value( mixed $value, string $key = '' ): mixed {
		if ( is_array( $value ) ) {
			$out = [];
			foreach ( $value as $child_key => $child ) {
				// List members inherit the key of the list that holds them, so
				// `ids => [11, 12]` is recognised as a list of identifiers.
				$out[ $child_key ] = self::scrub_value( $child, is_string( $child_key ) ? $child_key : $key );
			}
			return $out;
		}

		if ( is_string( $value ) ) {
			return self::scrub_text( $value );
		}

		if ( is_int( $value ) && self::is_identifier_key( $key ) ) {
			return 0;
		}

		return $value;
	}

	/**
	 * Decide what should happen to one memory row.
	 *
	 * Returns null when the row is already portable and correctly scoped, so a
	 * caller can count untouched rows without special-casing them.
	 *
	 * @param array<string, mixed> $entry Decoded memory row.
	 * @return array{id: int, action: string, reason: string, changes: array<string, mixed>, before: string, after: string}|null
	 */
	public static function plan( array $entry ): ?array {
		$id    = isset( $entry['id'] ) ? (int) $entry['id'] : 0;
		$type  = isset( $entry['type'] ) ? (string) $entry['type'] : 'generic';
		$scope = isset( $entry['scope'] ) ? (string) $entry['scope'] : '';

		$original = [
			'memory_key' => isset( $entry['memory_key'] ) ? (string) $entry['memory_key'] : '',
			'name'       => isset( $entry['name'] ) ? (string) $entry['name'] : '',
			'topic'      => isset( $entry['topic'] ) ? (string) $entry['topic'] : '',
			'value'      => $entry['value'] ?? null,
		];

		$scrubbed = [
			'memory_key' => self::scrub_text( $original['memory_key'] ),
			'name'       => self::scrub_text( $original['name'] ),
			'topic'      => self::scrub_text( $original['topic'] ),
			'value'      => self::scrub_value( $original['value'] ),
		];

		$changes = [];
		foreach ( $scrubbed as $field => $new ) {
			if ( $new !== $original[ $field ] ) {
				$changes[ $field ] = $new;
			}
		}

		$before = self::preview( $original );
		$after  = self::preview( $scrubbed );

		if ( ! self::has_substance( $scrubbed ) ) {
			// The row was nothing but the site's own identity. Report it and stop:
			// deletion of stored context stays an explicit, reviewed act.
			return [
				'id'      => $id,
				'action'  => 'review_for_deletion',
				'reason'  => 'Nothing but site identity remains after de-identification.',
				'changes' => [],
				'before'  => $before,
				'after'   => $after,
			];
		}

		if ( in_array( $type, self::GLOBALIZABLE_TYPES, true ) && self::GLOBAL_SCOPE !== $scope ) {
			$changes['scope'] = self::GLOBAL_SCOPE;
		}

		if ( [] === $changes ) {
			return null;
		}

		return [
			'id'      => $id,
			'action'  => 'generalize',
			'reason'  => isset( $changes['scope'] )
				? 'De-identified and promoted to global scope.'
				: 'De-identified in place; the row describes this site.',
			'changes' => $changes,
			'before'  => $before,
			'after'   => $after,
		];
	}

	/**
	 * Whether a candidate host is already the placeholder, alone or fused to a
	 * surviving slug segment.
	 */
	private static function is_scrubbed_host( string $candidate ): bool {
		$candidate = strtolower( $candidate );

		return self::HOST_PLACEHOLDER === $candidate
			|| str_ends_with( $candidate, '-' . self::HOST_PLACEHOLDER )
			|| str_ends_with( $candidate, '.' . self::HOST_PLACEHOLDER );
	}

	private static function is_identifier_key( string $key ): bool {
		return 1 === preg_match( self::IDENTIFIER_KEY_PATTERN, strtolower( $key ) );
	}

	/**
	 * Whether a scrubbed row still says something once placeholders are removed.
	 *
	 * @param array<string, mixed> $fields
	 */
	private static function has_substance( array $fields ): bool {
		$text = str_replace(
			[ self::EMAIL_PLACEHOLDER, self::HOST_PLACEHOLDER, self::ID_PLACEHOLDER ],
			' ',
			self::flatten( $fields )
		);

		return mb_strlen( (string) preg_replace( '~[^a-z0-9]+~i', '', $text ) ) >= self::SUBSTANCE_MIN_LENGTH;
	}

	/**
	 * @param array<string, mixed> $fields
	 */
	private static function preview( array $fields ): string {
		return mb_substr( self::flatten( $fields ), 0, self::PREVIEW_MAX_LENGTH );
	}

	/**
	 * Flatten a row's textual content into one comparable string.
	 *
	 * @param array<string, mixed> $fields
	 */
	private static function flatten( array $fields ): string {
		$parts = [];
		foreach ( $fields as $value ) {
			$parts[] = self::stringify( $value );
		}

		return trim( (string) preg_replace( '~\s+~', ' ', implode( ' ', $parts ) ) );
	}

	private static function stringify( mixed $value ): string {
		if ( is_array( $value ) ) {
			$parts = [];
			foreach ( $value as $child ) {
				$parts[] = self::stringify( $child );
			}
			return implode( ' ', $parts );
		}

		if ( is_bool( $value ) ) {
			return $value ? 'true' : 'false';
		}

		if ( null === $value ) {
			return '';
		}

		if ( is_scalar( $value ) ) {
			return (string) $value;
		}

		return '';
	}
}
