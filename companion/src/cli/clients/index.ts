import { existsSync } from 'node:fs';
import { homedir } from 'node:os';
import { join } from 'node:path';
import {
	claudeDesktopAdapter,
	cursorAdapter,
	genericMcpAdapter,
	vscodeAdapter,
} from './generic-json.js';
import { codexAdapter } from './codex-toml.js';
import type { ClientAdapter, McpServerEntry, SupportTier } from './types.js';

export type { ClientAdapter, McpServerEntry, SupportTier, ApplyResult, RemoveResult, VerifyConfigResult } from './types.js';
export { ClientConfigError } from './types.js';
export { codexAdapter } from './codex-toml.js';
export {
	createGenericJsonAdapter,
	cursorAdapter,
	claudeDesktopAdapter,
	vscodeAdapter,
	genericMcpAdapter,
} from './generic-json.js';

/** Catalog metadata used by detect-client and ClientCatalog-aligned fields. */
export interface ClientCatalogMeta {
	id: string;
	label: string;
	supportTier: SupportTier;
	configFormat: string;
	officialCliAdd: string;
	oauthSupport: boolean;
	appPasswordSupport: boolean;
	relistBehavior: string;
	newTaskRequiredAfterCatalogChange: boolean;
	safeToolBudget: number;
	defaultProfile: string;
	/** True when we ship a real adapter (not stub). */
	adapterImplemented: boolean;
}

