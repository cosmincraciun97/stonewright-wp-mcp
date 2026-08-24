/**
 * Integration: permanent gateways exist with zero WordPress connectivity,
 * profiles cannot remove them, remote duplicates do not shadow locals, reconnect
 * coalesces and preserves prior registry on failure.
 */

import { describe, expect, it } from 'vitest';
import { readFileSync, writeFileSync } from 'node:fs';
import { createMcpServer } from '../../src/mcp-server.js';
import { PERMANENT_GATEWAY_TOOL_NAMES } from '../../src/connection/index.js';
import { NEVER_DISABLE_TOOL_NAMES, proxyToolNamesForProfile } from '../../src/wordpress-mcp.js';
import { APP_VERSION, companionPackageSpec } from '../../src/version.js';
import { verifyActiveClientRestartProof } from '../../src/connection/active-client-attestation.js';

function registeredToolNames(server: unknown): string[] {
	return Object.keys((server as { _registeredTools?: Record<string, unknown> })._registeredTools ?? {});
}

function toolHandler(server: unknown, name: string) {
	const tools = (server as { _registeredTools?: Record<string, { handler?: (input: unknown) => Promise<unknown> }> })._registeredTools ?? {};
	return tools[name]?.handler;
}

describe('permanent gateways integration', () => {
	it('registers all permanent gateways with zero WordPress connectivity', async () => {
		const fs = await import('node:fs');
		const os = await import('node:os');
		const path = await import('node:path');
		const stateDir = fs.mkdtempSync(path.join(os.tmpdir(), 'sw-offline-'));
		const server = await createMcpServer({
			env: {
				// No URL, no credentials — pure offline companion.
				// Isolate sites.json so a real machine profile cannot trigger REST probes.
				HOME: stateDir,
				STONEWRIGHT_HOME: stateDir,
				STONEWRIGHT_STATE_DIR: stateDir,
				STONEWRIGHT_SITES_FILE: path.join(stateDir, 'missing-sites.json'),
				STONEWRIGHT_MODE: 'plugin',
			},
			fetchImpl: () => Promise.reject(new Error('network offline')),
		});
		const names = registeredToolNames(server);
		for (const gateway of PERMANENT_GATEWAY_TOOL_NAMES) {
			expect(names).toContain(gateway);
		}

		const status = await toolHandler(server, 'stonewright-wordpress-mcp-status')?.({}) as {
			structuredContent?: {
				schema_version?: number;
				connected?: boolean;
				startup_ready?: boolean;
				connection_stage?: string;
			};
		};
		expect(status.structuredContent?.schema_version).toBe(2);
		expect(status.structuredContent?.connected).toBe(false);

		const taskStart = await toolHandler(server, 'stonewright-task-start')?.({
			task: 'offline recovery check',
		}) as { structuredContent?: { ok?: boolean; startup_ready?: boolean; registered_gateway_tools?: string[] } };
		expect(taskStart.structuredContent?.ok).toBe(true);
		expect(taskStart.structuredContent?.registered_gateway_tools).toEqual(
			expect.arrayContaining([...PERMANENT_GATEWAY_TOOL_NAMES]),
		);

		const ping = await toolHandler(server, 'stonewright-ping')?.({}) as {
			structuredContent?: { ok?: boolean; source?: string };
		};
		expect(ping.structuredContent?.ok).toBe(true);
		expect(ping.structuredContent?.source).toBe('local');
	});

	it('never disables permanent gateways via NEVER_DISABLE / companion ownership', () => {
		for (const gateway of PERMANENT_GATEWAY_TOOL_NAMES) {
			expect(NEVER_DISABLE_TOOL_NAMES.has(gateway)).toBe(true);
		}
	});

	it('keeps local gateways when remote exposes the same names', async () => {
		const server = await createMcpServer({
			env: {
				STONEWRIGHT_MCP_URL: 'https://example.com/wp-json/mcp/stonewright',
				WP_API_USERNAME: 'admin',
				WP_API_PASSWORD: 'pw',
				STONEWRIGHT_MCP_TOOL_PROFILE: 'essential-static',
			},
			fetchImpl: stonewrightMcpFetch([
				{ name: 'stonewright-task-start' },
				{ name: 'stonewright-tool-profile' },
				{ name: 'stonewright-ping' },
				{ name: 'stonewright-context-bootstrap' },
				{ name: 'stonewright-skills-get' },
				{ name: 'stonewright-php-execute' },
			]),
		});
		const names = registeredToolNames(server);
		// Local gateways present once (no dual registration crash).
		expect(names.filter((n) => n === 'stonewright-task-start')).toHaveLength(1);
		expect(names.filter((n) => n === 'stonewright-ping')).toHaveLength(1);
		expect(names).toContain('stonewright-php-execute');
		expect(names).toContain('stonewright-reconnect');
		expect(names).toContain('stonewright-connect-doctor');
	});

	it('plugin that exposes only ping becomes ready without losing task-start', async () => {
		const server = await createMcpServer({
			env: {
				STONEWRIGHT_MCP_URL: 'https://example.com/wp-json/mcp/stonewright',
				WP_API_USERNAME: 'admin',
				WP_API_PASSWORD: 'pw',
				STONEWRIGHT_MCP_TOOL_PROFILE: 'bootstrap',
			},
			fetchImpl: stonewrightMcpFetch([
				{ name: 'stonewright-ping' },
			]),
		});
		const names = registeredToolNames(server);
		expect(names).toContain('stonewright-task-start');
		expect(names).toContain('stonewright-ping');
		// Local ping gateway owns the name even if remote only exposed ping.
		const taskStart = await toolHandler(server, 'stonewright-task-start')?.({
			task: 'minimal plugin surface',
		}) as { structuredContent?: { ok?: boolean } };
		expect(taskStart.structuredContent?.ok).toBe(true);
	});

	it('client_has_tool is never true from counts alone', async () => {
		const server = await createMcpServer({
			env: {
				STONEWRIGHT_MCP_URL: 'https://example.com/wp-json/mcp/stonewright',
				WP_API_USERNAME: 'admin',
				WP_API_PASSWORD: 'pw',
				STONEWRIGHT_MCP_TOOL_PROFILE: 'full',
			},
			fetchImpl: stonewrightMcpFetch(
				Array.from({ length: 80 }, (_, i) => ({ name: `stonewright-tool-${i}` })).concat([
					{ name: 'stonewright-php-execute' },
					{ name: 'stonewright-context-bootstrap' },
					{ name: 'stonewright-skills-get' },
				]),
			),
		});
		const check = await toolHandler(server, 'stonewright-client-surface-check')?.({
			expected_tool: 'stonewright-php-execute',
		}) as { structuredContent?: { client_has_tool?: boolean; companion?: { remote_tool_count?: number } } };
		// Even with a large remote count, client_has_tool stays false without attestation/invocation.
		expect(check.structuredContent?.companion?.remote_tool_count).toBeGreaterThan(50);
		expect(check.structuredContent?.client_has_tool).toBe(false);

		const attested = await toolHandler(server, 'stonewright-client-surface-check')?.({
			expected_tool: 'stonewright-php-execute',
			observed_tool_names: ['stonewright-php-execute'],
		}) as { structuredContent?: { client_has_tool?: boolean } };
		expect(attested.structuredContent?.client_has_tool).toBe(true);

		// Permanent gateways report true via membership.
		const gatewayCheck = await toolHandler(server, 'stonewright-client-surface-check')?.({
			expected_tool: 'stonewright-task-start',
		}) as { structuredContent?: { client_has_tool?: boolean } };
		expect(gatewayCheck.structuredContent?.client_has_tool).toBe(true);
	});

	it('closes a pending restart only inside the active host after the required gateway sequence', async () => {
		const fs = await import('node:fs');
		const os = await import('node:os');
		const path = await import('node:path');
		const stateDir = fs.mkdtempSync(path.join(os.tmpdir(), 'sw-active-attest-'));
		const sitesFile = path.join(stateDir, 'sites.json');
		const expectedPackage = companionPackageSpec();
		writeFileSync(sitesFile, `${JSON.stringify({
			schema_version: 2,
			default_site_id: 'SITEA',
			sites: [{
				id: 'SITEA', alias: 'site-a', environment: 'development', canonical_url: 'https://site-a.example',
				url_fingerprint: 'sha256:test', username_hint: 'editor', credential_ref: 'env://SW_ACTIVE_ATTEST_PASSWORD',
				auth_method: 'application-password', configured_mode: 'plugin-only', preferred_active_mode: 'plugin',
				fallback_policy: 'never', companion_profile: 'bootstrap',
				clients: {
					codex: {
						server_name: 'stonewright-site-a',
						pending_restart: {
							receipt_id: 'receipt-active-host', attestation_challenge: 'challenge-active-host',
							created_at: '2026-08-24T00:00:00.000Z', status: 'restart-required', client: 'codex',
							expected_package: expectedPackage, expected_version: APP_VERSION,
							pre_restart_process_start_id: 'old-process', pre_restart_catalog_digest: 'sha256:old',
							config_before_sha256: 'sha256:before', config_after_sha256: 'sha256:after',
						},
					},
				},
			}],
		}, null, 2)}\n`, 'utf8');

		process.env.SW_ACTIVE_ATTEST_PASSWORD = 'example-password';
		const server = await createMcpServer({
			env: {
				HOME: stateDir,
				STONEWRIGHT_HOME: stateDir,
				STONEWRIGHT_STATE_DIR: stateDir,
				STONEWRIGHT_SITES_FILE: sitesFile,
				STONEWRIGHT_SITE_ALIAS: 'site-a',
				STONEWRIGHT_MODE: 'plugin',
				STONEWRIGHT_MCP_TOOL_PROFILE: 'bootstrap',
				SW_ACTIVE_ATTEST_PASSWORD: 'example-password',
			},
			fetchImpl: stonewrightMcpFetch(proxyToolNamesForProfile('bootstrap').map((name) => ({ name }))),
		});
		delete process.env.SW_ACTIVE_ATTEST_PASSWORD;

		const premature = await toolHandler(server, 'stonewright-client-surface-check')?.({
			expected_tool: 'stonewright-task-start',
		}) as { structuredContent?: { restart_attestation?: { status?: string; missing_calls?: string[] } } };
		expect(premature.structuredContent?.restart_attestation?.status).toBe('incomplete');
		expect(premature.structuredContent?.restart_attestation?.missing_calls).toEqual(expect.arrayContaining([
			'stonewright-task-start',
			'stonewright-setup-profile',
			'stonewright-wordpress-mcp-status',
		]));
		const pendingRegistry = JSON.parse(readFileSync(sitesFile, 'utf8')) as {
			sites: Array<{ clients: Record<string, { pending_restart?: unknown }> }>;
		};
		expect(pendingRegistry.sites[0].clients.codex.pending_restart).toBeDefined();

		await toolHandler(server, 'stonewright-task-start')?.({ task: 'verify active host restart' });
		await toolHandler(server, 'stonewright-setup-profile')?.({});
		const activeStatus = await toolHandler(server, 'stonewright-wordpress-mcp-status')?.({}) as {
			structuredContent?: { refresh_required_tool_names?: string[] };
		};
		expect(activeStatus.structuredContent?.refresh_required_tool_names).toEqual([]);
		const completed = await toolHandler(server, 'stonewright-client-surface-check')?.({
			expected_tool: 'stonewright-task-start',
			observed_tool_names: [...registeredToolNames(server), 'stonewright-not-registered'],
		}) as { structuredContent?: { restart_attestation?: { status?: string; attestation_digest?: string } } };

		expect(completed.structuredContent?.restart_attestation?.status).toBe('verified');
		expect(completed.structuredContent?.restart_attestation?.attestation_digest).toMatch(/^hmac-sha256:/);
		const registry = JSON.parse(readFileSync(sitesFile, 'utf8')) as {
			sites: Array<{ clients: Record<string, {
				pending_restart?: unknown;
				last_restart_proof?: {
					status?: string;
					attestation_scope?: string;
					receipt_id?: string;
					expected_package?: string;
					expected_version?: string;
					attestation_digest?: string;
					attestation_challenge?: string;
					observed_tool_names?: string[];
				};
			}> }>;
		};
		expect(registry.sites[0].clients.codex.pending_restart).toBeUndefined();
		const proof = registry.sites[0].clients.codex.last_restart_proof;
		expect(proof).toMatchObject({
			status: 'verified', attestation_scope: 'active-client', receipt_id: 'receipt-active-host',
			expected_package: expectedPackage, expected_version: APP_VERSION,
		});
		expect(proof?.attestation_digest).toMatch(/^hmac-sha256:/);
		expect(proof?.observed_tool_names).toEqual(expect.arrayContaining([
				'stonewright-task-start',
				'stonewright-setup-profile',
				'stonewright-wordpress-mcp-status',
				'stonewright-client-surface-check',
				'stonewright-ping',
		]));
		expect(proof?.observed_tool_names).not.toContain('stonewright-not-registered');
		expect(verifyActiveClientRestartProof(proof)).toBe(true);
		expect(verifyActiveClientRestartProof({ ...proof, catalog_digest: 'sha256:tampered' })).toBe(false);
		expect(verifyActiveClientRestartProof({ ...proof, status: 'restart-required' })).toBe(false);
		expect(JSON.stringify(completed)).not.toContain('challenge-active-host');
	});

	it('concurrent reconnect requests coalesce', async () => {
		const server = await createMcpServer({
			env: {
				STONEWRIGHT_MODE: 'direct',
				STONEWRIGHT_WP_URL: 'https://example.com',
				STONEWRIGHT_WP_USERNAME: 'admin',
				STONEWRIGHT_WP_APP_PASSWORD: 'pw',
			},
			fetchImpl: () => Promise.resolve(new Response('not found', { status: 404 })),
		});
		const reconnect = toolHandler(server, 'stonewright-reconnect');
		expect(reconnect).toBeTypeOf('function');
		const [a, b] = await Promise.all([
			reconnect?.({ reason: 'plugin activated' }),
			reconnect?.({ reason: 'plugin activated' }),
		]) as Array<{ structuredContent?: { ok?: boolean; error?: string | null; coalesced?: boolean; connection_generation?: number } }>;
		// At least one waiter should be marked coalesced when both ran concurrently.
		expect([a.structuredContent?.coalesced, b.structuredContent?.coalesced].filter(Boolean)).toHaveLength(1);
		expect([a.structuredContent?.coalesced, b.structuredContent?.coalesced]).toContain(false);
		expect(a.structuredContent?.ok).toBe(true);
		expect(b.structuredContent?.ok).toBe(true);
		expect(a.structuredContent?.error).toBeNull();
		expect(typeof a.structuredContent?.connection_generation).toBe('number');
	});

	it('does not invent authentication or WordPress reachability in unprobed Direct mode', async () => {
		const server = await createMcpServer({
			env: {
				STONEWRIGHT_MODE: 'direct',
				STONEWRIGHT_WP_URL: 'https://example.com',
				STONEWRIGHT_WP_USERNAME: 'admin',
			},
		});
		const status = await toolHandler(server, 'stonewright-wordpress-mcp-status')?.({}) as {
			structuredContent?: {
				authentication?: { configured?: boolean; method?: string };
				wordpress_runtime?: { reachable?: boolean | null };
			};
		};

		expect(status.structuredContent?.authentication).toEqual({ configured: false, method: 'none' });
		expect(status.structuredContent?.wordpress_runtime?.reachable).toBeNull();
	});

	it('status contract remains backward compatible (connected, startup_ready)', async () => {
		const server = await createMcpServer({
			env: {
				STONEWRIGHT_MCP_URL: 'https://example.com/wp-json/mcp/stonewright',
				WP_API_USERNAME: 'admin',
				WP_API_PASSWORD: 'pw',
			},
			fetchImpl: stonewrightMcpFetch([
				{ name: 'stonewright-context-bootstrap' },
				{ name: 'stonewright-skills-get' },
				{ name: 'stonewright-php-execute' },
			]),
		});
		const status = await toolHandler(server, 'stonewright-wordpress-mcp-status')?.({}) as {
			structuredContent?: {
				schema_version?: number;
				connected?: boolean;
				startup_ready?: boolean;
				ok?: boolean;
				surface_digest?: string;
				connection_stage?: string;
			};
		};
		expect(status.structuredContent?.schema_version).toBe(2);
		expect(typeof status.structuredContent?.connected).toBe('boolean');
		expect(typeof status.structuredContent?.startup_ready).toBe('boolean');
		expect(status.structuredContent?.surface_digest).toMatch(/^sha256:/);
		expect(status.structuredContent?.connection_stage).toBeTruthy();
	});

	it('mode-capabilities returns Direct vs Plugin comparison', async () => {
		const server = await createMcpServer({ env: {} });
		const result = await toolHandler(server, 'stonewright-mode-capabilities')?.({}) as {
			structuredContent?: { capabilities?: Array<{ capability: string }> };
		};
		const ids = (result.structuredContent?.capabilities ?? []).map((c) => c.capability);
		expect(ids).toEqual(expect.arrayContaining([
			'read_content',
			'elementor_writes',
			'confirmation_tokens',
		]));
	});

	it('connect-doctor returns one primary next_action', async () => {
		const server = await createMcpServer({ env: {} });
		const result = await toolHandler(server, 'stonewright-connect-doctor')?.({}) as {
			structuredContent?: { primary_next_action?: string; next_action?: string; schema_version?: number };
		};
		expect(result.structuredContent?.schema_version).toBe(2);
		expect(result.structuredContent?.primary_next_action || result.structuredContent?.next_action).toBeTruthy();
	});
});

