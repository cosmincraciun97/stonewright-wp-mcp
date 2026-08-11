import { existsSync } from 'node:fs';
import { join } from 'node:path';
import { readTextFile, writeWithRollback } from './atomic-config.js';
import {
	type ApplyResult,
	type ClientAdapter,
	ClientConfigError,
	type McpServerEntry,
	type RemoveResult,
	type VerifyConfigResult,
} from './types.js';

type JsonRoot = {
	mcpServers?: Record<string, unknown>;
	servers?: Record<string, unknown>;
	mcp?: { servers?: Record<string, unknown> };
	[key: string]: unknown;
};

function parseJson(path: string, raw: string | null): JsonRoot {
	if (raw === null || raw.trim() === '') {
		return {};
	}
	try {
		const parsed = JSON.parse(raw) as unknown;
		if (!parsed || typeof parsed !== 'object' || Array.isArray(parsed)) {
			throw new ClientConfigError('config_parse_failure', `${path}: root must be a JSON object`);
		}
		return parsed as JsonRoot;
	} catch (err) {
		if (err instanceof ClientConfigError) throw err;
		throw new ClientConfigError(
			'config_parse_failure',
			`${path}: ${err instanceof Error ? err.message : String(err)}`,
		);
	}
}

function serverBucket(root: JsonRoot): {
	key: 'mcpServers' | 'servers' | 'mcp.servers';
	map: Record<string, unknown>;
} {
	if (root.mcpServers && typeof root.mcpServers === 'object') {
		return { key: 'mcpServers', map: root.mcpServers };
	}
	if (root.servers && typeof root.servers === 'object') {
		return { key: 'servers', map: root.servers };
	}
	if (root.mcp && typeof root.mcp === 'object' && root.mcp.servers && typeof root.mcp.servers === 'object') {
		return { key: 'mcp.servers', map: root.mcp.servers };
	}
	// Default Cursor/Claude-style
	if (!root.mcpServers) root.mcpServers = {};
	return { key: 'mcpServers', map: root.mcpServers };
}

function entryFromJson(name: string, value: unknown): McpServerEntry | null {
	if (!value || typeof value !== 'object') return null;
	const row = value as Record<string, unknown>;
	const command = typeof row.command === 'string' ? row.command : '';
	const args = Array.isArray(row.args) ? row.args.filter((a): a is string => typeof a === 'string') : [];
	const env =
		row.env && typeof row.env === 'object' && !Array.isArray(row.env)
			? Object.fromEntries(
					Object.entries(row.env as Record<string, unknown>)
						.filter(([, v]) => typeof v === 'string')
						.map(([k, v]) => [k, v as string]),
				)
			: {};
	if (!command) return null;
	return { serverName: name, command, args, env };
}

function entryToJson(entry: McpServerEntry): Record<string, unknown> {
	return {
		command: entry.command,
		args: entry.args,
		env: entry.env,
	};
}

function setBucket(root: JsonRoot, key: 'mcpServers' | 'servers' | 'mcp.servers', map: Record<string, unknown>): void {
	if (key === 'mcpServers') {
		root.mcpServers = map;
		return;
	}
	if (key === 'servers') {
		root.servers = map;
		return;
	}
	if (!root.mcp || typeof root.mcp !== 'object') root.mcp = {};
	(root.mcp as { servers: Record<string, unknown> }).servers = map;
}

function validateJsonFile(path: string): void {
	const raw = readTextFile(path);
	if (raw === null) {
		throw new ClientConfigError('config_parse_failure', `${path} missing after write`);
	}
	parseJson(path, raw);
}

