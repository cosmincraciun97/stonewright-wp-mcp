/**
 * Format-preserving Codex config.toml MCP server section editor.
 * Only touches [mcp_servers.<name>] blocks — leaves comments and other keys alone.
 */
import { existsSync } from 'node:fs';
import { join } from 'node:path';
import { readTextFile, writeWithRollback } from './atomic-config.js';
import {
	applyStringReplacement,
	requireOnePackageReference,
	sha256Text,
} from './package-reference.js';
import {
	type ApplyResult,
	type ClientAdapter,
	ClientConfigError,
	type McpServerEntry,
	type RemoveResult,
	type VerifyConfigResult,
} from './types.js';

function tomlEscape(value: string): string {
	return `"${value.replace(/\\/g, '\\\\').replace(/"/g, '\\"')}"`;
}

function renderServerBlock(entry: McpServerEntry): string {
	const args = entry.args.map(tomlEscape).join(', ');
	const lines = [
		`[mcp_servers.${entry.serverName}]`,
		`command = ${tomlEscape(entry.command)}`,
		`args = [${args}]`,
	];
	const envKeys = Object.keys(entry.env);
	if (envKeys.length > 0) {
		lines.push('');
		lines.push(`[mcp_servers.${entry.serverName}.env]`);
		for (const key of envKeys.sort()) {
			lines.push(`${key} = ${tomlEscape(entry.env[key])}`);
		}
	}
	return lines.join('\n');
}

/**
 * Split TOML into segments: non-mcp preamble/other sections and mcp_servers blocks keyed by name.
 */
function parseMcpSections(text: string): {
	prefix: string;
	/** Ordered server names as they appear */
	order: string[];
	blocks: Map<string, string>;
	suffix: string;
} {
	const lines = text.split(/\r?\n/);
	const order: string[] = [];
	const blocks = new Map<string, string>();
	const prefixLines: string[] = [];
	const suffixLines: string[] = [];
	let mode: 'prefix' | 'block' | 'suffix' = 'prefix';
	let currentName: string | null = null;
	let currentLines: string[] = [];

	const headerRe = /^\[mcp_servers\.([^.\]]+)(?:\.[^\]]+)?\][ \t]*(?:#[^\r\n]*)?$/;

	const flushBlock = () => {
		if (currentName !== null) {
			if (!blocks.has(currentName)) {
				order.push(currentName);
			}
			const prev = blocks.get(currentName);
			blocks.set(currentName, prev ? `${prev}\n${currentLines.join('\n')}` : currentLines.join('\n'));
		}
		currentName = null;
		currentLines = [];
	};

	for (const line of lines) {
		const m = headerRe.exec(line);
		if (m) {
			const name = m[1];
			if (mode === 'prefix') {
				mode = 'block';
			}
			if (mode === 'suffix') {
				// another mcp block after non-mcp content following mcp — treat as block again
				mode = 'block';
			}
			if (currentName !== name) {
				flushBlock();
				currentName = name;
				currentLines = [line];
			} else {
				currentLines.push(line);
			}
			continue;
		}

		if (mode === 'block') {
			// Leaving mcp_servers region when we hit a non-mcp section header
			if (/^\[[^\]\r\n]+\][ \t]*(?:#[^\r\n]*)?$/.test(line) && !line.startsWith('[mcp_servers.')) {
				flushBlock();
				mode = 'suffix';
				suffixLines.push(line);
				continue;
			}
			currentLines.push(line);
			continue;
		}

		if (mode === 'suffix') {
			suffixLines.push(line);
		} else {
			prefixLines.push(line);
		}
	}
	flushBlock();

	return {
		prefix: prefixLines.join('\n'),
		order,
		blocks,
		suffix: suffixLines.join('\n'),
	};
}

