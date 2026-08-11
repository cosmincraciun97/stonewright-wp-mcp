/**
 * MCP server for the Stonewright companion.
 *
 * Permanent local gateways register first; WordPress proxy / Direct tools follow.
 */

import { McpServer } from '@modelcontextprotocol/sdk/server/mcp.js';
import { z } from 'zod';
import {
	runWpCli,
	runWpCliBatch,
	getWpCliJob,
	startWpCliJob,
	wpCliDiscover,
	wpCliInstall,
	wpCliStatus,
	type WpCliDiscoverInput,
	type WpCliBatchRunInput,
	type WpCliInstallInput,
	type WpCliJobGetInput,
	type WpCliJobStartInput,
	type WpCliRunInput,
} from './wp-cli.js';
import { MCP_MISSING_BOOTSTRAP_STOP, buildToolInventory } from './setup-profile.js';
import {
	STARTUP_REQUIRED_PROXY_TOOL_NAMES,
	type ProxyToolProfile,
	emitToolListChanged,
	mergeServerInstructions,
	proxyToolProfileFromEnv,
	proxyToolNamesForProfile,
	registerWordPressMcpPrompts,
	registerWordPressMcpTools,
	resolveWordPressMcpConfig,
} from './wordpress-mcp.js';
import { APP_VERSION } from './version.js';
import { registerDirectTools, DIRECT_TOOL_NAMES, type DirectToolProfile } from './direct/registry.js';
import { resolveRuntimeMode, type ProbeResult } from './direct/mode.js';
import { applySiteAliasToEnv } from './direct/apply-site-env.js';
import { PLUGIN_ONLY_CAPABILITIES } from './direct/tools/site-discover.js';
import {
	PERMANENT_GATEWAY_TOOL_NAMES,
	PERMANENT_GATEWAY_TOOL_NAME_SET,
	type ReconnectInput,
	type ReconnectResult,
} from './connection/index.js';
import {
	createConnectionRuntime,
	registerPermanentGateways,
	type ConnectionRuntime,
	type WordPressMcpConnectionStatus,
} from './connection/runtime.js';

export type { WordPressMcpConnectionStatus };

export interface CreateMcpServerOptions {
	env?: NodeJS.ProcessEnv;
	fetchImpl?: typeof fetch;
}

const LOCAL_RECOVERY_TOOL_NAMES = [
	...PERMANENT_GATEWAY_TOOL_NAMES,
	'stonewright-wp-cli-status',
	'stonewright-wp-cli-discover',
	'stonewright-wp-cli-run',
	'stonewright-wp-cli-batch-run',
	'stonewright-wp-cli-job-start',
	'stonewright-wp-cli-job-status',
	'stonewright-wp-cli-install',
] as const;

const LOW_TOOLS_LOCAL_RECOVERY_TOOL_NAMES = [
	...PERMANENT_GATEWAY_TOOL_NAMES,
	'stonewright-wp-cli-status',
	'stonewright-wp-cli-batch-run',
	'stonewright-wp-cli-job-start',
	'stonewright-wp-cli-job-status',
] as const;

const LEGACY_LOCAL_TOOL_NAMES = [
	'companion_wp_cli_status',
	'companion_wp_cli_discover',
	'companion_wp_cli_run',
	'companion_wp_cli_batch_run',
	'companion_wp_cli_install',
] as const;

const LOCAL_TOOL_NAMES = [
	...LEGACY_LOCAL_TOOL_NAMES,
	...LOCAL_RECOVERY_TOOL_NAMES,
] as const;

export async function createMcpServer(options: CreateMcpServerOptions = {}): Promise<McpServer> {
	const env = options.env ?? process.env;
	// Multi-site connect installs only STONEWRIGHT_SITE_ALIAS in client config.
	// Resolve that alias from the local registry and inject URL/username/password
	// before mode probe / WordPress MCP config / Direct registration.
	applySiteAliasToEnv(env);
	const profile = proxyToolProfileFromEnv(env);
	const fetchImpl = options.fetchImpl ?? fetch;
	const runtime = createConnectionRuntime({
		env,
		profile,
		...(options.fetchImpl ? { fetchImpl: options.fetchImpl } : {}),
	});

	// Companion-only text first; plugin instructions are merged after remote init
	// (AI client connects after createMcpServer returns, so late set is safe).
	const server = new McpServer({
		name: 'stonewright-companion',
		version: APP_VERSION,
	}, {
		instructions: companionInstructions(profile),
	});

	const commonInput = {
		cwd: z.string().optional(),
		path: z.string().optional(),
		url: z.string().optional(),
		user: z.string().optional(),
		wp_cli_context: z.enum(['auto', 'admin', 'cli', 'frontend']).optional(),
		context: z.string().optional(),
		timeoutMs: z.number().int().positive().optional(),
	};

	// 1) Permanent gateways BEFORE any remote handshake (local-ready).
	registerPermanentGateways(server, runtime);
	// 2) WP-CLI helpers (local; not removed by profiles).
	registerWpCliTools(server, commonInput, env, profile);
	runtime.refreshSurfaceFromServer();

	// Wire reconnect after helpers exist so it can re-run mode probe + registration.
	runtime.performReconnect = async (input: ReconnectInput): Promise<ReconnectResult> => {
		return performReconnect(server, runtime, options, input);
	};

	await bootstrapConnection(server, runtime, options, fetchImpl);
	return server;
}

