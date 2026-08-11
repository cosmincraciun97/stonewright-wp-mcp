import { createHash } from 'node:crypto';
import { describe, expect, it, vi } from 'vitest';
import {
	ConnectionStateMachine,
	PERMANENT_GATEWAY_TOOL_NAMES,
	RegistryBarrier,
	ReconnectController,
	SurfaceRevisionTracker,
	buildConnectionStatusV2,
	clientHasTool,
	computeRefreshRequiredToolNames,
	computeSurfaceDigest,
	defaultProxyProfileForClient,
	isPermanentGatewayTool,
	mapConfiguredMode,
	modeCapabilitiesComparison,
} from '../../src/connection/index.js';
import {
	coerceProxyToolProfile,
	proxyToolNamesForProfile,
	proxyToolProfileFromEnv,
} from '../../src/wordpress-mcp.js';

describe('permanent gateways', () => {
	it('lists the required permanent local gateway tools', () => {
		expect(PERMANENT_GATEWAY_TOOL_NAMES).toEqual(expect.arrayContaining([
			'stonewright-task-start',
			'stonewright-connect-doctor',
			'stonewright-wordpress-mcp-status',
			'stonewright-setup-profile',
			'stonewright-mode-capabilities',
			'stonewright-tool-profile',
			'stonewright-client-surface-check',
			'stonewright-reconnect',
			'stonewright-ping',
		]));
		expect(PERMANENT_GATEWAY_TOOL_NAMES).toHaveLength(9);
		for (const name of PERMANENT_GATEWAY_TOOL_NAMES) {
			expect(isPermanentGatewayTool(name)).toBe(true);
		}
	});
});

describe('surface digest + revision', () => {
	it('computes sha256 digest of sorted tool names', () => {
		const names = ['stonewright-b', 'stonewright-a'];
		const expected = `sha256:${createHash('sha256').update(['stonewright-a', 'stonewright-b'].join('\n')).digest('hex')}`;
		expect(computeSurfaceDigest(names)).toBe(expected);
		expect(computeSurfaceDigest(['stonewright-a', 'stonewright-b'])).toBe(expected);
	});

	it('tracks monotonic revision on catalog change', () => {
		const tracker = new SurfaceRevisionTracker();
		const first = tracker.commit(['stonewright-task-start']);
		expect(first.revision).toBe(1);
		expect(first.digest.startsWith('sha256:')).toBe(true);
		const same = tracker.commit(['stonewright-task-start']);
		expect(same.revision).toBe(1);
		expect(same.changed).toBe(false);
		const next = tracker.commit(['stonewright-task-start', 'stonewright-ping']);
		expect(next.revision).toBe(2);
		const forced = tracker.bump();
		expect(forced.revision).toBe(3);
	});
});

describe('connection state machine', () => {
	it('starts local-ready and reaches plugin-ready through registering', () => {
		const sm = new ConnectionStateMachine();
		expect(sm.getStage()).toBe('local-ready');
		expect(sm.isRegistryReady()).toBe(false);
		sm.transition('probing');
		sm.transition('plugin-authenticated');
		sm.transition('plugin-registering');
		expect(sm.isRegistryReady()).toBe(false);
		expect(sm.isConnectedDerived()).toBe(true);
		sm.transition('plugin-ready', { bumpGeneration: true });
		expect(sm.getStage()).toBe('plugin-ready');
		expect(sm.isRegistryReady()).toBe(true);
		expect(sm.getGeneration()).toBe(1);
	});
});

