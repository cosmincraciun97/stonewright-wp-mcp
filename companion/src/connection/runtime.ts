/**
 * Mutable connection runtime shared by permanent gateways and createMcpServer.
 */

import type { McpServer } from '@modelcontextprotocol/sdk/server/mcp.js';
import { z } from 'zod';
import {
	AGENT_DO_NOT_USE,
	agentUseInstead,
	buildSetupProfile,
	buildToolInventory,
	type ToolInventory,
} from '../setup-profile.js';
import {
	STARTUP_REQUIRED_PROXY_TOOL_NAMES,
	emitToolListChanged,
	proxyToolNamesForProfile,
	type ProxyToolProfile,
	type WordPressProxyLiveState,
} from '../wordpress-mcp.js';
import { APP_VERSION, companionPackageSpec } from '../version.js';
import type { DirectSessionControls, DirectToolProfile } from '../direct/registry.js';
import * as selfImprove from '../direct/tools/self-improve.js';
import {
	ConnectionStateMachine,
	PERMANENT_GATEWAY_TOOL_NAMES,
	PERMANENT_GATEWAY_TOOL_NAME_SET,
	ReconnectController,
	RegistryBarrier,
	SurfaceRevisionTracker,
	buildConnectionStatusV2,
	clientHasTool,
	clientVisibilityFromEvidence,
	computeRefreshRequiredToolNames,
	defaultClientVisibility,
	mapConfiguredMode,
	modeCapabilitiesComparison,
	normalizeToolName,
	type ActiveMode,
	type ConfiguredMode,
	type ConnectionStatusV2,
	type ReconnectInput,
	type ReconnectResult,
} from './index.js';

export interface WordPressMcpConnectionStatus extends Record<string, unknown> {
	ok: boolean;
	configured: boolean;
	connected: boolean;
	url: string | null;
	tool_profile: string | null;
	surface_revision: number | null;
	surface_digest: string | null;
	startup_ready: boolean;
	startup_required_tool_names: string[];
	startup_missing_tool_names: string[];
	local_recovery_tool_names: string[];
	local_tool_names: string[];
	profile_expected_tool_count: number;
	client_visible_expected_tool_count: number;
	profile_missing_tool_names: string[];
	remote_tool_count: number;
	proxied_tool_count: number;
	profile_filtered_tool_count: number;
	profile_filtered_tool_names: string[];
	tool_inventory: ToolInventory;
	companion_version: string;
	expected_companion_package: string;
	refresh_required_tool_names: string[];
	prompt_skill_count: number;
	error: { message: string } | null;
	agent_do_not_use: string[];
	agent_use_instead: string[];
	recovery: string[];
	mode: 'plugin' | 'direct';
	mode_reason: string | null;
	configured_mode: ConfiguredMode;
	connection_stage: string;
	connection_generation: number;
	direct_tool_count: number;
	direct_tool_names: string[];
	unavailable_plugin_capabilities: Array<{ id: string; label: string; reason: string; upgrade: string }>;
	live: WordPressProxyLiveState | null;
	schema_version: number;
	client_visibility: { state: string; reason: string };
	error_code: string | null;
	next_action: string | null;
}

export interface ConnectionRuntime {
	env: NodeJS.ProcessEnv;
	profile: ProxyToolProfile;
	fetchImpl: typeof fetch;
	status: WordPressMcpConnectionStatus;
	stateMachine: ConnectionStateMachine;
	registry: RegistryBarrier;
	surface: SurfaceRevisionTracker;
	reconnect: ReconnectController;
	invokedToolNames: Set<string>;
	callRemoteTool: ((name: string, args: Record<string, unknown>) => Promise<unknown>) | null;
	directSession: DirectSessionControls | null;
	authConfigured: boolean;
	authMethod: ConnectionStatusV2['authentication']['method'];
	wpReachable: boolean | null;
	server: McpServer | null;
	/** Rebuild / re-probe plugin or Direct registration. */
	performReconnect: (input: ReconnectInput) => Promise<ReconnectResult>;
	/** Snapshot registered tool names from the MCP server. */
	listRegisteredToolNames: () => string[];
	/** Build schema v2 status for gateways. */
	buildStatusV2: (overrides?: Partial<ConnectionStatusV2>) => ConnectionStatusV2;
	/** Merge v2 fields into the mutable legacy status object. */
	syncLegacyStatus: () => void;
	/** Mark a tool as invoked this session (for client_has_tool). */
	markInvoked: (name: string) => void;
	/** Recompute surface digest/revision from live registrations. */
	refreshSurfaceFromServer: (options?: { forceBump?: boolean }) => void;
}

