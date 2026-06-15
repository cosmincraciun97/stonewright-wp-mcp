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
	notes: string[];
}

export function buildSetupProfile(
	env: NodeJS.ProcessEnv = process.env,
	platform: SetupPlatform = process.platform,
): SetupProfile {
	const siteUrl = normaliseSiteUrl(env['STONEWRIGHT_WP_URL'] ?? env['WP_API_URL'] ?? env['STONEWRIGHT_MCP_URL'] ?? '');
	const wpRoot = (env['STONEWRIGHT_WP_ROOT'] ?? '').trim();
	const username = (env['STONEWRIGHT_WP_USERNAME'] ?? env['WP_API_USERNAME'] ?? '').trim();
	const password = env['STONEWRIGHT_WP_APP_PASSWORD'] ?? env['WP_API_PASSWORD'];
	const authorization = (env['STONEWRIGHT_MCP_AUTHORIZATION'] ?? '').trim();
	const local = siteUrl !== '' && isLocalUrl(siteUrl);
	const canAutoCredentials = local && wpRoot !== '';

	const mcpEnv: Record<string, string> = {
		STONEWRIGHT_WP_APP_PASSWORD_AUTO: canAutoCredentials ? 'local-only' : 'never',
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
		install_command: 'npm install -g @stonewright/companion',
		mcp_server: {
			command: 'npx',
			args: ['-y', '@stonewright/companion@latest'],
			env: mcpEnv,
		},
		checks,
		notes: [
			'Use this MCP config on Windows, macOS, and Linux; env vars carry paths safely.',
			'No shell script wrapper required; the companion uses Node and execFile argv tokens.',
			'For local .local/.test sites, Application Passwords can be generated through guarded WP-CLI.',
			'For production sites, provide STONEWRIGHT_WP_USERNAME plus STONEWRIGHT_WP_APP_PASSWORD or STONEWRIGHT_MCP_AUTHORIZATION.',
		],
	};
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
