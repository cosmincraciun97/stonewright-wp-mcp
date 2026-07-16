import type { McpServer } from '@modelcontextprotocol/sdk/server/mcp.js';
import { createHash } from 'node:crypto';
import { chmodSync, existsSync, mkdirSync, readFileSync, writeFileSync } from 'node:fs';
import { homedir } from 'node:os';
import { dirname, join, resolve } from 'node:path';
import { z, type ZodTypeAny } from 'zod';
import { runWpCli, type ExecFileRunner } from './wp-cli.js';
import { APP_VERSION } from './version.js';

export interface WordPressMcpConfig {
	url: string;
	username?: string;
	password?: string;
	authorization?: string;
	timeoutMs: number;
	credentialStorePath?: string;
	credentialSource?: 'store' | 'generated';
}

interface JsonRpcResponse {
	jsonrpc?: string;
	id?: number | string | null;
	result?: unknown;
	error?: {
		code?: number;
		message?: string;
		data?: unknown;
	};
}

interface RemoteTool {
	name: string;
	title?: string;
	description?: string;
	inputSchema?: Record<string, unknown>;
	outputSchema?: Record<string, unknown>;
	annotations?: Record<string, unknown>;
}

interface ToolListResult {
	tools?: RemoteTool[];
}

export interface WordPressMcpRegistrationResult {
	profile: ProxyToolProfile;
	remoteTools: RemoteTool[];
	registeredTools: RemoteTool[];
	profileFilteredToolNames: string[];
	filteredToolCount: number;
}

interface PromptSkill {
	slug?: string;
	title?: string;
	description?: string;
	content?: string;
}

interface PromptSkillsResult {
	skills?: PromptSkill[];
}

interface StoredWordPressCredential {
	url: string;
	username: string;
	password: string;
	createdAt?: string;
	appName?: string;
	wpRoot?: string;
}

type FetchLike = typeof fetch;

const COMPANION_OWNED_TOOL_NAMES = new Set([
	'stonewright-wp-cli-status',
	'stonewright-wp-cli-discover',
	'stonewright-wp-cli-run',
	'stonewright-wp-cli-batch-run',
	'stonewright-wp-cli-job-start',
	'stonewright-wp-cli-job-status',
	'stonewright-wp-cli-install',
	'stonewright-wordpress-mcp-status',
]);

export type ProxyToolProfile = 'full' | 'low-tools' | 'essential' | 'elementor-design' | 'content-model' | 'gutenberg' | 'wp-cli' | 'site-admin';

export const STARTUP_REQUIRED_PROXY_TOOL_NAMES = [
	'stonewright-context-bootstrap',
	'stonewright-task-start',
	'stonewright-skills-get',
] as const;
const BASE_PROXY_TOOL_NAMES = [
	...STARTUP_REQUIRED_PROXY_TOOL_NAMES,
	'stonewright-tool-profile',
	'stonewright-php-execute',
] as const;

const BLUEPRINT_PROXY_TOOL_NAMES = [
	'stonewright-blueprint-list',
	'stonewright-blueprint-get',
	'stonewright-blueprint-apply',
	'stonewright-brand-kit-list',
	'stonewright-brand-kit-apply',
] as const;

/**
 * Direct-mode / offline fallback only. Plugin `tool-profile action=resolve` is the
 * single source of truth when WordPress is reachable.
 */