export function createConnectionRuntime(args: {
	env: NodeJS.ProcessEnv;
	profile: ProxyToolProfile;
	fetchImpl?: typeof fetch;
}): ConnectionRuntime {
	const env = args.env;
	const profile = args.profile;
	const stateMachine = new ConnectionStateMachine();
	const registry = new RegistryBarrier();
	const surface = new SurfaceRevisionTracker();
	const invokedToolNames = new Set<string>();
	const initialAuthMethod = detectAuthMethod(env);

	const status = createInitialStatus(profile);

	const runtime: ConnectionRuntime = {
		env,
		profile,
		fetchImpl: args.fetchImpl ?? fetch,
		status,
		stateMachine,
		registry,
		surface,
		reconnect: null as unknown as ReconnectController,
		invokedToolNames,
		callRemoteTool: null,
		directSession: null,
		authConfigured: initialAuthMethod !== 'none',
		authMethod: initialAuthMethod,
		wpReachable: null,
		server: null,
		performReconnect: () => Promise.reject(new Error('Reconnect executor not wired')),
		listRegisteredToolNames: () => {
			if (!runtime.server) return [...PERMANENT_GATEWAY_TOOL_NAMES];
			const tools = (runtime.server as unknown as { _registeredTools?: Record<string, { enabled?: boolean }> })._registeredTools ?? {};
			return Object.entries(tools)
				.filter(([, handle]) => handle?.enabled !== false)
				.map(([name]) => name)
				.sort();
		},
		buildStatusV2: (overrides = {}) => {
			const stage = runtime.stateMachine.getStage();
			const activeMode: ActiveMode = runtime.status.mode === 'direct'
				? 'direct'
				: runtime.status.connected
					? 'plugin'
					: stage === 'local-ready' || stage === 'degraded'
						? 'local-only'
						: 'none';
			const registered = runtime.listRegisteredToolNames();
			const localCount = registered.filter((n) =>
				PERMANENT_GATEWAY_TOOL_NAME_SET.has(n) || n.startsWith('stonewright-wp-cli-') || n.startsWith('companion_'),
			).length;
			const remoteCount = runtime.status.remote_tool_count;
			const requested = proxyToolNamesForProfile(runtime.profile);
			const refresh = computeRefreshRequiredToolNames(requested, registered);
			const base = buildConnectionStatusV2({
				siteAlias: (runtime.env['STONEWRIGHT_SITE_ALIAS'] ?? '').trim() || null,
				configuredMode: runtime.status.configured_mode,
				activeMode,
				connectionStage: stage,
				connectionGeneration: runtime.stateMachine.getGeneration(),
				mcpUrl: runtime.status.url,
				authConfigured: runtime.authConfigured,
				authMethod: runtime.authMethod,
				wpReachable: runtime.wpReachable,
				siteUrl: siteUrlFromEnv(runtime.env),
				plugin: {
					reachable: runtime.status.mode === 'plugin' && runtime.status.connected ? true : null,
					enabled_requested: runtime.status.configured_mode !== 'direct-only',
					effective_state: stage,
					registry_ready: runtime.registry.isReady || stage === 'direct-ready',
				},
				surface: {
					profile: String(runtime.status.tool_profile ?? runtime.profile),
					local_tool_count: localCount,
					remote_tool_count: remoteCount,
					registered_tool_count: registered.length,
					revision: runtime.surface.getRevision(),
					digest: runtime.surface.getDigest(),
					relist_required: Boolean(runtime.status.live?.lastRefresh && (runtime.status.live.lastRefresh.added.length > 0 || runtime.status.live.lastRefresh.removed.length > 0)),
				},
				clientVisibility: clientVisibilityFromEvidence({ invokedToolNames: runtime.invokedToolNames }),
				errorCode: runtime.status.error_code ?? (runtime.status.error ? 'connection_error' : null),
				nextAction: runtime.status.next_action,
				startupReady: runtime.status.startup_ready,
				refreshRequiredToolNames: refresh,
				ok: runtime.status.ok,
			});
			return { ...base, ...overrides };
		},
		syncLegacyStatus: () => {
			const v2 = runtime.buildStatusV2();
			runtime.status.schema_version = v2.schema_version;
			runtime.status.connected = v2.connected;
			runtime.status.connection_stage = v2.connection_stage;
			runtime.status.connection_generation = v2.connection_generation;
			runtime.status.configured_mode = v2.configured_mode;
			runtime.status.surface_revision = v2.surface.revision;
			runtime.status.surface_digest = v2.surface.digest;
			runtime.status.client_visibility = v2.client_visibility;
			runtime.status.error_code = v2.error_code;
			runtime.status.next_action = v2.next_action;
			runtime.status.startup_ready = v2.startup_ready;
			runtime.status.refresh_required_tool_names = v2.refresh_required_tool_names;
		},
		markInvoked: (name: string) => {
			runtime.invokedToolNames.add(normalizeToolName(name));
		},
		refreshSurfaceFromServer: (options = {}) => {
			const names = runtime.listRegisteredToolNames();
			if (options.forceBump) {
				runtime.surface.bump(names);
			} else {
				runtime.surface.commit(names);
			}
			runtime.status.surface_revision = runtime.surface.getRevision();
			runtime.status.surface_digest = runtime.surface.getDigest();
			runtime.status.local_tool_names = names.filter((n) =>
				PERMANENT_GATEWAY_TOOL_NAME_SET.has(n) || n.startsWith('stonewright-wp-cli-') || n.startsWith('companion_'),
			);
			const requested = proxyToolNamesForProfile(runtime.profile);
			runtime.status.refresh_required_tool_names = computeRefreshRequiredToolNames(requested, names);
			runtime.syncLegacyStatus();
		},
	};

	runtime.reconnect = new ReconnectController(async (input) => runtime.performReconnect(input));

	registry.seedLocal(PERMANENT_GATEWAY_TOOL_NAMES.map((name) => ({
		name,
		source: 'local-gateway' as const,
	})));
	surface.commit([...PERMANENT_GATEWAY_TOOL_NAMES]);
	status.surface_revision = surface.getRevision();
	status.surface_digest = surface.getDigest();
	status.configured_mode = mapConfiguredMode(env['STONEWRIGHT_MODE']);
	status.connection_stage = stateMachine.getStage();
	status.connection_generation = stateMachine.getGeneration();

	return runtime;
}

