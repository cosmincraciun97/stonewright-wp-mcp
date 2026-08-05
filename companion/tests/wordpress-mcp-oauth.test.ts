import { describe, expect, it } from 'vitest';
import { mkdtempSync, rmSync } from 'node:fs';
import { tmpdir } from 'node:os';
import { join } from 'node:path';
import { OAuthReauthRequiredError, OAuthTokenStore } from '../src/oauth-token-manager.js';
import { WordPressMcpClient, type WordPressMcpConfig } from '../src/wordpress-mcp.js';

function fixtureConfig(tokenStorePath: string): WordPressMcpConfig {
	return {
		url: 'https://example.com/wp-json/mcp/stonewright-oauth',
		timeoutMs: 5_000,
		oauth: {
			tokenEndpoint: 'https://example.com/wp-json/stonewright/v1/oauth/token',
			clientId: 'client-example',
			tokenStorePath,
			resource: 'https://example.com/wp-json/mcp/stonewright-oauth',
		},
	};
}

function rpcResponse(body: Record<string, unknown>, status = 200): Response {
	return new Response(JSON.stringify(body), {
		status,
		headers: { 'content-type': 'application/json' },
	});
}

function successForPluginRequest(init?: RequestInit): Response {
	const payload = JSON.parse(String(init?.body ?? '{}')) as { id?: number; method?: string };
	if (payload.method === 'notifications/initialized') return new Response('', { status: 202 });
	if (payload.method === 'initialize') {
		return rpcResponse({ jsonrpc: '2.0', id: payload.id, result: { protocolVersion: '2025-06-18' } });
	}
	return rpcResponse({ jsonrpc: '2.0', id: payload.id, result: { tools: [] } });
}

describe('WordPress MCP OAuth runtime', () => {
	it('refreshes through the token manager before the first protected request', async () => {
		const directory = mkdtempSync(join(tmpdir(), 'stonewright-oauth-runtime-'));
		try {
			const tokenStorePath = join(directory, 'tokens.json');
			new OAuthTokenStore(tokenStorePath).save({ accessToken: 'expired-access', refreshToken: 'refresh-one', expiresAt: 0 });
			let refreshCalls = 0;
			const refreshBodies: string[] = [];
			const protectedAuthorization: string[] = [];
			const fetchImpl: typeof fetch = async (input, init) => {
				await Promise.resolve();
				if (String(input).endsWith('/oauth/token')) {
					refreshCalls += 1;
					refreshBodies.push(String(init?.body ?? ''));
					return rpcResponse({ access_token: 'fixture-fresh-access', refresh_token: 'fixture-refresh-two', expires_in: 300, token_type: 'Bearer' });
				}
				protectedAuthorization.push(new Headers(init?.headers).get('authorization') ?? '');
				return successForPluginRequest(init);
			};

			const client = new WordPressMcpClient(fixtureConfig(tokenStorePath), fetchImpl);
			await client.listTools();

			expect(refreshCalls).toBe(1);
			expect(new URLSearchParams(refreshBodies[0]).get('resource')).toBe('https://example.com/wp-json/mcp/stonewright-oauth');
			expect(protectedAuthorization).toHaveLength(3);
			expect(protectedAuthorization).toEqual(protectedAuthorization.map(() => 'Bearer fixture-fresh-access'));
			expect(new OAuthTokenStore(tokenStorePath).load()?.refreshToken).toBe('fixture-refresh-two');
		} finally {
			rmSync(directory, { recursive: true, force: true });
		}
	});

	it('refreshes and retries a rejected protected request exactly once', async () => {
		const directory = mkdtempSync(join(tmpdir(), 'stonewright-oauth-401-'));
		try {
			const tokenStorePath = join(directory, 'tokens.json');
			new OAuthTokenStore(tokenStorePath).save({
				accessToken: 'rejected-access',
				refreshToken: 'refresh-one',
				expiresAt: Date.now() + 300_000,
			});
			let refreshCalls = 0;
			let protectedCalls = 0;
			const fetchImpl: typeof fetch = async (input, init) => {
				await Promise.resolve();
				if (String(input).endsWith('/oauth/token')) {
					refreshCalls += 1;
					return rpcResponse({ access_token: 'fixture-replacement-access', refresh_token: 'fixture-refresh-two', expires_in: 300, token_type: 'Bearer' });
				}
				protectedCalls += 1;
				if (protectedCalls === 1) {
					expect(new Headers(init?.headers).get('authorization')).toBe('Bearer rejected-access');
					return rpcResponse({ error: 'invalid_token' }, 401);
				}
				expect(new Headers(init?.headers).get('authorization')).toBe('Bearer fixture-replacement-access');
				return successForPluginRequest(init);
			};

			await new WordPressMcpClient(fixtureConfig(tokenStorePath), fetchImpl).listTools();

			expect(refreshCalls).toBe(1);
			expect(protectedCalls).toBe(4);
		} finally {
			rmSync(directory, { recursive: true, force: true });
		}
	});

	it('clears terminal invalid_grant and never retries it on later calls', async () => {
		const directory = mkdtempSync(join(tmpdir(), 'stonewright-oauth-terminal-'));
		try {
			const tokenStorePath = join(directory, 'tokens.json');
			const store = new OAuthTokenStore(tokenStorePath);
			store.save({ accessToken: 'rejected-access', refreshToken: 'revoked-refresh', expiresAt: Date.now() + 300_000 });
			let refreshCalls = 0;
			let protectedCalls = 0;
			const fetchImpl: typeof fetch = async (input) => {
				await Promise.resolve();
				if (String(input).endsWith('/oauth/token')) {
					refreshCalls += 1;
					return rpcResponse({ error: 'invalid_grant', reason: 'refresh_token_revoked' }, 400);
				}
				protectedCalls += 1;
				return rpcResponse({ error: 'invalid_token' }, 401);
			};
			const client = new WordPressMcpClient(fixtureConfig(tokenStorePath), fetchImpl);

			await expect(client.listTools()).rejects.toBeInstanceOf(OAuthReauthRequiredError);
			await expect(client.listTools()).rejects.toMatchObject({ code: 'reauthentication_required' });

			expect(refreshCalls).toBe(1);
			expect(protectedCalls).toBe(1);
			expect(store.load()).toBeNull();
		} finally {
			rmSync(directory, { recursive: true, force: true });
		}
	});
});
