import type { McpServer } from '@modelcontextprotocol/sdk/server/mcp.js';
import { z, type ZodTypeAny } from 'zod';

export interface WordPressMcpConfig {
	url: string;
	username?: string;
	password?: string;
	authorization?: string;
	timeoutMs: number;
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

type FetchLike = typeof fetch;

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

	return config;
}

function normalizeWordPressMcpUrl(raw: string): string {
	const url = raw.trim().replace(/\/+$/, '');
	if (!url) return '';
	const wpRestBase = ['wp', 'json'].join('-');
	const mcpPath = `${wpRestBase}/mcp/`;
	if (url.includes(`/${mcpPath}`)) return url;
	return `${url}/${mcpPath}stonewright`;
}

export async function registerWordPressMcpTools(
	server: McpServer,
	config: WordPressMcpConfig,
	fetchImpl: FetchLike = fetch,
): Promise<RemoteTool[]> {
	const client = new WordPressMcpClient(config, fetchImpl);
	const tools = await client.listTools();

	for (const tool of tools) {
		if (!tool.name || tool.name.startsWith('companion_')) {
			continue;
		}

		server.tool(
			tool.name,
			tool.description ?? tool.title ?? 'Proxied Stonewright WordPress MCP tool.',
			zodShapeFromJsonSchema(tool.inputSchema ?? emptyObjectSchema()),
			async (input) => normalizeToolResponse(await client.callTool(tool.name, input)),
		);
	}

	return tools;
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

	private async ensureInitialized(): Promise<void> {
		if (this.initialized) return;

		await this.request('initialize', {
			protocolVersion: '2025-06-18',
			capabilities: {},
			clientInfo: {
				name: 'stonewright-companion',
				version: '1.0.0-alpha.1',
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
