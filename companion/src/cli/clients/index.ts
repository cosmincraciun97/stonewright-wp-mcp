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
import { AUTHORITATIVE_CLIENT_CATALOG } from '../../contracts/client-catalog.generated.js';

export type { ClientAdapter, McpServerEntry, SupportTier, ApplyResult, RemoveResult, VerifyConfigResult, PackageUpdateResult } from './types.js';
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
		'chatgpt-desktop': 'codex',
	};
	const key = map[normalized] ?? normalized;
	return implementedAdapters().find((a) => a.id === key) ?? null;
}

export function listClientCatalog(): ClientCatalogMeta[] {
	const clients = AUTHORITATIVE_CLIENT_CATALOG.map((client): ClientCatalogMeta => ({
		...client,
		supportTier: client.supportTier as SupportTier,
		adapterImplemented: getClientAdapter(client.id) !== null,
	}));
	const codex = clients.find((client) => client.id === 'codex')!;
	const desktopAlias: ClientCatalogMeta = {
		...codex,
		id: 'chatgpt-desktop',
		label: 'Codex in ChatGPT Desktop',
		adapterImplemented: true,
	};
	return [
		...clients,
		desktopAlias,
	].sort((a, b) =>
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
	const codex = results.find((client) => client.id === 'codex');
	if (codex) {
		results.push({
			...codex,
			id: 'chatgpt-desktop',
			label: 'Codex in ChatGPT Desktop',
		});
	}
	// Heuristic paths for stubs
	const stubPaths: Record<string, string> = {
		'claude-code': join(homeDir, '.claude.json'),
		cline: join(homeDir, '.cline', 'config.json'),
		windsurf: join(homeDir, '.codeium', 'windsurf', 'mcp_config.json'),
		zed: join(homeDir, '.config', 'zed', 'settings.json'),
	};
	const detectedIds = new Set(results.map((client) => client.id));
	for (const stub of listClientCatalog().filter((client) => !detectedIds.has(client.id))) {
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
	wordpressMode?: 'development' | 'staging' | 'production-safe';
	wordpressToolSurface?: 'bootstrap' | 'essential' | 'full';
}): McpServerEntry {
	return {
		serverName: args.serverName,
		command: 'npx',
		args: ['-y', '--package', args.packageSpec, 'stonewright-mcp'],
		env: {
			STONEWRIGHT_MODE: args.modeEnv,
			STONEWRIGHT_MCP_TOOL_PROFILE: args.toolProfile ?? 'essential-static',
			STONEWRIGHT_SITE_ALIAS: args.siteAlias,
			STONEWRIGHT_WORDPRESS_MODE: args.wordpressMode ?? 'development',
			STONEWRIGHT_WORDPRESS_TOOL_SURFACE: args.wordpressToolSurface ?? 'essential',
		},
	};
}
