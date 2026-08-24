/**
 * Format-preserving Codex config.toml MCP server section editor.
 * Only touches [mcp_servers.<name>] blocks — leaves comments and other keys alone.
 */
import { existsSync } from 'node:fs';
import { join } from 'node:path';
import { parse as parseToml, TomlError } from 'smol-toml';
import { readTextFile, writeWithRollback } from './atomic-config.js';
import {
	applyStringReplacement,
	requireValidTargetPackageReference,
	requireOnePackageReference,
	sha256Text,
	validateOfficialStonewrightNpxEntry,
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

interface TomlTableSpan {
	name: string;
	start: number;
	bodyStart: number;
	end: number;
}

interface TomlStringSpan {
	start: number;
	end: number;
	value: string;
	quote: 'toml-basic' | 'toml-literal';
}

function lineEnd(text: string, start: number): number {
	const end = text.indexOf('\n', start);
	return end < 0 ? text.length : end;
}

function lineColumn(text: string, offset: number): { line: number; column: number } {
	const safe = Math.max(0, Math.min(offset, text.length));
	const prefix = text.slice(0, safe);
	const lastNewline = prefix.lastIndexOf('\n');
	return {
		line: prefix.split('\n').length,
		column: safe - lastNewline,
	};
}

function tomlParseFailure(text: string, offset: number, code: string): never {
	const where = lineColumn(text, offset);
	throw new ClientConfigError(
		'config_parse_failure',
		`config_parse_failure: ${code} at line ${where.line}, column ${where.column}.`,
	);
}

function validateTomlDocument(text: string): void {
	try {
		parseToml(text);
	} catch (err) {
		const location = err instanceof TomlError
			? ` at line ${err.line}, column ${err.column}`
			: '';
		throw new ClientConfigError(
			'config_parse_failure',
			`config_parse_failure: invalid full TOML document${location}.`,
		);
	}
}

function decodeTomlBasicString(text: string, start: number, end: number): string {
	let value = '';
	for (let i = start + 1; i < end - 1; i++) {
		const char = text[i];
		if (char !== '\\') {
			value += char;
			continue;
		}
		const escape = text[++i];
		const simple: Record<string, string> = {
			b: '\b', t: '\t', n: '\n', f: '\f', r: '\r', '"': '"', '\\': '\\',
		};
		if (Object.hasOwn(simple, escape)) {
			value += simple[escape];
			continue;
		}
		if (escape === 'u' || escape === 'U') {
			const width = escape === 'u' ? 4 : 8;
			const hex = text.slice(i + 1, i + 1 + width);
			if (!new RegExp(`^[0-9A-Fa-f]{${width}}$`).test(hex)) {
				tomlParseFailure(text, i - 1, 'TOML_INVALID_UNICODE_ESCAPE');
			}
			const point = Number.parseInt(hex, 16);
			if (point > 0x10ffff || (point >= 0xd800 && point <= 0xdfff)) {
				tomlParseFailure(text, i - 1, 'TOML_INVALID_UNICODE_CODEPOINT');
			}
			value += String.fromCodePoint(point);
			i += width;
			continue;
		}
		tomlParseFailure(text, i - 1, 'TOML_INVALID_ESCAPE');
	}
	return value;
}

function scanTomlString(text: string, start: number): { end: number; token?: TomlStringSpan } {
	const quote = text[start];
	const multiline = text.slice(start, start + 3) === quote.repeat(3);
	if (multiline) {
		let i = start + 3;
		while (i < text.length) {
			if (quote === '"' && text[i] === '\\') {
				i += 2;
				continue;
			}
			if (text.slice(i, i + 3) === quote.repeat(3)) return { end: i + 3 };
			i++;
		}
		tomlParseFailure(text, start, 'TOML_UNCLOSED_MULTILINE_STRING');
	}

	let i = start + 1;
	while (i < text.length) {
		if (quote === '"' && text[i] === '\\') {
			i += 2;
			continue;
		}
		if (text[i] === quote) {
			const end = i + 1;
			if (quote === "'") {
				return { end, token: { start, end, value: text.slice(start + 1, i), quote: 'toml-literal' } };
			}
			return {
				end,
				token: { start, end, value: decodeTomlBasicString(text, start, end), quote: 'toml-basic' },
			};
		}
		if (text[i] === '\n' || text[i] === '\r') tomlParseFailure(text, start, 'TOML_UNCLOSED_STRING');
		i++;
	}
	tomlParseFailure(text, start, 'TOML_UNCLOSED_STRING');
}

/** Locate only real TOML table headers. Header-like text in comments or strings is ignored. */
function scanTomlTables(text: string): TomlTableSpan[] {
	const found: Array<Omit<TomlTableSpan, 'end'>> = [];
	let i = 0;
	let currentLineStart = 0;
	while (i < text.length) {
		const char = text[i];
		if (char === '\n') {
			currentLineStart = ++i;
			continue;
		}
		if (char === '#') {
			i = lineEnd(text, i);
			continue;
		}
		if (char === '"' || char === "'") {
			const scanned = scanTomlString(text, i);
			const skipped = text.slice(i, scanned.end);
			const last = skipped.lastIndexOf('\n');
			if (last >= 0) currentLineStart = i + last + 1;
			i = scanned.end;
			continue;
		}
		if (char === '[' && text.slice(currentLineStart, i).trim() === '' && text[i + 1] !== '[') {
			let end = i + 1;
			while (end < text.length && text[end] !== ']' && text[end] !== '\n' && text[end] !== '\r') end++;
			if (text[end] !== ']') tomlParseFailure(text, i, 'TOML_INVALID_TABLE_HEADER');
			const restEnd = lineEnd(text, end + 1);
			const rest = text.slice(end + 1, restEnd).trim();
			if (rest === '' || rest.startsWith('#')) {
				found.push({ name: text.slice(i + 1, end).trim(), start: i, bodyStart: end + 1 });
				i = end + 1;
				continue;
			}
		}
		i++;
	}
	return found.map((table, index) => ({
		...table,
		end: found[index + 1]?.start ?? text.length,
	}));
}

function exactTables(text: string, name: string): TomlTableSpan[] {
	return scanTomlTables(text).filter((table) => table.name === name);
}

function scanArrayStrings(text: string, start: number, limit: number): { end: number; strings: TomlStringSpan[] } {
	const strings: TomlStringSpan[] = [];
	let depth = 0;
	let i = start;
	while (i < limit) {
		const char = text[i];
		if (char === '#') {
			i = Math.min(lineEnd(text, i), limit);
			continue;
		}
		if (char === '"' || char === "'") {
			if (depth !== 1) tomlParseFailure(text, i, 'TOML_ARGS_NESTED_VALUE');
			const scanned = scanTomlString(text, i);
			if (scanned.end > limit) tomlParseFailure(text, i, 'TOML_STRING_CROSSES_TABLE');
			if (scanned.token) strings.push(scanned.token);
			i = scanned.end;
			continue;
		}
		if (char === '[') {
			if (depth >= 1) tomlParseFailure(text, i, 'TOML_ARGS_NON_STRING_MEMBER');
			depth++;
			i++;
			continue;
		}
		if (char === ']') {
			depth--;
			if (depth === 0) return { end: i + 1, strings };
		}
		if (depth === 1 && !/[\s,]/.test(char)) {
			tomlParseFailure(text, i, 'TOML_ARGS_NON_STRING_MEMBER');
		}
		i++;
	}
	tomlParseFailure(text, start, 'TOML_UNCLOSED_ARRAY');
}

function findBareAssignments(text: string, table: TomlTableSpan, key: string): number[] {
	const found: number[] = [];
	let i = table.bodyStart;
	let currentLineStart = text.lastIndexOf('\n', i - 1) + 1;
	while (i < table.end) {
		const char = text[i];
		if (char === '\n') {
			currentLineStart = ++i;
			continue;
		}
		if (char === '#') {
			i = Math.min(lineEnd(text, i), table.end);
			continue;
		}
		if (char === '"' || char === "'") {
			const scanned = scanTomlString(text, i);
			i = scanned.end;
			continue;
		}
		if (text.slice(currentLineStart, i).trim() === '' && /[A-Za-z0-9_-]/.test(char)) {
			let keyEnd = i + 1;
			while (keyEnd < table.end && /[A-Za-z0-9_-]/.test(text[keyEnd])) keyEnd++;
			let equals = keyEnd;
			while (equals < table.end && (text[equals] === ' ' || text[equals] === '\t')) equals++;
			if (text.slice(i, keyEnd) === key && text[equals] === '=') {
				let value = equals + 1;
				while (value < table.end && (text[value] === ' ' || text[value] === '\t')) value++;
				found.push(value);
			}
			i = keyEnd;
			continue;
		}
		i++;
	}
	return found;
}

function findBareAssignment(text: string, table: TomlTableSpan, key: string): number | null {
	const found = findBareAssignments(text, table, key);
	if (found.length > 1) tomlParseFailure(text, found[1], `TOML_DUPLICATE_${key.toUpperCase()}`);
	return found[0] ?? null;
}

function tableArrayStrings(text: string, table: TomlTableSpan, key: string): TomlStringSpan[] | null {
	const value = findBareAssignment(text, table, key);
	if (value === null || text[value] !== '[') return null;
	return scanArrayStrings(text, value, table.end).strings;
}

function tableStringValue(text: string, table: TomlTableSpan, key: string): string | null {
	const value = findBareAssignment(text, table, key);
	if (value === null || (text[value] !== '"' && text[value] !== "'")) return null;
	return scanTomlString(text, value).token?.value ?? null;
}

function parseEntryFromSyntax(text: string, name: string): McpServerEntry | null {
	const tables = exactTables(text, `mcp_servers.${name}`);
	if (tables.length !== 1) return null;
	const command = tableStringValue(text, tables[0], 'command');
	const args = tableArrayStrings(text, tables[0], 'args')?.map((token) => token.value) ?? [];
	if (!command) return null;
	const env: Record<string, string> = {};
	const envTables = exactTables(text, `mcp_servers.${name}.env`);
	if (envTables.length === 1) {
		const block = text.slice(envTables[0].bodyStart, envTables[0].end);
		for (const line of block.split(/\r?\n/)) {
			const match = /^\s*([A-Za-z_][A-Za-z0-9_]*)\s*=\s*("(?:\\.|[^"\\])*"|'[^']*')\s*(?:#.*)?$/.exec(line);
			if (!match) continue;
			try {
				env[match[1]] = match[2].startsWith("'") ? match[2].slice(1, -1) : JSON.parse(match[2]) as string;
			} catch {
				// Invalid environment strings are ignored; the package readback remains strict.
			}
		}
	}
	return { serverName: name, command, args, env };
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

function validateTomlHasStructure(path: string): void {
	const raw = readTextFile(path);
	if (raw === null) {
		throw new ClientConfigError('config_parse_failure', `${path} missing after write`);
	}
	validateTomlDocument(raw);
}

function findPackageReplacement(text: string, serverName: string, packageSpec: string) {
	requireValidTargetPackageReference(packageSpec);
	const tables = exactTables(text, `mcp_servers.${serverName}`);
	if (tables.length === 0) {
		throw new ClientConfigError('server_entry_not_found', `server_entry_not_found: no [mcp_servers.${serverName}] block.`);
	}
	if (tables.length !== 1) {
		throw new ClientConfigError('server_entry_ambiguous', `server_entry_ambiguous: found ${tables.length} [mcp_servers.${serverName}] blocks.`);
	}
	validateTomlDocument(text);
	const strings = tableArrayStrings(text, tables[0], 'args');
	if (!strings) {
		throw new ClientConfigError('package_reference_not_found', 'package_reference_not_found: target server has no args array.');
	}
	const command = tableStringValue(text, tables[0], 'command') ?? '';
	validateOfficialStonewrightNpxEntry(command, strings.map((item) => item.value));
	return requireOnePackageReference(strings, packageSpec);
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
			validateTomlDocument(text);
			return scanTomlTables(text)
				.map((table) => /^mcp_servers\.([^.]+)$/.exec(table.name)?.[1])
				.filter((name): name is string => Boolean(name));
		},

		read(configPath: string, serverName: string): McpServerEntry | null {
			if (!existsSync(configPath)) return null;
			const text = readTextFile(configPath) ?? '';
			validateTomlDocument(text);
			return parseEntryFromSyntax(text, serverName);
		},

		updatePackageReference(configPath: string, serverName: string, packageSpec: string) {
			const before = readTextFile(configPath);
			if (before === null) throw new ClientConfigError('config_missing', `${configPath} does not exist.`);
			const replacement = findPackageReplacement(before, serverName, packageSpec);
			const next = applyStringReplacement(before, replacement);
			const written = writeWithRollback({ path: configPath, expectedContents: before, nextContents: next, validate: validateTomlHasStructure });
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
			const beforeRaw = readTextFile(configPath);
			const before = beforeRaw ?? '';
			validateTomlDocument(before);
			const parts = parseMcpSections(before);
			const created = !parts.blocks.has(entry.serverName);
			parts.blocks.set(entry.serverName, renderServerBlock(entry));
			if (!parts.order.includes(entry.serverName)) {
				parts.order.push(entry.serverName);
			}
			const next = rebuildToml(parts);
			const { backupPath, changed, diff } = writeWithRollback({
				path: configPath,
				expectedContents: beforeRaw,
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
			validateTomlDocument(before);
			const parts = parseMcpSections(before);
			if (!parts.blocks.has(serverName)) {
				return { configPath, backupPath: null, removed: false, serverName };
			}
			parts.blocks.delete(serverName);
			parts.order = parts.order.filter((n) => n !== serverName);
			const next = rebuildToml(parts);
			const { backupPath } = writeWithRollback({
				path: configPath,
				expectedContents: before,
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
