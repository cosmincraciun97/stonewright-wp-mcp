import type { ConfiguredMode, FallbackPolicy, PreferredActiveMode } from './types.js';
import type { StonewrightRuntimeMode } from '../../direct/mode.js';

/**
 * Map STONEWRIGHT_MODE env values onto persisted configured_mode.
 */
export function envModeToConfigured(raw: string | undefined): ConfiguredMode {
	const value = (raw ?? 'auto').trim().toLowerCase();
	if (value === 'direct' || value === 'direct-only') return 'direct-only';
	if (value === 'plugin' || value === 'plugin-only') return 'plugin-only';
	return 'auto';
}

/**
 * Map configured_mode onto the STONEWRIGHT_MODE value written into MCP client env.
 */
export function configuredModeToEnv(mode: ConfiguredMode): StonewrightRuntimeMode {
	if (mode === 'direct-only') return 'direct';
	if (mode === 'plugin-only') return 'plugin';
	return 'auto';
}

export function defaultFallbackPolicy(mode: ConfiguredMode): FallbackPolicy {
	if (mode === 'direct-only') return 'always-direct';
	if (mode === 'plugin-only') return 'never';
	return 'direct-when-plugin-unavailable';
}

export function defaultPreferredActiveMode(mode: ConfiguredMode): PreferredActiveMode {
	if (mode === 'direct-only') return 'direct';
	return 'plugin';
}

/**
 * Decide whether the companion may probe the plugin MCP endpoint for this site.
 * direct-only: never probe.
 * plugin-only / auto: probe allowed.
 */
export function mayProbePlugin(mode: ConfiguredMode): boolean {
	return mode !== 'direct-only';
}

/**
 * Decide whether falling back to Direct is allowed when plugin is unavailable.
 */
export function mayFallbackToDirect(mode: ConfiguredMode, policy: FallbackPolicy): boolean {
	if (mode === 'direct-only') return true;
	if (mode === 'plugin-only') return false;
	if (policy === 'never') return false;
	if (policy === 'always-direct') return true;
	return true; // direct-when-plugin-unavailable
}

/**
 * Resolve the active runtime mode from site policy + optional probe result.
 * probePresent: true = plugin endpoint present, false = 404, null = not probed / unknown.
 */
export function resolveActiveMode(args: {
	configured: ConfiguredMode;
	fallbackPolicy: FallbackPolicy;
	probePresent?: boolean | null;
}): { active: PreferredActiveMode; reason: string; transition?: string } {
	const { configured, fallbackPolicy, probePresent } = args;

	if (configured === 'direct-only') {
		return {
			active: 'direct',
			reason: 'configured_mode=direct-only; plugin probe skipped',
		};
	}

	if (configured === 'plugin-only') {
		if (probePresent === false) {
			return {
				active: 'plugin',
				reason: 'configured_mode=plugin-only; plugin unavailable — fail closed (no Direct fallback)',
			};
		}
		return {
			active: 'plugin',
			reason:
				probePresent === true
					? 'configured_mode=plugin-only; plugin endpoint ready'
					: 'configured_mode=plugin-only; probe skipped or inconclusive — prefer plugin',
		};
	}

	// auto
	if (probePresent === true) {
		return {
			active: 'plugin',
			reason: 'configured_mode=auto; plugin endpoint present',
		};
	}
	if (probePresent === false) {
		if (mayFallbackToDirect(configured, fallbackPolicy)) {
			return {
				active: 'direct',
				reason: 'configured_mode=auto; plugin unavailable — falling back to Direct',
				transition: 'plugin→direct',
			};
		}
		return {
			active: 'plugin',
			reason: 'configured_mode=auto; plugin unavailable but fallback_policy forbids Direct',
		};
	}
	return {
		active: 'plugin',
		reason: 'configured_mode=auto; probe not run — prefer plugin until proven unavailable',
	};
}
