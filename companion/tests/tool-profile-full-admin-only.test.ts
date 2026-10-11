/**
 * The full profile (php-execute and the other code-running tools) is chosen by a person: an administrator
 * in wp-admin, or the person who runs the companion through STONEWRIGHT_MCP_TOOL_PROFILE. An agent cannot
 * switch to it through stonewright-tool-profile, and a profile hint in a tool result cannot move the
 * companion onto it.
 */
import { describe, expect, it, vi } from 'vitest';
import type { McpServer } from '@modelcontextprotocol/sdk/server/mcp.js';
import { createConnectionRuntime, registerPermanentGateways } from '../src/connection/runtime.js';
import { adoptableProfileHint, handleToolsChangedResponse } from '../src/wordpress-mcp.js';

const REFUSAL_TEXT =
	'The full profile (php-execute and the other abilities that run or write code) can only be chosen by an administrator in wp-admin. Show the user the settings link and stop. '
	+ JSON.stringify({
		retryable: false,
		execution_status: 'blocked',
		code: 'stonewright_tool_profile_full_requires_admin',
		approval_required: true,
		agent_must_stop: true,
		approval_url: 'https://example.test/wp-admin/admin.php?page=stonewright&tab=settings#stonewright_mcp_surface',
	});

function toolHandler(server: unknown, name: string) {
	const tools = (server as { _registeredTools?: Record<string, { handler?: (input: unknown) => Promise<unknown> }> })._registeredTools ?? {};
	return tools[name]?.handler;
}

async function newGateway() {
	const { McpServer: Server } = await import('@modelcontextprotocol/sdk/server/mcp.js');
	const runtime = createConnectionRuntime({
		env: { STONEWRIGHT_MCP_URL: 'https://example.test/wp-json/mcp/stonewright', STONEWRIGHT_OAUTH_TOKEN_STORE: '/tmp/example-tokens-unused.json' },
		profile: 'essential-static',
	});
	const server = new Server({ name: 'stonewright-test', version: '0.0.0' });
	registerPermanentGateways(server, runtime);
	const call = (input: Record<string, unknown>) =>
		toolHandler(server, 'stonewright-tool-profile')?.(input) as Promise<{ structuredContent: Record<string, unknown> }>;
	return { runtime, server, call };
}

describe('the stonewright-tool-profile gateway', () => {
	it('passes the plugin refusal of full through with the settings link, as an error', async () => {
		const { runtime, call } = await newGateway();
		runtime.status.connected = true;
		runtime.status.mode = 'plugin';
		runtime.callRemoteTool = vi.fn(() => Promise.resolve({ isError: true, content: [{ type: 'text', text: REFUSAL_TEXT }] }));

		const out = (await call({ action: 'activate', profile: 'full' })).structuredContent;

		expect(out['ok']).toBe(false);
		expect(out['isError']).toBe(true);
		expect(out['error_code']).toBe('stonewright_tool_profile_full_requires_admin');
		expect(out['approval_required']).toBe(true);
		expect(out['agent_must_stop']).toBe(true);
		expect(String(out['approval_url'])).toContain('page=stonewright');
		expect(String(out['message'])).toContain('administrator');
		expect(out['tools_changed']).toBeUndefined();
		expect(runtime.clientCatalogRelistRequired).toBeFalsy();
	});

	it('refuses an activation of full by itself when no plugin answers, without changing anything', async () => {
		const { runtime, call } = await newGateway();
		const before = runtime.profile;

		const out = (await call({ action: 'activate', profile: 'full' })).structuredContent;

		expect(out['ok']).toBe(false);
		expect(out['error_code']).toBe('stonewright_tool_profile_full_requires_admin');
		expect(out['approval_required']).toBe(true);
		expect(out['agent_must_stop']).toBe(true);
		expect(String(out['message'])).toContain('STONEWRIGHT_MCP_TOOL_PROFILE');
		expect(runtime.profile).toBe(before);
	});

	it('still describes the full profile when asked to resolve it', async () => {
		const { call } = await newGateway();

		const out = (await call({ action: 'resolve', profile: 'full' })).structuredContent;

		expect(out['ok']).toBe(true);
		expect(out['profile']).toBe('full');
	});

	it('keeps switching between the other profiles', async () => {
		const { runtime, call } = await newGateway();
		runtime.status.connected = true;
		runtime.status.mode = 'plugin';
		runtime.callRemoteTool = vi.fn(() => Promise.resolve({ structuredContent: { ok: true, profile: 'gutenberg', tools: ['stonewright-site-info'] } }));

		const out = (await call({ action: 'activate', profile: 'gutenberg' })).structuredContent;

		expect(out['ok']).toBe(true);
		expect(out['profile']).toBe('gutenberg');
	});
});

describe('a profile hint in a tool result', () => {
	it('does not move the companion onto full unless the site surface is full', () => {
		expect(adoptableProfileHint({ profile: 'full', requested_profile: 'full', mcp_surface: 'essential' }, 'essential')).toBeNull();
		expect(adoptableProfileHint({ session_tool_profile: 'full' }, 'essential')).toBeNull();
		expect(adoptableProfileHint({ session_tool_profile: 'full', configured_mcp_surface: 'full' }, 'essential')).toBe('full');
		expect(adoptableProfileHint({ profile: 'full', mcp_surface: 'full' }, 'essential')).toBe('full');
	});

	it('leaves every other hint alone, and a companion that already runs full where it is', () => {
		expect(adoptableProfileHint({ profile: 'gutenberg' }, 'essential')).toBe('gutenberg');
		expect(adoptableProfileHint({ profile: 'full' }, 'full')).toBe('full');
		expect(adoptableProfileHint({}, 'essential')).toBeNull();
		expect(adoptableProfileHint(null, 'essential')).toBeNull();
	});

	it('keeps handleToolsChangedResponse on its profile when the hint asks for full without the site surface', async () => {
		const server = { server: { sendToolListChanged: vi.fn() } } as unknown as McpServer;
		const result = await handleToolsChangedResponse({
			server,
			client: {
				listTools: vi.fn(() => Promise.resolve([{ name: 'stonewright-site-info' }])),
				callTool: vi.fn(() => Promise.resolve({ structuredContent: { ok: true, source: 'plugin', tools: ['stonewright-site-info'] } })),
			},
			structured: { tools_changed: true, profile: 'full', requested_profile: 'full', mcp_surface: 'essential' },
			activeProfile: 'essential',
			maxTools: null,
			registered: new Map(),
			registerProxyTool: vi.fn(),
		});

		expect(result.profile).toBe('essential');
	});
});