async function bootstrapConnection(
	server: McpServer,
	runtime: ConnectionRuntime,
	options: CreateMcpServerOptions,
	fetchImpl: typeof fetch,
): Promise<void> {
	const env = runtime.env;
	const profile = runtime.profile;
	const wpMcpStatus = runtime.status;

	runtime.stateMachine.transition('probing');
	wpMcpStatus.connection_stage = runtime.stateMachine.getStage();

	const modeProbe = await resolveRuntimeMode({
		env,
		fetchImpl,
	});
	wpMcpStatus.mode = modeProbe.mode;
	wpMcpStatus.mode_reason = modeProbe.reason;
	wpMcpStatus.configured_mode = modeProbe.configured;
	runtime.wpReachable = modeProbe.pluginEndpointStatus !== null ? true : null;

	if (modeProbe.mode === 'direct') {
		// plugin-only must never fall into Direct tools (resolveRuntimeMode already
		// returns plugin for plugin-only; this branch is auto/direct-only).
		await registerDirectMode(server, env, options, runtime, modeProbe, profile);
		return;
	}

	// Plugin path (plugin-only or auto preferring plugin).
	runtime.stateMachine.transition('plugin-authenticated');
	let wpMcpConfig = null;
	try {
		wpMcpConfig = await resolveWordPressMcpConfig(env);
	} catch (err) {
		wpMcpStatus.configured = hasWordPressMcpConfig(env);
		wpMcpStatus.error = { message: err instanceof Error ? err.message : String(err) };
		wpMcpStatus.error_code = 'config_error';
		wpMcpStatus.next_action = 'Fix WordPress MCP config, then call stonewright-reconnect.';
		runtime.stateMachine.transition('degraded', { error: wpMcpStatus.error.message });
		runtime.syncLegacyStatus();
		return;
	}

	if (!wpMcpConfig) {
		runtime.authConfigured = false;
		runtime.authMethod = 'none';
		wpMcpStatus.configured = hasWordPressMcpConfig(env);
		// plugin-only fails closed: no Direct tools.
		if (modeProbe.configured === 'plugin-only') {
			wpMcpStatus.ok = false;
			wpMcpStatus.connected = false;
			wpMcpStatus.error = { message: 'Plugin-only mode: WordPress MCP is not configured.' };
			wpMcpStatus.error_code = 'plugin_unavailable';
			wpMcpStatus.next_action = 'Configure STONEWRIGHT_WP_URL and credentials, install the plugin, then reconnect.';
			runtime.stateMachine.transition('degraded', { error: wpMcpStatus.error.message });
			runtime.syncLegacyStatus();
			return;
		}
		// auto without config: keep local gateways; stay degraded/plugin path recovery.
		wpMcpStatus.ok = false;
		wpMcpStatus.connected = false;
		wpMcpStatus.error_code = 'not_configured';
		wpMcpStatus.next_action = 'Call stonewright-setup-profile and configure site credentials.';
		runtime.stateMachine.transition('degraded', { error: 'not_configured' });
		runtime.syncLegacyStatus();
		return;
	}
	const authEvidence = authEvidenceFromConfig(wpMcpConfig);
	runtime.authConfigured = authEvidence.configured;
	runtime.authMethod = authEvidence.method;

	wpMcpStatus.configured = true;
	wpMcpStatus.url = wpMcpConfig.url;
	runtime.stateMachine.transition('plugin-registering');
	wpMcpStatus.startup_ready = false;
	wpMcpStatus.connection_stage = 'plugin-registering';
	runtime.registry.beginStaging();

	try {
		const registration = await registerWordPressMcpTools(server, wpMcpConfig, fetchImpl, env);
		const promptSkills = await registerWordPressMcpPrompts(server, wpMcpConfig, fetchImpl);
		setServerInstructions(
			server,
			mergeServerInstructions(companionInstructions(profile), registration.remoteInstructions),
		);
		runtime.callRemoteTool = registration.callRemoteTool;
		runtime.registry.stageMany(
			registration.registeredTools.map((tool) => ({ name: tool.name, source: 'remote' as const })),
		);
		runtime.registry.commit();
		runtime.stateMachine.transition('plugin-ready', { bumpGeneration: true });

		wpMcpStatus.ok = true;
		wpMcpStatus.connected = true;
		runtime.wpReachable = true;
		wpMcpStatus.mode = 'plugin';
		wpMcpStatus.tool_profile = registration.profile;
		wpMcpStatus.live = registration.liveState;
		wpMcpStatus.remote_tool_count = registration.remoteTools.length;
		wpMcpStatus.proxied_tool_count = registration.registeredTools.length;
		wpMcpStatus.profile_filtered_tool_count = registration.filteredToolCount;
		wpMcpStatus.profile_filtered_tool_names = registration.profileFilteredToolNames;
		// Permanent gateways cover task-start; remaining startup tools may still be remote.
		wpMcpStatus.startup_missing_tool_names = missingStartupTools([
			...PERMANENT_GATEWAY_TOOL_NAMES,
			...registration.registeredTools.map((tool) => tool.name),
		]);
		wpMcpStatus.startup_ready = wpMcpStatus.startup_missing_tool_names.length === 0;
		const profileExpectedToolNames = proxyToolNamesForProfile(registration.profile);
		const localToolNames = localToolNamesForProfile(registration.profile);
		wpMcpStatus.profile_expected_tool_count = profileExpectedToolNames.length;
		wpMcpStatus.client_visible_expected_tool_count = profileExpectedToolNames.length + localToolNames.length;
		wpMcpStatus.tool_inventory = buildToolInventory(registration.profile, localToolNames);
		wpMcpStatus.profile_missing_tool_names = missingProfileTools(
			profileExpectedToolNames,
			[...PERMANENT_GATEWAY_TOOL_NAMES, ...registration.registeredTools.map((tool) => tool.name)],
			localToolNames,
		);
		wpMcpStatus.local_recovery_tool_names = Array.from(localRecoveryToolNamesForProfile(registration.profile));
		wpMcpStatus.local_tool_names = Array.from(localToolNames);
		wpMcpStatus.prompt_skill_count = promptSkills.length;
		wpMcpStatus.recovery = recoveryHints(
			registration.filteredToolCount,
			wpMcpStatus.startup_missing_tool_names.length,
			wpMcpStatus.profile_missing_tool_names.length,
		);
		wpMcpStatus.error = null;
		wpMcpStatus.error_code = null;
		wpMcpStatus.next_action = 'Call stonewright-task-start and follow fast_path.tool_profile.';
		runtime.refreshSurfaceFromServer({ forceBump: true });
		runtime.syncLegacyStatus();
	} catch (err) {
		const message = err instanceof Error ? err.message : String(err);
		runtime.registry.abort(message);
		// plugin-only: fail closed, no Direct fallback.
		if (modeProbe.configured === 'plugin-only') {
			wpMcpStatus.ok = false;
			wpMcpStatus.connected = false;
			wpMcpStatus.error = { message };
			wpMcpStatus.error_code = 'plugin_unavailable';
			wpMcpStatus.next_action = 'Fix plugin connectivity, then call stonewright-reconnect. Direct tools are not available in plugin-only mode.';
			runtime.stateMachine.transition('degraded', { error: message });
			runtime.syncLegacyStatus();
			return;
		}
		// auto: fall back to Direct when plugin registration fails with 404-like absence.
		if (modeProbe.configured === 'auto' && /404|not found|ECONNREFUSED/i.test(message)) {
			await registerDirectMode(server, env, options, runtime, {
				...modeProbe,
				mode: 'direct',
				reason: `Plugin registration failed (${message}); auto fallback to Direct.`,
			}, profile);
			return;
		}
		wpMcpStatus.ok = false;
		wpMcpStatus.connected = false;
		wpMcpStatus.error = { message };
		wpMcpStatus.error_code = 'connection_error';
		wpMcpStatus.next_action = 'Call stonewright-connect-doctor, fix credentials/URL, then stonewright-reconnect.';
		runtime.stateMachine.transition('degraded', { error: message });
		// Permanent gateways remain; prior registry empty on first boot.
		runtime.syncLegacyStatus();
	}
}