const FALLBACK_PROXY_TOOL_NAMES: Record<Exclude<ProxyToolProfile, 'full'>, readonly string[]> = {
	'low-tools': [
		...STARTUP_REQUIRED_PROXY_TOOL_NAMES,
		'stonewright-php-execute',
		'stonewright-security-issue-confirmation-token',
		'stonewright-tool-profile',
	],
	essential: [
		...BASE_PROXY_TOOL_NAMES,
		...BLUEPRINT_PROXY_TOOL_NAMES,
		'stonewright-security-issue-confirmation-token',
		'stonewright-elementor-schema',
		'stonewright-content-bulk-upsert-posts',
		'stonewright-design-native-plan',
		'stonewright-elementor-v3-batch-mutate',
		'stonewright-elementor-v3-build-page-from-spec',
		'stonewright-gutenberg-apply-to-post',
		'stonewright-media-upload-batch',
		'stonewright-theme-builder-apply-template',
		'stonewright-elementor-page-digest',
	],
	'elementor-design': [
		...BASE_PROXY_TOOL_NAMES,
		...BLUEPRINT_PROXY_TOOL_NAMES,
		'stonewright-site-info',
		'stonewright-site-plugins-list',
		'stonewright-security-create-one-time-link',
		'stonewright-design-implementation-contract',
		'stonewright-widget-intent-resolve',
		'stonewright-elementor-widget-implementation-guide',
		'stonewright-elementor-v3-status',
		'stonewright-elementor-v3-capabilities-summary',
		'stonewright-elementor-v3-container-schema',
		'stonewright-elementor-v3-list-widgets',
		'stonewright-elementor-schema',
		'stonewright-elementor-describe-widget',
		'stonewright-elementor-v4-status',
		'stonewright-elementor-v4-list-variables',
		'stonewright-elementor-v4-list-classes',
		'stonewright-elementor-v4-list-atomic-node-types',
		'stonewright-media-list',
		'stonewright-media-upload-batch',
		'stonewright-content-create-page',
		'stonewright-content-update-page',
		'stonewright-content-bulk-upsert-posts',
		'stonewright-elementor-v3-update-page-settings',
		'stonewright-elementor-v3-update-kit-colors',
		'stonewright-elementor-v3-update-kit-typography',
		'stonewright-design-validate-spec',
		'stonewright-elementor-v3-build-page-from-spec',
		'stonewright-elementor-v3-batch-mutate',
		'stonewright-elementor-v3-apply-bundle',
		'stonewright-theme-builder-apply-template',
		'stonewright-elementor-page-digest',
	],
	'content-model': [
		...BASE_PROXY_TOOL_NAMES,
		...BLUEPRINT_PROXY_TOOL_NAMES,
		'stonewright-site-capabilities',
		'stonewright-site-plugins-list',
		'stonewright-system-abilities-list',
		'stonewright-content-bulk-upsert-posts',
		'stonewright-content-model-loop-grid-flow',
		'stonewright-media-list',
		'stonewright-media-upload-batch',
		'stonewright-wc-product-list',
		'stonewright-wc-order-list',
		'stonewright-wc-sales-report',
		'stonewright-acf-field-group-list',
		'stonewright-acf-field-group-get',
		'stonewright-acf-field-group-save',
		'stonewright-acf-values-get',
		'stonewright-acf-value-update',
		'stonewright-cpt-register',
		'stonewright-cpt-list',
		'stonewright-taxonomy-register',
	],
	gutenberg: [
		...BASE_PROXY_TOOL_NAMES,
		...BLUEPRINT_PROXY_TOOL_NAMES,
		'stonewright-site-theme',
		'stonewright-fse-get-theme-json',
		'stonewright-fse-read-template',
		'stonewright-fse-write-template',
		'stonewright-fse-write-global-styles',
		'stonewright-blocks-list-registered',
		'stonewright-blocks-get-schema',
		'stonewright-blocks-parse',
		'stonewright-blocks-serialize',
		'stonewright-gutenberg-render-blocks',
		'stonewright-design-validate-spec',
		'stonewright-design-spec-to-gutenberg',
		'stonewright-gutenberg-apply-to-post',
	],
	'wp-cli': [
		...BASE_PROXY_TOOL_NAMES,
		'stonewright-site-info',
		'stonewright-site-plugins-list',
	],
	'site-admin': [
		...BASE_PROXY_TOOL_NAMES,
		...BLUEPRINT_PROXY_TOOL_NAMES,
		'stonewright-site-info',
		'stonewright-site-environment',
		'stonewright-site-health',
		'stonewright-site-plugins-list',
		'stonewright-site-theme',
		'stonewright-security-create-one-time-link',
		'stonewright-system-abilities-list',
		'stonewright-menu-list',
		'stonewright-comment-list',
		'stonewright-comment-get',
		'stonewright-comment-create',
		'stonewright-comment-update',
		'stonewright-comment-delete',
		'stonewright-user-list',
		'stonewright-user-get',
		'stonewright-user-create',
		'stonewright-user-update',
		'stonewright-user-delete',
		'stonewright-user-app-passwords',
		'stonewright-widget-list',
		'stonewright-widget-get',
		'stonewright-widget-save',
		'stonewright-widget-delete',
		'stonewright-settings-get',
		'stonewright-settings-update',
		'stonewright-theme-list',
		'stonewright-theme-activate',
		'stonewright-theme-custom-css',
		'stonewright-plugin-activate',
		'stonewright-plugin-deactivate',
		'stonewright-plugin-delete',
		'stonewright-post-revision-list',
		'stonewright-post-revision-get',
		'stonewright-post-revision-restore',
		'stonewright-site-health-test',
		'stonewright-search-query',
		'stonewright-oembed-resolve',
		'stonewright-seo-status',
	],
};

