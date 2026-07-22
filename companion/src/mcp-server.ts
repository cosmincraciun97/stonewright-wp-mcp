/**
 * MCP server for the Stonewright companion.
 *
 * WordPress-facing helpers such as WP-CLI are registered here.
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
import { AGENT_DO_NOT_USE, MCP_MISSING_BOOTSTRAP_STOP, agentUseInstead, buildSetupProfile, buildToolInventory, type ToolInventory } from './setup-profile.js';
import {
	STARTUP_REQUIRED_PROXY_TOOL_NAMES,
	type ProxyToolProfile,
	mergeServerInstructions,
	proxyToolProfileFromEnv,
	proxyToolNamesForProfile,
	registerWordPressMcpPrompts,
	registerWordPressMcpTools,
	resolveWordPressMcpConfig,
} from './wordpress-mcp.js';
import { APP_VERSION, companionPackageSpec } from './version.js';
import { registerDirectTools, DIRECT_TOOL_NAMES, type DirectToolProfile } from './direct/registry.js';
import { resolveRuntimeMode, type ProbeResult } from './direct/mode.js';
import { PLUGIN_ONLY_CAPABILITIES } from './direct/tools/site-discover.js';

interface WordPressMcpConnectionStatus extends Record<string, unknown> {
	ok: boolean;
	configured: boolean;
	connected: boolean;
	url: string | null;
	tool_profile: string | null;
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
	direct_tool_count: number;
	direct_tool_names: string[];
	unavailable_plugin_capabilities: Array<{ id: string; label: string; reason: string; upgrade: string }>;
}

export interface CreateMcpServerOptions {
	env?: NodeJS.ProcessEnv;
	fetchImpl?: typeof fetch;
}

const LOCAL_RECOVERY_TOOL_NAMES = [
	'stonewright-setup-profile',
	'stonewright-wordpress-mcp-status',
	'stonewright-client-surface-check',
	'stonewright-wp-cli-status',
	'stonewright-wp-cli-discover',
	'stonewright-wp-cli-run',
	'stonewright-wp-cli-batch-run',
	'stonewright-wp-cli-job-start',
	'stonewright-wp-cli-job-status',
	'stonewright-wp-cli-install',
] as const;

const LOW_TOOLS_LOCAL_RECOVERY_TOOL_NAMES = [
	'stonewright-setup-profile',
	'stonewright-wordpress-mcp-status',
	// Keep low-tools under the 12-tool cap; surface-check stays on normal profiles.
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
	const profile = proxyToolProfileFromEnv(env);
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

	registerWpCliTools(server, commonInput, env, profile);
	registerSetupTools(server, env);
	const wpMcpStatus = createWordPressMcpConnectionStatus(profile);
	registerWordPressMcpStatusTool(server, wpMcpStatus);
	// Diagnostic surface stays off low-tools so strict clients remain ≤12 tools.
	if (profile !== 'low-tools') {
		registerClientSurfaceCheckTool(server, wpMcpStatus, env);
	}

	const modeProbe = await resolveRuntimeMode({
		env,
		...(options.fetchImpl ? { fetchImpl: options.fetchImpl } : {}),
	});
	wpMcpStatus.mode = modeProbe.mode;
	wpMcpStatus.mode_reason = modeProbe.reason;

	if (modeProbe.mode === 'direct') {
		await registerDirectMode(server, env, options, wpMcpStatus, modeProbe, profile);
		return server;
	}

	let wpMcpConfig = null;
	try {
		wpMcpConfig = await resolveWordPressMcpConfig(env);
	} catch (err) {
		wpMcpStatus.configured = hasWordPressMcpConfig(env);
		wpMcpStatus.error = { message: err instanceof Error ? err.message : String(err) };
	}
	if (wpMcpConfig) {
		wpMcpStatus.configured = true;
		wpMcpStatus.url = wpMcpConfig.url;
		try {
			const registration = await registerWordPressMcpTools(server, wpMcpConfig, options.fetchImpl ?? fetch, env);
			const promptSkills = await registerWordPressMcpPrompts(server, wpMcpConfig, options.fetchImpl ?? fetch);
			// Forward plugin initialize.instructions so clients see task-start + site rules.
			setServerInstructions(
				server,
				mergeServerInstructions(companionInstructions(profile), registration.remoteInstructions),
			);
			wpMcpStatus.ok = true;
			wpMcpStatus.connected = true;
			wpMcpStatus.mode = 'plugin';
			wpMcpStatus.tool_profile = registration.profile;
			wpMcpStatus.remote_tool_count = registration.remoteTools.length;
			wpMcpStatus.proxied_tool_count = registration.registeredTools.length;
			wpMcpStatus.profile_filtered_tool_count = registration.filteredToolCount;
			wpMcpStatus.profile_filtered_tool_names = registration.profileFilteredToolNames;
			wpMcpStatus.startup_missing_tool_names = missingStartupTools(registration.registeredTools.map((tool) => tool.name));
			wpMcpStatus.startup_ready = wpMcpStatus.startup_missing_tool_names.length === 0;
			const profileExpectedToolNames = proxyToolNamesForProfile(registration.profile);
			const localToolNames = localToolNamesForProfile(registration.profile);
			wpMcpStatus.profile_expected_tool_count = profileExpectedToolNames.length;
			wpMcpStatus.client_visible_expected_tool_count = profileExpectedToolNames.length + localToolNames.length;
			wpMcpStatus.tool_inventory = buildToolInventory(registration.profile, localToolNames);
			wpMcpStatus.profile_missing_tool_names = missingProfileTools(
				profileExpectedToolNames,
				registration.registeredTools.map((tool) => tool.name),
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
		} catch (err) {
			wpMcpStatus.ok = false;
			wpMcpStatus.connected = false;
			wpMcpStatus.error = { message: err instanceof Error ? err.message : String(err) };
		}
	}

	return server;
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
	wpMcpStatus: WordPressMcpConnectionStatus,
	modeProbe: ProbeResult,
	profile: ProxyToolProfile,
): Promise<void> {
	wpMcpStatus.mode = 'direct';
	wpMcpStatus.mode_reason = modeProbe.reason;
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
		const registered = registerDirectTools(server, {
			env,
			...(options.fetchImpl ? { fetchImpl: options.fetchImpl } : {}),
			// Explicit Direct override wins; otherwise the shared MCP surface drives
			// progressive registration. Bootstrap expands after task-start.
			toolProfile: directToolProfileFromEnv(env, profile),
		});
		const localToolNames = localToolNamesForProfile(profile);
		wpMcpStatus.ok = true;
		wpMcpStatus.connected = true;
		wpMcpStatus.configured = hasWordPressMcpConfig(env) || Boolean(env['STONEWRIGHT_WP_USERNAME']);
		wpMcpStatus.url = modeProbe.endpoint;
		wpMcpStatus.tool_profile = profile;
		wpMcpStatus.direct_tool_count = registered.length;
		wpMcpStatus.direct_tool_names = registered.slice(0, 40);
		wpMcpStatus.startup_ready = true;
		wpMcpStatus.startup_missing_tool_names = [];
		wpMcpStatus.startup_required_tool_names = ['stonewright-task-start'];
		wpMcpStatus.remote_tool_count = registered.length;
		wpMcpStatus.proxied_tool_count = 0;
		wpMcpStatus.profile_expected_tool_count = registered.length;
		wpMcpStatus.client_visible_expected_tool_count = registered.length + localToolNames.length;
		wpMcpStatus.local_recovery_tool_names = Array.from(localRecoveryToolNamesForProfile(profile));
		wpMcpStatus.local_tool_names = Array.from(localToolNames);
		wpMcpStatus.tool_inventory = buildToolInventory(profile, [...localToolNames, ...registered.slice(0, 12)]);
		wpMcpStatus.profile_missing_tool_names = [];
		wpMcpStatus.refresh_required_tool_names = [
			'stonewright-site-discover',
			'stonewright-setup-profile',
			'stonewright-wp-cli-status',
		];
		wpMcpStatus.recovery = [
			'Direct mode is active: core REST tools are registered without the Stonewright plugin.',
			'Call stonewright-task-start first; Bootstrap unlocks the compact Direct task profile for this session.',
			'Use stonewright-site-discover when endpoint or plugin-only capability details are needed.',
			'Install the Stonewright plugin for Elementor engine, php-execute, memory, and production-safe confirmation tokens.',
			'Set STONEWRIGHT_MODE=plugin after installing the plugin, then restart the MCP client.',
		];
		wpMcpStatus.error = null;
		wpMcpStatus.agent_use_instead = [
			'stonewright-site-discover',
			'stonewright-setup-profile',
			'stonewright-content-list',
			'stonewright-wp-cli-status',
			'stonewright-wp-cli-run',
			...DIRECT_TOOL_NAMES.slice(0, 8),
		];
	} catch (err) {
		wpMcpStatus.ok = false;
		wpMcpStatus.connected = false;
		wpMcpStatus.error = { message: err instanceof Error ? err.message : String(err) };
	}
}

function directToolProfileFromEnv(env: NodeJS.ProcessEnv, profile: ProxyToolProfile): DirectToolProfile {
	const explicit = (env['STONEWRIGHT_DIRECT_TOOL_PROFILE'] ?? '').trim().toLowerCase();
	if (['bootstrap', 'essential', 'elementor-design', 'content-model', 'gutenberg', 'site-admin', 'full'].includes(explicit)) {
		return explicit as DirectToolProfile;
	}
	if (['bootstrap', 'essential', 'elementor-design', 'content-model', 'gutenberg', 'site-admin', 'full'].includes(profile)) {
		return profile as DirectToolProfile;
	}
	return 'essential';
}

function companionInstructions(profile: ProxyToolProfile): string {
	const lines = [
		'Stonewright companion fast start:',
		`- Current compact profile: ${profile}.`,
		'- First call stonewright-setup-profile if connection, credentials, or tool visibility is unclear.',
		'- For WordPress work, call stonewright-task-start and follow fast_path.tool_profile (works in plugin and Direct modes). Use stonewright-context-bootstrap only for the compatibility bootstrap path.',
		'- In Direct (pluginless) mode, stonewright-task-start returns locally stored skills and memory for this site (or _global). Load matched skill bodies with stonewright-skill-get only when needed; record corrections with stonewright-learning-record.',
		'- Never guess WordPress/Elementor/Gutenberg schemas — read first, research official docs when unknown, verify after writes.',
		'- First Direct-mode session on a machine: offer stonewright-agents-md-sync so future sessions in any AI client auto-discover ~/.stonewright/AGENTS.md.',
		`- ${MCP_MISSING_BOOTSTRAP_STOP}`,
		'- Use stonewright-php-execute for direct full WordPress runtime access when a short PHP snippet is faster than many typed calls.',
		'- Use stonewright-wordpress-mcp-status only to diagnose the Stonewright MCP connection when proxied WordPress tools are missing.',
		'- If a needed tool (e.g. php-execute) is missing from the client list while status is connected, call stonewright-client-surface-check then task-start/tool-profile and re-list tools — never call /abilities/run.',
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
		'- Do not run wp commands in a normal shell. Use stonewright-php-execute for PHP snippets instead of WP-CLI eval, shell, package, --exec, or --require entry points.',
	];

	if (profile === 'low-tools') {
		lines.push('- This session is strict-cap mode: keep STONEWRIGHT_MCP_TOOL_PROFILE=low-tools, use the visible fast-path tools, and switch to a specialist profile only when required.');
	}

	return lines.join('\n');
}

function hasWordPressMcpConfig(env: NodeJS.ProcessEnv): boolean {
	return Boolean((env['STONEWRIGHT_MCP_URL'] ?? env['WP_API_URL'] ?? env['STONEWRIGHT_WP_URL'] ?? '').trim());
}

function createWordPressMcpConnectionStatus(profile: ProxyToolProfile): WordPressMcpConnectionStatus {
	const profileExpectedToolNames = proxyToolNamesForProfile(profile);
	const localToolNames = localToolNamesForProfile(profile);
	const profileMissingToolNames = missingProfileTools(profileExpectedToolNames, [], localToolNames);

	return {
		ok: false,
		configured: false,
		connected: false,
		url: null,
		tool_profile: profile,
		startup_ready: false,
		startup_required_tool_names: Array.from(STARTUP_REQUIRED_PROXY_TOOL_NAMES),
		startup_missing_tool_names: Array.from(STARTUP_REQUIRED_PROXY_TOOL_NAMES),
		local_recovery_tool_names: Array.from(localRecoveryToolNamesForProfile(profile)),
		local_tool_names: Array.from(localToolNames),
		profile_expected_tool_count: profileExpectedToolNames.length,
		client_visible_expected_tool_count: profileExpectedToolNames.length + localToolNames.length,
		profile_missing_tool_names: profileMissingToolNames,
		remote_tool_count: 0,
		proxied_tool_count: 0,
		profile_filtered_tool_count: 0,
		profile_filtered_tool_names: [],
		tool_inventory: buildToolInventory(profile, localToolNames),
		companion_version: APP_VERSION,
		expected_companion_package: companionPackageSpec(),
		refresh_required_tool_names: [
			'stonewright-context-bootstrap',
			'stonewright-task-start',
			'stonewright-php-execute',
		],
		prompt_skill_count: 0,
		error: null,
		agent_do_not_use: Array.from(AGENT_DO_NOT_USE),
		agent_use_instead: agentUseInstead({ STONEWRIGHT_MCP_TOOL_PROFILE: profile }),
		recovery: recoveryHints(0, STARTUP_REQUIRED_PROXY_TOOL_NAMES.length, profileMissingToolNames.length),
		mode: 'plugin',
		mode_reason: null,
		direct_tool_count: 0,
		direct_tool_names: [],
		unavailable_plugin_capabilities: [],
	};
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

function registerSetupTools(server: McpServer, env: NodeJS.ProcessEnv): void {
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
		async (input) => {
			const mergedEnv = {
				...env,
				...(typeof input.siteUrl === 'string' ? { STONEWRIGHT_WP_URL: input.siteUrl } : {}),
				...(typeof input.wpRoot === 'string' ? { STONEWRIGHT_WP_ROOT: input.wpRoot } : {}),
				...(typeof input.username === 'string' ? { STONEWRIGHT_WP_USERNAME: input.username } : {}),
				...(typeof input.appPassword === 'string' ? { STONEWRIGHT_WP_APP_PASSWORD: input.appPassword } : {}),
			};
			const modeProbe = await resolveRuntimeMode({ env: mergedEnv });
			return toolResponse(buildSetupProfile(mergedEnv, process.platform, {
				mode: modeProbe.mode,
				mode_reason: modeProbe.reason,
			}));
		},
	);
}

function registerWordPressMcpStatusTool(server: McpServer, status: WordPressMcpConnectionStatus): void {
	server.registerTool(
		'stonewright-wordpress-mcp-status',
		{
			description: 'Return whether the companion successfully proxied the WordPress Stonewright MCP endpoint. Available even when the endpoint is down so agents can recover without losing setup and WP-CLI tools.',
			inputSchema: {},
		},
		() => toolResponse(status),
	);
}

/**
 * Diagnose companion vs WordPress vs client tool-surface mismatches.
 * Always local — works even when php-execute is filtered out of the proxy set.
 */
