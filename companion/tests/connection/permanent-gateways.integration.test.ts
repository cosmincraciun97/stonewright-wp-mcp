/**
 * Integration: permanent gateways exist with zero WordPress connectivity,
 * profiles cannot remove them, remote duplicates do not shadow locals, reconnect
 * coalesces and preserves prior registry on failure.
 */

import { createHmac } from 'node:crypto';
import { describe, expect, it, vi } from 'vitest';
import { mkdirSync, mkdtempSync, readFileSync, writeFileSync } from 'node:fs';
import { tmpdir } from 'node:os';
import { join } from 'node:path';
import { createMcpServer } from '../../src/mcp-server.js';
import { PERMANENT_GATEWAY_TOOL_NAMES } from '../../src/connection/index.js';
import { NEVER_DISABLE_TOOL_NAMES, proxyToolNamesForProfile } from '../../src/wordpress-mcp.js';
import { APP_VERSION, companionPackageSpec } from '../../src/version.js';
import { verifyActiveClientRestartProof } from '../../src/connection/active-client-attestation.js';
import { sha256Text } from '../../src/cli/clients/package-reference.js';

function registeredToolNames(server: unknown): string[] {
	return Object.keys((server as { _registeredTools?: Record<string, unknown> })._registeredTools ?? {});
}

function toolHandler(server: unknown, name: string) {
	const tools = (server as { _registeredTools?: Record<string, { handler?: (input: unknown) => Promise<unknown> }> })._registeredTools ?? {};
	return tools[name]?.handler;
}

function setMcpClientIdentity(server: unknown, name: string): void {
	const protocol = (server as { server?: { _clientVersion?: { name: string; version: string } } }).server;
	if (protocol) protocol._clientVersion = { name, version: 'test-client-1.0.0' };
}

function catalogObservationFromToolList(server: unknown): string {
	const tools = (server as {
		_registeredTools?: Record<string, { description?: string }>;
	})._registeredTools ?? {};
	const description = tools['stonewright-client-surface-check']?.description ?? '';
	const match = /catalog_observation=([A-Za-z0-9_-]{32,})/.exec(description);
	expect(match, 'client-surface-check must expose a process-bound observation in its tools/list description').not.toBeNull();
	return match?.[1] ?? '';
}

function workflowPreflightPayload(overrides: Record<string, unknown> = {}): Record<string, unknown> {
	return {
		schema_version: 2,
		ok: true,
		context_token: 'synthetic-context-token',
		expires_at: new Date(Date.now() + 60_000).toISOString(),
		mode: 'development',
		saved_wordpress_mode: 'development',
		effective_wordpress_mode: 'development',
		configured_mcp_surface: 'bootstrap',
		surface_revision: 1,
		session_tool_profile: 'bootstrap',
		session_profile_applied: false,
		session_profile_reason: 'bootstrap_profile_needs_no_expansion',
		tool_profile: 'bootstrap',
		tools_changed: false,
		re_list_instruction: '',
		auth_guidance: [],
		fast_path: { tool_profile: { profile: 'bootstrap' } },
		guidance: [],
		...overrides,
	};
}

function activeAttestationFixture(options: {
	client?: string;
	expiresAt?: string;
	packageSpec?: string;
	configText?: string;
} = {}) {
	const stateDir = mkdtempSync(join(tmpdir(), 'sw-active-attest-'));
	const sitesFile = join(stateDir, 'sites.json');
	const client = options.client ?? 'codex';
	const expectedPackage = options.packageSpec ?? companionPackageSpec();
	const configPath = client === 'codex'
		? join(stateDir, '.codex', 'config.toml')
		: join(stateDir, `.${client}`, 'mcp.json');
	const configText = options.configText ?? (client === 'codex'
		? `[mcp_servers.stonewright-site-a]\ncommand = "npx"\nargs = ["-y", "--package", "${expectedPackage}", "stonewright-mcp"]\n`
		: `${JSON.stringify({
			mcpServers: {
				'stonewright-site-a': {
					command: 'npx',
					args: ['-y', '--package', expectedPackage, 'stonewright-mcp'],
				},
			},
		}, null, 2)}\n`);
	mkdirSync(join(configPath, '..'), { recursive: true });
	writeFileSync(configPath, configText, 'utf8');
	const receipt = {
		receipt_id: 'receipt-active-host',
		created_at: new Date(Date.now() - 1_000).toISOString(),
		expires_at: options.expiresAt ?? new Date(Date.now() + 60_000).toISOString(),
		status: 'restart-required',
		client,
		expected_package: expectedPackage,
		expected_package_provenance: 'github-release',
		expected_version: APP_VERSION,
		pre_restart_process_start_id: 'old-process',
		pre_restart_catalog_digest: 'sha256:old',
		config_before_sha256: 'sha256:before',
		config_after_sha256: sha256Text(configText),
	};
	writeFileSync(sitesFile, `${JSON.stringify({
		schema_version: 2,
		default_site_id: 'SITEA',
		sites: [{
			id: 'SITEA', alias: 'site-a', environment: 'development', canonical_url: 'https://site-a.example',
			url_fingerprint: 'sha256:test', username_hint: 'editor', credential_ref: 'env://SW_ACTIVE_ATTEST_PASSWORD',
			auth_method: 'application-password', configured_mode: 'plugin-only', preferred_active_mode: 'plugin',
			fallback_policy: 'never', companion_profile: 'bootstrap',
			clients: {
				[client]: {
					server_name: 'stonewright-site-a',
					config_path: configPath,
					restart_attestation_key: 'private-registry-key-never-exported',
					pending_restart: receipt,
				},
			},
		}],
	}, null, 2)}\n`, 'utf8');
	return { stateDir, sitesFile, configPath, configText, expectedPackage, receipt, client };
}