function rebuildToml(parts: {
	prefix: string;
	order: string[];
	blocks: Map<string, string>;
	suffix: string;
}): string {
	const chunks: string[] = [];
	if (parts.prefix.trim() !== '') {
		chunks.push(parts.prefix.replace(/\s+$/, ''));
	}
	for (const name of parts.order) {
		const block = parts.blocks.get(name);
		if (block && block.trim() !== '') {
			chunks.push(block.replace(/^\n+/, '').replace(/\s+$/, ''));
		}
	}
	// Any blocks not in order
	for (const [name, block] of parts.blocks) {
		if (!parts.order.includes(name) && block.trim() !== '') {
			chunks.push(block.replace(/^\n+/, '').replace(/\s+$/, ''));
		}
	}
	if (parts.suffix.trim() !== '') {
		chunks.push(parts.suffix.replace(/^\n+/, '').replace(/\s+$/, ''));
	}
	return `${chunks.join('\n\n')}\n`;
}

function parseEntryFromBlock(name: string, block: string): McpServerEntry | null {
	const commandMatch = /^command\s*=\s*"((?:\\.|[^"\\])*)"\s*(?:#.*)?$/m.exec(block);
	if (!commandMatch) return null;
	const command = commandMatch[1].replace(/\\"/g, '"').replace(/\\\\/g, '\\');
	const argsMatch = /^args\s*=\s*\[([^\]]*)\]\s*(?:#.*)?$/m.exec(block);
	const args: string[] = [];
	if (argsMatch) {
		const re = /"((?:\\.|[^"\\])*)"/g;
		let m: RegExpExecArray | null;
		while ((m = re.exec(argsMatch[1])) !== null) {
			args.push(m[1].replace(/\\"/g, '"').replace(/\\\\/g, '\\'));
		}
	}
	const env: Record<string, string> = {};
	const envSection = block.split(new RegExp(`\\[mcp_servers\\.${name}\\.env\\]`))[1];
	if (envSection) {
		const envBody = envSection.split(/\n\[/)[0] ?? envSection;
		for (const line of envBody.split('\n')) {
			const em = /^([A-Za-z_][A-Za-z0-9_]*)\s*=\s*"(.*)"\s*$/.exec(line.trim());
			if (em) {
				env[em[1]] = em[2].replace(/\\"/g, '"').replace(/\\\\/g, '\\');
			}
		}
	}
	return { serverName: name, command, args, env };
}

function validateTomlHasStructure(path: string): void {
	const raw = readTextFile(path);
	if (raw === null) {
		throw new ClientConfigError('config_parse_failure', `${path} missing after write`);
	}
	// Basic validation: balanced enough that we can re-parse mcp sections
	try {
		parseMcpSections(raw);
	} catch (err) {
		throw new ClientConfigError(
			'config_parse_failure',
			`TOML structure invalid: ${err instanceof Error ? err.message : String(err)}`,
		);
	}
}

function findPackageReplacement(text: string, serverName: string, packageSpec: string) {
	const escaped = serverName.replace(/[.*+?^${}()|[\]\\]/g, '\\$&');
	const header = new RegExp(`^\\[mcp_servers\\.${escaped}\\][ \\t]*(?:#[^\\r\\n]*)?$`, 'gm');
	const matches = [...text.matchAll(header)];
	if (matches.length === 0) {
		throw new ClientConfigError('server_entry_not_found', `server_entry_not_found: no [mcp_servers.${serverName}] block.`);
	}
	if (matches.length !== 1) {
		throw new ClientConfigError('server_entry_ambiguous', `server_entry_ambiguous: found ${matches.length} [mcp_servers.${serverName}] blocks.`);
	}
	const blockStart = matches[0].index + matches[0][0].length;
	const nextHeader = /^\[[^\]\r\n]+\][ \t]*(?:#[^\r\n]*)?$/gm;
	nextHeader.lastIndex = blockStart;
	const next = nextHeader.exec(text);
	const blockEnd = next?.index ?? text.length;
	const block = text.slice(blockStart, blockEnd);
	const argsMatch = /^\s*args\s*=\s*\[([\s\S]*?)\]/m.exec(block);
	if (!argsMatch || argsMatch.index === undefined) {
		throw new ClientConfigError('package_reference_not_found', 'package_reference_not_found: target server has no args array.');
	}
	const argsStart = blockStart + argsMatch.index + argsMatch[0].indexOf('[') + 1;
	const candidates: Array<{ start: number; end: number; value: string; quote: string }> = [];
	const stringRe = /"((?:\\.|[^"\\])*)"/g;
	let stringMatch: RegExpExecArray | null;
	while ((stringMatch = stringRe.exec(argsMatch[1])) !== null) {
		const raw = stringMatch[1];
		candidates.push({
			start: argsStart + stringMatch.index,
			end: argsStart + stringMatch.index + stringMatch[0].length,
			value: raw.replace(/\\"/g, '"').replace(/\\\\/g, '\\'),
			quote: 'toml',
		});
	}
	return requireOnePackageReference(candidates, packageSpec);
}

