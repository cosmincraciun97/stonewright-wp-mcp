import { companionPackageSpec } from './version.js';

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
	agent_do_not_use: string[];
	agent_use_instead: string[];
	notes: string[];
}

export const AGENT_DO_NOT_USE = [
	'Do not run wp cli info, wp plugin activate, wp option update, or other wp commands in a normal shell as Stonewright recovery.',
	'Do not use another MCP adapter execute-php or arbitrary PHP execution to replace Stonewright tools.',
	'Do not read repository docs or ability matrices as a substitute for the live MCP tool list.',
	'Do not call /wp-json/stonewright/v1/abilities/run from shell as an MCP workaround.',
];

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
	(name) => !['stonewright-wp-cli-install', 'stonewright-wp-cli-job-start', 'stonewright-wp-cli-job-status'].includes(name),
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
			args: ['-y', companionPackageSpec()],
			env: mcpEnv,
		},
		checks,
		first_calls: [
			'stonewright-context-bootstrap',
			'stonewright-workflow-preflight',
			'stonewright-tool-profile',
		],
		tool_visibility_checks: toolVisibilityChecks(env),
		agent_do_not_use: AGENT_DO_NOT_USE,
		agent_use_instead: agentUseInstead(env),
		notes: [
			'Use this MCP config on Windows, macOS, and Linux; env vars carry paths safely.',
			'No shell script wrapper required; the companion uses Node and execFile argv tokens.',
			'The npx target is the versioned GitHub release tarball so fresh sessions work even before npm package publishing is configured.',
			'Verify the MCP tool list includes stonewright-context-bootstrap before starting WordPress work.',
			'Use stonewright-wordpress-mcp-status if proxied WordPress tools are missing; setup and WP-CLI tools remain available while fixing the connection.',
			'STONEWRIGHT_MCP_TOOL_PROFILE=essential keeps new MCP sessions compact while preserving Stonewright fast-path tools.',
			'Use STONEWRIGHT_MCP_TOOL_PROFILE=low-tools for Antigravity, Gemini API, or other strict tool-cap clients.',
			'Profile aliases such as elementor, design, acf, cpt-ui, fse, and wp cli normalize to compact canonical profiles.',
			'Leave PORT unset for stdio-only MCP clients unless you need the optional HTTP bridge.',
			'Call stonewright-tool-profile for tool-cap, slow-startup, or token-sensitive clients before broad discovery.',
			'Do not treat local client skills or repository files as a substitute for live Stonewright MCP tools; if the tool is missing, reload the MCP client instead of bypassing the server.',
			'Do not call /wp-json/stonewright/v1/abilities/run from shell as an MCP workaround.',
			'For local .local/.test sites, Application Passwords can be generated through guarded WP-CLI.',
			'For production sites, provide STONEWRIGHT_WP_USERNAME plus STONEWRIGHT_WP_APP_PASSWORD or STONEWRIGHT_MCP_AUTHORIZATION.',
		],
	};
}

export function agentUseInstead(env: NodeJS.ProcessEnv = process.env): string[] {
	return isLowToolsProfile(env) ? LOW_TOOLS_AGENT_USE_INSTEAD : AGENT_USE_INSTEAD;
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

	if (!isLowToolsProfile(env)) {
		tools.push('stonewright-wp-cli-install');
		tools.push('stonewright-wp-cli-job-start');
		tools.push('stonewright-wp-cli-job-status');
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
