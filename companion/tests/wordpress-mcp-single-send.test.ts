import { describe, expect, it } from 'vitest';
import { rmSync } from 'node:fs';
import { join } from 'node:path';
import { createOAuthTestDirectory } from './helpers/windows-oauth-acl.js';
import { OAuthTokenStore } from '../src/oauth-token-manager.js';
import { WordPressMcpClient, type WordPressMcpConfig } from '../src/wordpress-mcp.js';

// The documented retry behaviour: each WordPress MCP request is sent once and is not
// repeated after a timeout, a network error or an error response. The one repeat is on
// OAuth connections: an HTTP 401 refreshes the access token and sends that request once more.

interface RpcPayload {
	id?: number;
	method?: string;
	params?: { name?: string; arguments?: Record<string, unknown> };
}

function json(body: Record<string, unknown>, status = 200): Response {
	return new Response(JSON.stringify(body), { status, headers: { 'content-type': 'application/json' } });
}

/** Answers the handshake and lets `onCall` answer every tools/call. */
function mcpServer(onCall: (payload: RpcPayload, init: RequestInit | undefined) => Response | Promise<Response>) {
	const calls: Array<{ payload: RpcPayload; authorization: string | null }> = [];
	const fetchImpl: typeof fetch = async (input, init) => {
		await Promise.resolve();
		if (String(input).endsWith('/oauth/token')) {
			return json({ access_token: 'fixture-replacement-access', refresh_token: 'fixture-refresh-two', expires_in: 300, token_type: 'Bearer' });
		}
		const payload = JSON.parse(String(init?.body ?? '{}')) as RpcPayload;
		if (payload.method === 'notifications/initialized') return new Response('', { status: 202 });
		if (payload.method === 'initialize') return json({ jsonrpc: '2.0', id: payload.id, result: { protocolVersion: '2025-06-18' } });
		calls.push({ payload, authorization: new Headers(init?.headers).get('authorization') });
		return onCall(payload, init);
	};
	return { calls, fetchImpl };
}

const basicConfig: WordPressMcpConfig = {
	url: 'https://example.com/wp-json/mcp/stonewright',
	timeoutMs: 5_000,
	username: 'fixture-admin',
	password: 'fixture-password',
};

describe('WordPress MCP requests are sent once', () => {
	it('does not resend a tool call that answered with a server error', async () => {
		const server = mcpServer(() => json({ code: 'internal_error' }, 500));

		await expect(new WordPressMcpClient(basicConfig, server.fetchImpl).callTool('stonewright-elementor-update', { post_id: 7 })).rejects.toThrow();

		expect(server.calls).toHaveLength(1);
		expect(server.calls[0]?.payload.params?.name).toBe('stonewright-elementor-update');
	});

	it('does not resend a tool call after a network error', async () => {
		const server = mcpServer(() => {
			throw new TypeError('fetch failed');
		});

		await expect(new WordPressMcpClient(basicConfig, server.fetchImpl).callTool('stonewright-elementor-update', {})).rejects.toThrow();

		expect(server.calls).toHaveLength(1);
	});

	it('does not resend a tool call that answered 401 on a connection without OAuth', async () => {
		const server = mcpServer(() => json({ code: 'rest_forbidden' }, 401));

		await expect(new WordPressMcpClient(basicConfig, server.fetchImpl).callTool('stonewright-elementor-update', {})).rejects.toThrow();

		expect(server.calls).toHaveLength(1);
	});

	it('does not resend a tool call that answered with a JSON-RPC error', async () => {
		const server = mcpServer((payload) => json({ jsonrpc: '2.0', id: payload.id, error: { code: -32000, message: 'rejected' } }));

		await expect(new WordPressMcpClient(basicConfig, server.fetchImpl).callTool('stonewright-elementor-update', {})).rejects.toThrow();

		expect(server.calls).toHaveLength(1);
	});

	it('does not resend a tool list that failed', async () => {
		const server = mcpServer(() => json({ code: 'internal_error' }, 503));

		await expect(new WordPressMcpClient(basicConfig, server.fetchImpl).listTools()).rejects.toThrow();

		expect(server.calls).toHaveLength(1);
	});

	it('does not refresh or resend a tool call that answered with a server error on an OAuth connection', async () => {
		const directory = createOAuthTestDirectory('stonewright-single-send-500-');
		try {
			const tokenStorePath = join(directory, 'tokens.json');
			new OAuthTokenStore(tokenStorePath).save({ accessToken: 'live-access', refreshToken: 'refresh-one', expiresAt: Date.now() + 300_000 });
			const server = mcpServer(() => json({ code: 'internal_error' }, 500));
			const config: WordPressMcpConfig = {
				url: 'https://example.com/wp-json/mcp/stonewright-oauth',
				timeoutMs: 5_000,
				oauth: {
					tokenEndpoint: 'https://example.com/wp-json/stonewright/v1/oauth/token',
					clientId: 'client-example',
					tokenStorePath,
					resource: 'https://example.com/wp-json/mcp/stonewright-oauth',
				},
			};

			await expect(new WordPressMcpClient(config, server.fetchImpl).callTool('stonewright-elementor-update', {})).rejects.toThrow();

			expect(server.calls).toHaveLength(1);
			expect(server.calls[0]?.authorization).toBe('Bearer live-access');
		} finally {
			rmSync(directory, { recursive: true, force: true });
		}
	});

	it('repeats a tool call once with the refreshed token after an OAuth 401, and never a second time', async () => {
		const directory = createOAuthTestDirectory('stonewright-single-send-');
		try {
			const tokenStorePath = join(directory, 'tokens.json');
			new OAuthTokenStore(tokenStorePath).save({ accessToken: 'rejected-access', refreshToken: 'refresh-one', expiresAt: Date.now() + 300_000 });
			const server = mcpServer(() => json({ error: 'invalid_token' }, 401));
			const config: WordPressMcpConfig = {
				url: 'https://example.com/wp-json/mcp/stonewright-oauth',
				timeoutMs: 5_000,
				oauth: {
					tokenEndpoint: 'https://example.com/wp-json/stonewright/v1/oauth/token',
					clientId: 'client-example',
					tokenStorePath,
					resource: 'https://example.com/wp-json/mcp/stonewright-oauth',
				},
			};

			await expect(new WordPressMcpClient(config, server.fetchImpl).callTool('stonewright-elementor-update', { post_id: 7 })).rejects.toThrow();

			expect(server.calls).toHaveLength(2);
			expect(server.calls[0]?.authorization).toBe('Bearer rejected-access');
			expect(server.calls[1]?.authorization).toBe('Bearer fixture-replacement-access');
			expect(server.calls[1]?.payload).toEqual(server.calls[0]?.payload);
		} finally {
			rmSync(directory, { recursive: true, force: true });
		}
	});
});