export function createGenericJsonAdapter(meta: {
	id: string;
	label: string;
	supportTier: ClientAdapter['supportTier'];
	defaultConfigPath: (homeDir: string) => string;
	officialCliAdd?: string;
	/** Prefer "servers" key (VS Code) instead of mcpServers. */
	preferServersKey?: boolean;
}): ClientAdapter {
	return {
		id: meta.id,
		label: meta.label,
		supportTier: meta.supportTier,
		configFormat: meta.preferServersKey ? 'json-servers' : 'json-mcp',
		defaultConfigPath: meta.defaultConfigPath,
		officialCliAdd: meta.officialCliAdd,

		listServerNames(configPath: string): string[] {
			if (!existsSync(configPath)) return [];
			const root = parseJson(configPath, readTextFile(configPath));
			const { map } = serverBucket(root);
			return Object.keys(map);
		},

		read(configPath: string, serverName: string): McpServerEntry | null {
			if (!existsSync(configPath)) return null;
			const root = parseJson(configPath, readTextFile(configPath));
			const { map } = serverBucket(root);
			return entryFromJson(serverName, map[serverName]);
		},

		upsert(configPath: string, entry: McpServerEntry): ApplyResult {
			const beforeRaw = readTextFile(configPath);
			const root = parseJson(configPath, beforeRaw);
			let bucket = serverBucket(root);
			if (meta.preferServersKey && bucket.key === 'mcpServers' && !beforeRaw) {
				root.servers = {};
				delete root.mcpServers;
				bucket = serverBucket(root);
			}
			const created = !(entry.serverName in bucket.map);
			bucket.map[entry.serverName] = entryToJson(entry);
			setBucket(root, bucket.key, bucket.map);
			const next = `${JSON.stringify(root, null, 2)}\n`;
			const { backupPath, diff } = writeWithRollback({
				path: configPath,
				nextContents: next,
				validate: validateJsonFile,
			});
			return {
				configPath,
				backupPath,
				diff,
				serverName: entry.serverName,
				created,
			};
		},

		remove(configPath: string, serverName: string): RemoveResult {
			if (!existsSync(configPath)) {
				return { configPath, backupPath: null, removed: false, serverName };
			}
			const root = parseJson(configPath, readTextFile(configPath));
			const bucket = serverBucket(root);
			if (!(serverName in bucket.map)) {
				return { configPath, backupPath: null, removed: false, serverName };
			}
			delete bucket.map[serverName];
			setBucket(root, bucket.key, bucket.map);
			const next = `${JSON.stringify(root, null, 2)}\n`;
			const { backupPath } = writeWithRollback({
				path: configPath,
				nextContents: next,
				validate: validateJsonFile,
			});
			return { configPath, backupPath, removed: true, serverName };
		},

		verify(configPath: string, serverName: string): VerifyConfigResult {
			if (!existsSync(configPath)) {
				return {
					ok: false,
					configPath,
					serverName,
					hasEntry: false,
					detail: 'Config file does not exist',
					structural: true,
				};
			}
			try {
				const entry = this.read(configPath, serverName);
				if (!entry) {
					return {
						ok: false,
						configPath,
						serverName,
						hasEntry: false,
						detail: `No MCP server entry named "${serverName}"`,
						structural: true,
					};
				}
				const hasProfile = Boolean(entry.env.STONEWRIGHT_MCP_TOOL_PROFILE || entry.env.STONEWRIGHT_MODE);
				return {
					ok: true,
					configPath,
					serverName,
					hasEntry: true,
					detail: hasProfile
						? `Entry "${serverName}" present with Stonewright env (structural verify)`
						: `Entry "${serverName}" present (structural verify)`,
					structural: true,
				};
			} catch (err) {
				return {
					ok: false,
					configPath,
					serverName,
					hasEntry: false,
					detail: err instanceof Error ? err.message : String(err),
					structural: true,
				};
			}
		},
	};
}

export function cursorAdapter(): ClientAdapter {
	return createGenericJsonAdapter({
		id: 'cursor',
		label: 'Cursor',
		supportTier: 'compatible',
		defaultConfigPath: (home) => join(home, '.cursor', 'mcp.json'),
	});
}

export function claudeDesktopAdapter(): ClientAdapter {
	return createGenericJsonAdapter({
		id: 'claude-desktop',
		label: 'Claude Desktop',
		supportTier: 'compatible',
		defaultConfigPath: (home) => {
			if (process.platform === 'darwin') {
				return join(home, 'Library', 'Application Support', 'Claude', 'claude_desktop_config.json');
			}
			if (process.platform === 'win32') {
				return join(home, 'AppData', 'Roaming', 'Claude', 'claude_desktop_config.json');
			}
			return join(home, '.config', 'Claude', 'claude_desktop_config.json');
		},
	});
}

export function vscodeAdapter(): ClientAdapter {
	return createGenericJsonAdapter({
		id: 'vscode-copilot',
		label: 'VS Code (Copilot)',
		supportTier: 'compatible',
		preferServersKey: true,
		defaultConfigPath: (home) => join(home, '.vscode', 'mcp.json'),
	});
}

export function genericMcpAdapter(): ClientAdapter {
	return createGenericJsonAdapter({
		id: 'generic-mcp',
		label: 'Generic MCP',
		supportTier: 'community',
		defaultConfigPath: (home) => join(home, '.stonewright', 'mcp.generic.json'),
	});
}
