<?php
/**
 * The site setting that turns section reuse on or off.
 * SPDX-License-Identifier: GPL-2.0-or-later
 *
 * @package Stonewright
 */

declare( strict_types=1 );

namespace Stonewright\WpMcp\SectionReuse;

use Stonewright\WpMcp\Context\AgentHints;
use Stonewright\WpMcp\Core\AbilityRegistry;
use Stonewright\WpMcp\Security\AuditLog;
use Stonewright\WpMcp\Security\ErrorPatterns;
use Stonewright\WpMcp\Support\AgentNotices;

/**
 * Option `stonewright_section_reuse`: `ask` (default) lets an agent offer to copy a section the site
 * already has into a new page; `off` hides the reuse abilities and tells agents not to mention reuse.
 *
 * Agents learn the value three ways. It travels in `agent_preferences` of task-start and in the
 * connect-time instructions (through the `stonewright_agent_preferences` filter). A change bumps the
 * tool-surface revision, so a client that honors `tools/list_changed` lists the tools again, and for
 * fifteen minutes it adds one short line to every Stonewright response. A client that keeps a stale tool
 * list is still safe: every reuse ability reads the live option when it runs.
 */
final class SectionReuseSetting {

	public const OPTION = 'stonewright_section_reuse';
	public const ASK    = 'ask';
	public const OFF    = 'off';

	/** Notice key and lifetime of the line that follows a change. */
	public const NOTICE_KEY = 'section_reuse';
	public const NOTICE_TTL = 900;

	/** The settings group of the Setup form, which carries the nonce and the capability check of options.php. */
	public const OPTION_GROUP = 'stonewright_settings';

	/** The preference name agents read. */
	public const PREFERENCE = 'section_reuse';

	/** The abilities that exist only while reuse is on. */
	public const ABILITIES = [
		'stonewright/section-reuse-find',
		'stonewright/section-reuse-extract',
	];

	/** Error code of the refusal an ability or an insert operation returns while the setting is off. */
	public const OFF_CODE = 'stonewright_section_reuse_off';

	/** Identity of the bundled skill that tells agents how to reuse sections. */
	public const SKILL_SLUG = 'stonewright-section-reuse';

	/** Text an ability returns to an agent that calls it while the setting is off. */
	public const OFF_INSTRUCTION = 'Section reuse is off. Do not ask the user about reusing sections.';

	/**
	 * Hooks the agent preference and the change handlers. The settings field itself is registered on the
	 * Setup screen, in the Stonewright settings group.
	 */
	public static function register(): void {
		add_filter( AgentHints::PREFERENCES_FILTER, [ self::class, 'agent_preferences' ] );
		add_action( 'admin_init', [ self::class, 'register_setting' ] );
		add_action( 'update_option_' . self::OPTION, [ self::class, 'on_updated' ], 10, 2 );
		add_action( 'add_option_' . self::OPTION, [ self::class, 'on_added' ], 10, 2 );
	}

	/** Registers the option with the Settings API under the Stonewright settings group. */
	public static function register_setting(): void {
		register_setting(
			self::OPTION_GROUP,
			self::OPTION,
			[
				'type'              => 'string',
				'default'           => self::ASK,
				'sanitize_callback' => [ self::class, 'sanitize' ],
				'show_in_rest'      => false,
			]
		);
	}

	/** The stored value: `off`, or `ask` for anything else. */
	public static function value(): string {
		$stored = get_option( self::OPTION, self::ASK );

		return self::OFF === $stored ? self::OFF : self::ASK;
	}

	public static function is_enabled(): bool {
		return self::OFF !== self::value();
	}

	/**
	 * What the settings form saves. Only `ask` and `off` are values; anything else keeps the stored value,
	 * so a malformed request never turns reuse on or off.
	 */
	public static function sanitize( mixed $value ): string {
		$text = is_string( $value ) ? strtolower( trim( $value ) ) : '';

		return in_array( $text, [ self::ASK, self::OFF ], true ) ? $text : self::value();
	}

