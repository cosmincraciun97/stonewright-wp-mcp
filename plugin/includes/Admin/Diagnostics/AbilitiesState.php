<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\Admin\Diagnostics;

use Stonewright\WpMcp\Admin\Setup\DomainLockCard;
use Stonewright\WpMcp\Admin\Setup\SetupTabs;
use Stonewright\WpMcp\Security\DomainLock;
use Stonewright\WpMcp\Security\PluginEffectiveState;

/**
 * Words for the effective state of Stonewright's abilities, shared by the Troubleshoot checks, the Setup preflight
 * and the Verify connection advice.
 *
 * The stored switch only records what the operator asked for. Whether abilities can really run is the effective
 * state (PluginEffectiveState), which the domain lock, a failed dependency or a host policy can block while the
 * switch stays on. Every surface that says "enabled" must take that state, not the option.
 */
final class AbilitiesState {

	/**
	 * What the effective state means for the user.
	 *
	 * @return array{state: string, effective: bool, summary: string, remedy: string}
	 */
	public static function current(): array {
		return self::describe( PluginEffectiveState::effective_state() );
	}

	/**
	 * @param string $state One of the PluginEffectiveState::STATE_* values.
	 * @return array{state: string, effective: bool, summary: string, remedy: string}
	 */
	public static function describe( string $state ): array {
		switch ( $state ) {
			case PluginEffectiveState::STATE_ENABLED:
				return [
					'state'     => $state,
					'effective' => true,
					'summary'   => __( 'Enabled.', 'stonewright' ),
					'remedy'    => '',
				];
			case PluginEffectiveState::STATE_BLOCKED_DOMAIN_MISMATCH:
				return [
					'state'     => $state,
					'effective' => false,
					'summary'   => __( 'Enabled in the settings, but blocked: the site address no longer matches the domain lock.', 'stonewright' ),
					'remedy'    => self::lock_remedy(),
				];
			case PluginEffectiveState::STATE_BLOCKED_DEPENDENCY:
				return [
					'state'     => $state,
					'effective' => false,
					'summary'   => __( 'Enabled in the settings, but blocked: a bundled library failed to load.', 'stonewright' ),
					'remedy'    => __( 'Reinstall Stonewright from the release ZIP so its bundled libraries are complete, then reload this page.', 'stonewright' ),
				];
			case PluginEffectiveState::STATE_BLOCKED_SECURITY_POLICY:
				return [
					'state'     => $state,
					'effective' => false,
					'summary'   => __( 'Enabled in the settings, but blocked by a host security policy.', 'stonewright' ),
					'remedy'    => __( 'Remove the stonewright_blocked_by_security_policy filter, or ask the host which policy applies, then reload this page.', 'stonewright' ),
				];
			default:
				return [
					'state'     => PluginEffectiveState::STATE_DISABLED_BY_OPERATOR,
					'effective' => false,
					'summary'   => __( 'Stonewright is turned off in the settings.', 'stonewright' ),
					'remedy'    => __( 'Enable Stonewright in step 1 and save the settings.', 'stonewright' ),
				];
		}
	}

	/** What to do about a domain lock mismatch: the two actions the Domain lock card offers. */
	public static function lock_remedy(): string {
		return __( 'In Setup, open Settings and use Review and rebind this site to accept the new address, or Restore prior domain binding to go back to the previous one.', 'stonewright' );
	}

	/**
	 * The locked and the current address, in words.
	 */
	public static function lock_summary(): string {
		$status = DomainLock::status();

		return sprintf(
			/* translators: 1: the address the site is locked to, 2: the address of the site now */
			__( 'The site address changed. Locked address: %1$s. Current address: %2$s. AI abilities are blocked until this is resolved.', 'stonewright' ),
			(string) $status['locked'],
			(string) $status['current']
		);
	}

	/** Where the Domain lock card lives in the admin. */
	public static function lock_url(): string {
		return SetupTabs::url( 'settings', [], DomainLockCard::ID );
	}
}
