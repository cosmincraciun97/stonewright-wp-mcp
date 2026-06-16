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
	'stonewright-wp-cli-install',
	'stonewright-wordpress-mcp-status',
]);

export type ProxyToolProfile = 'full' | 'essential' | 'elementor-design' | 'content-model' | 'gutenberg' | 'wp-cli' | 'site-admin';

export const STARTUP_REQUIRED_PROXY_TOOL_NAMES = [
	'stonewright-context-bootstrap',
	'stonewright-workflow-preflight',
	'stonewright-tool-profile',
	'stonewright-skills-get',
] as const;
const BASE_PROXY_TOOL_NAMES = STARTUP_REQUIRED_PROXY_TOOL_NAMES;

const ESSENTIAL_PROXY_TOOL_NAMES = [
	...BASE_PROXY_TOOL_NAMES,
	'stonewright-security-issue-confirmation-token',
	'stonewright-security-create-one-time-link',
	'stonewright-ping',
	'stonewright-site-info',
	'stonewright-site-capabilities',
	'stonewright-site-environment',
	'stonewright-site-health',
	'stonewright-site-plugins-list',
	'stonewright-site-theme',
	'stonewright-system-abilities-list',
	'stonewright-system-instructions-get',
	'stonewright-content-create-page',
	'stonewright-content-get-page',
	'stonewright-content-update-page',
	'stonewright-content-bulk-upsert-posts',
	'stonewright-media-list',
	'stonewright-media-upload-batch',
	'stonewright-design-implementation-contract',
	'stonewright-elementor-v3-status',
	'stonewright-elementor-v3-capabilities-summary',
	'stonewright-elementor-v3-container-schema',
	'stonewright-elementor-v3-list-widgets',
	'stonewright-elementor-v3-get-widget-schema',
	'stonewright-elementor-v3-get-page-structure',
	'stonewright-elementor-v3-get-element',
	'stonewright-widget-intent-resolve',
	'stonewright-elementor-widget-implementation-guide',
	'stonewright-elementor-v3-build-page-from-spec',
	'stonewright-elementor-v3-batch-mutate',
	'stonewright-elementor-v3-apply-bundle',
	'stonewright-elementor-v3-update-page-settings',
	'stonewright-elementor-v3-update-kit-colors',
	'stonewright-elementor-v3-update-kit-typography',
	'stonewright-elementor-v3-save-template',
	'stonewright-design-validate-spec',
	'stonewright-design-build-spec',
	'stonewright-design-choose-renderer',
	'stonewright-design-spec-to-elementor-v3',
	'stonewright-design-spec-to-gutenberg',
	'stonewright-design-preview-render',
	'stonewright-design-apply-to-post',
	'stonewright-blocks-list-registered',
	'stonewright-blocks-get-schema',
	'stonewright-blocks-parse',
	'stonewright-blocks-serialize',
	'stonewright-gutenberg-render-blocks',
	'stonewright-gutenberg-apply-to-post',
	'stonewright-fse-get-theme-json',
	'stonewright-fse-list-templates',
	'stonewright-fse-read-template',
	'stonewright-fse-write-template',
	'stonewright-fse-write-global-styles',
] as const;

const PROXY_TOOL_PROFILE_NAMES: Record<Exclude<ProxyToolProfile, 'full'>, readonly string[]> = {
	essential: ESSENTIAL_PROXY_TOOL_NAMES,
	'elementor-design': [
		...BASE_PROXY_TOOL_NAMES,
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
		'stonewright-elementor-v3-get-widget-schema',
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
	],
	'content-model': [
		...BASE_PROXY_TOOL_NAMES,
		'stonewright-site-capabilities',
		'stonewright-site-plugins-list',
		'stonewright-system-abilities-list',
		'stonewright-content-bulk-upsert-posts',
		'stonewright-media-list',
		'stonewright-media-upload-batch',
	],
	gutenberg: [
		...BASE_PROXY_TOOL_NAMES,
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
		'stonewright-site-info',
		'stonewright-site-environment',
		'stonewright-site-health',
		'stonewright-site-plugins-list',
		'stonewright-site-theme',
		'stonewright-security-create-one-time-link',
		'stonewright-system-abilities-list',
		'stonewright-menu-list',
	],
};

const PROXY_TOOL_PROFILE_SETS = Object.fromEntries(
	Object.entries(PROXY_TOOL_PROFILE_NAMES).map(([profile, names]) => [profile, new Set(names)]),
) as Record<Exclude<ProxyToolProfile, 'full'>, Set<string>>;

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

	for (const tool of tools) {
		if (!tool.name || tool.name.startsWith('companion_') || COMPANION_OWNED_TOOL_NAMES.has(tool.name)) {
			continue;
		}
		if (!proxyToolAllowed(tool.name, profile)) {
			profileFilteredToolNames.push(tool.name);
			continue;
		}

		server.tool(
			tool.name,
			tool.description ?? tool.title ?? 'Proxied Stonewright WordPress MCP tool.',
			zodShapeFromJsonSchema(tool.inputSchema ?? emptyObjectSchema()),
			async (input) => normalizeToolResponse(await client.callTool(tool.name, input)),
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

function proxyToolProfileFromEnv(env: NodeJS.ProcessEnv): ProxyToolProfile {
	const raw = (env['STONEWRIGHT_MCP_TOOL_PROFILE'] ?? env['STONEWRIGHT_MCP_PROXY_PROFILE'] ?? 'essential')
		.trim()
		.toLowerCase();
	if (['0', 'false', 'off', 'full', 'all'].includes(raw)) {
		return 'full';
	}
	if (raw === '' || raw === 'auto' || raw === 'fast' || raw === 'general' || raw === 'compact') {
		return 'essential';
	}
	if (raw in PROXY_TOOL_PROFILE_SETS) {
		return raw as Exclude<ProxyToolProfile, 'full'>;
	}
	return 'essential';
}

function proxyToolAllowed(toolName: string, profile: ProxyToolProfile): boolean {
	if (profile === 'full') {
		return true;
	}
	return PROXY_TOOL_PROFILE_SETS[profile].has(toolName);
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