function authEvidenceFromConfig(config: NonNullable<Awaited<ReturnType<typeof resolveWordPressMcpConfig>>>): {
	configured: boolean;
	method: 'app-password' | 'authorization' | 'oauth' | 'none';
} {
	if (config.oauth) return { configured: true, method: 'oauth' };
	if (config.authorization) return { configured: true, method: 'authorization' };
	if (config.username && config.password) return { configured: true, method: 'app-password' };
	return { configured: false, method: 'none' };
}

async function performReconnect(
	server: McpServer,
	runtime: ConnectionRuntime,
	options: CreateMcpServerOptions,
	input: ReconnectInput,
): Promise<ReconnectResult> {
	const priorReady = runtime.registry.isReady;
	const priorGeneration = runtime.stateMachine.getGeneration();
	const priorRevision = runtime.surface.getRevision();
	const priorNames = runtime.listRegisteredToolNames();
	const previousCallRemote = runtime.callRemoteTool;
	const previousLive = runtime.status.live;
	const previousConnected = runtime.status.connected;
	const previousStartup = runtime.status.startup_ready;
	const previousAuthConfigured = runtime.authConfigured;
	const previousAuthMethod = runtime.authMethod;
	const previousWpReachable = runtime.wpReachable;

	try {
		// Re-run bootstrap path. Failed reconnect must preserve prior healthy registry:
		// we only clear remote call handle after a successful re-register.
		if (input.force_probe || runtime.status.configured_mode === 'auto') {
			runtime.stateMachine.transition('probing', { bumpGeneration: true });
		}

		const resumeListNotifications = pauseListNotifications(server);
		try {
			await bootstrapConnection(server, runtime, options, runtime.fetchImpl);
		} finally {
			resumeListNotifications();
		}

		if (!runtime.status.connected && priorReady) {
			// Restore prior healthy signals when reconnect failed.
			runtime.callRemoteTool = previousCallRemote;
			runtime.status.live = previousLive;
			runtime.status.connected = previousConnected;
			runtime.status.startup_ready = previousStartup;
			runtime.authConfigured = previousAuthConfigured;
			runtime.authMethod = previousAuthMethod;
			runtime.wpReachable = previousWpReachable;
			runtime.registry.abort(runtime.status.error?.message ?? 'reconnect failed');
			runtime.stateMachine.transition('degraded', {
				error: runtime.status.error?.message ?? 'reconnect failed',
			});
			// Keep prior surface names/digest; still bump revision so clients re-list.
			runtime.surface.bump(priorNames);
			runtime.syncLegacyStatus();
			await emitToolListChanged(server);
			await emitPromptListChanged(server);
			return {
				ok: false,
				coalesced: false,
				reason: input.reason,
				site_alias: input.site_alias ?? null,
				force_probe: Boolean(input.force_probe),
				connection_generation: runtime.stateMachine.getGeneration(),
				surface_revision: runtime.surface.getRevision(),
				prior_registry_preserved: true,
				error: runtime.status.error?.message ?? 'reconnect failed',
			};
		}

		runtime.refreshSurfaceFromServer({ forceBump: true });
		await emitToolListChanged(server);
		await emitPromptListChanged(server);
		return {
			ok: runtime.status.connected || runtime.status.mode === 'direct',
			coalesced: false,
			reason: input.reason,
			site_alias: input.site_alias ?? null,
			force_probe: Boolean(input.force_probe),
			connection_generation: runtime.stateMachine.getGeneration(),
			surface_revision: runtime.surface.getRevision(),
			prior_registry_preserved: false,
			error: runtime.status.error?.message ?? null,
		};
	} catch (err) {
		const message = err instanceof Error ? err.message : String(err);
		if (priorReady) {
			runtime.callRemoteTool = previousCallRemote;
			runtime.status.live = previousLive;
			runtime.status.connected = previousConnected;
			runtime.status.startup_ready = previousStartup;
			runtime.authConfigured = previousAuthConfigured;
			runtime.authMethod = previousAuthMethod;
			runtime.wpReachable = previousWpReachable;
		}
		runtime.registry.abort(message);
		runtime.surface.bump(priorNames);
		runtime.syncLegacyStatus();
		await emitToolListChanged(server);
		await emitPromptListChanged(server);
		return {
			ok: false,
			coalesced: false,
			reason: input.reason,
			site_alias: input.site_alias ?? null,
			force_probe: Boolean(input.force_probe),
			connection_generation: priorGeneration,
			surface_revision: priorRevision + 1,
			prior_registry_preserved: priorReady,
			error: message,
		};
	}
}

