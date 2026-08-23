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

export function isStonewrightPackageReference(value: string): boolean {
	return stonewrightPackageVersion(value) !== null;
}

export function requireOnePackageReference(
	candidates: Array<{ start: number; end: number; value: string; quote?: string }>,
	packageSpec: string,
): StringReplacement {
	if (!isStonewrightPackageReference(packageSpec)) {
		throw new ClientConfigError(
			'package_reference_invalid',
			'package_reference_invalid: --to must be @stonewright/companion@VERSION or the exact official GitHub release archive with matching SemVer.',
		);
	}
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
	const replacement = match.quote === 'toml'
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
			if (end < 0) throw new ClientConfigError('config_parse_failure', 'JSONC block comment is not closed.');
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
				throw new ClientConfigError('config_parse_failure', 'JSONC contains an invalid string token.');
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
			throw new ClientConfigError('config_parse_failure', `JSONC expected ${value ?? 'a value'}.`);
		}
		return token;
	};
	const parseValue = (): JsonNode => {
		const token = peek();
		if (!token) throw new ClientConfigError('config_parse_failure', 'JSONC ended before a value.');
		if (token.value === '{') {
			const start = consume('{').start;
			const properties: Array<{ key: JsonToken; value: JsonNode }> = [];
			while (peek()?.value !== '}') {
				const key = consume();
				if (key.type !== 'string') throw new ClientConfigError('config_parse_failure', 'JSONC object key must be a string.');
				consume(':');
				properties.push({ key, value: parseValue() });
				if (peek()?.value === ',') {
					consume(',');
					if (peek()?.value === '}') break;
				} else if (peek()?.value !== '}') {
					throw new ClientConfigError('config_parse_failure', 'JSONC object members must be comma-separated.');
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
					throw new ClientConfigError('config_parse_failure', 'JSONC array items must be comma-separated.');
				}
			}
			const end = consume(']').end;
			return { type: 'array', start, end, items };
		}
		const value = consume();
		return { type: 'value', start: value.start, end: value.end, token: value };
	};
	const root = parseValue();
	if (index !== tokens.length) throw new ClientConfigError('config_parse_failure', 'JSONC has trailing non-comment content.');
	return root;
}

function objectValues(node: JsonNode, key: string): JsonNode[] {
	if (node.type !== 'object') return [];
	return (node.properties ?? []).filter((property) => property.key.value === key).map((property) => property.value);
}

export function findJsoncPackageReplacement(text: string, serverName: string, packageSpec: string): StringReplacement {
	const root = parseJsoncTree(text);
	if (root.type !== 'object') throw new ClientConfigError('config_parse_failure', 'JSONC root must be an object.');
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
	const argsNodes = objectValues(serverNodes[0], 'args');
	if (argsNodes.length !== 1 || argsNodes[0].type !== 'array') {
		throw new ClientConfigError('package_reference_not_found', 'package_reference_not_found: target server has no unique args array.');
	}
	const strings = (argsNodes[0].items ?? [])
		.filter((node) => node.type === 'value' && node.token?.type === 'string')
		.map((node) => ({
			start: node.token!.start,
			end: node.token!.end,
			value: node.token!.value,
		}));
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
		throw new ClientConfigError('config_parse_failure', `Unsupported JSONC literal: ${node.token?.value ?? ''}`);
	};
	return materialize(root);
}
