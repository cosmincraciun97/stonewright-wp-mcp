import { companionPackageSpec } from './version.js';
import { proxyToolNamesForProfile, proxyToolProfileFromEnv, type ProxyToolProfile } from './wordpress-mcp.js';

export type SetupPlatform = NodeJS.Platform | 'linux' | 'darwin' | 'win32';

export interface SetupCheck {
	id: string;
	label: string;
	status: 'ok' | 'warning';
	message: string;
}

export interface SetupProfile extends Record<string, unknown> {
	ok: boolean;
	platform: string;
	install_command: string;
	mcp_server: {
		command: string;
		args: string[];
		env: Record<string, string>;
	};
	checks: SetupCheck[];
	first_calls: string[];
	tool_visibility_checks: string[];
	tool_inventory: ToolInventory;
	wp_cli_environment: WpCliEnvironment;
	agent_do_not_use: string[];
	agent_use_instead: string[];
	notes: string[];
}

export interface WpCliEnvironment {
	applies_to: string;
	not_required_for: string;
	required_dependencies: string[];
	first_check: string;
	if_missing: string;
	restart_after_changes: string;
}

export interface ToolInventory {
	profile: ProxyToolProfile;
	startup_budget: {
		strict_client_tool_cap: number;
		client_visible_expected_tool_count: number;
		under_low_tools_cap: boolean;
	};
	first_call_tool_names: string[];
	diagnostic_tool_names: string[];
	direct_wp_cli_tool_names: string[];
	direct_wp_cli_long_running_tool_names: string[];
	proxied_profile_tool_count: number;
	proxied_profile_tool_groups: Record<string, string[]>;
	token_notes: string[];
}

export const AGENT_DO_NOT_USE = [
	'Do not run wp cli info, wp plugin activate, wp option update, or other wp commands in a normal shell as Stonewright recovery.',
	'Do not use another MCP adapter execute-php or arbitrary PHP execution to replace Stonewright tools.',
	'Do not read repository docs or ability matrices as a substitute for the live MCP tool list.',
	'Do not inspect private AI-client config files to find or call Stonewright.',
	'Do not create scratch scripts such as query-mcp.js or run-ability.js to bypass the MCP client tool surface.',
	'Do not create helper JSON argument files such as bootstrap-args.json, cli_command.json, or get_structure.json to bypass typed MCP tool input.',
	'Do not launch the Stonewright companion from ad hoc shell scripts such as query-local-stonewright.js to bypass the MCP client tool list.',
	'Do not create or modify action scripts such as run-loop-mutate.js or run-bootstrap-and-mutate.js to bypass typed Stonewright tool calls.',
	'Do not inspect plugin or companion source code to reverse-engineer tool schemas during WordPress implementation tasks.',
	'Do not hand-roll JSON-RPC calls to /mcp or /wp-json/mcp/stonewright as an MCP workaround.',
	'Do not call /wp-json/stonewright/v1/abilities/run from shell as an MCP workaround.',
];

export const MCP_MISSING_BOOTSTRAP_STOP =
	'If stonewright-context-bootstrap is not visible, stop WordPress work, report that Stonewright MCP is not loaded, and ask the user to reload or fix the MCP client config.';

export const WP_CLI_LOCAL_REQUIREMENT_NOTE =
	'Local WP-CLI requires PHP CLI with mysqli/MySQL enabled, wp or wp-cli.phar, STONEWRIGHT_WP_ROOT pointing at wp-config.php, and a running database reachable from wp-config.php.';
export const WP_CLI_REMOTE_NOT_REQUIRED_NOTE =
	'Remote HTTP MCP sites do not require local PHP/MySQL unless the companion is expected to run WP-CLI for that site.';
export const WP_CLI_RESTART_NOTE =
	'Restart or reload the MCP client after changing Stonewright env vars, PHP/WP-CLI paths, or the release tarball.';

export const AGENT_USE_INSTEAD = [
	'stonewright-wordpress-mcp-status',
	'stonewright-setup-profile',
	'stonewright-context-bootstrap',
	'stonewright-workflow-preflight',
	'stonewright-wp-cli-status',
	'stonewright-wp-cli-discover',
	'stonewright-wp-cli-run',
	'stonewright-wp-cli-batch-run',
	'stonewright-wp-cli-job-start',
	'stonewright-wp-cli-job-status',
	'stonewright-wp-cli-install',
];

const LOW_TOOLS_AGENT_USE_INSTEAD = AGENT_USE_INSTEAD.filter(
	(name) => name !== 'stonewright-wp-cli-install',
);
const LOW_TOOL_PROFILE_ALIASES = new Set(['antigravity', 'gemini', 'low', 'low-tools', 'minimal', 'strict', 'tiny']);