/**
 * MCP SDK registration helpers notify after every handle mutation. Reconnect
 * stages a complete replacement catalog, so suppress those intermediate
 * notifications and publish one tool/prompt list change after commit/abort.
 */
function pauseListNotifications(server: McpServer): () => void {
	const mutable = server as unknown as {
		sendToolListChanged?: () => void | Promise<void>;
		sendPromptListChanged?: () => void | Promise<void>;
	};
	const ownTool = Object.getOwnPropertyDescriptor(mutable, 'sendToolListChanged');
	const ownPrompt = Object.getOwnPropertyDescriptor(mutable, 'sendPromptListChanged');
	Object.defineProperty(mutable, 'sendToolListChanged', {
		configurable: true,
		writable: true,
		value: () => undefined,
	});
	Object.defineProperty(mutable, 'sendPromptListChanged', {
		configurable: true,
		writable: true,
		value: () => undefined,
	});
	return () => {
		if (ownTool) Object.defineProperty(mutable, 'sendToolListChanged', ownTool);
		else delete mutable.sendToolListChanged;
		if (ownPrompt) Object.defineProperty(mutable, 'sendPromptListChanged', ownPrompt);
		else delete mutable.sendPromptListChanged;
	};
}

async function emitPromptListChanged(server: McpServer): Promise<boolean> {
	try {
		const inner = (server as unknown as {
			server?: { sendPromptListChanged?: () => void | Promise<void> };
		}).server;
		if (!inner?.sendPromptListChanged) return false;
		await Promise.resolve(inner.sendPromptListChanged());
		return true;
	} catch {
		return false;
	}
}