describe('registry barrier', () => {
	it('keeps startup not ready until commit and preserves prior catalog on abort', () => {
		const barrier = new RegistryBarrier();
		barrier.seedLocal([
			{ name: 'stonewright-task-start', source: 'local-gateway' },
			{ name: 'stonewright-ping', source: 'local-gateway' },
		]);
		expect(barrier.isReady).toBe(false);
		expect(barrier.getActiveNames()).toContain('stonewright-task-start');

		barrier.beginStaging();
		barrier.stage({ name: 'stonewright-php-execute', source: 'remote' });
		// Still not ready mid-register.
		expect(barrier.isReady).toBe(false);

		const committed = barrier.commit();
		expect(committed.ready).toBe(true);
		expect(committed.names).toEqual(expect.arrayContaining([
			'stonewright-task-start',
			'stonewright-php-execute',
		]));
		const gen = committed.generation;

		barrier.beginStaging();
		barrier.stage({ name: 'stonewright-evil', source: 'remote' });
		const aborted = barrier.abort('plugin unreachable');
		expect(aborted.ready).toBe(true);
		expect(aborted.generation).toBe(gen);
		expect(aborted.names).toContain('stonewright-php-execute');
		expect(aborted.names).not.toContain('stonewright-evil');
		expect(barrier.getLastFailure()).toBe('plugin unreachable');
	});

	it('local gateway names win over remote duplicates', () => {
		const barrier = new RegistryBarrier();
		barrier.seedLocal([{ name: 'stonewright-task-start', source: 'local-gateway' }]);
		barrier.beginStaging();
		barrier.stage({ name: 'stonewright-task-start', source: 'remote' });
		barrier.stage({ name: 'stonewright-content-get', source: 'remote' });
		const snap = barrier.commit();
		const taskStart = snap.entries.find((e) => e.name === 'stonewright-task-start');
		expect(taskStart?.source).toBe('local-gateway');
		expect(snap.names).toContain('stonewright-content-get');
	});
});

describe('reconnect singleflight', () => {
	it('coalesces concurrent reconnect requests into one transition', async () => {
		let runs = 0;
		const controller = new ReconnectController(async (input) => {
			runs += 1;
			await new Promise((r) => setTimeout(r, 30));
			return {
				ok: true,
				coalesced: false,
				reason: input.reason,
				site_alias: input.site_alias ?? null,
				force_probe: Boolean(input.force_probe),
				connection_generation: 2,
				surface_revision: 3,
				prior_registry_preserved: true,
				error: null,
			};
		});

		const [a, b, c] = await Promise.all([
			controller.reconnect({ reason: 'plugin activated' }),
			controller.reconnect({ reason: 'plugin activated' }),
			controller.reconnect({ reason: 'plugin activated' }),
		]);

		expect(runs).toBe(1);
		expect(a.coalesced).toBe(false);
		expect(b.coalesced).toBe(true);
		expect(c.coalesced).toBe(true);
		expect(a.connection_generation).toBe(2);
	});

	it('failed reconnect reports failure without inventing a new catalog', async () => {
		const controller = new ReconnectController(async () => ({
			ok: false,
			coalesced: false,
			reason: 'force',
			site_alias: null,
			force_probe: true,
			connection_generation: 1,
			surface_revision: 1,
			prior_registry_preserved: true,
			error: 'auth failed',
		}));
		const result = await controller.reconnect({ reason: 'force', force_probe: true });
		expect(result.ok).toBe(false);
		expect(result.prior_registry_preserved).toBe(true);
		expect(result.error).toBe('auth failed');
	});
});

describe('client_has_tool truthfulness', () => {
	it('never becomes true from counts alone', () => {
		// Simulate the anti-pattern: connected + remote_tool_count would have been enough before.
		const remoteToolCount = 80;
		const connected = true;
		const startupReady = true;
		void remoteToolCount;
		void connected;
		void startupReady;
		expect(clientHasTool('stonewright-php-execute', {})).toBe(false);
		expect(clientHasTool('stonewright-php-execute', {
			observedToolNames: null,
			invokedToolNames: new Set(),
		})).toBe(false);
	});

	it('is true for permanent gateway membership', () => {
		expect(clientHasTool('stonewright-task-start')).toBe(true);
		expect(clientHasTool('stonewright-reconnect')).toBe(true);
	});

	it('is true from observed_tool_names attestation or session invocation', () => {
		expect(clientHasTool('stonewright-php-execute', {
			observedToolNames: ['stonewright-php-execute'],
		})).toBe(true);
		expect(clientHasTool('stonewright-php-execute', {
			invokedToolNames: new Set(['stonewright-php-execute']),
		})).toBe(true);
	});
});