async function activeAttestationServer(
	fixture: ReturnType<typeof activeAttestationFixture>,
	mcpClient = 'Codex',
	taskStartResponse?: Record<string, unknown>,
) {
	process.env.SW_ACTIVE_ATTEST_PASSWORD = 'example-password';
	const server = await createMcpServer({
		env: {
			HOME: fixture.stateDir,
			STONEWRIGHT_HOME: fixture.stateDir,
			STONEWRIGHT_STATE_DIR: fixture.stateDir,
			STONEWRIGHT_SITES_FILE: fixture.sitesFile,
			STONEWRIGHT_SITE_ALIAS: 'site-a',
			STONEWRIGHT_MODE: 'plugin',
			STONEWRIGHT_MCP_TOOL_PROFILE: 'bootstrap',
			STONEWRIGHT_MCP_URL: 'https://example.com/wp-json/mcp/stonewright',
			STONEWRIGHT_WP_URL: 'https://example.com',
			STONEWRIGHT_WP_USERNAME: 'editor',
			STONEWRIGHT_WP_APP_PASSWORD: 'example-password',
			SW_ACTIVE_ATTEST_PASSWORD: 'example-password',
		},
		fetchImpl: stonewrightMcpFetch(
			proxyToolNamesForProfile('bootstrap').map((name) => ({ name })),
			{ taskStartResponse },
		),
	});
	delete process.env.SW_ACTIVE_ATTEST_PASSWORD;
	setMcpClientIdentity(server, mcpClient);
	return server;
}