/** SDK stores instructions on the inner Server; mutate before client connect. */
function setServerInstructions(server: McpServer, instructions: string): void {
	const inner = (server as unknown as { server?: { _instructions?: string } }).server;
	if (inner) {
		inner._instructions = instructions;
	}
}

async function registerDirectMode(
	server: McpServer,
	env: NodeJS.ProcessEnv,
	options: CreateMcpServerOptions,
	runtime: ConnectionRuntime,
	modeProbe: ProbeResult,
	profile: ProxyToolProfile,
): Promise<void> {
	const wpMcpStatus = runtime.status;
	wpMcpStatus.mode = 'direct';
	wpMcpStatus.mode_reason = modeProbe.reason;
	wpMcpStatus.configured_mode = modeProbe.configured;
	wpMcpStatus.unavailable_plugin_capabilities = PLUGIN_ONLY_CAPABILITIES.map((cap) => ({
		id: cap.id,
		label: cap.label,
		reason: cap.reason,
		upgrade: cap.upgrade,
	}));

	try {
		const { seedBuiltinSkills } = await import('./direct/skills-store.js');
		const { ensureStonewrightAgentsMd } = await import('./direct/agents-md.js');
		seedBuiltinSkills(undefined, env);
		ensureStonewrightAgentsMd(undefined, env);
		const directProfile = directToolProfileFromEnv(env, profile);
		const registered = registerDirectTools(server, {
			env,
			...(options.fetchImpl ? { fetchImpl: options.fetchImpl } : {}),
			// Explicit Direct override wins; otherwise the shared MCP surface drives
			// progressive registration. essential-static expands after task-start.
			toolProfile: directProfile,
			skipToolNames: PERMANENT_GATEWAY_TOOL_NAME_SET,
			onSessionReady: (controls) => {
				runtime.directSession = controls;
			},
			onSurfaceChange: (revision, liveProfile) => {
				wpMcpStatus.surface_revision = revision;
				wpMcpStatus.tool_profile = liveProfile;
				runtime.refreshSurfaceFromServer();
			},
		});
		const prompts = (server as unknown as {
			_registeredPrompts?: Record<string, { enabled?: boolean; disable?: () => void }>;
		})._registeredPrompts ?? {};
		for (const [name, prompt] of Object.entries(prompts)) {
			if (name.startsWith('stonewright-skill-') && prompt.enabled !== false) {
				prompt.disable?.();
			}
		}
		setServerInstructions(server, companionInstructions(profile, 'direct'));
		const localToolNames = localToolNamesForProfile(profile);
		runtime.registry.commitDirect(
			registered.map((name) => ({ name, source: 'direct' as const })),
		);
		runtime.stateMachine.transition('direct-ready', { bumpGeneration: true });

		wpMcpStatus.ok = true;
		wpMcpStatus.connected = true;
		// Direct is a committed replacement surface, not a degraded Plugin
		// session. Drop every Plugin-only live signal so status and recovery
		// gateways cannot advertise tools from the previous remote catalog.
		runtime.callRemoteTool = null;
		wpMcpStatus.live = null;
		wpMcpStatus.configured = hasWordPressMcpConfig(env) || Boolean(env['STONEWRIGHT_WP_USERNAME']);
		wpMcpStatus.url = modeProbe.endpoint;
		wpMcpStatus.tool_profile = directProfile;
		wpMcpStatus.direct_tool_count = registered.length;
		wpMcpStatus.direct_tool_names = registered.slice(0, 40);
		wpMcpStatus.startup_ready = true;
		wpMcpStatus.startup_missing_tool_names = [];
		wpMcpStatus.startup_required_tool_names = ['stonewright-task-start'];
		wpMcpStatus.remote_tool_count = registered.length;
		wpMcpStatus.proxied_tool_count = 0;
		wpMcpStatus.profile_filtered_tool_count = 0;
		wpMcpStatus.profile_filtered_tool_names = [];
		wpMcpStatus.prompt_skill_count = 0;
		wpMcpStatus.profile_expected_tool_count = registered.length;
		wpMcpStatus.client_visible_expected_tool_count = registered.length + localToolNames.length;
		wpMcpStatus.local_recovery_tool_names = Array.from(localRecoveryToolNamesForProfile(profile));
		wpMcpStatus.local_tool_names = Array.from(localToolNames);
		wpMcpStatus.tool_inventory = buildToolInventory(
			profile,
			[...localToolNames, ...registered],
			'direct',
		);
		wpMcpStatus.profile_missing_tool_names = [];
		wpMcpStatus.refresh_required_tool_names = [
			'stonewright-task-start',
			'stonewright-rules-get',
			'stonewright-site-discover',
			'stonewright-setup-profile',
		];
		wpMcpStatus.recovery = [
			'Direct mode is active: core REST tools are registered without the Stonewright plugin.',
			'Call stonewright-task-start first; the compact Direct task profile unlocks for this session.',
			'Use stonewright-site-discover when endpoint or plugin-only capability details are needed.',
			'Direct local memory and user skills remain available. Install the plugin only when its native mutation engine, php-execute, or production-safe confirmation tokens are required.',
			'Set STONEWRIGHT_MODE=plugin after installing the plugin, then call stonewright-reconnect or restart the MCP client.',
		];
		wpMcpStatus.error = null;
		wpMcpStatus.error_code = null;
		wpMcpStatus.next_action = 'Call stonewright-task-start (works with zero WordPress credentials).';
		wpMcpStatus.agent_use_instead = [
			'stonewright-task-start',
			'stonewright-site-discover',
			'stonewright-setup-profile',
			'stonewright-content-list',
			'stonewright-wp-cli-status',
			'stonewright-wp-cli-run',
			...DIRECT_TOOL_NAMES.slice(0, 8),
		];
		runtime.refreshSurfaceFromServer({ forceBump: true });
		runtime.syncLegacyStatus();
	} catch (err) {
		wpMcpStatus.ok = false;
		wpMcpStatus.connected = false;
		wpMcpStatus.error = { message: err instanceof Error ? err.message : String(err) };
		wpMcpStatus.error_code = 'direct_registration_failed';
		runtime.stateMachine.transition('degraded', { error: wpMcpStatus.error.message });
		runtime.syncLegacyStatus();
	}
}