	/**
	 * `stonewright_agent_preferences` filter callback.
	 *
	 * @param mixed $preferences Preferences collected so far.
	 * @return array<mixed>
	 */
	public static function agent_preferences( mixed $preferences ): array {
		$preferences                      = is_array( $preferences ) ? $preferences : [];
		$preferences[ self::PREFERENCE ] = self::value();

		return $preferences;
	}

	/** The line that rides on every response for fifteen minutes after a change. */
	public static function notice_line( string $value ): string {
		return self::OFF === $value
			? 'section_reuse: off - do not offer section reuse'
			: 'section_reuse: ask - offer reuse of saved sections when building a page';
	}

	/**
	 * The error an ability returns to a caller that reaches it while the setting is off. It is a refusal the
	 * site chose, so it is blocked and never retryable.
	 */
	public static function off_error(): \WP_Error {
		return new \WP_Error(
			self::OFF_CODE,
			self::OFF_INSTRUCTION,
			array_merge(
				[
					'status'      => 409,
					'enabled'     => false,
					'instruction' => self::OFF_INSTRUCTION,
				],
				self::refusal_flags( self::OFF_CODE )
			)
		);
	}

	/**
	 * What a batch writer adds to the failure it reports when the operation that failed was the off
	 * refusal: nothing to retry, and a blocked outcome instead of an error. Any other code adds nothing.
	 *
	 * @return array{retryable?:bool,execution_status?:string}
	 */
	public static function refusal_flags( string $code ): array {
		return self::OFF_CODE === $code ? [ 'retryable' => false, 'execution_status' => 'blocked' ] : [];
	}

	/**
	 * `update_option_{option}` action: an existing value was replaced.
	 *
	 * @param mixed $old_value Value before.
	 * @param mixed $value     Value after.
	 */
	public static function on_updated( mixed $old_value, mixed $value ): void {
		self::on_change( $old_value, $value );
	}

	/**
	 * `add_option_{option}` action: the first save. The value before it is the default.
	 *
	 * @param mixed $option Option name.
	 * @param mixed $value  Value saved.
	 */
	public static function on_added( mixed $option, mixed $value ): void {
		unset( $option );
		self::on_change( self::ASK, $value );
	}

	/**
	 * A change of the setting: audit it, tell agents for fifteen minutes, and signal a new tool list.
	 * Never throws; a failure to notify cannot undo the save.
	 *
	 * @param mixed    $old_value Value before.
	 * @param mixed    $value     Value after.
	 * @param int|null $clock     Current time; tests pass one.
	 */
	public static function on_change( mixed $old_value, mixed $value, ?int $clock = null ): void {
		$from = self::OFF === $old_value ? self::OFF : self::ASK;
		$to   = self::OFF === $value ? self::OFF : self::ASK;
		if ( $from === $to ) {
			return;
		}
		try {
			AuditLog::record(
				'stonewright/section-reuse-setting',
				[
					'_meta' => [
						'operation_class'  => 'section_reuse_setting',
						'resource_type'    => 'option',
						'resource_ref'     => self::OPTION,
						'execution_status' => 'executed',
					],
					'from'  => $from,
					'to'    => $to,
				],
				'ok'
			);
		} catch ( \Throwable $failure ) {
			unset( $failure );
		}
		try {
			// A refusal recorded while the setting was off says nothing about the new value.
			ErrorPatterns::forget_code( self::OFF_CODE );
		} catch ( \Throwable $failure ) {
			unset( $failure );
		}
		try {
			AgentNotices::push( self::NOTICE_KEY, self::notice_line( $to ), self::NOTICE_TTL, $clock );
		} catch ( \Throwable $failure ) {
			unset( $failure );
		}
		try {
			// Best effort: the bump reaches a client that honors tools/list_changed; the notice and the
			// live check cover the rest.
			AbilityRegistry::bump_surface_revision();
		} catch ( \Throwable $failure ) {
			unset( $failure );
		}
	}
}
