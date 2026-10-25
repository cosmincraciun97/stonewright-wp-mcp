<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\Security;

use Stonewright\WpMcp\Core\AbilityAnnotations;

/**
 * A pause on agent writes that an administrator sets in wp-admin: until a chosen time, abilities that change the
 * site are refused with `stonewright_write_freeze` while reads, the task start and the ways back keep working.
 *
 * The freeze is for a busy period (a launch, a traffic peak, an incident), when the site owner wants the agent to
 * look and plan but not to save. It is one autoloaded option, `[ 'until' => unix time, 'reason' => text ]`, that
 * only a person in wp-admin can lift or shorten (LeashGuard::guard_write_freeze_write()). The gate sits in
 * AbilityRegistry::authorize_execution(), so every path that runs an ability by name passes through it.
 *
 * An ability counts as a write when the recorded ability facts say so (Core\AbilityAnnotations). The exempt list
 * holds what an agent needs to undo a change or to keep working safely during the freeze: the rollbacks and
 * restores of the change history, the confirmation token a production-safe rollback needs, the incident record, the task start, and the
 * records of what the agent learned or was told, none of which changes the site's content. A restore that is not
 * tied to a change of the history (a post revision, a theme backup) can put any older content on the site, so it
 * stays frozen like any other write.
 */
final class WriteFreeze {

	public const OPTION     = 'stonewright_write_freeze';
	public const ERROR_CODE = 'stonewright_write_freeze';

	/** Longest reason that is kept, in characters. */
	public const MAX_REASON = 191;

	/** Abilities that run during a freeze although the facts list them as writes. */
	public const EXEMPT = [
		'stonewright/rescue-rollback',
		'stonewright/change-rollback',
		'stonewright/change-restore',
		'stonewright/task-start',
		'stonewright/learning-record',
		'stonewright/security-issue-confirmation-token',
		'stonewright/incident-repair-record',
		'stonewright/feedback-capture',
		'stonewright/tool-profile',
	];

	/**
	 * The freeze as stored, or null when there is none.
	 *
	 * @return array{until: int, reason: string}|null
	 */
	public static function stored(): ?array {
		$raw = get_option( self::OPTION, [] );
		if ( ! is_array( $raw ) || ! isset( $raw['until'] ) || (int) $raw['until'] < 1 ) {
			return null;
		}

		return [
			'until'  => (int) $raw['until'],
			'reason' => is_string( $raw['reason'] ?? null ) ? $raw['reason'] : '',
		];
	}

	/** Whether writes are frozen at $now. An expired freeze is inactive, whatever the option still holds. */
	public static function active( ?int $now = null ): bool {
		$stored = self::stored();

		return null !== $stored && $stored['until'] > ( $now ?? time() );
	}

	/** Whether the freeze applies to this ability: a recorded write that is not on the exempt list. */
	public static function applies_to( string $ability_name ): bool {
		return AbilityAnnotations::is_recorded_write( $ability_name ) && ! in_array( $ability_name, self::EXEMPT, true );
	}

	/**
	 * The refusal for an ability, or null when it may run: no freeze, an expired one, or an ability the freeze
	 * does not cover.
	 */
	public static function gate( string $ability_name, ?int $now = null ): ?\WP_Error {
		if ( ! self::active( $now ) || ! self::applies_to( $ability_name ) ) {
			return null;
		}

		return self::refusal();
	}

	/** The answer to a write during the freeze. The agent shows the link and stops. */
	public static function refusal(): \WP_Error {
		$stored = self::stored() ?? [ 'until' => time(), 'reason' => '' ];
		$until  = $stored['until'];
		$when   = wp_date( 'Y-m-d H:i', $until );
		$reason = '' !== $stored['reason'] ? $stored['reason'] : __( 'no reason given', 'stonewright' );

		return new \WP_Error(
			self::ERROR_CODE,
			sprintf(
				/* translators: 1: date and time in the site's timezone, 2: the reason the administrator gave */
				__( 'Writes are frozen on this site until %1$s: %2$s. An administrator lifts it in wp-admin under Stonewright > Setup > Settings. Show the user the settings link and stop; reads, rollbacks and the task start still work. Do not retry and do not look for another way to write.', 'stonewright' ),
				false === $when ? gmdate( 'Y-m-d H:i', $until ) . ' UTC' : $when,
				$reason
			),
			[
				'status'           => 423,
				'retryable'        => false,
				'execution_status' => 'blocked',
				'agent_must_stop'  => true,
				'until'            => gmdate( 'c', $until ),
				'reason'           => $stored['reason'],
				'settings_url'     => self::settings_url(),
			]
		);
	}

	/** The address of the settings card where an administrator lifts the freeze. */
	public static function settings_url(): string {
		return LeashGuard::settings_url() . '#stonewright-busy-sites';
	}

	/**
	 * What a freeze change is worth to the leash: true when the new value lifts the freeze or ends it sooner.
	 *
	 * @param mixed $old_value The stored value.
	 * @param mixed $new_value The value about to be stored.
	 */
	public static function loosens( mixed $old_value, mixed $new_value, ?int $now = null ): bool {
		$now = $now ?? time();
		$old = is_array( $old_value ) ? (int) ( $old_value['until'] ?? 0 ) : 0;
		$new = is_array( $new_value ) ? (int) ( $new_value['until'] ?? 0 ) : 0;

		return $old > $now && $new < $old;
	}
}