function createInitialStatus(profile: ProxyToolProfile): WordPressMcpConnectionStatus {
	const profileExpectedToolNames = proxyToolNamesForProfile(profile);
	const localToolNames = [...PERMANENT_GATEWAY_TOOL_NAMES];
	return {
		ok: false,
		configured: false,
		connected: false,
		url: null,
		tool_profile: profile,
		surface_revision: 0,
		surface_digest: null,
		startup_ready: false,
		startup_required_tool_names: Array.from(STARTUP_REQUIRED_PROXY_TOOL_NAMES),
		startup_missing_tool_names: Array.from(STARTUP_REQUIRED_PROXY_TOOL_NAMES),
		local_recovery_tool_names: localToolNames,
		local_tool_names: localToolNames,
		profile_expected_tool_count: profileExpectedToolNames.length,
		client_visible_expected_tool_count: profileExpectedToolNames.length + localToolNames.length,
		profile_missing_tool_names: profileExpectedToolNames.filter((n) => !localToolNames.includes(n as never)),
		remote_tool_count: 0,
		proxied_tool_count: 0,
		profile_filtered_tool_count: 0,
		profile_filtered_tool_names: [],
		tool_inventory: buildToolInventory(profile, localToolNames),
		companion_version: APP_VERSION,
		expected_companion_package: companionPackageSpec(),
		refresh_required_tool_names: computeRefreshRequiredToolNames(profileExpectedToolNames, localToolNames),
		prompt_skill_count: 0,
		error: null,
		agent_do_not_use: Array.from(AGENT_DO_NOT_USE),
		agent_use_instead: agentUseInstead({ STONEWRIGHT_MCP_TOOL_PROFILE: profile }),
		recovery: [
			'Verify STONEWRIGHT_WP_URL or STONEWRIGHT_MCP_URL points to /wp-json/mcp/stonewright.',
			'Verify STONEWRIGHT_WP_USERNAME plus STONEWRIGHT_WP_APP_PASSWORD or STONEWRIGHT_MCP_AUTHORIZATION.',
			'Keep using permanent local gateways (task-start, connect-doctor, reconnect, setup-profile) while fixing the WordPress MCP connection.',
		],
		mode: 'plugin',
		mode_reason: null,
		configured_mode: 'auto',
		connection_stage: 'local-ready',
		connection_generation: 0,
		direct_tool_count: 0,
		direct_tool_names: [],
		unavailable_plugin_capabilities: [],
		live: null,
		schema_version: 2,
		client_visibility: defaultClientVisibility(),
		error_code: null,
		next_action: 'Call stonewright-task-start or stonewright-connect-doctor to diagnose connectivity.',
	};
}

function detectAuthMethod(env: NodeJS.ProcessEnv): ConnectionStatusV2['authentication']['method'] {
	if (
		(env['STONEWRIGHT_OAUTH_CLIENT_ID'] ?? '').trim()
		&& (env['STONEWRIGHT_OAUTH_TOKEN_STORE'] ?? '').trim()
	) return 'oauth';
	if ((env['STONEWRIGHT_MCP_AUTHORIZATION'] ?? '').trim()) return 'authorization';
	if (
		(env['STONEWRIGHT_WP_USERNAME'] ?? env['WP_API_USERNAME'] ?? '').trim()
		&& (env['STONEWRIGHT_WP_APP_PASSWORD'] ?? env['WP_API_PASSWORD'] ?? '').trim()
	) return 'app-password';
	return 'none';
}

function siteUrlFromEnv(env: NodeJS.ProcessEnv): string | null {
	const raw = (env['STONEWRIGHT_WP_URL'] ?? env['WP_API_URL'] ?? env['STONEWRIGHT_MCP_URL'] ?? '').trim();
	return raw || null;
}

function toolResponse<T extends Record<string, unknown>>(result: T): {
	content: Array<{ type: 'text'; text: string }>;
	structuredContent: T;
} {
	return {
		content: [{ type: 'text', text: JSON.stringify(result) }],
		structuredContent: result,
	};
}

