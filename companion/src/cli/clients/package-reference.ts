import { createHash } from 'node:crypto';
import { ClientConfigError } from './types.js';

export interface StringReplacement {
	start: number;
	end: number;
	value: string;
	replacement: string;
}

export function sha256Text(value: string): string {
	return `sha256:${createHash('sha256').update(value).digest('hex')}`;
}

const SEMVER = '(?:0|[1-9]\\d*)\\.(?:0|[1-9]\\d*)\\.(?:0|[1-9]\\d*)(?:-(?:(?:0|[1-9]\\d*|[0-9A-Za-z-]*[A-Za-z-][0-9A-Za-z-]*)(?:\\.(?:0|[1-9]\\d*|[0-9A-Za-z-]*[A-Za-z-][0-9A-Za-z-]*))*))?(?:\\+[0-9A-Za-z-]+(?:\\.[0-9A-Za-z-]+)*)?';
const NPM_PACKAGE = new RegExp(`^@stonewright/companion@(${SEMVER})$`);
const OFFICIAL_RELEASE_ARCHIVE = new RegExp(
	`^https://github\\.com/cosmincraciun97/stonewright-wp-mcp/releases/download/v(${SEMVER})/stonewright-companion-(${SEMVER})\\.tgz$`,
);

export function stonewrightPackageVersion(value: string): string | null {
	const npm = NPM_PACKAGE.exec(value);
	if (npm?.[1]) return npm[1];
	const archive = OFFICIAL_RELEASE_ARCHIVE.exec(value);
	if (!archive?.[1] || archive[1] !== archive[2]) return null;
	return archive[1];
}

export function stonewrightPackageIdentity(value: string): {
	version: string;
	provenance: 'npm-registry' | 'github-release';
} | null {
	const version = stonewrightPackageVersion(value);
	if (!version) return null;
	return {
		version,
		provenance: value.startsWith('@stonewright/companion@') ? 'npm-registry' : 'github-release',
	};
}

export function isStonewrightPackageReference(value: string): boolean {
	return stonewrightPackageVersion(value) !== null;
}

export function requireValidTargetPackageReference(packageSpec: string): void {
	if (!isStonewrightPackageReference(packageSpec)) {
		throw new ClientConfigError(
			'package_reference_invalid',
			'package_reference_invalid: --to must be @stonewright/companion@VERSION or the exact official GitHub release archive with matching SemVer.',
		);
	}
}

/** Fail closed unless an entry matches the documented npx Stonewright launch shape. */
export function validateOfficialStonewrightNpxEntry(command: string, args: readonly string[]): void {
	const executable = command.trim().replaceAll('\\', '/').split('/').at(-1)?.toLowerCase();
	const start = args[0] === '-y' || args[0] === '--yes' ? 1 : 0;
	const packageSpec = args[start + 1];
	const valid = (executable === 'npx' || executable === 'npx.cmd')
		&& args.length === start + 3
		&& args[start] === '--package'
		&& typeof packageSpec === 'string'
		&& isStonewrightPackageReference(packageSpec)
		&& args[start + 2] === 'stonewright-mcp'
		&& args.filter((arg) => arg === '--package').length === 1
		&& args.filter((arg) => isStonewrightPackageReference(arg)).length === 1;
	if (!valid) {
		throw new ClientConfigError(
			'official_executable_contract_invalid',
			'official_executable_contract_invalid: expected npx or npx.cmd with optional -y, exactly one --package followed by one exact Stonewright package, then stonewright-mcp.',
		);
	}
}

export function requireOnePackageReference(
	candidates: Array<{ start: number; end: number; value: string; quote?: string }>,
	packageSpec: string,
): StringReplacement {
	requireValidTargetPackageReference(packageSpec);
	const matches = candidates.filter((candidate) => isStonewrightPackageReference(candidate.value));
	if (matches.length === 0) {
		throw new ClientConfigError(
			'package_reference_not_found',
			'package_reference_not_found: target server has no Stonewright package token.',
		);
	}
	if (matches.length !== 1) {
		throw new ClientConfigError(
			'package_reference_multiple',
			`package_reference_multiple: target server has ${matches.length} Stonewright package tokens.`,
		);
	}
	const match = matches[0];
	const replacement = match.quote === 'toml-literal'
		? `'${packageSpec.replace(/'/g, "''")}'`
		: match.quote === 'toml' || match.quote === 'toml-basic'
			? `"${packageSpec.replace(/\\/g, '\\\\').replace(/"/g, '\\"')}"`
			: JSON.stringify(packageSpec);
	return { start: match.start, end: match.end, value: match.value, replacement };
}

