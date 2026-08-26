import { describe, expect, it } from 'vitest';
import { buildConnectionStatusV3 } from '../../src/connection/status-contract.js';
import { createConnectionRuntime } from '../../src/connection/runtime.js';

const TOKEN_SHAPED_KEY = /^(access_token|refresh_token|authorization|password|cookie|bearer|app_password|secret|token)$/i;

function collectObjectKeys(value: unknown, keys: string[] = []): string[] {
	if (!value || typeof value !== 'object') return keys;
	for (const [key, child] of Object.entries(value as Record<string, unknown>)) {
		keys.push(key);
		collectObjectKeys(child, keys);
	}
	return keys;
}

const sampleAuthentication = {
	configured: true,
	method: 'app-password' as const,
	state: 'authenticated' as const,
	reason_code: null,
	last_success_at: null,
	refresh_expires_at: null,
	continuity_target_seconds: 604800 as const,
	agent_notice_required: false,
	user_action: null,
};

const sampleRecovery = {
	catalog_preserved: true,
	remote_calls_available: true,
	last_success_at: null,
	reconnect_attempted: false,
	reconnect_coalesced: false,
};

describe('connection status reconciliation', () => {
	it('reports saved and effective WordPress state separately from transport mode', () => {
		const status = buildConnectionStatusV3({
			configuredMode: 'plugin-only',
			activeMode: 'plugin',
			connectionStage: 'plugin-ready',
			connectionGeneration: 4,
			transportKind: 'stdio',
			authentication: sampleAuthentication,
			recovery: sampleRecovery,
			plugin: { reachable: true, enabled_requested: true, effective_state: 'ready', registry_ready: true },
			surface: {
				profile: 'full', local_tool_count: 16, remote_tool_count: 387, registered_tool_count: 403,
				revision: 8, digest: 'sha256:surface', relist_required: false,
			},
			startupReady: true,
			processStartId: 'process-123',
			catalogDigest: 'sha256:catalog',
			observedToolNames: ['stonewright-task-start'],
			reconciliation: {
				client_expected_wordpress_mode: 'development',
				client_expected_wp_surface: 'full',
				saved_wordpress_mode: 'development',
				effective_wordpress_mode: 'development',
				saved_wp_surface: 'full',
				effective_companion_profile: 'full',
				profile_source: 'site',
				mismatch_reason: null,
				mismatch_action: null,
			},
		});

		expect(status.transport.kind).toBe('stdio');
		expect(status.configured_mode).toBe('plugin-only');
		expect(status.process_start_id).toBe('process-123');
		expect(status.catalog_digest).toBe('sha256:catalog');
		expect(status.observed_tool_names).toEqual(['stonewright-task-start']);
		expect(status.reconciliation).toEqual({
			client_expected_wordpress_mode: 'development',
			client_expected_wp_surface: 'full',
			saved_wordpress_mode: 'development',
			effective_wordpress_mode: 'development',
			saved_wp_surface: 'full',
			effective_companion_profile: 'full',
			profile_source: 'site',
			mismatch_reason: null,
			mismatch_action: null,
		});
		expect(status.recovery.catalog_preserved).toBe(true);
		expect(status.recovery.remote_calls_available).toBe(true);
		expect(status.authentication.last_success_at).toBeNull();
		for (const key of collectObjectKeys(status)) {
			expect(key).not.toMatch(TOKEN_SHAPED_KEY);
		}
	});

	it('reports a client-lock mismatch with one concrete remediation', () => {
		const status = buildConnectionStatusV3({
			configuredMode: 'auto', activeMode: 'plugin', connectionStage: 'plugin-ready', connectionGeneration: 1,
			authentication: sampleAuthentication,
			recovery: sampleRecovery,
			plugin: { reachable: true, enabled_requested: true, effective_state: 'ready', registry_ready: true },
			surface: {
				profile: 'essential-static', local_tool_count: 16, remote_tool_count: 387,
				registered_tool_count: 89, revision: 3, digest: 'sha256:surface', relist_required: true,
			},
			startupReady: true,
			reconciliation: {
				client_expected_wordpress_mode: 'development', client_expected_wp_surface: 'full',
				saved_wordpress_mode: 'development', effective_wordpress_mode: 'development',
				saved_wp_surface: 'full', effective_companion_profile: 'essential-static',
				profile_source: 'client-lock', mismatch_reason: 'saved_surface_differs_from_effective_profile',
				mismatch_action: 'Remove the client profile lock or set it to full, then restart MCP.',
			},
		});

		expect(status.ok).toBe(false);
		expect(status.startup_ready).toBe(false);
		expect(status.reconciliation.mismatch_reason).toBe('saved_surface_differs_from_effective_profile');
		expect(status.reconciliation.mismatch_action).toMatch(/restart MCP/);
	});

	it('keeps client hints separate from authoritative site-saved state', () => {
		const runtime = createConnectionRuntime({
			env: {
				STONEWRIGHT_MODE: 'plugin',
				STONEWRIGHT_WORDPRESS_MODE: 'development',
				STONEWRIGHT_WORDPRESS_TOOL_SURFACE: 'full',
				STONEWRIGHT_MCP_TOOL_PROFILE: 'essential-static',
				STONEWRIGHT_MCP_TOOL_PROFILE_LOCK: '1',
			},
			profile: 'essential-static',
		});
		const locked = runtime.buildStatusV3();
		expect(locked.schema_version).toBe(3);
		expect(locked.recovery.catalog_preserved).toBe(true);
		expect(locked.recovery.remote_calls_available).toBe(false);
		expect(locked.authentication.last_success_at).toBeNull();
		for (const key of collectObjectKeys(locked)) {
			expect(key).not.toMatch(TOKEN_SHAPED_KEY);
		}
		expect(locked.reconciliation).toEqual(expect.objectContaining({
			client_expected_wordpress_mode: 'development',
			client_expected_wp_surface: 'full',
			saved_wordpress_mode: null,
			effective_wordpress_mode: null,
			saved_wp_surface: null,
			effective_companion_profile: 'essential-static',
			profile_source: 'client-lock',
			mismatch_reason: null,
		}));

		runtime.env.STONEWRIGHT_MCP_TOOL_PROFILE_LOCK = '0';
		runtime.savedWordPressMode = 'development';
		runtime.savedWordPressSurface = 'full';
		runtime.effectiveWordPressMode = 'development';
		runtime.effectiveWordPressSurface = 'full';
		const reconciled = runtime.buildStatusV3();
		expect(reconciled.reconciliation).toEqual(expect.objectContaining({
			effective_wordpress_mode: 'development',
			effective_companion_profile: 'full',
			profile_source: 'site',
			mismatch_reason: null,
			mismatch_action: null,
		}));
	});
});