const STUB_CLIENTS: ClientCatalogMeta[] = [
	{
		id: 'claude-code',
		label: 'Claude Code',
		supportTier: 'community',
		configFormat: 'cli-only',
		officialCliAdd: 'claude mcp add',
		oauthSupport: false,
		appPasswordSupport: true,
		relistBehavior: 'restart-session',
		newTaskRequiredAfterCatalogChange: true,
		safeToolBudget: 40,
		defaultProfile: 'essential-static',
		adapterImplemented: false,
	},
	{
		id: 'gemini-cli',
		label: 'Gemini CLI',
		supportTier: 'community',
		configFormat: 'cli-only',
		officialCliAdd: 'gemini mcp add',
		oauthSupport: false,
		appPasswordSupport: true,
		relistBehavior: 'restart-session',
		newTaskRequiredAfterCatalogChange: true,
		safeToolBudget: 40,
		defaultProfile: 'essential-static',
		adapterImplemented: false,
	},
	{
		id: 'cline',
		label: 'Cline',
		supportTier: 'community',
		configFormat: 'json-mcp',
		officialCliAdd: '',
		oauthSupport: false,
		appPasswordSupport: true,
		relistBehavior: 'reload-window',
		newTaskRequiredAfterCatalogChange: true,
		safeToolBudget: 40,
		defaultProfile: 'essential-static',
		adapterImplemented: false,
	},
	{
		id: 'windsurf',
		label: 'Windsurf',
		supportTier: 'community',
		configFormat: 'json-mcp',
		officialCliAdd: '',
		oauthSupport: false,
		appPasswordSupport: true,
		relistBehavior: 'reload-window',
		newTaskRequiredAfterCatalogChange: true,
		safeToolBudget: 40,
		defaultProfile: 'essential-static',
		adapterImplemented: false,
	},
	{
		id: 'zed',
		label: 'Zed',
		supportTier: 'community',
		configFormat: 'json-mcp',
		officialCliAdd: '',
		oauthSupport: false,
		appPasswordSupport: true,
		relistBehavior: 'restart-session',
		newTaskRequiredAfterCatalogChange: true,
		safeToolBudget: 40,
		defaultProfile: 'essential-static',
		adapterImplemented: false,
	},
	{
		id: 'opencode',
		label: 'OpenCode',
		supportTier: 'unknown',
		configFormat: 'unknown',
		officialCliAdd: '',
		oauthSupport: false,
		appPasswordSupport: true,
		relistBehavior: 'unknown',
		newTaskRequiredAfterCatalogChange: true,
		safeToolBudget: 40,
		defaultProfile: 'essential-static',
		adapterImplemented: false,
	},
	{
		id: 'roo-code',
		label: 'Roo Code',
		supportTier: 'unknown',
		configFormat: 'unknown',
		officialCliAdd: '',
		oauthSupport: false,
		appPasswordSupport: true,
		relistBehavior: 'unknown',
		newTaskRequiredAfterCatalogChange: true,
		safeToolBudget: 40,
		defaultProfile: 'essential-static',
		adapterImplemented: false,
	},
	{
		id: 'kilo-code',
		label: 'Kilo Code',
		supportTier: 'unknown',
		configFormat: 'unknown',
		officialCliAdd: '',
		oauthSupport: false,
		appPasswordSupport: true,
		relistBehavior: 'unknown',
		newTaskRequiredAfterCatalogChange: true,
		safeToolBudget: 40,
		defaultProfile: 'essential-static',
		adapterImplemented: false,
	},
	{
		id: 'amazon-q',
		label: 'Amazon Q',
		supportTier: 'community',
		configFormat: 'unknown',
		officialCliAdd: '',
		oauthSupport: false,
		appPasswordSupport: true,
		relistBehavior: 'unknown',
		newTaskRequiredAfterCatalogChange: true,
		safeToolBudget: 40,
		defaultProfile: 'essential-static',
		adapterImplemented: false,
	},
	{
		id: 'antigravity',
		label: 'Antigravity',
		supportTier: 'unknown',
		configFormat: 'unknown',
		officialCliAdd: '',
		oauthSupport: false,
		appPasswordSupport: true,
		relistBehavior: 'unknown',
		newTaskRequiredAfterCatalogChange: true,
		safeToolBudget: 40,
		defaultProfile: 'essential-static',
		adapterImplemented: false,
	},
	{
		id: 'antigravity-cli',
		label: 'Antigravity CLI',
		supportTier: 'unknown',
		configFormat: 'json-mcp',
		officialCliAdd: '',
		oauthSupport: true,
		appPasswordSupport: true,
		relistBehavior: 'restart-session',
		newTaskRequiredAfterCatalogChange: true,
		safeToolBudget: 40,
		defaultProfile: 'essential-static',
		adapterImplemented: false,
	},
	{
		id: 'chatgpt-desktop',
		label: 'Codex in ChatGPT Desktop',
		supportTier: 'community',
		configFormat: 'json-mcp',
		officialCliAdd: '',
		oauthSupport: true,
		appPasswordSupport: true,
		relistBehavior: 'restart-app',
		newTaskRequiredAfterCatalogChange: true,
		safeToolBudget: 40,
		defaultProfile: 'essential-static',
		adapterImplemented: false,
	},
	{
		id: 'chatgpt',
		label: 'ChatGPT',
		supportTier: 'community',
		configFormat: 'json-mcp',
		officialCliAdd: '',
		oauthSupport: true,
		appPasswordSupport: true,
		relistBehavior: 'reload-or-restart',
		newTaskRequiredAfterCatalogChange: true,
		safeToolBudget: 40,
		defaultProfile: 'essential-static',
		adapterImplemented: false,
	},
	{
		id: 'claude-ai',
		label: 'Claude.ai',
		supportTier: 'community',
		configFormat: 'json-mcp',
		officialCliAdd: '',
		oauthSupport: true,
		appPasswordSupport: true,
		relistBehavior: 'reload-or-restart',
		newTaskRequiredAfterCatalogChange: true,
		safeToolBudget: 40,
		defaultProfile: 'essential-static',
		adapterImplemented: false,
	},
	{
		id: 'github-copilot',
		label: 'GitHub Copilot',
		supportTier: 'community',
		configFormat: 'json-servers',
		officialCliAdd: '',
		oauthSupport: true,
		appPasswordSupport: true,
		relistBehavior: 'reload-window',
		newTaskRequiredAfterCatalogChange: true,
		safeToolBudget: 40,
		defaultProfile: 'essential-static',
		adapterImplemented: false,
	},
];