function directToolProfileFromEnv(env: NodeJS.ProcessEnv, profile: ProxyToolProfile): DirectToolProfile {
	const explicit = (env['STONEWRIGHT_DIRECT_TOOL_PROFILE'] ?? '').trim().toLowerCase();
	if (['bootstrap', 'essential-static', 'essential', 'elementor-design', 'content-model', 'gutenberg', 'site-admin', 'full'].includes(explicit)) {
		return explicit as DirectToolProfile;
	}
	if (['bootstrap', 'essential-static', 'essential', 'elementor-design', 'content-model', 'gutenberg', 'site-admin', 'full'].includes(profile)) {
		return profile as DirectToolProfile;
	}
	return 'essential-static';
}

function companionInstructions(
	profile: ProxyToolProfile,
	mode: 'plugin' | 'direct' = 'plugin',
): string {
	const lines = [
		'Stonewright companion fast start:',
		`- Current compact profile: ${profile}.`,
		'- First call stonewright-setup-profile if connection, credentials, or tool visibility is unclear.',
		'- For WordPress work, call stonewright-task-start and follow fast_path.tool_profile (works in plugin and Direct modes). Use stonewright-context-bootstrap only for the compatibility bootstrap path.',
		'- In Direct (pluginless) mode, stonewright-task-start returns locally stored skills and memory for this site (or _global). Load matched skill bodies with stonewright-skill-get only when needed; record corrections with stonewright-learning-record.',
		'- Never guess WordPress/Elementor/Gutenberg schemas — read first, research official docs when unknown, verify after writes.',
		'- First Direct-mode session on a machine: offer stonewright-agents-md-sync so future sessions in any AI client auto-discover ~/.stonewright/AGENTS.md.',
		`- ${MCP_MISSING_BOOTSTRAP_STOP}`,
		'- Do not inspect private AI-client config files to find or call Stonewright.',
		'- Do not create scratch scripts such as query-mcp.js or run-ability.js to bypass the MCP client tool surface.',
		'- Do not create helper JSON argument files such as bootstrap-args.json, cli_command.json, or get_structure.json to bypass typed MCP tool input.',
		'- Do not launch the Stonewright companion from ad hoc shell scripts such as query-local-stonewright.js to bypass the MCP client tool list.',
		'- Do not create or modify action scripts such as run-loop-mutate.js or run-bootstrap-and-mutate.js to bypass typed Stonewright tool calls.',
		'- Do not inspect plugin or companion source code to reverse-engineer tool schemas during WordPress implementation tasks.',
		'- Do not hand-roll JSON-RPC calls to /mcp or /wp-json/mcp/stonewright as an MCP workaround.',
		'- Do not call /wp-json/stonewright/v1/abilities/run from shell as an MCP workaround.',
		'- Use stonewright-wp-cli-status, stonewright-wp-cli-discover, stonewright-wp-cli-run, and stonewright-wp-cli-batch-run for tokenized WP-CLI work.',
		'- Use stonewright-wp-cli-job-start and stonewright-wp-cli-job-status for long imports, plugin operations, cache rebuilds, media work, or large batches when those tools are visible.',
	];

	if (mode === 'direct') {
		lines.push(
			'- Direct mode is first-class and keeps its own local memory, user skills, redacted audit, task profiles, and typed WordPress tools.',
			'- Direct has no stonewright-php-execute. Do not replace it with shell PHP, WP-CLI eval, another MCP adapter, or an ad hoc REST runner.',
			'- Use stonewright-wordpress-mcp-status to confirm mode, version, startup readiness, and the live Direct tool surface.',
			'- Do not run wp commands in a normal shell. Use only typed Direct tools or the tokenized companion WP-CLI tools.',
		);
	} else {
		lines.push(
			'- Use stonewright-php-execute for direct full WordPress runtime access when a short PHP snippet is faster than many typed calls.',
			'- Use stonewright-wordpress-mcp-status only to diagnose the Stonewright MCP connection when proxied WordPress tools are missing.',
			'- If a needed tool (e.g. php-execute) is missing from the client list while status is connected, call stonewright-client-surface-check then task-start/tool-profile and re-list tools — never call /abilities/run.',
			'- Do not run wp commands in a normal shell. Use stonewright-php-execute for PHP snippets instead of WP-CLI eval, shell, package, --exec, or --require entry points.',
		);
	}

	if (profile === 'low-tools') {
		lines.push('- This session is strict-cap mode: keep STONEWRIGHT_MCP_TOOL_PROFILE=low-tools, use the visible fast-path tools, and switch to a specialist profile only when required.');
	}

	return lines.join('\n');
}