/**
 * Register permanent local gateways BEFORE remote handshake.
 * Local handlers may proxy to remote when callRemoteTool is wired.
 */
export function registerPermanentGateways(server: McpServer, runtime: ConnectionRuntime): void {
	runtime.server = server;

	const wrap = (name: string, handler: (input: Record<string, unknown>) => Promise<Record<string, unknown>> | Record<string, unknown>) => {
		return async (input: Record<string, unknown>) => {
			runtime.markInvoked(name);
			const result = await handler(input ?? {});
			return toolResponse(result);
		};
	};

	server.registerTool(
		'stonewright-setup-profile',
		{
			description: 'Return a compact cross-platform Stonewright companion setup profile with copy-paste MCP config, environment checks, and credential guidance.',
			inputSchema: {
				siteUrl: z.string().optional(),
				wpRoot: z.string().optional(),
				username: z.string().optional(),
				appPassword: z.string().optional(),
			},
		},
		wrap('stonewright-setup-profile', (input) => {
			const mergedEnv = {
				...runtime.env,
				...(typeof input['siteUrl'] === 'string' ? { STONEWRIGHT_WP_URL: input['siteUrl'] } : {}),
				...(typeof input['wpRoot'] === 'string' ? { STONEWRIGHT_WP_ROOT: input['wpRoot'] } : {}),
				...(typeof input['username'] === 'string' ? { STONEWRIGHT_WP_USERNAME: input['username'] } : {}),
				...(typeof input['appPassword'] === 'string' ? { STONEWRIGHT_WP_APP_PASSWORD: input['appPassword'] } : {}),
			};
			const profile = buildSetupProfile(mergedEnv, process.platform, {
				mode: runtime.status.mode,
				...(runtime.status.mode_reason ? { mode_reason: runtime.status.mode_reason } : {}),
			});
			const statusV2 = runtime.buildStatusV2();
			return {
				...profile,
				schema_version: statusV2.schema_version,
				connection_stage: statusV2.connection_stage,
				configured_mode: statusV2.configured_mode,
				active_mode: statusV2.active_mode,
				startup_ready: statusV2.startup_ready,
				connected: statusV2.connected,
				client_visibility: statusV2.client_visibility,
				error_code: statusV2.error_code,
				next_action: statusV2.next_action,
				surface: statusV2.surface,
			};
		}),
	);

	server.registerTool(
		'stonewright-wordpress-mcp-status',
		{
			description: 'Return truthful companion connection status (schema_version 2). Available offline so agents can recover without losing gateway tools.',
			inputSchema: {},
		},
		wrap('stonewright-wordpress-mcp-status', () => {
			runtime.syncLegacyStatus();
			const live = runtime.status.live;
			const liveBlock = live
				? {
					live_tool_profile: live.profile,
					surface_revision: live.surfaceRevision ?? runtime.surface.getRevision(),
					live_enabled_tool_count: live.enabledToolNames.length,
					live_enabled_tool_names: live.enabledToolNames,
					last_refresh_at: live.lastRefreshAt,
					last_refresh_added: live.lastRefresh?.added ?? [],
					last_refresh_removed: live.lastRefresh?.removed ?? [],
				}
				: {
					live_tool_profile: null,
					surface_revision: runtime.surface.getRevision(),
				};
			const v2 = runtime.buildStatusV2();
			// Prefer plugin live revision when it is ahead of the local surface tracker.
			const surfaceRevision = live?.surfaceRevision != null
				&& (v2.surface.revision == null || live.surfaceRevision >= v2.surface.revision)
				? live.surfaceRevision
				: v2.surface.revision;
			return {
				...runtime.status,
				...liveBlock,
				...v2,
				surface_revision: surfaceRevision,
				surface_digest: v2.surface.digest,
				live_tool_profile: live?.profile ?? v2.surface.profile,
			};
		}),
	);

	server.registerTool(
		'stonewright-client-surface-check',
		{
			description:
				'Diagnose Stonewright client tool-surface problems. client_has_tool is never inferred from counts alone — only permanent gateways, observed_tool_names attestation, or session invocation.',
			inputSchema: {
				expected_tool: z.string().optional(),
				observed_tool_names: z.array(z.string()).optional(),
			},
		},
		wrap('stonewright-client-surface-check', (input) => {
			const expected = typeof input['expected_tool'] === 'string' && input['expected_tool'].trim() !== ''
				? normalizeToolName(input['expected_tool'])
				: 'stonewright-php-execute';
			const observed = Array.isArray(input['observed_tool_names'])
				? input['observed_tool_names'].filter((n): n is string => typeof n === 'string')
				: null;
			const live = runtime.status.live;
			const filtered = new Set(runtime.status.profile_filtered_tool_names ?? []);
			const missingProfile = new Set(runtime.status.profile_missing_tool_names ?? []);
			const profile = live?.profile ?? ((runtime.status.tool_profile as ProxyToolProfile) || runtime.profile);
			const liveEnabled = new Set(live?.enabledToolNames ?? []);
			for (const name of liveEnabled) {
				filtered.delete(name);
				missingProfile.delete(name);
			}
			const proxiedToolCount = live?.enabledToolNames.length ?? runtime.status.proxied_tool_count;
			const localNames = new Set(runtime.listRegisteredToolNames());
			const profileNames = new Set(proxyToolNamesForProfile(profile));

			const serverHas = PERMANENT_GATEWAY_TOOL_NAME_SET.has(expected)
				|| localNames.has(expected)
				|| (runtime.status.connected
					&& !missingProfile.has(expected)
					&& (profile === 'full' || profileNames.has(expected) || liveEnabled.has(expected)));

			// NEVER infer client_has_tool from remote_tool_count / connected / startup_ready.
			const clientHas = clientHasTool(expected, {
				observedToolNames: observed,
				invokedToolNames: runtime.invokedToolNames,
			});

			let errorCode = 'ok';
			const fix: string[] = [];
			if (!runtime.status.configured && runtime.status.configured_mode !== 'direct-only') {
				errorCode = 'not_configured';
				fix.push('run_setup_profile', 'set_STONEWRIGHT_WP_URL_and_credentials');
			} else if (!runtime.status.connected && runtime.status.mode === 'plugin') {
				errorCode = 'auth_or_connectivity_fail';
				fix.push('call_connect_doctor', 'call_reconnect', 'verify_app_password', 'verify_mcp_url');
			} else if (filtered.has(expected)) {
				errorCode = 'client_tool_not_registered';
				fix.push('call_task_start', 'activate_profile:elementor-design_or_full', 'relist_tools', 'restart_mcp');
			} else if (!serverHas) {
				errorCode = 'server_missing_tool';
				fix.push('deploy_plugin_update', 'enable_ability', 'check_remote_tools_list');
			} else if (!clientHas) {
				errorCode = 'client_tool_not_registered';
				fix.push('relist_tools', 'provide_observed_tool_names', 'restart_mcp');
			}

			const v2 = runtime.buildStatusV2({
				client_visibility: clientVisibilityFromEvidence({
					observedToolNames: observed,
					invokedToolNames: runtime.invokedToolNames,
				}, { attested: Boolean(observed?.length) }),
				error_code: errorCode === 'ok' ? null : errorCode,
			});

			return {
				ok: errorCode === 'ok',
				error_code: errorCode,
				schema_version: v2.schema_version,
				server_has_tool: serverHas,
				client_has_tool: clientHas,
				expected_tool: expected,
				connection_stage: v2.connection_stage,
				startup_ready: v2.startup_ready,
				connected: v2.connected,
				client_visibility: v2.client_visibility,
				surface: v2.surface,
				next_action: errorCode === 'ok'
					? 'Surface looks healthy for the expected tool.'
					: fix[0] ?? 'call_connect_doctor',
				companion: {
					tool_profile: profile,
					connected: runtime.status.connected,
					configured: runtime.status.configured,
					remote_tool_count: runtime.status.remote_tool_count,
					proxied_tool_count: proxiedToolCount,
					live_enabled_tool_count: live?.enabledToolNames.length ?? null,
					profile_filtered_tool_count: runtime.status.profile_filtered_tool_count,
					profile_filtered_tool_names: runtime.status.profile_filtered_tool_names,
					profile_missing_tool_names: runtime.status.profile_missing_tool_names,
					mode: runtime.status.mode,
				},
				diagnosis: errorCode === 'ok'
					? 'Client surface looks healthy for the expected tool.'
					: errorCode === 'auth_or_connectivity_fail'
						? 'WordPress MCP endpoint is not connected (auth fail or host down).'
						: errorCode === 'not_configured'
							? 'Companion is not configured with site credentials.'
							: 'Do not infer client tool presence from counts. Re-list tools, attest observed_tool_names, or restart MCP. Never call /abilities/run.',
				fix,
				agent_do_not_use: [
					'Do not call /wp-json/stonewright/v1/abilities/run as a workaround.',
					'Do not hand-roll JSON-RPC against the MCP endpoint.',
				],
			};
		}),
	);

	server.registerTool(
		'stonewright-connect-doctor',
		{
			description: 'Comprehensive Stonewright connection diagnosis with one primary next_action (schema_version 2).',
			inputSchema: {
				site_alias: z.string().optional(),
			},
		},
		wrap('stonewright-connect-doctor', (input) => {
			runtime.syncLegacyStatus();
			const v2 = runtime.buildStatusV2();
			let nextAction = v2.next_action;
			let errorCode = v2.error_code;
			if (!runtime.status.configured && runtime.status.configured_mode !== 'direct-only') {
				errorCode = 'not_configured';
				nextAction = 'Call stonewright-setup-profile and set STONEWRIGHT_WP_URL plus credentials.';
			} else if (runtime.status.configured_mode === 'plugin-only' && !runtime.status.connected) {
				errorCode = 'plugin_unavailable';
				nextAction = 'Install/enable the Stonewright plugin and credentials, then call stonewright-reconnect with force_probe=true.';
			} else if (!runtime.status.startup_ready && runtime.status.mode === 'plugin') {
				errorCode = errorCode ?? 'registry_not_ready';
				nextAction = 'Wait for registry barrier or call stonewright-reconnect; permanent gateways remain available.';
			} else if (runtime.status.connected && runtime.status.startup_ready) {
				errorCode = null;
				nextAction = 'Call stonewright-task-start and follow fast_path.tool_profile.';
			} else if (runtime.status.mode === 'direct') {
				errorCode = null;
				nextAction = 'Direct mode is active. Call stonewright-task-start (works offline).';
			} else {
				nextAction = nextAction ?? 'Call stonewright-reconnect or verify STONEWRIGHT_MCP_URL and credentials.';
			}

			return {
				...v2,
				ok: errorCode === null,
				error_code: errorCode,
				next_action: nextAction,
				site_alias: typeof input['site_alias'] === 'string' ? input['site_alias'] : v2.site_alias,
				diagnosis: {
					configured_mode: v2.configured_mode,
					active_mode: v2.active_mode,
					connection_stage: v2.connection_stage,
					plugin: v2.plugin,
					surface: v2.surface,
					permanent_gateways: [...PERMANENT_GATEWAY_TOOL_NAMES],
				},
				primary_next_action: nextAction,
			};
		}),
	);

	server.registerTool(
		'stonewright-mode-capabilities',
		{
			description: 'Normalized Direct vs Plugin capability comparison (read content, typed updates, Elementor writes, custom code, backup, confirmation tokens).',
			inputSchema: {},
		},
		wrap('stonewright-mode-capabilities', () => {
			const v2 = runtime.buildStatusV2();
			return {
				ok: true,
				schema_version: v2.schema_version,
				configured_mode: v2.configured_mode,
				active_mode: v2.active_mode,
				connection_stage: v2.connection_stage,
				capabilities: modeCapabilitiesComparison(),
				next_action: v2.next_action,
			};
		}),
	);

	server.registerTool(
		'stonewright-reconnect',
		{
			description: 'Re-probe connectivity and rebuild the tool surface. Concurrent reconnects coalesce into one transition. Failed reconnect preserves the prior healthy registry.',
			inputSchema: {
				reason: z.string(),
				site_alias: z.string().optional(),
				force_probe: z.boolean().optional(),
			},
		},
		wrap('stonewright-reconnect', async (input) => {
			const reason = typeof input['reason'] === 'string' ? input['reason'] : 'unspecified';
			const result = await runtime.reconnect.reconnect({
				reason,
				...(typeof input['site_alias'] === 'string' ? { site_alias: input['site_alias'] } : {}),
				...(typeof input['force_probe'] === 'boolean' ? { force_probe: input['force_probe'] } : {}),
			});
			const v2 = runtime.buildStatusV2();
			return {
				...result,
				schema_version: v2.schema_version,
				connection_stage: v2.connection_stage,
				startup_ready: v2.startup_ready,
				connected: v2.connected,
				surface: v2.surface,
				next_action: result.ok
					? 'Re-list tools (honor tools/list_changed) then call stonewright-task-start.'
					: 'Prior registry preserved. Fix the reported error, then retry reconnect.',
				status: v2,
			};
		}),
	);

	server.registerTool(
		'stonewright-ping',
		{
			description: 'Local gateway ping. Proxies to the plugin when connected; always works offline.',
			inputSchema: {
				message: z.string().optional(),
			},
		},
		wrap('stonewright-ping', async (input) => {
			if (runtime.callRemoteTool && runtime.status.connected) {
				try {
					const remote = await runtime.callRemoteTool('stonewright-ping', input);
					return {
						ok: true,
						source: 'plugin',
						remote,
						connection_stage: runtime.stateMachine.getStage(),
						startup_ready: runtime.status.startup_ready,
					};
				} catch (err) {
					return {
						ok: true,
						source: 'local-fallback',
						message: typeof input['message'] === 'string' ? input['message'] : 'pong',
						error: err instanceof Error ? err.message : String(err),
						connection_stage: runtime.stateMachine.getStage(),
					};
				}
			}
			return {
				ok: true,
				source: 'local',
				message: typeof input['message'] === 'string' ? input['message'] : 'pong',
				connection_stage: runtime.stateMachine.getStage(),
				startup_ready: runtime.status.startup_ready,
				companion_version: APP_VERSION,
			};
		}),
	);

	server.registerTool(
		'stonewright-tool-profile',
		{
			description: 'Local gateway tool-profile. Proxies to the plugin when connected; resolves local fallback catalogs offline.',
			inputSchema: {
				action: z.string().optional(),
				profile: z.string().optional(),
				max_tools: z.number().int().positive().optional(),
			},
		},
		wrap('stonewright-tool-profile', async (input) => {
			if (runtime.callRemoteTool && runtime.status.connected) {
				try {
					const remote = await runtime.callRemoteTool('stonewright-tool-profile', input);
					const structured = extractStructured(remote);
					return {
						ok: true,
						source: 'plugin',
						...(structured ?? { remote }),
						connection_stage: runtime.stateMachine.getStage(),
						startup_ready: runtime.status.startup_ready,
						surface_revision: runtime.surface.getRevision(),
						surface_digest: runtime.surface.getDigest(),
					};
				} catch {
					// fall through to local
				}
			}
			const action = typeof input['action'] === 'string' ? input['action'] : 'resolve';
			const requested = typeof input['profile'] === 'string' && input['profile'].trim()
				? input['profile']
				: runtime.profile;
			const tools = proxyToolNamesForProfile(
				// coerce via names lookup; unknown falls to current
				(requested as ProxyToolProfile) in { full: 1, bootstrap: 1, 'essential-static': 1, essential: 1, 'low-tools': 1, 'elementor-design': 1, 'content-model': 1, gutenberg: 1, 'wp-cli': 1, 'site-admin': 1 }
					? (requested as ProxyToolProfile)
					: runtime.profile,
			);
			return {
				ok: true,
				source: 'local',
				action,
				profile: requested,
				tools,
				mcp_surface: runtime.profile,
				connection_stage: runtime.stateMachine.getStage(),
				startup_ready: runtime.status.startup_ready,
				surface_revision: runtime.surface.getRevision(),
				surface_digest: runtime.surface.getDigest(),
				re_list_instruction: '',
			};
		}),
	);

	server.registerTool(
		'stonewright-task-start',
		{
			description: 'Permanent local task-start gateway. Always callable offline. Runs bounded health/reconnect per configured mode and never instructs calling a tool that is not locally registered.',
			inputSchema: {
				task: z.string().min(1),
				surface: z.string().optional(),
				intent: z.string().optional(),
				site: z.string().optional(),
				site_alias: z.string().optional(),
			},
		},
		wrap('stonewright-task-start', async (input) => {
			const task = String(input['task'] ?? '');
			const site = typeof input['site'] === 'string'
				? input['site']
				: typeof input['site_alias'] === 'string'
					? input['site_alias']
					: undefined;

			// Plugin path: prefer remote task-start when connected and registry ready (or still registering).
			if (runtime.callRemoteTool && runtime.status.mode === 'plugin' && runtime.status.connected) {
				try {
					const remote = await runtime.callRemoteTool('stonewright-task-start', {
						task,
						...(typeof input['surface'] === 'string' ? { surface: input['surface'] } : {}),
						...(typeof input['intent'] === 'string' ? { intent: input['intent'] } : {}),
						...(site ? { site } : {}),
					});
					const structured = extractStructured(remote) ?? { remote };
					// Keep live surface_revision in sync when the plugin reports tools_changed.
					const remoteRevision = structured['surface_revision'];
					if (typeof remoteRevision === 'number' && Number.isSafeInteger(remoteRevision) && runtime.status.live) {
						runtime.status.live.surfaceRevision = remoteRevision;
						if (typeof structured['session_tool_profile'] === 'string') {
							runtime.status.live.profile = structured['session_tool_profile'] as typeof runtime.status.live.profile;
							runtime.status.tool_profile = structured['session_tool_profile'] as ProxyToolProfile;
						}
					}
					const v2 = runtime.buildStatusV2();
					const registered = new Set(runtime.listRegisteredToolNames());
					const guidance = filterGuidanceToRegistered(
						Array.isArray((structured as { guidance?: unknown }).guidance)
							? (structured as { guidance: string[] }).guidance
							: [],
						registered,
					);
					return {
						...structured,
						ok: true,
						source: 'plugin',
						schema_version: v2.schema_version,
						connection_stage: v2.connection_stage,
						startup_ready: v2.startup_ready,
						connected: v2.connected,
						configured_mode: v2.configured_mode,
						active_mode: v2.active_mode,
						surface: v2.surface,
						surface_revision: typeof remoteRevision === 'number' ? remoteRevision : v2.surface.revision,
						client_visibility: v2.client_visibility,
						error_code: v2.error_code,
						next_action: v2.startup_ready
							? 'Follow fast_path.tool_profile; only call tools present in the current MCP list.'
							: 'Registry barrier incomplete (startup_ready:false). Permanent gateways remain usable; wait or reconnect.',
						guidance,
						registered_gateway_tools: [...PERMANENT_GATEWAY_TOOL_NAMES],
					};
				} catch (err) {
					// Fall through to local bounded response.
					runtime.status.error = { message: err instanceof Error ? err.message : String(err) };
				}
			}

			// Direct / offline local task-start.
			const selfCtx: selfImprove.SelfImproveContext = {
				env: runtime.env,
				...(runtime.fetchImpl ? { fetchImpl: runtime.fetchImpl } : {}),
				directToolCount: runtime.status.direct_tool_count,
			};
			const local = await selfImprove.taskStartAuthoritative(selfCtx, {
				task,
				...(typeof input['surface'] === 'string' ? { surface: input['surface'] } : {}),
				...(typeof input['intent'] === 'string' ? { intent: input['intent'] } : {}),
				...(site ? { site } : {}),
			});

			let sessionProfile: DirectToolProfile | ProxyToolProfile = runtime.profile;
			let surfaceRevision = runtime.surface.getRevision();
			let toolsChanged = false;
			let sessionTools: string[] = runtime.listRegisteredToolNames();

			if (runtime.directSession && runtime.status.mode === 'direct') {
				const configuredProfile = runtime.profile === 'bootstrap' ? 'bootstrap' : runtime.profile;
				// Map proxy profile to Direct profile for expansion.
				const directConfigured = toDirectProfile(configuredProfile);
				const suggested = directConfigured === 'bootstrap'
					? (await import('../direct/registry.js')).suggestDirectToolProfile(
						task,
						String(input['surface'] ?? ''),
						String(input['intent'] ?? ''),
					)
					: directConfigured === 'essential-static'
						? 'essential'
						: directConfigured;
				const changed = suggested !== runtime.directSession.getActiveProfile()
					? await runtime.directSession.activateProfile(suggested)
					: { added: [], removed: [], surfaceRevision: runtime.directSession.getSurfaceRevision() };
				sessionProfile = suggested;
				surfaceRevision = changed.surfaceRevision;
				toolsChanged = changed.added.length > 0 || changed.removed.length > 0;
				sessionTools = runtime.listRegisteredToolNames();
				if (toolsChanged) {
					runtime.refreshSurfaceFromServer({ forceBump: true });
					surfaceRevision = runtime.surface.getRevision();
					await emitToolListChanged(server);
				}
			}

			const v2 = runtime.buildStatusV2();
			const registered = new Set(runtime.listRegisteredToolNames());
			const guidance = filterGuidanceToRegistered(
				Array.isArray((local as { guidance?: unknown }).guidance)
					? (local as { guidance: string[] }).guidance
					: [],
				registered,
			);

			return {
				...local,
				ok: true,
				source: runtime.status.mode === 'direct' ? 'direct' : 'local',
				schema_version: v2.schema_version,
				connection_stage: v2.connection_stage,
				startup_ready: v2.startup_ready,
				connected: v2.connected,
				configured_mode: v2.configured_mode,
				active_mode: v2.active_mode,
				configured_mcp_surface: runtime.profile,
				session_tool_profile: sessionProfile,
				surface_revision: surfaceRevision,
				surface_digest: runtime.surface.getDigest(),
				session_tools: sessionTools,
				tools_changed: toolsChanged,
				re_list_instruction: toolsChanged
					? 'Re-list tools now (tools/list). The task profile is active for this session.'
					: '',
				client_visibility: v2.client_visibility,
				error_code: v2.error_code,
				next_action: v2.startup_ready
					? 'Use only tools present in the current MCP list / session_tools.'
					: 'startup_ready is false (registry barrier incomplete). Permanent gateways remain callable; do not invent missing tools.',
				guidance,
				registered_gateway_tools: [...PERMANENT_GATEWAY_TOOL_NAMES],
				plugin: v2.plugin,
				surface: v2.surface,
			};
		}),
	);
}