const FALLBACK_PROXY_TOOL_SETS = Object.fromEntries(
	Object.entries(FALLBACK_PROXY_TOOL_NAMES).map(([profile, names]) => [profile, new Set(names)]),
) as Record<Exclude<ProxyToolProfile, 'full'>, Set<string>>;

/** @deprecated Alias kept for tests; prefer resolvePluginProxyToolNames. */
export function proxyToolNamesForProfile(profile: ProxyToolProfile): string[] {
	if (profile === 'full') {
		return [];
	}
	return Array.from(FALLBACK_PROXY_TOOL_NAMES[profile]);
}

export function maxToolsFromEnv(env: NodeJS.ProcessEnv = process.env): number | null {
	const raw = (env['STONEWRIGHT_MCP_MAX_TOOLS'] ?? '').trim();
	if (!raw) return null;
	const n = Number(raw);
	if (!Number.isFinite(n) || n < 1) return null;
	return Math.floor(n);
}

export function trimToolsToMax(names: string[], maxTools: number | null): { kept: string[]; trimmed: string[] } {
	if (maxTools === null || names.length <= maxTools) {
		return { kept: names, trimmed: [] };
	}
	return {
		kept: names.slice(0, maxTools),
		trimmed: names.slice(maxTools),
	};
}

const PROXY_TOOL_PROFILE_ALIASES: Record<string, ProxyToolProfile> = {
	'antigravity': 'low-tools',
	'gemini': 'low-tools',
	'low': 'low-tools',
	'low-tools': 'low-tools',
	'minimal': 'low-tools',
	'strict': 'low-tools',
	'tiny': 'low-tools',
	'elementor': 'elementor-design',
	'elementor-v3': 'elementor-design',
	'elementor-v4': 'elementor-design',
	'design': 'elementor-design',
	'visual': 'elementor-design',
	'acf': 'content-model',
	'acpt': 'content-model',
	'content': 'content-model',
	'custom-fields': 'content-model',
	'cpt': 'content-model',
	'cpt-ui': 'content-model',
	'fields': 'content-model',
	'meta-box': 'content-model',
	'metabox': 'content-model',
	'pods': 'content-model',
	'woocommerce': 'content-model',
	'woo': 'content-model',
	'block': 'gutenberg',
	'blocks': 'gutenberg',
	'fse': 'gutenberg',
	'theme-json': 'gutenberg',
	'cli': 'wp-cli',
	'wpcli': 'wp-cli',
	'wp-cli': 'wp-cli',
	'admin': 'site-admin',
	'site': 'site-admin',
	'settings': 'site-admin',
};

export function loadWordPressMcpConfig(env: NodeJS.ProcessEnv = process.env): WordPressMcpConfig | null {
	const siteUrlAlias = env['NODE_ENV'] === 'test' && !env['STONEWRIGHT_MCP_URL'] && !env['WP_API_URL']
		? ''
		: env['STONEWRIGHT_WP_URL'] ?? '';
	const url = normalizeWordPressMcpUrl(env['STONEWRIGHT_MCP_URL'] ?? env['WP_API_URL'] ?? siteUrlAlias);
	if (!url) return null;

	const config: WordPressMcpConfig = {
		url,
		timeoutMs: Number(env['STONEWRIGHT_MCP_TIMEOUT_MS'] ?? 30_000),
	};
	const username = (env['WP_API_USERNAME'] ?? env['STONEWRIGHT_WP_USERNAME'] ?? '').trim();
	const authorization = (env['STONEWRIGHT_MCP_AUTHORIZATION'] ?? '').trim();
	if (username) config.username = username;
	const password = env['WP_API_PASSWORD'] ?? env['STONEWRIGHT_WP_APP_PASSWORD'];
	if (password !== undefined) {
		config.password = password;
	}
	if (authorization) config.authorization = authorization;

	if (!config.authorization && !(config.username && config.password)) {
		const credentialStorePath = wordpressCredentialStorePath(env, url);
		const stored = readStoredCredential(credentialStorePath, url);
		if (stored) {
			config.username = stored.username;
			config.password = stored.password;
			config.credentialStorePath = credentialStorePath;
			config.credentialSource = 'store';
		}
	}

	return config;
}

