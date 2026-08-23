import { describe, expect, it } from 'vitest';
import { buildConnectionStatusV2 } from '../../src/connection/status-contract.js';
import { createConnectionRuntime } from '../../src/connection/runtime.js';

describe('connection status reconciliation', () => {
	it('reports saved and effective WordPress state separately from transport mode', () => {
		const status = buildConnectionStatusV2({
			configuredMode: 'plugin-only',
			activeMode: 'plugin',
			connectionStage: 'plugin-ready',
			connectionGeneration: 4,
			transportKind: 'stdio',
			authConfigured: true,
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
			saved_wordpress_mode: 'development',
			effective_wordpress_mode: 'development',
			saved_wp_surface: 'full',
			effective_companion_profile: 'full',
			profile_source: 'site',
			mismatch_reason: null,
			mismatch_action: null,
		});
	});

	it('reports a client-lock mismatch with one concrete remediation', () => {
		const status = buildConnectionStatusV2({
			configuredMode: 'auto', activeMode: 'plugin', connectionStage: 'plugin-ready', connectionGeneration: 1,
			authConfigured: true,
			plugin: { reachable: true, enabled_requested: true, effective_state: 'ready', registry_ready: true },
			surface: {
				profile: 'essential-static', local_tool_count: 16, remote_tool_count: 387,
				registered_tool_count: 89, revision: 3, digest: 'sha256:surface', relist_required: true,
			},
			startupReady: true,
			reconciliation: {
				saved_wordpress_mode: 'development', effective_wordpress_mode: 'development',
				saved_wp_surface: 'full', effective_companion_profile: 'essential-static',
				profile_source: 'client-lock', mismatch_reason: 'saved_surface_differs_from_effective_profile',
				mismatch_action: 'Remove the client profile lock or set it to full, then restart MCP.',
			},
		});

		expect(status.ok).toBe(true);
		expect(status.reconciliation.mismatch_reason).toBe('saved_surface_differs_from_effective_profile');
		expect(status.reconciliation.mismatch_action).toMatch(/restart MCP/);
	});

	it('derives saved state from client config and updates effective state only from live evidence', () => {
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
		const locked = runtime.buildStatusV2();
		expect(locked.reconciliation).toEqual(expect.objectContaining({
			saved_wordpress_mode: 'development',
			effective_wordpress_mode: null,
			saved_wp_surface: 'full',
			effective_companion_profile: 'essential-static',
			profile_source: 'client-lock',
			mismatch_reason: 'saved_surface_differs_from_effective_profile',
		}));

		runtime.env.STONEWRIGHT_MCP_TOOL_PROFILE_LOCK = '0';
		runtime.effectiveWordPressMode = 'development';
		runtime.effectiveWordPressSurface = 'full';
		const reconciled = runtime.buildStatusV2();
		expect(reconciled.reconciliation).toEqual(expect.objectContaining({
			effective_wordpress_mode: 'development',
			effective_companion_profile: 'full',
			profile_source: 'site',
			mismatch_reason: null,
			mismatch_action: null,
		}));
	});
});
