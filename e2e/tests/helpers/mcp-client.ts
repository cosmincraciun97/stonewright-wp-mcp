import type { APIRequestContext, Page } from '@playwright/test';
import { restUrl, detectRestMode, restRequest } from './wp-rest';

export type JsonRpcResult = {
	jsonrpc?: string;
	id?: number | string;
	result?: Record<string, unknown>;
	error?: { code?: number; message?: string };
};

export type McpHandshakeResult = {
	endpoint: string;
	initializeResult: Record<string, unknown>;
	toolNames: string[];
	taskStartResult: {
		isError: boolean;
		ok: boolean | null;
		startupReady: boolean | null;
		raw: Record<string, unknown>;
	};
};

type RpcResponse = {
	status: number;
	json: JsonRpcResult;
	sessionId: string;
};

function basicAuth(username: string, password: string): string {
	return `Basic ${Buffer.from(`${username}:${password}`, 'utf8').toString('base64')}`;
}

function parseJsonRpc(text: string): JsonRpcResult {
	const trimmed = text.trim();
	if (!trimmed.startsWith('{')) {
		return {};
	}
	try {
		return JSON.parse(trimmed) as JsonRpcResult;
	} catch {
		return {};
	}
}

async function postRpc(
	request: APIRequestContext,
	endpoint: string,
	auth: string,
	sessionId: string,
	payload: Record<string, unknown>,
): Promise<RpcResponse> {
	const headers: Record<string, string> = {
		Accept: 'application/json, text/event-stream',
		'Content-Type': 'application/json',
		Authorization: auth,
	};
	if (sessionId !== '') {
		headers['Mcp-Session-Id'] = sessionId;
	}
	const response = await request.post(endpoint, {
		headers,
		data: payload,
		timeout: 20_000,
		failOnStatusCode: false,
	});
	const json = parseJsonRpc(await response.text());
	const headerSession = response.headers()['mcp-session-id'] ?? '';
	return {
		status: response.status(),
		json,
		sessionId: headerSession !== '' ? headerSession : sessionId,
	};
}

function decodeToolResult(result: Record<string, unknown>): Record<string, unknown> {
	const structured = result.structuredContent;
	if (structured && typeof structured === 'object' && !Array.isArray(structured)) {
		return structured as Record<string, unknown>;
	}
	const content = result.content;
	if (Array.isArray(content)) {
		for (const part of content) {
			if (!part || typeof part !== 'object') continue;
			const row = part as { type?: string; text?: string };
			if (row.type !== 'text' || typeof row.text !== 'string') continue;
			try {
				const parsed = JSON.parse(row.text) as unknown;
				if (parsed && typeof parsed === 'object' && !Array.isArray(parsed)) {
					return parsed as Record<string, unknown>;
				}
			} catch {
				// keep scanning
			}
		}
	}
	return result;
}

export async function mcpEndpointPath(page: Page): Promise<string> {
	const mode = await detectRestMode(page);
	return restUrl('/mcp/stonewright', mode);
}

/**
 * Authenticated MCP handshake for the local e2e fixture.
 * Initialize → notifications/initialized → tools/list (bounded pages) → tools/call.
 */
export async function mcpHandshake(
	page: Page,
	credentials: { username: string; password: string },
	endpoint = '',
): Promise<McpHandshakeResult> {
	const target = endpoint !== '' ? endpoint : await mcpEndpointPath(page);
	const auth = basicAuth(credentials.username, credentials.password);
	const request = page.request;

	const initialize = await postRpc(request, target, auth, '', {
		jsonrpc: '2.0',
		id: 1,
		method: 'initialize',
		params: {
			protocolVersion: '2024-11-05',
			capabilities: {},
			clientInfo: { name: 'stonewright-e2e', version: '1.0.0' },
		},
	});
	if (initialize.status < 200 || initialize.status >= 300 || initialize.json.error) {
		throw new Error(
			`initialize failed HTTP ${initialize.status}: ${JSON.stringify(initialize.json)}`,
		);
	}
	const initializeResult = (initialize.json.result ?? {}) as Record<string, unknown>;
	let sessionId = initialize.sessionId;

	const initialized = await postRpc(request, target, auth, sessionId, {
		jsonrpc: '2.0',
		method: 'notifications/initialized',
		params: {},
	});
	if (initialized.status >= 400) {
		throw new Error(`notifications/initialized failed HTTP ${initialized.status}`);
	}
	sessionId = initialized.sessionId || sessionId;

	const toolNames: string[] = [];
	let cursor = '';
	let rpcId = 2;
	for (let pageIndex = 0; pageIndex < 5; pageIndex += 1) {
		const listed = await postRpc(request, target, auth, sessionId, {
			jsonrpc: '2.0',
			id: rpcId,
			method: 'tools/list',
			params: cursor === '' ? {} : { cursor },
		});
		rpcId += 1;
		if (listed.status < 200 || listed.status >= 300 || listed.json.error) {
			throw new Error(`tools/list failed HTTP ${listed.status}: ${JSON.stringify(listed.json)}`);
		}
		sessionId = listed.sessionId || sessionId;
		const result = (listed.json.result ?? {}) as { tools?: Array<{ name?: string }>; nextCursor?: string };
		for (const tool of result.tools ?? []) {
			const name = String(tool.name ?? '').replace(/\//g, '-');
			if (name !== '') toolNames.push(name);
		}
		const next = typeof result.nextCursor === 'string' ? result.nextCursor : '';
		if (next === '' || next === cursor) break;
		cursor = next;
	}

	const called = await postRpc(request, target, auth, sessionId, {
		jsonrpc: '2.0',
		id: rpcId,
		method: 'tools/call',
		params: {
			name: 'stonewright-task-start',
			arguments: {
				task: 'e2e connection verification',
				surface: 'system',
				intent: 'inspect',
			},
		},
	});
	if (called.status < 200 || called.status >= 300 || called.json.error) {
		throw new Error(`tools/call failed HTTP ${called.status}: ${JSON.stringify(called.json)}`);
	}
	const raw = (called.json.result ?? {}) as Record<string, unknown>;
	const decoded = decodeToolResult(raw);
	return {
		endpoint: target,
		initializeResult,
		toolNames: [...new Set(toolNames)],
		taskStartResult: {
			isError: raw.isError === true,
			ok: typeof decoded.ok === 'boolean' ? decoded.ok : null,
			startupReady: typeof decoded.startup_ready === 'boolean' ? decoded.startup_ready : null,
			raw: decoded,
		},
	};
}

export async function createApplicationPassword(
	page: Page,
	nonce: string,
	name: string,
): Promise<{ password: string; uuid: string }> {
	const created = await restRequest(page, 'POST', '/wp/v2/users/me/application-passwords', {
		nonce,
		data: { name },
	});
	const body = created.body as { password?: string; uuid?: string };
	if (!created.ok || typeof body.password !== 'string' || typeof body.uuid !== 'string') {
		throw new Error(`Could not mint application password: ${JSON.stringify(created.body)}`);
	}
	return { password: body.password, uuid: body.uuid };
}

export async function deleteApplicationPassword(
	page: Page,
	nonce: string,
	uuid: string,
): Promise<void> {
	const deleted = await restRequest(page, 'DELETE', `/wp/v2/users/me/application-passwords/${uuid}`, { nonce });
	if (!deleted.ok) {
		throw new Error(`Could not delete application password ${uuid}: HTTP ${deleted.status}`);
	}
}