function hasWordPressMcpConfig(env: NodeJS.ProcessEnv): boolean {
	return Boolean((env['STONEWRIGHT_MCP_URL'] ?? env['WP_API_URL'] ?? env['STONEWRIGHT_WP_URL'] ?? '').trim());
}

function missingStartupTools(registeredToolNames: string[]): string[] {
	const registered = new Set(registeredToolNames);
	return STARTUP_REQUIRED_PROXY_TOOL_NAMES.filter((name) => !registered.has(name));
}

function missingProfileTools(profileToolNames: string[], registeredToolNames: string[], localToolNames: readonly string[]): string[] {
	const available = new Set([...registeredToolNames, ...localToolNames]);
	return profileToolNames.filter((name) => !available.has(name));
}

function localRecoveryToolNamesForProfile(profile: ProxyToolProfile): readonly string[] {
	return profile === 'low-tools' ? LOW_TOOLS_LOCAL_RECOVERY_TOOL_NAMES : LOCAL_RECOVERY_TOOL_NAMES;
}

function localToolNamesForProfile(profile: ProxyToolProfile): readonly string[] {
	if (profile === 'low-tools') return LOW_TOOLS_LOCAL_RECOVERY_TOOL_NAMES;
	return profile === 'full' ? LOCAL_TOOL_NAMES : LOCAL_RECOVERY_TOOL_NAMES;
}

function recoveryHints(profileFilteredToolCount: number, startupMissingToolCount: number, profileMissingToolCount: number): string[] {
	const hints = [
		'Verify STONEWRIGHT_WP_URL or STONEWRIGHT_MCP_URL points to /wp-json/mcp/stonewright.',
		'Verify STONEWRIGHT_WP_USERNAME plus STONEWRIGHT_WP_APP_PASSWORD or STONEWRIGHT_MCP_AUTHORIZATION.',
		'Keep using stonewright-setup-profile and stonewright-wp-cli-status while fixing the WordPress MCP connection.',
	];
	if (startupMissingToolCount > 0) {
		hints.push('If startup_ready is false, update/enable the missing startup tools in the WordPress Stonewright plugin, then restart the MCP session.');
	}
	if (profileFilteredToolCount > 0) {
		hints.push('If a needed WordPress MCP tool is absent and profile_filtered_tool_count is greater than 0, switch STONEWRIGHT_MCP_TOOL_PROFILE to a narrower task profile or full, then restart the MCP session.');
	}
	if (profileMissingToolCount > 0) {
		hints.push('If profile_missing_tool_names is not empty, update or enable those WordPress Stonewright tools, or switch STONEWRIGHT_MCP_TOOL_PROFILE to full for specialist recovery.');
	}
	return hints;
}