function stonewrightMcpFetch(tools: Array<{ name: string; description?: string; inputSchema?: Record<string, unknown> }>): typeof fetch {
	return (_url: string | URL | Request, init?: RequestInit): Promise<Response> => {
		const url = String(_url);
		if (url.includes('/wp-json/stonewright/v1/skills')) {
			return Promise.resolve(new Response(JSON.stringify({ skills: [] }), {
				headers: { 'content-type': 'application/json' },
			}));
		}
		const body = JSON.parse(String(init?.body ?? '{}')) as {
			method?: string;
			params?: { name?: string; arguments?: Record<string, unknown> };
		};
		if (body.method === 'initialize') {
			return Promise.resolve(
				new Response(JSON.stringify({ jsonrpc: '2.0', id: 1, result: { protocolVersion: '2025-06-18' } }), {
					headers: { 'mcp-session-id': 'session-1', 'content-type': 'application/json' },
				}),
			);
		}
		if (body.method === 'notifications/initialized') {
			return Promise.resolve(new Response('', { status: 202 }));
		}
		if (body.method === 'tools/list') {
			return Promise.resolve(
				new Response(JSON.stringify({
					jsonrpc: '2.0',
					id: 2,
					result: {
						tools: tools.map((tool) => ({
							description: 'Proxied Stonewright test tool.',
							inputSchema: { type: 'object', properties: {} },
							...tool,
						})),
					},
				}), { headers: { 'content-type': 'application/json' } }),
			);
		}
		if (body.method === 'tools/call') {
			const name = body.params?.name ?? '';
			const structuredContent =
				name === 'stonewright-tool-profile'
					? {
						ok: true,
						tools: tools.map((t) => t.name),
						mcp_surface: 'essential-static',
						surface_revision: 1,
					}
					: name === 'stonewright-task-start'
						? { ok: true, mode: 'plugin', guidance: [] }
						: name === 'stonewright-ping'
							? { ok: true, pong: true }
							: { ok: true };
			return Promise.resolve(new Response(JSON.stringify({
				jsonrpc: '2.0',
				id: 3,
				result: {
					structuredContent,
					content: [{ type: 'text', text: JSON.stringify(structuredContent) }],
				},
			}), { headers: { 'content-type': 'application/json' } }));
		}
		return Promise.resolve(
			new Response(JSON.stringify({ jsonrpc: '2.0', id: 3, result: {} }), {
				headers: { 'content-type': 'application/json' },
			}),
		);
	};
}