export function applyStringReplacement(text: string, replacement: StringReplacement): string {
	return `${text.slice(0, replacement.start)}${replacement.replacement}${text.slice(replacement.end)}`;
}

interface JsonToken {
	type: 'string' | 'punct' | 'literal';
	value: string;
	start: number;
	end: number;
}

interface JsonNode {
	type: 'object' | 'array' | 'value';
	start: number;
	end: number;
	token?: JsonToken;
	properties?: Array<{ key: JsonToken; value: JsonNode }>;
	items?: JsonNode[];
}

function jsoncParseFailure(text: string, offset: number, code: string): never {
	const safe = Math.max(0, Math.min(offset, text.length));
	const prefix = text.slice(0, safe);
	const lastNewline = prefix.lastIndexOf('\n');
	const line = prefix.split('\n').length;
	const column = safe - lastNewline;
	throw new ClientConfigError(
		'config_parse_failure',
		`config_parse_failure: ${code} at line ${line}, column ${column}.`,
	);
}

function jsoncTokens(text: string): JsonToken[] {
	const tokens: JsonToken[] = [];
	let i = 0;
	while (i < text.length) {
		const char = text[i];
		if (/\s/.test(char)) {
			i++;
			continue;
		}
		if (char === '/' && text[i + 1] === '/') {
			i += 2;
			while (i < text.length && text[i] !== '\n') i++;
			continue;
		}
		if (char === '/' && text[i + 1] === '*') {
			const end = text.indexOf('*/', i + 2);
			if (end < 0) jsoncParseFailure(text, i, 'JSONC_UNCLOSED_BLOCK_COMMENT');
			i = end + 2;
			continue;
		}
		if ('{}[]:,'.includes(char)) {
			tokens.push({ type: 'punct', value: char, start: i, end: i + 1 });
			i++;
			continue;
		}
		if (char === '"') {
			const start = i++;
			let escaped = false;
			while (i < text.length) {
				const current = text[i++];
				if (escaped) {
					escaped = false;
					continue;
				}
				if (current === '\\') {
					escaped = true;
					continue;
				}
				if (current === '"') break;
			}
			const raw = text.slice(start, i);
			try {
				tokens.push({ type: 'string', value: JSON.parse(raw) as string, start, end: i });
			} catch {
				jsoncParseFailure(text, start, 'JSONC_INVALID_STRING');
			}
			continue;
		}
		const start = i;
		while (i < text.length && !/[\s{}[\]:,]/.test(text[i])) i++;
		tokens.push({ type: 'literal', value: text.slice(start, i), start, end: i });
	}
	return tokens;
}

function parseJsoncTree(text: string): JsonNode {
	const tokens = jsoncTokens(text);
	let index = 0;
	const peek = () => tokens[index];
	const consume = (value?: string): JsonToken => {
		const token = tokens[index++];
		if (!token || (value !== undefined && token.value !== value)) {
			jsoncParseFailure(text, token?.start ?? text.length, 'JSONC_UNEXPECTED_TOKEN');
		}
		return token;
	};
	const parseValue = (): JsonNode => {
		const token = peek();
		if (!token) jsoncParseFailure(text, text.length, 'JSONC_VALUE_MISSING');
		if (token.value === '{') {
			const start = consume('{').start;
			const properties: Array<{ key: JsonToken; value: JsonNode }> = [];
			while (peek()?.value !== '}') {
				const key = consume();
				if (key.type !== 'string') jsoncParseFailure(text, key.start, 'JSONC_OBJECT_KEY_INVALID');
				consume(':');
				properties.push({ key, value: parseValue() });
				if (peek()?.value === ',') {
					consume(',');
					if (peek()?.value === '}') break;
				} else if (peek()?.value !== '}') {
					jsoncParseFailure(text, peek()?.start ?? text.length, 'JSONC_OBJECT_SEPARATOR_MISSING');
				}
			}
			const end = consume('}').end;
			return { type: 'object', start, end, properties };
		}
		if (token.value === '[') {
			const start = consume('[').start;
			const items: JsonNode[] = [];
			while (peek()?.value !== ']') {
				items.push(parseValue());
				if (peek()?.value === ',') {
					consume(',');
					if (peek()?.value === ']') break;
				} else if (peek()?.value !== ']') {
					jsoncParseFailure(text, peek()?.start ?? text.length, 'JSONC_ARRAY_SEPARATOR_MISSING');
				}
			}
			const end = consume(']').end;
			return { type: 'array', start, end, items };
		}
		const value = consume();
		return { type: 'value', start: value.start, end: value.end, token: value };
	};
	const root = parseValue();
	if (index !== tokens.length) jsoncParseFailure(text, tokens[index]?.start ?? text.length, 'JSONC_TRAILING_CONTENT');
	return root;
}