async function completeRequiredAttestationSequence(server: unknown) {
	await toolHandler(server, 'stonewright-task-start')?.({ task: 'verify active host restart' });
	await toolHandler(server, 'stonewright-setup-profile')?.({
		siteUrl: 'https://example.com', username: 'editor', appPassword: 'example-password',
	});
	await toolHandler(server, 'stonewright-wordpress-mcp-status')?.({});
	return toolHandler(server, 'stonewright-client-surface-check')?.({
		expected_tool: 'stonewright-task-start',
		catalog_observation: catalogObservationFromToolList(server),
	});
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
		expect(status.structuredContent?.schema_version).toBe(3);
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

	it('client_has_tool is never true from counts or caller-supplied names alone', async () => {
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
		expect(attested.structuredContent?.client_has_tool).toBe(false);

		// Permanent gateways report true via membership.
		const gatewayCheck = await toolHandler(server, 'stonewright-client-surface-check')?.({
			expected_tool: 'stonewright-task-start',
		}) as { structuredContent?: { client_has_tool?: boolean } };
		expect(gatewayCheck.structuredContent?.client_has_tool).toBe(true);
	});

	it('does not translate companion site_alias into the plugin task-start schema', async () => {
		let remoteArgs: Record<string, unknown> | undefined;
		const server = await createMcpServer({
			env: {
				STONEWRIGHT_MCP_URL: 'https://example.com/wp-json/mcp/stonewright',
				WP_API_USERNAME: 'admin',
				WP_API_PASSWORD: 'pw',
				STONEWRIGHT_MCP_TOOL_PROFILE: 'essential-static',
			},
			fetchImpl: stonewrightMcpFetch(
				proxyToolNamesForProfile('essential-static').map((name) => ({ name })),
				{ onTaskStart: (args) => { remoteArgs = args; } },
			),
		});

		const result = await toolHandler(server, 'stonewright-task-start')?.({
			task: 'inspect schema translation', intent: 'read-only', site_alias: 'site-a', surface: 'essential',
		}) as { structuredContent?: { ok?: boolean } };

		expect(result.structuredContent?.ok).toBe(true);
		expect(remoteArgs).toEqual({ task: 'inspect schema translation', intent: 'read-only', surface: 'essential' });
	});

	it('preserves plugin task-start failure instead of reporting false success', async () => {
		const server = await createMcpServer({
			env: {
				STONEWRIGHT_MCP_URL: 'https://example.com/wp-json/mcp/stonewright',
				WP_API_USERNAME: 'admin',
				WP_API_PASSWORD: 'pw',
			},
			fetchImpl: stonewrightMcpFetch([{ name: 'stonewright-task-start' }], {
				taskStartResponse: {
					ok: false,
					error_code: 'invalid_input',
					message: 'site is not a valid property of the object.',
				},
			}),
		});

		const result = await toolHandler(server, 'stonewright-task-start')?.({ task: 'invalid remote request' }) as {
			structuredContent?: { ok?: boolean; startup_ready?: boolean; error_code?: string };
		};

		expect(result.structuredContent).toEqual(expect.objectContaining({
			ok: false,
			startup_ready: false,
			error_code: 'invalid_input',
		}));
	});

	it('preserves a plugin JSON-RPC task-start rejection instead of falling back to local success', async () => {
		const stateDir = mkdtempSync(join(tmpdir(), 'sw-task-start-reject-'));
		const server = await createMcpServer({
			env: {
				HOME: stateDir,
				STONEWRIGHT_HOME: stateDir,
				STONEWRIGHT_STATE_DIR: stateDir,
				STONEWRIGHT_SITES_FILE: join(stateDir, 'missing-sites.json'),
				STONEWRIGHT_MCP_URL: 'https://example.com/wp-json/mcp/stonewright',
				WP_API_USERNAME: 'admin',
				WP_API_PASSWORD: 'pw',
			},
			fetchImpl: stonewrightMcpFetch([{ name: 'stonewright-task-start' }], {
				taskStartError: {
					code: -32602,
					message: 'Invalid task-start input.',
				},
			}),
		});

		const result = await toolHandler(server, 'stonewright-task-start')?.({ task: 'rejected remote request' }) as {
			structuredContent?: { ok?: boolean; startup_ready?: boolean; error_code?: string; next_action?: string };
		};

		expect(result.structuredContent).toEqual(expect.objectContaining({
			ok: false,
			startup_ready: false,
			error_code: 'plugin_task_start_failed',
		}));
		expect(result.structuredContent?.next_action).toContain('Fix the plugin task-start failure');
	});

	it('preserves an MCP isError task-start result instead of treating error content as success', async () => {
		const stateDir = mkdtempSync(join(tmpdir(), 'sw-task-start-is-error-'));
		const server = await createMcpServer({
			env: {
				HOME: stateDir,
				STONEWRIGHT_HOME: stateDir,
				STONEWRIGHT_STATE_DIR: stateDir,
				STONEWRIGHT_SITES_FILE: join(stateDir, 'missing-sites.json'),
				STONEWRIGHT_MCP_URL: 'https://example.com/wp-json/mcp/stonewright',
				WP_API_USERNAME: 'admin',
				WP_API_PASSWORD: 'pw',
			},
			fetchImpl: stonewrightMcpFetch([{ name: 'stonewright-task-start' }], {
				taskStartIsError: true,
			}),
		});

		const result = await toolHandler(server, 'stonewright-task-start')?.({ task: 'invalid MCP tool input' }) as {
			structuredContent?: { ok?: boolean; startup_ready?: boolean; error_code?: string };
		};

		expect(result.structuredContent).toEqual(expect.objectContaining({
			ok: false,
			startup_ready: false,
			error_code: 'plugin_task_start_failed',
		}));
	});

	it('preserves a malformed plugin task-start result as an authoritative failure', async () => {
		const stateDir = mkdtempSync(join(tmpdir(), 'sw-task-start-malformed-'));
		const server = await createMcpServer({
			env: {
				HOME: stateDir,
				STONEWRIGHT_HOME: stateDir,
				STONEWRIGHT_STATE_DIR: stateDir,
				STONEWRIGHT_SITES_FILE: join(stateDir, 'missing-sites.json'),
				STONEWRIGHT_MCP_URL: 'https://example.com/wp-json/mcp/stonewright',
				WP_API_USERNAME: 'admin',
				WP_API_PASSWORD: 'pw',
			},
			fetchImpl: stonewrightMcpFetch([{ name: 'stonewright-task-start' }], {
				taskStartResponse: { guidance: [] },
			}),
		});

		const result = await toolHandler(server, 'stonewright-task-start')?.({ task: 'malformed remote response' }) as {
			structuredContent?: { ok?: boolean; startup_ready?: boolean; error_code?: string };
		};

		expect(result.structuredContent).toEqual(expect.objectContaining({
			ok: false,
			startup_ready: false,
			error_code: 'plugin_task_start_failed',
		}));
	});

	it('uses versioned WorkflowPreflight saved and effective modes to gate startup on mismatch', async () => {
		const server = await createMcpServer({
			env: {
				STONEWRIGHT_MCP_URL: 'https://example.com/wp-json/mcp/stonewright',
				WP_API_USERNAME: 'admin',
				WP_API_PASSWORD: 'pw',
			},
			fetchImpl: stonewrightMcpFetch([{ name: 'stonewright-task-start' }], {
				taskStartResponse: workflowPreflightPayload({
					mode: 'staging',
					saved_wordpress_mode: 'staging',
					effective_wordpress_mode: 'production-safe',
				}),
			}),
		});

		const result = await toolHandler(server, 'stonewright-task-start')?.({ task: 'verify real plugin mode' }) as {
			structuredContent?: {
				ok?: boolean;
				startup_ready?: boolean;
				error_code?: string;
				reconciliation?: { mismatch_reason?: string };
			};
		};

		expect(result.structuredContent).toEqual(expect.objectContaining({
			ok: false,
			startup_ready: false,
			error_code: 'wordpress_reconciliation_mismatch',
		}));
		expect(result.structuredContent?.reconciliation?.mismatch_reason).toBe('saved_mode_differs_from_effective_mode');
	});

	it('rejects an unsupported WorkflowPreflight schema before accepting plugin mode', async () => {
		const server = await createMcpServer({
			env: {
				STONEWRIGHT_MCP_URL: 'https://example.com/wp-json/mcp/stonewright',
				WP_API_USERNAME: 'admin',
				WP_API_PASSWORD: 'pw',
			},
			fetchImpl: stonewrightMcpFetch([{ name: 'stonewright-task-start' }], {
				taskStartResponse: workflowPreflightPayload({ schema_version: 99 }),
			}),
		});

		const result = await toolHandler(server, 'stonewright-task-start')?.({ task: 'reject future schema' }) as {
			structuredContent?: { ok?: boolean; startup_ready?: boolean; error_code?: string };
		};

		expect(result.structuredContent).toEqual(expect.objectContaining({
			ok: false,
			startup_ready: false,
			error_code: 'plugin_workflow_preflight_schema_unsupported',
		}));
	});

	it('reports authoritative full plugin surface and gates a stale client catalog despite an empty refresh list', async () => {
		const remoteTools = Array.from({ length: 378 }, (_, index) => ({ name: `stonewright-synthetic-${index}` }));
		remoteTools.splice(0, 3,
			{ name: 'stonewright-task-start' },
			{ name: 'stonewright-context-bootstrap' },
			{ name: 'stonewright-skills-get' },
		);
		remoteTools.push({ name: 'stonewright-elementor-v3-container-schema' });
		const server = await createMcpServer({
			env: {
				STONEWRIGHT_MCP_URL: 'https://example.com/wp-json/mcp/stonewright',
				WP_API_USERNAME: 'admin',
				WP_API_PASSWORD: 'pw',
				STONEWRIGHT_MCP_TOOL_PROFILE: 'essential-static',
			},
			fetchImpl: stonewrightMcpFetch(remoteTools, {
				taskStartResponse: workflowPreflightPayload({
					configured_mcp_surface: 'full',
					session_tool_profile: 'full',
					tools_changed: true,
					surface_revision: 9,
				}),
			}),
		});
		const preRefreshObservation = catalogObservationFromToolList(server);

		const taskStart = await toolHandler(server, 'stonewright-task-start')?.({ task: 'use full plugin surface' }) as {
			structuredContent?: {
				ok?: boolean; startup_ready?: boolean; error_code?: string; next_action?: string; refresh_required_tool_names?: string[];
				surface?: { profile?: string; remote_tool_count?: number; registered_tool_count?: number; relist_required?: boolean };
				reconciliation?: Record<string, unknown>;
			};
		};

		expect(taskStart.structuredContent?.surface).toEqual(expect.objectContaining({
			profile: 'full', remote_tool_count: 379, relist_required: true,
		}));
		expect(taskStart.structuredContent?.surface?.registered_tool_count).toBeGreaterThan(0);
		expect(taskStart.structuredContent?.refresh_required_tool_names).toEqual([]);
		expect(taskStart.structuredContent?.reconciliation).toEqual(expect.objectContaining({
			saved_wordpress_mode: 'development',
			saved_wp_surface: 'full',
			effective_wordpress_mode: 'development',
			effective_companion_profile: 'full',
		}));
		expect(taskStart.structuredContent).toEqual(expect.objectContaining({
			ok: false, startup_ready: false, error_code: 'client_catalog_relist_required',
		}));
		expect(catalogObservationFromToolList(server)).not.toBe(preRefreshObservation);

		const status = await toolHandler(server, 'stonewright-wordpress-mcp-status')?.({}) as {
			structuredContent?: {
				ok?: boolean; startup_ready?: boolean; error_code?: string; refresh_required_tool_names?: string[];
				tool_profile?: string; live_tool_profile?: string; proxied_tool_count?: number;
				live_enabled_tool_count?: number;
				surface?: { profile?: string; remote_tool_count?: number; relist_required?: boolean };
			};
		};
		expect(status.structuredContent).toEqual(expect.objectContaining({
			ok: false,
			startup_ready: false,
			error_code: 'client_catalog_relist_required',
			refresh_required_tool_names: [],
			tool_profile: 'full',
			live_tool_profile: 'full',
		}));
		expect(status.structuredContent?.surface).toEqual(expect.objectContaining({
			profile: 'full',
			remote_tool_count: 379,
			relist_required: true,
		}));
		expect(status.structuredContent?.proxied_tool_count).toBe(status.structuredContent?.live_enabled_tool_count);
		expect(status.structuredContent?.next_action).toBe(
			'Re-list tools and present the current catalog observation to stonewright-client-surface-check.',
		);

		const surface = await toolHandler(server, 'stonewright-client-surface-check')?.({
			expected_tool: 'stonewright-elementor-v3-container-schema',
			catalog_observation: preRefreshObservation,
		}) as {
			structuredContent?: { ok?: boolean; client_has_tool?: boolean; startup_ready?: boolean; error_code?: string };
		};
		expect(surface.structuredContent).toEqual(expect.objectContaining({
			ok: false,
			client_has_tool: false,
			startup_ready: false,
			error_code: 'client_tool_not_registered',
		}));

		const attestedSurface = await toolHandler(server, 'stonewright-client-surface-check')?.({
			expected_tool: 'stonewright-elementor-v3-container-schema',
			catalog_observation: catalogObservationFromToolList(server),
		}) as { structuredContent?: {
			ok?: boolean; client_has_tool?: boolean; startup_ready?: boolean; error_code?: string;
			surface?: { profile?: string; relist_required?: boolean };
		} };
		expect(attestedSurface.structuredContent).toEqual(expect.objectContaining({
			ok: true,
			client_has_tool: true,
			startup_ready: true,
			error_code: 'ok',
		}));
		expect(attestedSurface.structuredContent?.surface).toEqual(expect.objectContaining({
			profile: 'full',
			relist_required: false,
		}));

		const reconciledStatus = await toolHandler(server, 'stonewright-wordpress-mcp-status')?.({}) as {
			structuredContent?: { ok?: boolean; startup_ready?: boolean; error_code?: string | null; surface?: { relist_required?: boolean } };
		};
		expect(reconciledStatus.structuredContent).toEqual(expect.objectContaining({
			ok: true,
			startup_ready: true,
			error_code: null,
		}));
		expect(reconciledStatus.structuredContent?.surface?.relist_required).toBe(false);
	});

	it('rejects a proof signed with key material embedded in that same proof', () => {
		const proof = {
			verified_at: new Date().toISOString(),
			status: 'verified',
			attestation_scope: 'active-client',
			receipt_id: 'forged-receipt',
			client: 'codex',
			expected_package: companionPackageSpec(),
			expected_version: APP_VERSION,
			companion_version: APP_VERSION,
			process_start_id: 'forged-process',
			catalog_digest: 'sha256:forged-catalog',
			observed_tool_names: ['stonewright-task-start'],
			attestation_challenge: 'attacker-selected-key',
		};
		const material = JSON.stringify({
			status: proof.status,
			attestation_scope: proof.attestation_scope,
			receipt_id: proof.receipt_id,
			client: proof.client,
			expected_package: proof.expected_package,
			expected_version: proof.expected_version,
			companion_version: proof.companion_version,
			process_start_id: proof.process_start_id,
			catalog_digest: proof.catalog_digest,
			observed_tool_names: proof.observed_tool_names,
		});
		const forged = {
			...proof,
			attestation_digest: `hmac-sha256:${createHmac('sha256', proof.attestation_challenge).update(material).digest('hex')}`,
		};

		expect(verifyActiveClientRestartProof(forged)).toBe(false);
	});

	it('closes a pending restart only inside the active host after the required gateway sequence', async () => {
		const fixture = activeAttestationFixture();
		const server = await activeAttestationServer(fixture);

		const premature = await toolHandler(server, 'stonewright-client-surface-check')?.({
			expected_tool: 'stonewright-task-start',
		}) as { structuredContent?: { ok?: boolean; error_code?: string; next_action?: string } };
		expect(premature.structuredContent?.ok).toBe(false);
		expect(premature.structuredContent?.error_code).toBe('restart_attestation_call_out_of_order');
		expect(premature.structuredContent?.next_action).toContain('stonewright-task-start');
		const pendingRegistry = JSON.parse(readFileSync(fixture.sitesFile, 'utf8')) as {
			sites: Array<{ clients: Record<string, { pending_restart?: unknown }> }>;
		};
		expect(pendingRegistry.sites[0].clients.codex.pending_restart).toBeDefined();

		const activeTask = await toolHandler(server, 'stonewright-task-start')?.({ task: 'verify active host restart' }) as {
			structuredContent?: { ok?: boolean; error_code?: string };
		};
		expect(activeTask.structuredContent?.ok).toBe(true);
		const activeSetup = await toolHandler(server, 'stonewright-setup-profile')?.({
			siteUrl: 'https://example.com', username: 'editor', appPassword: 'example-password',
		}) as {
			structuredContent?: { ok?: boolean; error_code?: string; checks?: Array<{ id: string; status: string }> };
		};
		expect(activeSetup.structuredContent?.checks?.filter((check) => check.status !== 'ok')).toEqual([]);
		expect(activeSetup.structuredContent?.ok).toBe(true);
		const activeStatus = await toolHandler(server, 'stonewright-wordpress-mcp-status')?.({}) as {
			structuredContent?: { refresh_required_tool_names?: string[] };
		};
		expect(activeStatus.structuredContent?.refresh_required_tool_names).toEqual([]);
		const completed = await toolHandler(server, 'stonewright-client-surface-check')?.({
			expected_tool: 'stonewright-task-start',
			catalog_observation: catalogObservationFromToolList(server),
		}) as { structuredContent?: { restart_attestation?: { status?: string; attestation_digest?: string } } };

		expect(completed.structuredContent?.restart_attestation?.status).toBe('verified');
		expect(completed.structuredContent?.restart_attestation?.attestation_digest).toMatch(/^hmac-sha256:/);
		const registry = JSON.parse(readFileSync(fixture.sitesFile, 'utf8')) as {
			sites: Array<{ clients: Record<string, {
				pending_restart?: unknown;
				last_restart_proof?: {
					status?: string;
					attestation_scope?: string;
					receipt_id?: string;
					expected_package?: string;
					expected_package_provenance?: string;
					expected_version?: string;
					config_before_sha256?: string;
					config_after_sha256?: string;
					expires_at?: string;
					attestation_digest?: string;
					attestation_challenge?: string;
					catalog_observation_digest?: string;
				};
				restart_attestation_key?: string;
				last_consumed_restart_receipt_id?: string;
			}> }>;
		};
		expect(registry.sites[0].clients.codex.pending_restart).toBeUndefined();
		const proof = registry.sites[0].clients.codex.last_restart_proof;
		expect(proof).toMatchObject({
			status: 'verified', attestation_scope: 'active-client', receipt_id: 'receipt-active-host',
			expected_package: fixture.expectedPackage, expected_package_provenance: 'github-release',
			expected_version: APP_VERSION,
			config_before_sha256: fixture.receipt.config_before_sha256,
			config_after_sha256: fixture.receipt.config_after_sha256,
		});
		expect(proof?.attestation_digest).toMatch(/^hmac-sha256:/);
		expect(proof?.catalog_observation_digest).toMatch(/^sha256:/);
		expect(proof?.expires_at).toBe(fixture.receipt.expires_at);
		expect(proof).not.toHaveProperty('attestation_challenge');
		expect(registry.sites[0].clients.codex.last_consumed_restart_receipt_id).toBe('receipt-active-host');
		expect(JSON.stringify(completed)).not.toContain('private-registry-key-never-exported');
	});

	it('records the restart sequence when essential task-start reports tools_changed', async () => {
		const fixture = activeAttestationFixture();
		const server = await activeAttestationServer(fixture, 'Codex', workflowPreflightPayload({
			configured_mcp_surface: 'essential',
			session_tool_profile: 'essential',
			tool_profile: 'essential',
			tools_changed: true,
			re_list_instruction: 'Re-list tools now (tools/list).',
			fast_path: { tool_profile: { profile: 'essential' } },
		}));

		const taskStart = await toolHandler(server, 'stonewright-task-start')?.({ task: 'verify essential relist sequence' }) as {
			structuredContent?: { ok?: boolean; schema_version?: number; isError?: boolean; startup_ready?: boolean };
		};
		expect(taskStart.structuredContent?.schema_version).toBe(3);
		expect(taskStart.structuredContent?.ok).toBe(false);
		expect(taskStart.structuredContent?.startup_ready).toBe(false);
		expect(taskStart.structuredContent?.isError).not.toBe(true);

		const setup = await toolHandler(server, 'stonewright-setup-profile')?.({
			siteUrl: 'https://example.com', username: 'editor', appPassword: 'example-password',
		}) as { structuredContent?: { error_code?: string } };
		expect(setup.structuredContent?.error_code).not.toBe('restart_attestation_call_out_of_order');

		await toolHandler(server, 'stonewright-wordpress-mcp-status')?.({});
		const completed = await toolHandler(server, 'stonewright-client-surface-check')?.({
			expected_tool: 'stonewright-task-start',
			catalog_observation: catalogObservationFromToolList(server),
		}) as { structuredContent?: { restart_attestation?: { status?: string; attestation_digest?: string } } };

		expect(completed.structuredContent?.restart_attestation?.status).toBe('verified');
		expect(completed.structuredContent?.restart_attestation?.attestation_digest).toMatch(/^hmac-sha256:/);
		const registry = JSON.parse(readFileSync(fixture.sitesFile, 'utf8')) as {
			sites: Array<{ clients: Record<string, { pending_restart?: unknown; last_consumed_restart_receipt_id?: string }> }>;
		};
		expect(registry.sites[0].clients.codex.pending_restart).toBeUndefined();
		expect(registry.sites[0].clients.codex.last_consumed_restart_receipt_id).toBe('receipt-active-host');
	});

	it('does not advance restart verification after a failed required call', async () => {
		const fixture = activeAttestationFixture();
		const server = await activeAttestationServer(fixture, 'Codex', {
			ok: false,
			error_code: 'invalid_input',
			message: 'Synthetic task-start failure.',
		});

		const failedTask = await toolHandler(server, 'stonewright-task-start')?.({ task: 'fail before recording' }) as {
			structuredContent?: { ok?: boolean };
		};
		expect(failedTask.structuredContent?.ok).toBe(false);

		const setup = await toolHandler(server, 'stonewright-setup-profile')?.({
			siteUrl: 'https://example.com', username: 'editor', appPassword: 'example-password',
		}) as { structuredContent?: { ok?: boolean; error_code?: string; next_action?: string } };
		expect(setup.structuredContent).toEqual(expect.objectContaining({
			ok: false,
			error_code: 'restart_attestation_call_out_of_order',
		}));
		expect(setup.structuredContent?.next_action).toContain('stonewright-task-start');

		const registry = JSON.parse(readFileSync(fixture.sitesFile, 'utf8')) as {
			sites: Array<{ clients: Record<string, { pending_restart?: unknown }> }>;
		};
		expect(registry.sites[0].clients.codex.pending_restart).toBeDefined();
	});

	it.each([
		['missing ok', workflowPreflightPayload({ ok: undefined })],
		['non-boolean ok', workflowPreflightPayload({ ok: 'true' })],
		['malformed schema', workflowPreflightPayload({ schema_version: '2' })],
	])('does not advance restart verification after required call with %s', async (_label, taskStartResponse) => {
		const fixture = activeAttestationFixture();
		const server = await activeAttestationServer(fixture, 'Codex', taskStartResponse);

		await toolHandler(server, 'stonewright-task-start')?.({ task: 'malformed required response' });
		const setup = await toolHandler(server, 'stonewright-setup-profile')?.({
			siteUrl: 'https://example.com', username: 'editor', appPassword: 'example-password',
		}) as { structuredContent?: { ok?: boolean; error_code?: string } };

		expect(setup.structuredContent).toEqual(expect.objectContaining({
			ok: false,
			error_code: 'restart_attestation_call_out_of_order',
		}));
	});

	it('does not advance restart verification after required call returns MCP error content', async () => {
		const fixture = activeAttestationFixture();
		process.env.SW_ACTIVE_ATTEST_PASSWORD = 'example-password';
		const server = await createMcpServer({
			env: {
				HOME: fixture.stateDir,
				STONEWRIGHT_HOME: fixture.stateDir,
				STONEWRIGHT_STATE_DIR: fixture.stateDir,
				STONEWRIGHT_SITES_FILE: fixture.sitesFile,
				STONEWRIGHT_SITE_ALIAS: 'site-a',
				STONEWRIGHT_MODE: 'plugin',
				STONEWRIGHT_MCP_URL: 'https://example.com/wp-json/mcp/stonewright',
				STONEWRIGHT_WP_URL: 'https://example.com',
				STONEWRIGHT_WP_USERNAME: 'editor',
				STONEWRIGHT_WP_APP_PASSWORD: 'example-password',
				SW_ACTIVE_ATTEST_PASSWORD: 'example-password',
			},
			fetchImpl: stonewrightMcpFetch([{ name: 'stonewright-task-start' }], { taskStartIsError: true }),
		});
		delete process.env.SW_ACTIVE_ATTEST_PASSWORD;
		setMcpClientIdentity(server, 'Codex');

		await toolHandler(server, 'stonewright-task-start')?.({ task: 'error content' });
		const setup = await toolHandler(server, 'stonewright-setup-profile')?.({
			siteUrl: 'https://example.com', username: 'editor', appPassword: 'example-password',
		}) as { structuredContent?: { ok?: boolean; error_code?: string } };

		expect(setup.structuredContent).toEqual(expect.objectContaining({
			ok: false,
			error_code: 'restart_attestation_call_out_of_order',
		}));
	});

	it('rejects an expired pending restart receipt', async () => {
		const fixture = activeAttestationFixture({ expiresAt: new Date(Date.now() - 1_000).toISOString() });
		const server = await activeAttestationServer(fixture);
		const result = await completeRequiredAttestationSequence(server) as {
			structuredContent?: { restart_attestation?: { status?: string; error_code?: string } };
		};

		expect(result.structuredContent?.restart_attestation).toMatchObject({
			status: 'blocked', error_code: 'restart_attestation_expired',
		});
	});

	it('rejects a correctly signed restart proof after its expiry', async () => {
		const fixture = activeAttestationFixture();
		const server = await activeAttestationServer(fixture);
		await completeRequiredAttestationSequence(server);
		const registry = JSON.parse(readFileSync(fixture.sitesFile, 'utf8')) as {
			sites: Array<{ clients: Record<string, {
				last_restart_proof?: Record<string, unknown>;
				last_consumed_restart_receipt_id?: string;
			}> }>;
		};
		const binding = registry.sites[0].clients.codex;
		expect(verifyActiveClientRestartProof(
			binding.last_restart_proof,
			'private-registry-key-never-exported',
			binding.last_consumed_restart_receipt_id,
		)).toBe(true);

		vi.useFakeTimers();
		try {
			vi.setSystemTime(Date.parse(fixture.receipt.expires_at) + 1);
			expect(verifyActiveClientRestartProof(
				binding.last_restart_proof,
				'private-registry-key-never-exported',
				binding.last_consumed_restart_receipt_id,
			)).toBe(false);
		} finally {
			vi.useRealTimers();
		}
	});

	it('rejects a pending receipt from the wrong active MCP client', async () => {
		const fixture = activeAttestationFixture({ client: 'codex' });
		const server = await activeAttestationServer(fixture, 'Cursor');
		const result = await completeRequiredAttestationSequence(server) as {
			structuredContent?: { restart_attestation?: { status?: string; error_code?: string } };
		};

		expect(result.structuredContent?.restart_attestation).toMatchObject({
			status: 'blocked', error_code: 'restart_attestation_client_mismatch',
		});
	});

	it('rejects config drift after the package update', async () => {
		const fixture = activeAttestationFixture();
		const server = await activeAttestationServer(fixture);
		writeFileSync(fixture.configPath, `${fixture.configText}# changed after update\n`, 'utf8');
		const result = await completeRequiredAttestationSequence(server) as {
			structuredContent?: { restart_attestation?: { status?: string; error_code?: string } };
		};

		expect(result.structuredContent?.restart_attestation).toMatchObject({
			status: 'blocked', error_code: 'restart_attestation_config_changed',
		});
	});

	it('rejects a different package source loaded after the update', async () => {
		const fixture = activeAttestationFixture();
		const server = await activeAttestationServer(fixture);
		writeFileSync(
			fixture.configPath,
			fixture.configText.replace(fixture.expectedPackage, '@stonewright/companion@1.0.0-beta.11'),
			'utf8',
		);
		const result = await completeRequiredAttestationSequence(server) as {
			structuredContent?: { restart_attestation?: { status?: string; error_code?: string } };
		};

		expect(result.structuredContent?.restart_attestation).toMatchObject({
			status: 'blocked', error_code: 'restart_attestation_source_changed',
		});
	});

	it('consumes a pending receipt once and rejects replay', async () => {
		const fixture = activeAttestationFixture();
		const firstServer = await activeAttestationServer(fixture);
		const first = await completeRequiredAttestationSequence(firstServer) as {
			structuredContent?: { restart_attestation?: { status?: string } };
		};
		expect(first.structuredContent?.restart_attestation?.status).toBe('verified');

		const registry = JSON.parse(readFileSync(fixture.sitesFile, 'utf8')) as {
			sites: Array<{ clients: Record<string, { pending_restart?: unknown }> }>;
		};
		registry.sites[0].clients.codex.pending_restart = fixture.receipt;
		writeFileSync(fixture.sitesFile, `${JSON.stringify(registry, null, 2)}\n`, 'utf8');
		const replayServer = await activeAttestationServer(fixture);
		const replay = await completeRequiredAttestationSequence(replayServer) as {
			structuredContent?: { restart_attestation?: { status?: string; error_code?: string } };
		};

		expect(replay.structuredContent?.restart_attestation).toMatchObject({
			status: 'blocked', error_code: 'restart_attestation_replayed',
		});
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

		expect(status.structuredContent?.authentication).toEqual({
			configured: false,
			method: 'none',
			state: 'unknown',
			reason_code: null,
			last_success_at: null,
			refresh_expires_at: null,
			continuity_target_seconds: 604800,
			agent_notice_required: false,
			user_action: null,
		});
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
		expect(status.structuredContent?.schema_version).toBe(3);
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
		expect(result.structuredContent?.schema_version).toBe(3);
		expect(result.structuredContent?.primary_next_action || result.structuredContent?.next_action).toBeTruthy();
	});
});