export function buildSetupProfile(
	env: NodeJS.ProcessEnv = process.env,
	platform: SetupPlatform = process.platform,
): SetupProfile {
	const siteUrl = normaliseSiteUrl(env['STONEWRIGHT_WP_URL'] ?? env['WP_API_URL'] ?? env['STONEWRIGHT_MCP_URL'] ?? '');
	const wpRoot = (env['STONEWRIGHT_WP_ROOT'] ?? '').trim();
	const username = (env['STONEWRIGHT_WP_USERNAME'] ?? env['WP_API_USERNAME'] ?? '').trim();
	const password = env['STONEWRIGHT_WP_APP_PASSWORD'] ?? env['WP_API_PASSWORD'];
	const authorization = (env['STONEWRIGHT_MCP_AUTHORIZATION'] ?? '').trim();
	const toolProfile = (env['STONEWRIGHT_MCP_TOOL_PROFILE'] ?? env['STONEWRIGHT_MCP_PROXY_PROFILE'] ?? 'essential').trim() || 'essential';
	const local = siteUrl !== '' && isLocalUrl(siteUrl);
	const canAutoCredentials = local && wpRoot !== '';

	const mcpEnv: Record<string, string> = {
		STONEWRIGHT_WP_APP_PASSWORD_AUTO: canAutoCredentials ? 'local-only' : 'never',
		STONEWRIGHT_MCP_TOOL_PROFILE: toolProfile,
	};
	if (siteUrl !== '') {
		mcpEnv.STONEWRIGHT_WP_URL = siteUrl;
	}
	if (wpRoot !== '') {
		mcpEnv.STONEWRIGHT_WP_ROOT = wpRoot;
	}
	if (username !== '') {
		mcpEnv.STONEWRIGHT_WP_USERNAME = username;
	}
	if (typeof password === 'string' && password.trim() !== '') {
		mcpEnv.STONEWRIGHT_WP_APP_PASSWORD = password;
	}
	if (authorization !== '') {
		mcpEnv.STONEWRIGHT_MCP_AUTHORIZATION = authorization;
	}

	const visibilityChecks = toolVisibilityChecks(env);
	const checks: SetupCheck[] = [
		{
			id: 'site_url',
			label: 'WordPress URL',
			status: siteUrl !== '' ? 'ok' : 'warning',
			message: siteUrl !== ''
				? `Using ${siteUrl}`
				: 'Set STONEWRIGHT_WP_URL to the WordPress site URL.',
		},
		{
			id: 'wp_root',
			label: 'WordPress root',
			status: wpRoot !== '' || !local ? 'ok' : 'warning',
			message: wpRoot !== ''
				? `Using ${wpRoot}`
				: local
					? 'Set STONEWRIGHT_WP_ROOT for WP-CLI auto credentials and faster local writes.'
					: 'Optional for remote sites unless WP-CLI helper tools are needed.',
		},
		credentialsCheck(Boolean(authorization), username, typeof password === 'string' && password.trim() !== '', canAutoCredentials, local),
	];

	return {
		ok: checks.every((check) => check.status === 'ok'),
		platform,
		install_command: `npm install -g ${companionPackageSpec()}`,
		mcp_server: {
			command: 'npx',
			args: companionMcpArgs(),
			env: mcpEnv,
		},
		checks,
		first_calls: [
			'stonewright-context-bootstrap',
			'stonewright-workflow-preflight',
		],
		tool_visibility_checks: visibilityChecks,
		tool_inventory: buildToolInventory(proxyToolProfileFromEnv(env), visibilityChecks),
		wp_cli_environment: buildWpCliEnvironment(),
		agent_do_not_use: AGENT_DO_NOT_USE,
		agent_use_instead: agentUseInstead(env),
		notes: [
			'Use this MCP config on Windows, macOS, and Linux; env vars carry paths safely.',
			'No shell script wrapper required; the companion uses Node and execFile argv tokens.',
			'Use npx -y --package <versioned GitHub release tarball> stonewright-mcp so MCP clients run the explicit companion bin instead of relying on npx bin inference.',
			'Do not point IDE MCP configs at companion/dist/index.js; dist is a build artifact and is intentionally not committed.',
			'For source development, use npm --prefix <repo>/companion run mcp:source so the companion rebuilds before the MCP server starts.',
			'Do not configure generic WordPress MCP adapters such as @automattic/mcp-wordpress-remote as the stonewright server; use the Stonewright companion so setup, status, compact profiles, and guarded WP-CLI tools stay visible during endpoint recovery.',
			'Verify the MCP tool list includes stonewright-context-bootstrap before starting WordPress work.',
			'Use stonewright-wordpress-mcp-status if proxied WordPress tools are missing; setup and WP-CLI tools remain available while fixing the connection.',
			'STONEWRIGHT_MCP_TOOL_PROFILE=essential keeps new MCP sessions compact while preserving Stonewright fast-path tools.',
			'Use STONEWRIGHT_MCP_TOOL_PROFILE=low-tools for Antigravity, Gemini API, or other strict tool-cap clients; direct WP-CLI batch and background-job tools stay visible.',
			'Profile aliases such as elementor, design, acf, cpt-ui, fse, and wp cli normalize to compact canonical profiles.',
			'Leave PORT unset for stdio-only MCP clients. To run the optional HTTP bridge, set STONEWRIGHT_HTTP_ENABLE=1 plus PORT.',
			'Use fast_path.tool_profile from stonewright-workflow-preflight before making a separate stonewright-tool-profile call; call tool-profile only to switch or verify a compact profile.',
			MCP_MISSING_BOOTSTRAP_STOP,
			WP_CLI_LOCAL_REQUIREMENT_NOTE,
			WP_CLI_REMOTE_NOT_REQUIRED_NOTE,
			'If local WP-CLI dependencies are missing, stop and tell the user which dependency must be installed, enabled, started, or configured before continuing WP-CLI work.',
			WP_CLI_RESTART_NOTE,
			'Do not treat local client skills or repository files as a substitute for live Stonewright MCP tools; if the tool is missing, reload the MCP client instead of bypassing the server.',
			'Do not inspect private AI-client config files to find Stonewright; use the configured MCP tool list and stonewright-setup-profile instead.',
			'Do not create scratch scripts such as query-mcp.js or run-ability.js to bypass the MCP client tool surface.',
			'Do not create helper JSON argument files such as bootstrap-args.json, cli_command.json, or get_structure.json to bypass typed MCP tool input.',
			'Do not launch the Stonewright companion from ad hoc shell scripts such as query-local-stonewright.js to bypass the MCP client tool list.',
			'Do not create or modify action scripts such as run-loop-mutate.js or run-bootstrap-and-mutate.js to bypass typed Stonewright tool calls.',
			'Do not inspect plugin or companion source code to reverse-engineer tool schemas during WordPress implementation tasks.',
			'Do not hand-roll JSON-RPC calls to /mcp or /wp-json/mcp/stonewright as an MCP workaround.',
			'Do not call /wp-json/stonewright/v1/abilities/run from shell as an MCP workaround.',
			'For local .local/.test sites, Application Passwords can be generated through guarded WP-CLI.',
			'For production sites, provide STONEWRIGHT_WP_USERNAME plus STONEWRIGHT_WP_APP_PASSWORD or STONEWRIGHT_MCP_AUTHORIZATION.',
		],
	};
}