function objectValues(node: JsonNode, key: string): JsonNode[] {
	if (node.type !== 'object') return [];
	return (node.properties ?? []).filter((property) => property.key.value === key).map((property) => property.value);
}

export function findJsoncPackageReplacement(text: string, serverName: string, packageSpec: string): StringReplacement {
	requireValidTargetPackageReference(packageSpec);
	const root = parseJsoncTree(text);
	if (root.type !== 'object') jsoncParseFailure(text, root.start, 'JSONC_ROOT_NOT_OBJECT');
	const buckets = [
		...objectValues(root, 'mcpServers'),
		...objectValues(root, 'servers'),
		...objectValues(root, 'mcp').flatMap((mcp) => objectValues(mcp, 'servers')),
	].filter((node) => node.type === 'object');
	const serverNodes = buckets.flatMap((bucket) => objectValues(bucket, serverName));
	if (serverNodes.length === 0) {
		throw new ClientConfigError('server_entry_not_found', `server_entry_not_found: no MCP server entry named "${serverName}".`);
	}
	if (serverNodes.length !== 1) {
		throw new ClientConfigError('server_entry_ambiguous', `server_entry_ambiguous: found ${serverNodes.length} entries named "${serverName}".`);
	}
	const commandNodes = objectValues(serverNodes[0], 'command');
	const command = commandNodes.length === 1 && commandNodes[0].type === 'value' && commandNodes[0].token?.type === 'string'
		? commandNodes[0].token.value
		: '';
	const argsNodes = objectValues(serverNodes[0], 'args');
	if (argsNodes.length !== 1 || argsNodes[0].type !== 'array') {
		throw new ClientConfigError('package_reference_not_found', 'package_reference_not_found: target server has no unique args array.');
	}
	const items = argsNodes[0].items ?? [];
	const strings = items
		.filter((node) => node.type === 'value' && node.token?.type === 'string')
		.map((node) => ({
			start: node.token!.start,
			end: node.token!.end,
			value: node.token!.value,
		}));
	if (strings.length !== items.length) {
		throw new ClientConfigError('official_executable_contract_invalid', 'official_executable_contract_invalid: command and args must be strings.');
	}
	validateOfficialStonewrightNpxEntry(command, strings.map((item) => item.value));
	return requireOnePackageReference(strings, packageSpec);
}

export function parseJsonc(text: string): unknown {
	const root = parseJsoncTree(text);
	const materialize = (node: JsonNode): unknown => {
		if (node.type === 'array') return (node.items ?? []).map(materialize);
		if (node.type === 'object') {
			const result: Record<string, unknown> = {};
			for (const property of node.properties ?? []) result[property.key.value] = materialize(property.value);
			return result;
		}
		if (node.token?.type === 'string') return node.token.value;
		if (node.token?.value === 'true') return true;
		if (node.token?.value === 'false') return false;
		if (node.token?.value === 'null') return null;
		const number = Number(node.token?.value);
		if (Number.isFinite(number)) return number;
		jsoncParseFailure(text, node.token?.start ?? node.start, 'JSONC_LITERAL_UNSUPPORTED');
	};
	return materialize(root);
}