function toDirectProfile(profile: ProxyToolProfile): DirectToolProfile {
	if (profile === 'full') return 'full';
	if (profile === 'bootstrap') return 'bootstrap';
	if (profile === 'essential-static') return 'essential-static';
	if (profile === 'essential') return 'essential';
	if (profile === 'elementor-design' || profile === 'content-model' || profile === 'gutenberg' || profile === 'site-admin') {
		return profile;
	}
	return 'essential-static';
}

function filterGuidanceToRegistered(guidance: string[], registered: Set<string>): string[] {
	// Drop lines that tell the agent to call a stonewright-* tool that is not registered.
	return guidance.filter((line) => {
		const matches = line.match(/stonewright-[a-z0-9-]+/g) ?? [];
		if (matches.length === 0) return true;
		// Keep if any mentioned tool is registered, or if line is general advice.
		return matches.some((name) => registered.has(name)) || matches.every((name) => !name.startsWith('stonewright-'));
	});
}

function extractStructured(raw: unknown): Record<string, unknown> | null {
	if (!raw || typeof raw !== 'object') return null;
	const obj = raw as Record<string, unknown>;
	if (obj['structuredContent'] && typeof obj['structuredContent'] === 'object') {
		return obj['structuredContent'] as Record<string, unknown>;
	}
	if (Array.isArray(obj['content'])) {
		for (const part of obj['content']) {
			if (part && typeof part === 'object' && typeof (part as { text?: string }).text === 'string') {
				try {
					const parsed = JSON.parse((part as { text: string }).text) as unknown;
					if (parsed && typeof parsed === 'object') return parsed as Record<string, unknown>;
				} catch {
					// ignore
				}
			}
		}
	}
	return obj;
}

export { PERMANENT_GATEWAY_TOOL_NAME_SET, PERMANENT_GATEWAY_TOOL_NAMES };
