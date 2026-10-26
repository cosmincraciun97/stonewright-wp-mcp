<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\Elementor;

/**
 * Counts the saves of one Elementor document in a ten-minute window, so a run of small saves can be told to become
 * one.
 *
 * Every save counts, whoever makes it: a Stonewright writer through ElementorData::write() and a person in the
 * Elementor editor through the document's own save. The count of a post lives in the transient
 * `stonewright_elementor_saves_<post id>` with the window as its lifetime, started by the first save and not
 * extended by the later ones.
 *
 * What the count does depends on the setting `stonewright_elementor_write_pacing`: `warn` (the default) adds a
 * `write_pacing` field to the responses of the request that made the third save; `strict` also refuses the sixth
 * with `stonewright_write_pacing` until the window has passed; `off` counts nothing. Only a person in wp-admin loosens
 * the setting (LeashGuard::guard_write_pacing_write()). A default must warn, never refuse.
 */
final class WritePacing {

	public const OPTION = 'stonewright_elementor_write_pacing';

	public const MODE_WARN   = 'warn';
	public const MODE_STRICT = 'strict';
	public const MODE_OFF    = 'off';

	/** Strictness of each mode: the higher, the stricter. */
	public const MODES = [
		self::MODE_OFF    => 0,
		self::MODE_WARN   => 1,
		self::MODE_STRICT => 2,
	];

	public const WINDOW_SECONDS = 600;
	public const WARN_AT        = 3;
	public const REFUSE_AT      = 6;

	public const ERROR_CODE = 'stonewright_write_pacing';

	public const HINT = 'Consolidate the remaining changes into one elementor-v3-batch-mutate apply.';

	/** Transient name prefix; the post id follows. */
	public const TRANSIENT_PREFIX = 'stonewright_elementor_saves_';

	/** @var array<int, int> Post id to the saves counted for it, for the posts saved in this request. */
	private static array $touched = [];

	/**
	 * The limits in force, after the stonewright_elementor_write_pacing_limits filter.
	 *
	 * @return array{window: int, warn_at: int, refuse_at: int}
	 */
	public static function limits(): array {
		$limits   = [ 'window' => self::WINDOW_SECONDS, 'warn_at' => self::WARN_AT, 'refuse_at' => self::REFUSE_AT ];
		$filtered = apply_filters( 'stonewright_elementor_write_pacing_limits', $limits );
		if ( is_array( $filtered ) ) {
			foreach ( $limits as $key => $default ) {
				if ( isset( $filtered[ $key ] ) && is_numeric( $filtered[ $key ] ) && (int) $filtered[ $key ] > 0 ) {
					$limits[ $key ] = (int) $filtered[ $key ];
				}
			}
		}

		return $limits;
	}

	/** The setting; anything that is not a known mode reads as the default, warn. */
	public static function mode(): string {
		$stored = get_option( self::OPTION, self::MODE_WARN );

		return is_string( $stored ) && isset( self::MODES[ $stored ] ) ? $stored : self::MODE_WARN;
	}

	/**
	 * Counts one save of a post.
	 *
	 * @return array{count: int, since: int} The saves in the window now, and when the window started.
	 */
	public static function note_save( int $post_id, ?int $now = null ): array {
		$now = $now ?? time();
		if ( $post_id < 1 || self::MODE_OFF === self::mode() ) {
			return [ 'count' => 0, 'since' => $now ];
		}

		$window = self::limits()['window'];
		$state  = self::state( $post_id, $now );
		$count  = ( null === $state ? 0 : $state['count'] ) + 1;
		$since  = null === $state ? $now : $state['since'];
		set_transient( self::TRANSIENT_PREFIX . $post_id, [ 'count' => $count, 'since' => $since ], max( 1, $since + $window - $now ) );
		self::$touched[ $post_id ] = $count;

		return [ 'count' => $count, 'since' => $since ];
	}

	/**
	 * The refusal of the next save of a post: null unless the setting is strict and the saves already made leave no
	 * room for another within the window.
	 */
	public static function check( int $post_id, ?int $now = null ): ?\WP_Error {
		if ( self::MODE_STRICT !== self::mode() || $post_id < 1 ) {
			return null;
		}
		$now   = $now ?? time();
		$state = self::state( $post_id, $now );
		if ( null === $state || $state['count'] + 1 < self::limits()['refuse_at'] ) {
			return null;
		}
		$retry_after = max( 1, $state['since'] + self::limits()['window'] - $now );

		return new \WP_Error(
			self::ERROR_CODE,
			sprintf(
				/* translators: 1: number of saves, 2: minutes in the window, 3: seconds until the window ends */
				__( 'This Elementor document was saved %1$d times in the last %2$d minutes and this site paces saves. Try again in %3$d seconds. Consolidate the remaining changes into one elementor-v3-batch-mutate apply.', 'stonewright' ),
				$state['count'],
				intdiv( self::limits()['window'], 60 ),
				$retry_after
			),
			[
				'status'           => 429,
				'retryable'        => true,
				'execution_status' => 'blocked',
				'post_id'          => $post_id,
				'saves'            => $state['count'],
				'retry_after'      => $retry_after,
				'hint'             => self::HINT,
			]
		);
	}

	/**
	 * What an agent is told: the posts saved in this request whose count reached the warning level.
	 *
	 * @return array{posts: list<array{post_id: int, saves: int}>, window_minutes: int, hint: string}|null
	 */
	public static function notice(): ?array {
		$limits = self::limits();
		$posts  = [];
		foreach ( self::$touched as $post_id => $count ) {
			if ( $count >= $limits['warn_at'] ) {
				$posts[] = [ 'post_id' => $post_id, 'saves' => $count ];
			}
		}
		if ( [] === $posts || self::MODE_OFF === self::mode() ) {
			return null;
		}

		return [
			'posts'          => $posts,
			'window_minutes' => intdiv( $limits['window'], 60 ),
			'hint'           => self::HINT,
		];
	}

	/** Forgets the posts this request touched, for unit tests. */
	public static function reset_for_tests(): void {
		self::$touched = [];
	}

	/**
	 * The saves counted for a post inside the window, or null when there are none or the window has passed.
	 *
	 * @return array{count: int, since: int}|null
	 */
	private static function state( int $post_id, int $now ): ?array {
		$stored = get_transient( self::TRANSIENT_PREFIX . $post_id );
		if ( ! is_array( $stored ) || ! isset( $stored['count'], $stored['since'] ) ) {
			return null;
		}
		$since = (int) $stored['since'];
		if ( $now - $since >= self::limits()['window'] ) {
			return null;
		}

		return [ 'count' => (int) $stored['count'], 'since' => $since ];
	}
}