function stonewrightMcpFetch(
	tools: Array<{ name: string; description?: string; inputSchema?: Record<string, unknown> }>,
	options: {
		taskStartResponse?: Record<string, unknown>;
		taskStartError?: { code: number; message: string };
		taskStartIsError?: boolean;
		onTaskStart?: (args: Record<string, unknown>) => void;
	} = {},
): typeof fetch {
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
			if (name === 'stonewright-task-start') options.onTaskStart?.(body.params?.arguments ?? {});
			if (name === 'stonewright-task-start' && options.taskStartError) {
				return Promise.resolve(new Response(JSON.stringify({
					jsonrpc: '2.0',
					id: 3,
					error: options.taskStartError,
				}), { headers: { 'content-type': 'application/json' } }));
			}
			if (name === 'stonewright-task-start' && options.taskStartIsError) {
				return Promise.resolve(new Response(JSON.stringify({
					jsonrpc: '2.0',
					id: 3,
					result: {
						isError: true,
						content: [{ type: 'text', text: 'Invalid task-start input.' }],
					},
				}), { headers: { 'content-type': 'application/json' } }));
			}
			const structuredContent =
				name === 'stonewright-tool-profile'
					? {
						ok: true,
						tools: tools.map((t) => t.name),
						mcp_surface: 'essential-static',
						surface_revision: 1,
					}
					: name === 'stonewright-task-start'
						? options.taskStartResponse ?? workflowPreflightPayload()
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