function buildWpCliEnvironment(): WpCliEnvironment {
	return {
		applies_to: 'local WordPress sites and server-side companion installs that use guarded WP-CLI',
		not_required_for: 'remote WordPress sites reached only through the HTTP MCP endpoint',
		required_dependencies: [
			'PHP CLI with mysqli/MySQL extension enabled',
			'wp or wp-cli.phar available to the companion',
			'STONEWRIGHT_WP_ROOT pointing at a folder with wp-config.php',
			'MySQL/MariaDB service running and reachable from wp-config.php',
		],
		first_check: 'stonewright-wp-cli-status',
		if_missing: 'Stop and tell the user which local dependency is missing before continuing WP-CLI work.',
		restart_after_changes: WP_CLI_RESTART_NOTE,
	};
}

function companionMcpArgs(): string[] {
	return ['-y', '--package', companionPackageSpec(), 'stonewright-mcp'];
}

export function agentUseInstead(env: NodeJS.ProcessEnv = process.env): string[] {
	return isLowToolsProfile(env) ? LOW_TOOLS_AGENT_USE_INSTEAD : AGENT_USE_INSTEAD;
}

export function buildToolInventory(
	profile: ProxyToolProfile,
	localToolNames: readonly string[],
): ToolInventory {
	const proxiedProfileToolNames = proxyToolNamesForProfile(profile);
	const clientVisibleExpectedToolCount = new Set([...proxiedProfileToolNames, ...localToolNames]).size;

	return {
		profile,
		startup_budget: {
			strict_client_tool_cap: 30,
			client_visible_expected_tool_count: clientVisibleExpectedToolCount,
			under_low_tools_cap: profile !== 'low-tools' || clientVisibleExpectedToolCount <= 30,
		},
		first_call_tool_names: [
			'stonewright-context-bootstrap',
			'stonewright-workflow-preflight',
		],
		diagnostic_tool_names: localToolNames.filter((name) => [
			'stonewright-setup-profile',
			'stonewright-wordpress-mcp-status',
			'stonewright-wp-cli-status',
			'stonewright-wp-cli-discover',
		].includes(name)),
		direct_wp_cli_tool_names: localToolNames.filter((name) => name.startsWith('stonewright-wp-cli-')),
		direct_wp_cli_long_running_tool_names: localToolNames.filter((name) => [
			'stonewright-wp-cli-job-start',
			'stonewright-wp-cli-job-status',
		].includes(name)),
		proxied_profile_tool_count: proxiedProfileToolNames.length,
		proxied_profile_tool_groups: groupProxiedToolNames(proxiedProfileToolNames),
		token_notes: [
			'Use this inventory before broad tools/list discovery.',
			'Use direct_wp_cli_tool_names for guarded local WP-CLI; never run wp commands in a normal shell.',
			'Use proxied_profile_tool_groups to pick the next Stonewright WordPress tool without loading the full ability matrix.',
		],
	};
}

