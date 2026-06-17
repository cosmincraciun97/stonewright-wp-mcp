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
	proxyToolProfileFromEnv,
	proxyToolNamesForProfile,
	registerWordPressMcpPrompts,
	registerWordPressMcpTools,
	resolveWordPressMcpConfig,
} from './wordpress-mcp.js';
import { APP_VERSION } from './version.js';

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
	prompt_skill_count: number;
	error: { message: string } | null;
	agent_do_not_use: string[];
	agent_use_instead: string[];
	recovery: string[];
}

export interface CreateMcpServerOptions {
	env?: NodeJS.ProcessEnv;
	fetchImpl?: typeof fetch;
}

const LOCAL_RECOVERY_TOOL_NAMES = [
	'stonewright-setup-profile',
	'stonewright-wordpress-mcp-status',
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
	'stonewright-wp-cli-status',
	'stonewright-wp-cli-discover',
	'stonewright-wp-cli-run',
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
		context: z.string().optional(),
		timeoutMs: z.number().int().positive().optional(),
	};

	registerWpCliTools(server, commonInput, env, profile);
	registerSetupTools(server, env);
	const wpMcpStatus = createWordPressMcpConnectionStatus(profile);
	registerWordPressMcpStatusTool(server, wpMcpStatus);

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
			wpMcpStatus.ok = true;
			wpMcpStatus.connected = true;
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

function companionInstructions(profile: ProxyToolProfile): string {
	const lines = [
		'Stonewright companion fast start:',
		`- Current compact profile: ${profile}.`,
		'- First call stonewright-setup-profile if connection, credentials, or tool visibility is unclear.',
		'- For WordPress work, call stonewright-context-bootstrap, then stonewright-workflow-preflight. Use fast_path.tool_profile before making a separate stonewright-tool-profile call.',
		`- ${MCP_MISSING_BOOTSTRAP_STOP}`,
		'- Use stonewright-wordpress-mcp-status only to diagnose the Stonewright MCP connection when proxied WordPress tools are missing.',
		'- Do not inspect private AI-client config files to find or call Stonewright.',
		'- Do not create scratch scripts such as query-mcp.js or run-ability.js to bypass the MCP client tool surface.',
		'- Do not create helper JSON argument files such as bootstrap-args.json, cli_command.json, or get_structure.json to bypass typed MCP tool input.',
		'- Do not launch the Stonewright companion from ad hoc shell scripts such as query-local-stonewright.js to bypass the MCP client tool list.',
		'- Do not create or modify action scripts such as run-loop-mutate.js or run-bootstrap-and-mutate.js to bypass typed Stonewright tool calls.',
		'- Do not inspect plugin or companion source code to reverse-engineer tool schemas during WordPress implementation tasks.',
		'- Do not hand-roll JSON-RPC calls to /mcp or /wp-json/mcp/stonewright as an MCP workaround.',
		'- Do not call /wp-json/stonewright/v1/abilities/run from shell as an MCP workaround.',
		'- Use stonewright-wp-cli-status, stonewright-wp-cli-discover, stonewright-wp-cli-run, and stonewright-wp-cli-batch-run for guarded WP-CLI work.',
		'- Use stonewright-wp-cli-job-start and stonewright-wp-cli-job-status for long imports, plugin operations, cache rebuilds, media work, or large batches when those tools are visible.',
		'- Do not run wp commands in a normal shell. Do not use wp eval, wp eval-file, wp shell, wp package, --exec, or --require.',
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
		prompt_skill_count: 0,
		error: null,
		agent_do_not_use: Array.from(AGENT_DO_NOT_USE),
		agent_use_instead: agentUseInstead({ STONEWRIGHT_MCP_TOOL_PROFILE: profile }),
		recovery: recoveryHints(0, STARTUP_REQUIRED_PROXY_TOOL_NAMES.length, profileMissingToolNames.length),
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
	return profile === 'low-tools' ? LOW_TOOLS_LOCAL_RECOVERY_TOOL_NAMES : LOCAL_TOOL_NAMES;
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
		(input) => {
			const mergedEnv = {
				...env,
				...(typeof input.siteUrl === 'string' ? { STONEWRIGHT_WP_URL: input.siteUrl } : {}),
				...(typeof input.wpRoot === 'string' ? { STONEWRIGHT_WP_ROOT: input.wpRoot } : {}),
				...(typeof input.username === 'string' ? { STONEWRIGHT_WP_USERNAME: input.username } : {}),
				...(typeof input.appPassword === 'string' ? { STONEWRIGHT_WP_APP_PASSWORD: input.appPassword } : {}),
			};
			return toolResponse(buildSetupProfile(mergedEnv));
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

function registerWpCliTools(
	server: McpServer,
	commonInput: Record<string, z.ZodOptional<z.ZodString> | z.ZodOptional<z.ZodNumber>>,
	env: NodeJS.ProcessEnv,
	profile: ProxyToolProfile,
): void {
	for (const name of localAliases(profile, 'stonewright-wp-cli-status', 'companion_wp_cli_status')) {
		server.registerTool(
			name,
			{
				description: 'Check whether WP-CLI is available and return wp cli info diagnostics. This runs directly inside the Stonewright companion.',
				inputSchema: commonInput,
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
				description: 'Run a tokenized WP-CLI command directly through the Stonewright companion with execFile. Allows WordPress write commands while blocking arbitrary PHP and shell entry points.',
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
				description: 'Run multiple tokenized WP-CLI commands through the Stonewright companion in one UTF-8 JSON request. Use this for repeated post/meta/term/media/option work instead of large inline shell scripts; it still blocks arbitrary PHP and shell entry points.',
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
			description: 'Start a guarded WP-CLI command or batch as an in-process background job. Use for long plugin, import, cache, media, or content operations so the MCP request returns immediately.',
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
	return profile === 'low-tools' ? [canonical] : [legacy, canonical];
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