export async function resolveWordPressMcpConfig(
	env: NodeJS.ProcessEnv = process.env,
	runner?: ExecFileRunner,
): Promise<WordPressMcpConfig | null> {
	const config = loadWordPressMcpConfig(env);
	if (!config) return null;
	if (config.authorization || (config.username && config.password)) return config;
	if (!shouldAutoCreateCredential(env, config.url)) return config;

	const generated = await generateAndStoreCredential(config, env, runner);
	return generated ?? config;
}

function normalizeWordPressMcpUrl(raw: string): string {
	const url = raw.trim().replace(/\/+$/, '');
	if (!url) return '';
	const wpRestBase = ['wp', 'json'].join('-');
	const mcpPath = `${wpRestBase}/mcp/`;
	if (url.includes(`/${mcpPath}`)) return url;
	return `${url}/${mcpPath}stonewright`;
}

function wordpressCredentialStorePath(env: NodeJS.ProcessEnv, mcpUrl: string): string {
	const explicitStore = (env['STONEWRIGHT_CREDENTIAL_STORE'] ?? '').trim();
	if (explicitStore) return resolve(explicitStore);

	const explicitDir = (env['STONEWRIGHT_CREDENTIAL_DIR'] ?? '').trim();
	const baseDir = explicitDir
		? resolve(explicitDir)
		: process.platform === 'win32'
			? join(env['LOCALAPPDATA'] || join(homedir(), 'AppData', 'Local'), 'Stonewright', 'credentials')
			: join(env['XDG_CONFIG_HOME'] || join(homedir(), '.config'), 'stonewright', 'credentials');

	const projectRoot = (env['STONEWRIGHT_PROJECT_ROOT'] ?? env['STONEWRIGHT_WP_ROOT'] ?? process.cwd()).trim();
	const key = createHash('sha256').update(`${mcpUrl}\n${projectRoot}`).digest('hex').slice(0, 24);
	return join(baseDir, `${key}.json`);
}

function readStoredCredential(storePath: string, mcpUrl: string): StoredWordPressCredential | null {
	if (!existsSync(storePath)) return null;

	try {
		const parsed = JSON.parse(readFileSync(storePath, 'utf8')) as Partial<StoredWordPressCredential>;
		if (
			typeof parsed.url === 'string'
			&& normalizeWordPressMcpUrl(parsed.url) === mcpUrl
			&& typeof parsed.username === 'string'
			&& parsed.username.trim() !== ''
			&& typeof parsed.password === 'string'
			&& parsed.password.trim() !== ''
		) {
			return {
				url: mcpUrl,
				username: parsed.username,
				password: parsed.password,
				...(typeof parsed.createdAt === 'string' ? { createdAt: parsed.createdAt } : {}),
				...(typeof parsed.appName === 'string' ? { appName: parsed.appName } : {}),
				...(typeof parsed.wpRoot === 'string' ? { wpRoot: parsed.wpRoot } : {}),
			};
		}
	} catch {
		return null;
	}

	return null;
}

function writeStoredCredential(storePath: string, credential: StoredWordPressCredential): void {
	mkdirSync(dirname(storePath), { recursive: true });
	writeFileSync(
		storePath,
		`${JSON.stringify(credential, null, 2)}\n`,
		{ flag: 'w', mode: 0o600 },
	);
	try {
		chmodSync(storePath, 0o600);
	} catch {
		// Windows ACLs are managed by the user's profile; chmod is best-effort.
	}
}

function shouldAutoCreateCredential(env: NodeJS.ProcessEnv, mcpUrl: string): boolean {
	const setting = (env['STONEWRIGHT_WP_APP_PASSWORD_AUTO'] ?? 'local-only').trim().toLowerCase();
	if (['0', 'false', 'off', 'no', 'never'].includes(setting)) return false;
	if (['1', 'true', 'on', 'yes', 'always'].includes(setting)) return true;

	const host = new URL(mcpUrl).hostname.toLowerCase();
	return host === 'localhost'
		|| host === '127.0.0.1'
		|| host === '::1'
		|| host.endsWith('.local')
		|| host.endsWith('.test');
}