describe('refresh_required_tool_names', () => {
	it('is computed from requested profile vs registered names', () => {
		const requested = proxyToolNamesForProfile('essential-static');
		const registered = ['stonewright-task-start', 'stonewright-ping'];
		const missing = computeRefreshRequiredToolNames(requested, registered);
		expect(missing.length).toBeGreaterThan(0);
		expect(missing).not.toContain('stonewright-task-start');
		// Not a static hardcoded-only list: different registered sets change the result.
		const fuller = computeRefreshRequiredToolNames(requested, requested);
		expect(fuller).toEqual([]);
	});
});

describe('profile defaults', () => {
	it('defaults unset env and unknown clients to essential-static', () => {
		expect(proxyToolProfileFromEnv({})).toBe('essential-static');
		expect(coerceProxyToolProfile('essential-static')).toBe('essential-static');
		expect(defaultProxyProfileForClient(undefined)).toBe('essential-static');
		expect(defaultProxyProfileForClient('unknown-ide')).toBe('essential-static');
	});

	it('never selects full implicitly', () => {
		expect(proxyToolProfileFromEnv({})).not.toBe('full');
		expect(coerceProxyToolProfile('')).toBe('essential-static');
		expect(coerceProxyToolProfile('auto')).toBe('essential-static');
		expect(proxyToolNamesForProfile('essential-static').length).toBeGreaterThan(0);
	});

	it('keeps bootstrap available for explicit / dynamic clients', () => {
		expect(coerceProxyToolProfile('bootstrap')).toBe('bootstrap');
		expect(defaultProxyProfileForClient('claude-code', { allowBootstrapForDynamic: true })).toBe('bootstrap');
	});
});

describe('configured mode mapping', () => {
	it('maps env direct/plugin/auto to configured modes', () => {
		expect(mapConfiguredMode('direct')).toBe('direct-only');
		expect(mapConfiguredMode('plugin')).toBe('plugin-only');
		expect(mapConfiguredMode('auto')).toBe('auto');
		expect(mapConfiguredMode(undefined)).toBe('auto');
	});
});

describe('status contract v2', () => {
	it('includes schema_version 2 and derived connected field', () => {
		const status = buildConnectionStatusV2({
			configuredMode: 'auto',
			activeMode: 'plugin',
			connectionStage: 'plugin-ready',
			connectionGeneration: 1,
			authConfigured: true,
			plugin: {
				reachable: true,
				enabled_requested: true,
				effective_state: 'ready',
				registry_ready: true,
			},
			surface: {
				profile: 'essential-static',
				local_tool_count: 9,
				remote_tool_count: 12,
				registered_tool_count: 21,
				revision: 2,
				digest: 'sha256:abc',
				relist_required: false,
			},
			startupReady: true,
		});
		expect(status.schema_version).toBe(2);
		expect(status.connected).toBe(true);
		expect(status.startup_ready).toBe(true);
		expect(status.connection_stage).toBe('plugin-ready');
		expect(status.client_visibility.state).toBe('unverified');
	});

	it('mode-capabilities returns Direct vs Plugin comparison rows', () => {
		const rows = modeCapabilitiesComparison();
		const ids = rows.map((r) => r.capability);
		expect(ids).toEqual(expect.arrayContaining([
			'read_content',
			'typed_updates',
			'elementor_writes',
			'custom_code_apply',
			'backup',
			'confirmation_tokens',
		]));
	});
});

// Silence unused import if vi is only needed later.
void vi;