function registerWpCliTools(
	server: McpServer,
	commonInput: Record<string, z.ZodTypeAny>,
	env: NodeJS.ProcessEnv,
	profile: ProxyToolProfile,
): void {
	for (const name of localAliases(profile, 'stonewright-wp-cli-status', 'companion_wp_cli_status')) {
		server.registerTool(
			name,
			{
				description: 'Check whether WP-CLI is available and return wp cli info diagnostics. This runs directly inside the Stonewright companion.',
				inputSchema: {
					...commonInput,
					deep: z.boolean().optional(),
				},
			},
			async (input) => toolResponse(await wpCliStatus(toWpCliInput(input), undefined, env)),
		);
	}

	for (const name of localAliases(profile, 'stonewright-wp-cli-discover', 'companion_wp_cli_discover')) {
		server.registerTool(
			name,
			{
				description: 'Discover installed WP-CLI command metadata. Use responseMode=summary with commandFilter for token-efficient ACF, CPT UI, plugin, post, term, and option command discovery.',
				inputSchema: {
					...commonInput,
					commandFilter: z.array(z.string()).max(20).optional(),
					maxCommands: z.number().int().positive().max(500).optional(),
					responseMode: z.enum(['summary', 'full']).default('summary').optional(),
				},
			},
			async (input) => toolResponse(await wpCliDiscover(toWpCliInput(input) as WpCliDiscoverInput, undefined, env)),
		);
	}

	for (const name of localAliases(profile, 'stonewright-wp-cli-run', 'companion_wp_cli_run')) {
		server.registerTool(
			name,
			{
				description: 'Run a tokenized WP-CLI command directly through the Stonewright companion with execFile. Allows WordPress write commands; use stonewright-php-execute for PHP runtime snippets instead of WP-CLI eval or shell entry points.',
				inputSchema: {
					...commonInput,
					command: z.array(z.string()).min(1),
					parseJson: z.boolean().optional(),
					responseMode: z.enum(['full', 'summary']).optional(),
				},
			},
			async (input) => toolResponse(await runWpCli(toWpCliInput(input) as WpCliRunInput, undefined, env)),
		);
	}

	for (const name of localAliases(profile, 'stonewright-wp-cli-batch-run', 'companion_wp_cli_batch_run')) {
		server.registerTool(
			name,
			{
				description: 'Run multiple tokenized WP-CLI commands through the Stonewright companion in one UTF-8 JSON request. Use this for repeated post/meta/term/media/option work instead of large inline shell scripts; use stonewright-php-execute for PHP runtime snippets.',
				inputSchema: {
					...commonInput,
					commands: z.array(z.array(z.string()).min(1)).min(1).max(100),
					parseJson: z.boolean().optional(),
					stopOnError: z.boolean().optional(),
					responseMode: z.enum(['full', 'summary']).optional(),
				},
			},
			async (input) => toolResponse(await runWpCliBatch(toWpCliInput(input) as WpCliBatchRunInput, undefined, env)),
		);
	}

	server.registerTool(
		'stonewright-wp-cli-job-start',
		{
			description: 'Start a tokenized WP-CLI command or batch as an in-process background job. Use for long plugin, import, cache, media, or content operations so the MCP request returns immediately.',
			inputSchema: {
				...commonInput,
				command: z.array(z.string()).min(1).optional(),
				commands: z.array(z.array(z.string()).min(1)).min(1).max(100).optional(),
				parseJson: z.boolean().optional(),
				stopOnError: z.boolean().optional(),
				responseMode: z.enum(['full', 'summary']).optional(),
			},
		},
		(input) => toolResponse(startWpCliJob(toWpCliInput(input) as WpCliJobStartInput, undefined, env)),
	);

	server.registerTool(
		'stonewright-wp-cli-job-status',
		{
			description: 'Poll a Stonewright companion WP-CLI background job by jobId and return compact status plus result when complete.',
			inputSchema: {
				jobId: z.string().optional(),
				job_id: z.string().optional(),
			},
		},
		(input) => toolResponse(getWpCliJob(input as WpCliJobGetInput)),
	);

	if (profile === 'low-tools') {
		return;
	}

	for (const name of localAliases(profile, 'stonewright-wp-cli-install', 'companion_wp_cli_install')) {
		server.registerTool(
			name,
			{
				description: 'Download the official WP-CLI phar into Stonewright companion cache so future stonewright-wp-cli-* calls can run even when wp is not on PATH.',
				inputSchema: {
					installDir: z.string().optional(),
					force: z.boolean().optional(),
					expectedSha256: z.string().optional(),
					timeoutMs: z.number().int().positive().optional(),
				},
			},
			async (input) => toolResponse(await wpCliInstall(input as WpCliInstallInput, fetch, env)),
		);
	}
}

function localAliases(profile: ProxyToolProfile, canonical: string, legacy: string): string[] {
	if (!localToolNamesForProfile(profile).includes(canonical)) return [];
	return profile === 'full' ? [legacy, canonical] : [canonical];
}

function toWpCliInput(input: Record<string, unknown>): Partial<WpCliRunInput> {
	return Object.fromEntries(Object.entries(input).filter(([, value]) => value !== undefined)) as Partial<WpCliRunInput>;
}

function toolResponse<T extends Record<string, unknown>>(result: T): {
	content: Array<{ type: 'text'; text: string }>;
	structuredContent: T;
} {
	return {
		content: [
			{
				type: 'text',
				text: JSON.stringify(result),
			},
		],
		structuredContent: result,
	};
}