async function generateAndStoreCredential(
	config: WordPressMcpConfig,
	env: NodeJS.ProcessEnv,
	runner?: ExecFileRunner,
): Promise<WordPressMcpConfig | null> {
	const username = config.username ?? await discoverAdminUsername(env, runner);
	if (!username) return null;

	const password = await createApplicationPassword(username, env, runner);
	if (!password) return null;

	const credentialStorePath = wordpressCredentialStorePath(env, config.url);
	const credential: StoredWordPressCredential = {
		url: config.url,
		username,
		password,
		createdAt: new Date().toISOString(),
		appName: appPasswordName(env),
		...(env['STONEWRIGHT_WP_ROOT'] ? { wpRoot: env['STONEWRIGHT_WP_ROOT'] } : {}),
	};
	writeStoredCredential(credentialStorePath, credential);

	return {
		...config,
		username,
		password,
		credentialStorePath,
		credentialSource: 'generated',
	};
}

async function discoverAdminUsername(env: NodeJS.ProcessEnv, runner?: ExecFileRunner): Promise<string | null> {
	const result = await runWpCli(
		{
			command: ['user', 'list', '--role=administrator', '--field=user_login', '--number=1'],
			...(env['STONEWRIGHT_WP_ROOT'] ? { path: env['STONEWRIGHT_WP_ROOT'] } : {}),
			timeoutMs: Number(env['STONEWRIGHT_WP_APP_PASSWORD_TIMEOUT_MS'] ?? 30_000),
		},
		runner,
		env,
	);

	if (!result.ok || typeof result.stdout !== 'string') return null;
	return cleanWpCliStdout(result.stdout);
}

async function createApplicationPassword(
	username: string,
	env: NodeJS.ProcessEnv,
	runner?: ExecFileRunner,
): Promise<string | null> {
	const result = await runWpCli(
		{
			command: ['user', 'application-password', 'create', username, appPasswordName(env), '--porcelain'],
			...(env['STONEWRIGHT_WP_ROOT'] ? { path: env['STONEWRIGHT_WP_ROOT'] } : {}),
			timeoutMs: Number(env['STONEWRIGHT_WP_APP_PASSWORD_TIMEOUT_MS'] ?? 30_000),
		},
		runner,
		env,
	);

	if (!result.ok || typeof result.stdout !== 'string') return null;
	return cleanWpCliStdout(result.stdout);
}

function appPasswordName(env: NodeJS.ProcessEnv): string {
	const configured = (env['STONEWRIGHT_WP_APP_PASSWORD_NAME'] ?? '').trim();
	return configured || 'Stonewright Companion';
}

function cleanWpCliStdout(stdout: string): string | null {
	return stdout
		.split(/\r?\n/)
		.map((line) => line.trim())
		.filter((line) => line !== '' && !line.startsWith('Warning:') && !line.startsWith('Success:'))
		.at(-1) ?? null;
}

export async function registerWordPressMcpTools(
	server: McpServer,
	config: WordPressMcpConfig,
	fetchImpl: FetchLike = fetch,
	env: NodeJS.ProcessEnv = process.env,
): Promise<WordPressMcpRegistrationResult> {
	const client = new WordPressMcpClient(config, fetchImpl);
	const tools = await client.listTools();
	const profile = proxyToolProfileFromEnv(env);
	const registeredTools: RemoteTool[] = [];
	const profileFilteredToolNames: string[] = [];
	const maxTools = maxToolsFromEnv(env);

	// Prefer plugin-resolved ordered list; fall back to local FALLBACK lists (Direct / offline).
	const resolved = await resolvePluginProxyToolNames(client, profile, maxTools);
	const allowedOrder = resolved.tools;
	const allowedSet = profile === 'full' && allowedOrder.length === 0
		? null
		: new Set(allowedOrder.length > 0 ? allowedOrder : proxyToolNamesForProfile(profile));

	const candidates: RemoteTool[] = [];
	for (const tool of tools) {
		if (!tool.name || tool.name.startsWith('companion_') || COMPANION_OWNED_TOOL_NAMES.has(tool.name)) {
			continue;
		}
		if (allowedSet !== null && !allowedSet.has(tool.name)) {
			profileFilteredToolNames.push(tool.name);
			continue;
		}
		candidates.push(tool);
	}

	// Preserve plugin priority order when available.
	if (allowedOrder.length > 0) {
		const byName = new Map(candidates.map((t) => [t.name, t]));
		const ordered: RemoteTool[] = [];
		for (const name of allowedOrder) {
			const tool = byName.get(name);
			if (tool) ordered.push(tool);
		}
		// Include any allowed tools not present in the resolved order (defensive).
		for (const tool of candidates) {
			if (!ordered.includes(tool)) ordered.push(tool);
		}
		candidates.length = 0;
		candidates.push(...ordered);
	}

	const { kept, trimmed } = trimToolsToMax(
		candidates.map((t) => t.name),
		maxTools,
	);
	if (trimmed.length > 0) {
		// Deterministic client-cap trim from the tail of the priority-ordered list.
		console.error(
			`[stonewright] ${trimmed.length} tools trimmed (client cap ${String(maxTools)}): ${trimmed.join(', ')}`,
		);
		profileFilteredToolNames.push(...trimmed);
	}
	const keepSet = new Set(kept);
	const finalTools = candidates.filter((t) => keepSet.has(t.name));

	for (const tool of finalTools) {
		server.tool(
			tool.name,
			tool.description ?? tool.title ?? 'Proxied Stonewright WordPress MCP tool.',
			zodShapeFromJsonSchema(tool.inputSchema ?? emptyObjectSchema()),
			async (input) => {
				const response = normalizeToolResponse(await client.callTool(tool.name, input));
				const structured = asRecord(response.structuredContent);
				if (structured && structured['tools_changed'] === true) {
					try {
						// McpServer wraps Protocol Server; emit tools/list_changed for clients.
						const inner = (server as unknown as { server?: { sendToolListChanged?: () => Promise<void> } }).server;
						await inner?.sendToolListChanged?.();
					} catch {
						// Notification is best-effort; clients can re-list via re_list_instruction.
					}
				}
				return response;
			},
		);
		registeredTools.push(tool);
	}

	return {
		profile,
		remoteTools: tools,
		registeredTools,
		profileFilteredToolNames: profileFilteredToolNames.slice(0, 12),
		filteredToolCount: profileFilteredToolNames.length,
	};
}