function groupProxiedToolNames(toolNames: string[]): Record<string, string[]> {
	const groups: Record<string, string[]> = {
		startup: [],
		elementor_design: [],
		content_media: [],
		code_sandbox: [],
		gutenberg_fse: [],
		site_admin: [],
		other: [],
	};

	for (const name of toolNames) {
		if (['stonewright-context-bootstrap', 'stonewright-workflow-preflight', 'stonewright-tool-profile', 'stonewright-skills-get'].includes(name)) {
			groups.startup.push(name);
		} else if (name.includes('elementor') || name.includes('design') || name.includes('widget')) {
			groups.elementor_design.push(name);
		} else if (name.includes('content') || name.includes('media')) {
			groups.content_media.push(name);
		} else if (name.includes('sandbox')) {
			groups.code_sandbox.push(name);
		} else if (name.includes('gutenberg') || name.includes('blocks') || name.includes('fse')) {
			groups.gutenberg_fse.push(name);
		} else if (name.includes('site') || name.includes('system') || name.includes('security') || name.includes('menu') || name === 'stonewright-ping') {
			groups.site_admin.push(name);
		} else {
			groups.other.push(name);
		}
	}

	return Object.fromEntries(Object.entries(groups).filter(([, names]) => names.length > 0));
}

function toolVisibilityChecks(env: NodeJS.ProcessEnv): string[] {
	const tools = [
			'stonewright-context-bootstrap',
			'stonewright-workflow-preflight',
			'stonewright-tool-profile',
			'stonewright-skills-get',
			'stonewright-wordpress-mcp-status',
			'stonewright-wp-cli-status',
			'stonewright-wp-cli-discover',
			'stonewright-wp-cli-run',
			'stonewright-wp-cli-batch-run',
	];

	tools.push('stonewright-wp-cli-job-start');
	tools.push('stonewright-wp-cli-job-status');

	if (!isLowToolsProfile(env)) {
		tools.push('stonewright-wp-cli-install');
	}

	return tools;
}

function isLowToolsProfile(env: NodeJS.ProcessEnv): boolean {
	const raw = (env['STONEWRIGHT_MCP_TOOL_PROFILE'] ?? env['STONEWRIGHT_MCP_PROXY_PROFILE'] ?? '').trim().toLowerCase();
	const normalized = raw.replace(/[\s_]+/g, '-');
	return LOW_TOOL_PROFILE_ALIASES.has(normalized);
}

function credentialsCheck(
	hasAuthorization: boolean,
	username: string,
	hasPassword: boolean,
	canAutoCredentials: boolean,
	local: boolean,
): SetupCheck {
	if (hasAuthorization || (username !== '' && hasPassword) || canAutoCredentials) {
		return {
			id: 'credentials',
			label: 'Credentials',
			status: 'ok',
			message: canAutoCredentials && !hasAuthorization && !hasPassword
				? 'Local site can auto-create one Application Password through guarded WP-CLI.'
				: 'Credentials configured.',
		};
	}

	return {
		id: 'credentials',
		label: 'Credentials',
		status: 'warning',
		message: local
			? 'Set STONEWRIGHT_WP_ROOT for local auto credentials, or provide STONEWRIGHT_WP_USERNAME and STONEWRIGHT_WP_APP_PASSWORD.'
			: 'Remote sites need STONEWRIGHT_WP_USERNAME plus STONEWRIGHT_WP_APP_PASSWORD, or STONEWRIGHT_MCP_AUTHORIZATION.',
	};
}

function normaliseSiteUrl(raw: string): string {
	return raw.trim().replace(/\/+$/, '');
}

function isLocalUrl(raw: string): boolean {
	try {
		const host = new URL(raw).hostname.toLowerCase();
		return host === 'localhost'
			|| host === '127.0.0.1'
			|| host === '::1'
			|| host.endsWith('.local')
			|| host.endsWith('.test');
	} catch {
		return false;
	}
}