function implementedAdapters(): ClientAdapter[] {
	return [codexAdapter(), cursorAdapter(), claudeDesktopAdapter(), vscodeAdapter(), genericMcpAdapter()];
}

export function getClientAdapter(id: string): ClientAdapter | null {
	const normalized = id.trim().toLowerCase();
	// aliases
	const map: Record<string, string> = {
		vscode: 'vscode-copilot',
		'vs-code': 'vscode-copilot',
		claude: 'claude-desktop',
		'codex-cli': 'codex',
	};
	const key = map[normalized] ?? normalized;
	return implementedAdapters().find((a) => a.id === key) ?? null;
}

export function listClientCatalog(): ClientCatalogMeta[] {
	const implemented = implementedAdapters().map(
		(a): ClientCatalogMeta => ({
			id: a.id,
			label: a.label,
			supportTier: a.supportTier,
			configFormat: a.configFormat,
			officialCliAdd: a.officialCliAdd ?? '',
			oauthSupport: false,
			appPasswordSupport: true,
			relistBehavior: a.id === 'codex' ? 'restart-or-reload-mcp' : 'reload-window',
			newTaskRequiredAfterCatalogChange: true,
			safeToolBudget: 40,
			defaultProfile: 'essential-static',
			adapterImplemented: true,
		}),
	);
	const implementedIds = new Set(implemented.map((c) => c.id));
	return [...implemented, ...STUB_CLIENTS.filter((c) => !implementedIds.has(c.id))].sort((a, b) =>
		a.label.localeCompare(b.label),
	);
}

export interface DetectedClient {
	id: string;
	label: string;
	supportTier: SupportTier;
	configPath: string | null;
	configExists: boolean;
	adapterImplemented: boolean;
	officialCliAdd: string;
}

export function detectClients(homeDir = homedir()): DetectedClient[] {
	const results: DetectedClient[] = [];
	for (const adapter of implementedAdapters()) {
		const configPath = adapter.defaultConfigPath(homeDir);
		results.push({
			id: adapter.id,
			label: adapter.label,
			supportTier: adapter.supportTier,
			configPath,
			configExists: existsSync(configPath),
			adapterImplemented: true,
			officialCliAdd: adapter.officialCliAdd ?? '',
		});
	}
	// Heuristic paths for stubs
	const stubPaths: Record<string, string> = {
		'claude-code': join(homeDir, '.claude.json'),
		cline: join(homeDir, '.cline', 'config.json'),
		windsurf: join(homeDir, '.codeium', 'windsurf', 'mcp_config.json'),
		zed: join(homeDir, '.config', 'zed', 'settings.json'),
	};
	for (const stub of STUB_CLIENTS) {
		const configPath = stubPaths[stub.id] ?? null;
		results.push({
			id: stub.id,
			label: stub.label,
			supportTier: stub.supportTier,
			configPath,
			configExists: configPath ? existsSync(configPath) : false,
			adapterImplemented: false,
			officialCliAdd: stub.officialCliAdd,
		});
	}
	return results.sort((a, b) => {
		// Prefer existing configs, then implemented adapters
		if (a.configExists !== b.configExists) return a.configExists ? -1 : 1;
		if (a.adapterImplemented !== b.adapterImplemented) return a.adapterImplemented ? -1 : 1;
		return a.label.localeCompare(b.label);
	});
}

export function buildStdioServerEntry(args: {
	serverName: string;
	packageSpec: string;
	siteAlias: string;
	modeEnv: string;
	toolProfile?: string;
}): McpServerEntry {
	return {
		serverName: args.serverName,
		command: 'npx',
		args: ['-y', '--package', args.packageSpec, 'stonewright-mcp'],
		env: {
			STONEWRIGHT_MODE: args.modeEnv,
			STONEWRIGHT_MCP_TOOL_PROFILE: args.toolProfile ?? 'essential-static',
			STONEWRIGHT_SITE_ALIAS: args.siteAlias,
		},
	};
}