/**
 * Ask the plugin for the ordered MCP tool names for a profile.
 * Returns empty tools on failure so callers can use FALLBACK lists.
 */
export async function resolvePluginProxyToolNames(
	client: { callTool: (name: string, args: Record<string, unknown>) => Promise<unknown> },
	profile: ProxyToolProfile,
	maxTools: number | null = null,
): Promise<{ tools: string[]; source: 'plugin' | 'fallback'; ordered: boolean }> {
	if (profile === 'full') {
		return { tools: [], source: 'fallback', ordered: true };
	}
	try {
		const raw = await client.callTool('stonewright-tool-profile', {
			action: 'resolve',
			profile,
			...(maxTools !== null ? { max_tools: maxTools } : {}),
		});
		const structured = extractStructured(raw);
		const toolsRaw = structured?.['tools'];
		const names: string[] = [];
		if (Array.isArray(toolsRaw)) {
			for (const entry of toolsRaw) {
				if (typeof entry === 'string' && entry.startsWith('stonewright-')) {
					names.push(entry);
				} else if (entry && typeof entry === 'object' && typeof (entry as { mcp_tool?: unknown }).mcp_tool === 'string') {
					names.push((entry as { mcp_tool: string }).mcp_tool);
				}
			}
		}
		if (names.length > 0) {
			return { tools: names, source: 'plugin', ordered: true };
		}
	} catch {
		// Plugin unreachable (Direct mode) — use fallback.
	}
	return {
		tools: proxyToolNamesForProfile(profile),
		source: 'fallback',
		ordered: true,
	};
}

function extractStructured(raw: unknown): Record<string, unknown> | null {
	const asObj = asRecord(raw);
	if (!asObj) return null;
	if (asObj['tools'] !== undefined || asObj['ok'] !== undefined) {
		return asObj;
	}
	const structured = asRecord(asObj['structuredContent']);
	if (structured) return structured;
	return asObj;
}

export function proxyToolProfileFromEnv(env: NodeJS.ProcessEnv): ProxyToolProfile {
	const raw = (env['STONEWRIGHT_MCP_TOOL_PROFILE'] ?? env['STONEWRIGHT_MCP_PROXY_PROFILE'] ?? 'essential')
		.trim()
		.toLowerCase();
	const normalized = raw.replace(/[\s_]+/g, '-');
	if (['0', 'false', 'off', 'full', 'all'].includes(normalized)) {
		return 'full';
	}
	if (normalized === '' || normalized === 'auto' || normalized === 'fast' || normalized === 'general' || normalized === 'compact') {
		return 'essential';
	}
	if (normalized in FALLBACK_PROXY_TOOL_SETS) {
		return normalized as Exclude<ProxyToolProfile, 'full'>;
	}
	if (normalized in PROXY_TOOL_PROFILE_ALIASES) {
		return PROXY_TOOL_PROFILE_ALIASES[normalized] ?? 'essential';
	}
	return 'essential';
}