function registerClientSurfaceCheckTool(
	server: McpServer,
	status: WordPressMcpConnectionStatus,
	env: NodeJS.ProcessEnv,
): void {
	server.registerTool(
		'stonewright-client-surface-check',
		{
			description:
				'Diagnose Stonewright client tool-surface problems: companion profile, remote tools, filtered tools, whether php-execute is registered, and concrete fixes (relist / activate profile / restart MCP). Prefer this over inventing REST abilities/run workarounds.',
			inputSchema: {
				expected_tool: z.string().optional(),
			},
		},
		(input) => {
			const expected = typeof input.expected_tool === 'string' && input.expected_tool.trim() !== ''
				? input.expected_tool.trim().replaceAll('/', '-')
				: 'stonewright-php-execute';
			const expectedNorm = expected.startsWith('stonewright-') ? expected : `stonewright-${expected}`;
			const filtered = new Set(status.profile_filtered_tool_names ?? []);
			const missingProfile = new Set(status.profile_missing_tool_names ?? []);
			const profile = (status.tool_profile as ProxyToolProfile) || 'bootstrap';
			const localNames = new Set(status.local_tool_names ?? []);
			const profileNames = new Set(proxyToolNamesForProfile(profile));
			// Prefer explicit inventory membership over "full ⇒ everything ok".
			const serverHas = status.connected
				&& status.remote_tool_count > 0
				&& !missingProfile.has(expectedNorm)
				&& (profile === 'full' || profileNames.has(expectedNorm) || localNames.has(expectedNorm)
					|| !filtered.has(expectedNorm));
			// Filtered tools are remote-visible but not client-registered.
			const clientHas = status.connected
				&& (localNames.has(expectedNorm)
					|| (status.proxied_tool_count > 0
						&& !filtered.has(expectedNorm)
						&& !missingProfile.has(expectedNorm)
						&& (profile === 'full'
							? status.remote_tool_count > 0 && !filtered.has(expectedNorm)
							: profileNames.has(expectedNorm))));

			let errorCode = 'ok';
			const fix: string[] = [];
			if (!status.configured) {
				errorCode = 'not_configured';
				fix.push('run_setup_profile', 'set_STONEWRIGHT_WP_URL_and_credentials');
			} else if (!status.connected) {
				errorCode = 'auth_or_connectivity_fail';
				fix.push('verify_app_password', 'verify_mcp_url', 'restart_mcp');
			} else if (filtered.has(expectedNorm)) {
				errorCode = 'client_tool_not_registered';
				fix.push('call_task_start', 'activate_profile:elementor-design_or_full', 'relist_tools', 'restart_mcp');
			} else if (!serverHas) {
				errorCode = 'server_missing_tool';
				fix.push('deploy_plugin_update', 'enable_ability', 'check_remote_tools_list');
			} else if (!clientHas) {
				errorCode = 'client_tool_not_registered';
				fix.push('relist_tools', 'activate_profile:full', 'restart_mcp');
			}

			const siteAlias = (env['STONEWRIGHT_SITE_ALIAS'] ?? '').trim();
			const writeTarget = status.url
				? String(status.url).replace(/\/wp-json\/mcp\/stonewright\/?$/i, '/')
				: null;

			return toolResponse({
				ok: errorCode === 'ok',
				error_code: errorCode,
				server_has_tool: serverHas || (status.connected && status.remote_tool_count > 50),
				client_has_tool: clientHas,
				expected_tool: expectedNorm,
				companion: {
					tool_profile: status.tool_profile,
					connected: status.connected,
					configured: status.configured,
					remote_tool_count: status.remote_tool_count,
					proxied_tool_count: status.proxied_tool_count,
					profile_filtered_tool_count: status.profile_filtered_tool_count,
					profile_filtered_tool_names: status.profile_filtered_tool_names,
					profile_missing_tool_names: status.profile_missing_tool_names,
					mode: status.mode,
				},
				write_target: {
					url: writeTarget,
					mcp_url: status.url,
					site_alias: siteAlias || null,
					label: writeTarget
						? `active write target = ${writeTarget} (${status.mode})`
						: 'active write target unknown — configure STONEWRIGHT_WP_URL',
				},
				diagnosis: errorCode === 'ok'
					? 'Client surface looks healthy for the expected tool.'
					: errorCode === 'auth_or_connectivity_fail'
						? 'WordPress MCP endpoint is not connected (auth fail or host down).'
						: errorCode === 'not_configured'
							? 'Companion is not configured with site credentials.'
							: 'Server likely has the tool but the companion client profile filtered it out. Re-list after task-start/tool-profile, or restart MCP. Do not call /abilities/run.',
				fix,
				agent_do_not_use: [
					'Do not call /wp-json/stonewright/v1/abilities/run as a workaround.',
					'Do not hand-roll JSON-RPC against the MCP endpoint.',
				],
			});
		},
	);
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