export function codexAdapter(): ClientAdapter {
	return {
		id: 'codex',
		label: 'Codex',
		supportTier: 'compatible',
		configFormat: 'toml-codex',
		defaultConfigPath: (home) => join(home, '.codex', 'config.toml'),
		officialCliAdd: 'codex mcp add',

		listServerNames(configPath: string): string[] {
			if (!existsSync(configPath)) return [];
			const text = readTextFile(configPath) ?? '';
			return parseMcpSections(text).order;
		},

		read(configPath: string, serverName: string): McpServerEntry | null {
			if (!existsSync(configPath)) return null;
			const text = readTextFile(configPath) ?? '';
			const parts = parseMcpSections(text);
			const block = parts.blocks.get(serverName);
			if (!block) return null;
			return parseEntryFromBlock(serverName, block);
		},

		updatePackageReference(configPath: string, serverName: string, packageSpec: string) {
			const before = readTextFile(configPath);
			if (before === null) throw new ClientConfigError('config_missing', `${configPath} does not exist.`);
			const replacement = findPackageReplacement(before, serverName, packageSpec);
			const next = applyStringReplacement(before, replacement);
			const written = writeWithRollback({ path: configPath, nextContents: next, validate: validateTomlHasStructure });
			return {
				configPath,
				backupPath: written.backupPath,
				changed: written.changed,
				diff: written.diff,
				serverName,
				previousPackageSpec: replacement.value,
				packageSpec,
				beforeSha256: sha256Text(before),
				afterSha256: sha256Text(next),
			};
		},

		upsert(configPath: string, entry: McpServerEntry): ApplyResult {
			const before = readTextFile(configPath) ?? '';
			const parts = parseMcpSections(before);
			const created = !parts.blocks.has(entry.serverName);
			parts.blocks.set(entry.serverName, renderServerBlock(entry));
			if (!parts.order.includes(entry.serverName)) {
				parts.order.push(entry.serverName);
			}
			const next = rebuildToml(parts);
			const { backupPath, changed, diff } = writeWithRollback({
				path: configPath,
				nextContents: next,
				validate: validateTomlHasStructure,
			});
			return {
				configPath,
				backupPath,
				changed,
				diff,
				serverName: entry.serverName,
				created,
			};
		},

		remove(configPath: string, serverName: string): RemoveResult {
			if (!existsSync(configPath)) {
				return { configPath, backupPath: null, removed: false, serverName };
			}
			const before = readTextFile(configPath) ?? '';
			const parts = parseMcpSections(before);
			if (!parts.blocks.has(serverName)) {
				return { configPath, backupPath: null, removed: false, serverName };
			}
			parts.blocks.delete(serverName);
			parts.order = parts.order.filter((n) => n !== serverName);
			const next = rebuildToml(parts);
			const { backupPath } = writeWithRollback({
				path: configPath,
				nextContents: next,
				validate: validateTomlHasStructure,
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
					detail: 'Codex config.toml does not exist',
					structural: true,
				};
			}
			const entry = this.read(configPath, serverName);
			if (!entry) {
				return {
					ok: false,
					configPath,
					serverName,
					hasEntry: false,
					detail: `No [mcp_servers.${serverName}] block`,
					structural: true,
				};
			}
			return {
				ok: true,
				configPath,
				serverName,
				hasEntry: true,
				detail: `Codex entry "${serverName}" present (structural verify)`,
				structural: true,
			};
		},
	};
}