export async function registerWordPressMcpPrompts(
	server: McpServer,
	config: WordPressMcpConfig,
	fetchImpl: FetchLike = fetch,
): Promise<PromptSkill[]> {
	const client = new WordPressMcpClient(config, fetchImpl);
	let skills: PromptSkill[] = [];

	try {
		skills = await client.listPromptSkills();
	} catch {
		return [];
	}

	for (const skill of skills) {
		const slug = promptNameSuffix(skill.slug ?? '');
		if (!slug) continue;

		server.registerPrompt(
			`stonewright-skill-${slug}`,
			{
				title: `Stonewright: ${skill.title || slug}`,
				description: skill.description || `Use Stonewright site skill ${slug}.`,
			},
			() => ({
				description: skill.description || undefined,
				messages: [
					{
						role: 'user',
						content: {
							type: 'text',
							text: promptSkillText(skill, slug),
						},
					},
				],
			}),
		);
	}

	return skills;
}

export function wordpressRestUrlFromMcpUrl(mcpUrl: string, restPath: string): string {
	const url = new URL(mcpUrl);
	const wpRestBase = ['wp', 'json'].join('-');
	const marker = `/${wpRestBase}/mcp/`;
	const markerIndex = url.pathname.indexOf(marker);
	const basePath = markerIndex >= 0 ? url.pathname.slice(0, markerIndex) : '';
	const [pathPart, queryPart = ''] = restPath.replace(/^\/+/, '').split('?', 2);
	url.pathname = `${basePath}/${wpRestBase}/${pathPart}`;
	url.search = queryPart;
	return url.toString();
}

class WordPressMcpClient {
	private nextId = 1;
	private sessionId = '';
	private initialized = false;

	public constructor(
		private readonly config: WordPressMcpConfig,
		private readonly fetchImpl: FetchLike,
	) {}

	public async listTools(): Promise<RemoteTool[]> {
		await this.ensureInitialized();
		const result = await this.request('tools/list', {});
		return Array.isArray((result as ToolListResult).tools) ? (result as ToolListResult).tools ?? [] : [];
	}

	public async callTool(name: string, args: Record<string, unknown>): Promise<unknown> {
		await this.ensureInitialized();
		return this.request('tools/call', {
			name,
			arguments: args,
		});
	}

	public async listPromptSkills(): Promise<PromptSkill[]> {
		const response = await this.fetchImpl(
			wordpressRestUrlFromMcpUrl(this.config.url, 'stonewright/v1/skills?mode=prompt&enabled_only=1'),
			{
				method: 'GET',
				headers: this.headers(),
			},
		);

		if (!response.ok) {
			return [];
		}

		const data = await response.json() as PromptSkillsResult;
		return Array.isArray(data.skills)
			? data.skills.filter((skill) => typeof skill.slug === 'string' && skill.slug.trim() !== '')
			: [];
	}

	private async ensureInitialized(): Promise<void> {
		if (this.initialized) return;

		await this.request('initialize', {
			protocolVersion: '2025-06-18',
			capabilities: {},
			clientInfo: {
				name: 'stonewright-companion',
				version: APP_VERSION,
			},
		});

		await this.notification('notifications/initialized', {});
		this.initialized = true;
	}

	private async request(method: string, params: Record<string, unknown>): Promise<unknown> {
		const response = await this.send({
			jsonrpc: '2.0',
			id: this.nextId++,
			method,
			params,
		});

		if (response.error) {
			throw new Error(response.error.message ?? `WordPress MCP error calling ${method}`);
		}
		return response.result ?? {};
	}

	private async notification(method: string, params: Record<string, unknown>): Promise<void> {
		await this.send({
			jsonrpc: '2.0',
			method,
			params,
		});
	}

	private async send(payload: Record<string, unknown>): Promise<JsonRpcResponse> {
		const controller = new AbortController();
		const timer = setTimeout(() => controller.abort(), this.config.timeoutMs);

		let response: Response;
		try {
			response = await this.fetchImpl(this.config.url, {
				method: 'POST',
				headers: this.headers(),
				body: JSON.stringify(payload),
				signal: controller.signal,
			});
		} finally {
			clearTimeout(timer);
		}

		const sessionId = response.headers.get('mcp-session-id');
		if (sessionId) {
			this.sessionId = sessionId;
		}

		const text = await response.text();
		if (!response.ok) {
			throw new Error(`WordPress MCP HTTP ${response.status}: ${text.slice(0, 300)}`);
		}

		if (text.trim() === '') {
			return { result: {} };
		}

		return parseJsonRpcResponse(text, response.headers.get('content-type') ?? '');
	}

