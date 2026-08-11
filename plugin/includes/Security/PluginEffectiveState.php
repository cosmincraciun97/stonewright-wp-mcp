<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\Security;

use Stonewright\WpMcp\Core\VendorGuard;

/**
 * Separates operator enablement intent from runtime effective state.
 *
 * Operator intent lives in `stonewright_enabled` and must never be rewritten by
 * domain-lock mismatch, dependency failure, or security-policy blocks.
 *
 * @stonewright-status stable
 */
final class PluginEffectiveState {

	public const OPTION_REQUESTED = 'stonewright_enabled';

	public const STATE_ENABLED                 = 'enabled';
	public const STATE_DISABLED_BY_OPERATOR    = 'disabled_by_operator';
	public const STATE_BLOCKED_DOMAIN_MISMATCH = 'blocked_domain_mismatch';
	public const STATE_BLOCKED_DEPENDENCY      = 'blocked_dependency';
	public const STATE_BLOCKED_SECURITY_POLICY = 'blocked_security_policy';

	/**
	 * Handlers allowed to change operator enablement intent.
	 *
	 * Contract test pins this list so domain lock / boot paths never mutate
	 * the option as a side effect of mismatch detection.
	 *
	 * @var list<string>
	 */
	public const ENABLEMENT_WRITERS = [
		'Stonewright\\WpMcp\\Admin\\AdminBarIndicator::apply_toggle',
		'Stonewright\\WpMcp\\Admin\\ConfigurationPage::register_settings',
	];

	/**
	 * Operator-saved intent: whether abilities should be on when nothing blocks them.
	 */
	public static function enabled_requested(): bool {
		return (bool) get_option( self::OPTION_REQUESTED, false );
	}

	/**
	 * Persist operator intent. Call only from ENABLEMENT_WRITERS.
	 */
	public static function set_enabled_requested( bool $enabled ): void {
		update_option( self::OPTION_REQUESTED, $enabled );
	}

	/**
	 * Runtime state used for ability registration, remote MCP, and admin chrome.
	 *
	 * @return self::STATE_*
	 */
	public static function effective_state(): string {
		if ( ! self::enabled_requested() ) {
			return self::STATE_DISABLED_BY_OPERATOR;
		}

		if ( ! DomainLock::check() ) {
			return self::STATE_BLOCKED_DOMAIN_MISMATCH;
		}

		if ( null !== VendorGuard::get_error() ) {
			return self::STATE_BLOCKED_DEPENDENCY;
		}

		if ( self::blocked_by_security_policy() ) {
			return self::STATE_BLOCKED_SECURITY_POLICY;
		}

		return self::STATE_ENABLED;
	}

	/**
	 * True only when abilities may actually run / register (beyond ping).
	 */
	public static function is_effectively_enabled(): bool {
		return self::STATE_ENABLED === self::effective_state();
	}

	/**
	 * Compact export for admin UI and connection diagnostics.
	 *
	 * @return array{
	 *   enabled_requested: bool,
	 *   effective_state: string,
	 *   effectively_enabled: bool,
	 *   domain_lock: array<string, mixed>
	 * }
	 */
	public static function snapshot(): array {
		return [
			'enabled_requested'    => self::enabled_requested(),
			'effective_state'      => self::effective_state(),
			'effectively_enabled'  => self::is_effectively_enabled(),
			'domain_lock'          => DomainLock::status(),
		];
	}

	/**
	 * Host-level hard stop (e.g. DISALLOW_FILE_MODS is not used here — that
	 * only gates sandbox file writes). Reserved for future security policy
	 * options; currently returns false so policy does not silently disable.
	 */
	private static function blocked_by_security_policy(): bool {
		/**
		 * Filter whether Stonewright is blocked by a host security policy even
		 * when the operator requested enablement and the domain matches.
		 *
		 * @param bool $blocked Whether the runtime should stay blocked.
		 */
		return (bool) apply_filters( 'stonewright_blocked_by_security_policy', false );
	}
}