	private headers(): Record<string, string> {
		const headers: Record<string, string> = {
			'Accept': 'application/json, text/event-stream',
			'Content-Type': 'application/json',
		};

		if (this.sessionId) {
			headers['Mcp-Session-Id'] = this.sessionId;
		}

		if (this.config.authorization) {
			headers['Authorization'] = this.config.authorization;
		} else if (this.config.username && this.config.password) {
			const token = Buffer.from(`${this.config.username}:${this.config.password}`).toString('base64');
			headers['Authorization'] = `Basic ${token}`;
		}

		return headers;
	}
}

function promptNameSuffix(rawSlug: string): string {
	return rawSlug
		.trim()
		.toLowerCase()
		.replace(/[^a-z0-9_-]+/g, '-')
		.replace(/^-+|-+$/g, '');
}

function promptSkillText(skill: PromptSkill, fallbackSlug: string): string {
	const title = skill.title || fallbackSlug;
	const slug = skill.slug || fallbackSlug;
	const content = skill.content || '';

	return [
		`Use Stonewright site skill "${title}" (${slug}).`,
		'Follow this playbook for the current task:',
		'',
		content,
	].join('\n');
}

function parseJsonRpcResponse(text: string, contentType: string): JsonRpcResponse {
	if (contentType.includes('text/event-stream')) {
		const dataLines = text
			.split(/\r?\n/)
			.filter((line) => line.startsWith('data:'))
			.map((line) => line.slice(5).trim())
			.filter(Boolean);
		const last = dataLines.at(-1);
		if (!last) return { result: {} };
		return JSON.parse(last) as JsonRpcResponse;
	}

	return JSON.parse(text) as JsonRpcResponse;
}

function normalizeToolResponse(result: unknown): {
	content: Array<{ type: 'text'; text: string }>;
	structuredContent?: Record<string, unknown>;
} {
	if (result && typeof result === 'object' && Array.isArray((result as { content?: unknown }).content)) {
		const response = result as {
			content: Array<{ type: 'text'; text: string }>;
			structuredContent?: unknown;
		};
		const structuredContent = asRecord(response.structuredContent);
		return structuredContent
			? { content: response.content, structuredContent }
			: { content: response.content };
	}

	return {
		content: [
			{
				type: 'text',
				text: JSON.stringify(result, null, 2),
			},
		],
		structuredContent: asRecord(result) ?? { value: result },
	};
}

function asRecord(value: unknown): Record<string, unknown> | null {
	return value && typeof value === 'object' && !Array.isArray(value)
		? value as Record<string, unknown>
		: null;
}

function emptyObjectSchema(): Record<string, unknown> {
	return {
		type: 'object',
		additionalProperties: false,
		properties: {},
	};
}

function zodShapeFromJsonSchema(schema: Record<string, unknown>): Record<string, ZodTypeAny> {
	const properties = schema['properties'];
	if (!properties || typeof properties !== 'object' || Array.isArray(properties)) {
		return {};
	}

	const required = Array.isArray(schema['required'])
		? new Set(schema['required'].filter((item): item is string => typeof item === 'string'))
		: new Set<string>();

	return Object.fromEntries(
		Object.entries(properties as Record<string, Record<string, unknown>>).map(([key, value]) => {
			const field = zodFieldFromJsonSchema(value);
			return [key, required.has(key) ? field : field.optional()];
		}),
	);
}

function zodFieldFromJsonSchema(schema: Record<string, unknown>): ZodTypeAny {
	const enumValues = schema['enum'];
	if (Array.isArray(enumValues) && enumValues.every((item): item is string => typeof item === 'string') && enumValues.length > 0) {
		return z.enum(enumValues as [string, ...string[]]);
	}

	switch (schema['type']) {
		case 'string':
			return z.string();
		case 'integer':
			return z.number().int();
		case 'number':
			return z.number();
		case 'boolean':
			return z.boolean();
		case 'array':
			return z.array(z.unknown());
		case 'object':
			return z.record(z.unknown());
		default:
			return z.unknown();
	}
}
