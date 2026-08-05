import { describe, expect, it } from 'vitest';
import { mkdirSync, mkdtempSync, readFileSync, rmSync, writeFileSync } from 'node:fs';
import { tmpdir } from 'node:os';
import { join } from 'node:path';
import {
	loadWordPressMcpConfig,
	resolveWordPressMcpConfig,
	wordpressRestUrlFromMcpUrl,
} from '../src/wordpress-mcp.js';
import type { ExecFileRunner } from '../src/wp-cli.js';

describe('loadWordPressMcpConfig', () => {
	it('derives the Stonewright MCP endpoint from local WordPress URL aliases', () => {
		const config = loadWordPressMcpConfig({
			STONEWRIGHT_WP_URL: 'http://mcp-test.local/',
			STONEWRIGHT_WP_USERNAME: 'admin',
			STONEWRIGHT_WP_APP_PASSWORD: 'test-app-password',
		});

		expect(config).toEqual({
			url: 'http://mcp-test.local/wp-json/mcp/stonewright',
			username: 'admin',
			password: 'test-app-password',
			timeoutMs: 30_000,
		});
	});

	it('does not append an endpoint when the URL already points at MCP', () => {
		const config = loadWordPressMcpConfig({
			STONEWRIGHT_WP_URL: 'https://example.com/wp-json/mcp/stonewright',
		});

		expect(config?.url).toBe('https://example.com/wp-json/mcp/stonewright');
	});

	it('loads replay-safe OAuth token-store authentication and derives the same-origin token endpoint', () => {
		const temp = mkdtempSync(join(tmpdir(), 'stonewright-oauth-config-'));
		try {
			const tokenStore = join(temp, 'tokens.json');
			const config = loadWordPressMcpConfig({
				STONEWRIGHT_MCP_URL: 'https://example.com/wp-json/mcp/stonewright',
				STONEWRIGHT_OAUTH_CLIENT_ID: 'client-example',
				STONEWRIGHT_OAUTH_TOKEN_STORE: tokenStore,
			});

			expect(config?.oauth).toEqual({
				tokenEndpoint: 'https://example.com/wp-json/stonewright/v1/oauth/token',
				clientId: 'client-example',
				tokenStorePath: tokenStore,
			});
			expect(config?.authorization).toBeUndefined();
			expect(config?.username).toBeUndefined();
		} finally {
			rmSync(temp, { recursive: true, force: true });
		}
	});

	it('fails closed for partial, mixed, or cross-origin OAuth configuration', () => {
		expect(() => loadWordPressMcpConfig({
			STONEWRIGHT_MCP_URL: 'https://example.com/wp-json/mcp/stonewright',
			STONEWRIGHT_OAUTH_CLIENT_ID: 'client-example',
		})).toThrow(/OAUTH_TOKEN_STORE/u);

		expect(() => loadWordPressMcpConfig({
			STONEWRIGHT_MCP_URL: 'https://example.com/wp-json/mcp/stonewright',
			STONEWRIGHT_OAUTH_CLIENT_ID: 'client-example',
			STONEWRIGHT_OAUTH_TOKEN_STORE: '/tmp/example-tokens.json',
			STONEWRIGHT_MCP_AUTHORIZATION: 'Bearer fixture-static',
		})).toThrow(/cannot be combined/u);

		expect(() => loadWordPressMcpConfig({
			STONEWRIGHT_MCP_URL: 'https://example.com/wp-json/mcp/stonewright',
			STONEWRIGHT_OAUTH_CLIENT_ID: 'client-example',
			STONEWRIGHT_OAUTH_TOKEN_STORE: '/tmp/example-tokens.json',
			STONEWRIGHT_OAUTH_TOKEN_ENDPOINT: 'https://other.example/oauth/token',
		})).toThrow(/same origin/u);
	});

	it('derives the Stonewright REST endpoint used for prompt skill discovery', () => {
		expect(wordpressRestUrlFromMcpUrl('https://example.com/wp-json/mcp/stonewright', 'stonewright/v1/skills?mode=prompt&enabled_only=1')).toBe(
			'https://example.com/wp-json/stonewright/v1/skills?mode=prompt&enabled_only=1',
		);
	});

	it('loads a saved project credential when env does not include the app password', () => {
		const temp = mkdtempSync(join(tmpdir(), 'stonewright-credential-store-'));
		try {
			const storePath = join(temp, 'credential.json');
			writeFileSync(storePath, JSON.stringify({
				url: 'http://mcp-test.local/wp-json/mcp/stonewright',
				username: 'admin',
				password: 'test-stored-app-password',
			}));

			const config = loadWordPressMcpConfig({
				STONEWRIGHT_WP_URL: 'http://mcp-test.local',
				STONEWRIGHT_CREDENTIAL_STORE: storePath,
			});

			expect(config).toEqual({
				url: 'http://mcp-test.local/wp-json/mcp/stonewright',
				username: 'admin',
				password: 'test-stored-app-password',
				timeoutMs: 30_000,
				credentialStorePath: storePath,
				credentialSource: 'store',
			});
		} finally {
			rmSync(temp, { recursive: true, force: true });
		}
	});

	it('auto-creates and saves one local Application Password when credentials are missing', async () => {
		const temp = mkdtempSync(join(tmpdir(), 'stonewright-credential-create-'));
		try {
			const storePath = join(temp, 'credential.json');
			const wpRoot = join(temp, 'wordpress');
			mkdirSync(wpRoot, { recursive: true });
			const commands: string[][] = [];
			const runner: ExecFileRunner = (_file, args) => {
				commands.push(args);
				if (args.includes('--field=user_login')) {
					return Promise.resolve({ stdout: 'admin\n', stderr: '', exitCode: 0 });
				}
				if (args.includes('application-password')) {
					return Promise.resolve({ stdout: 'test test test test test test\n', stderr: '', exitCode: 0 });
				}
				return Promise.resolve({ stdout: '', stderr: 'unexpected command', exitCode: 1 });
			};

			const config = await resolveWordPressMcpConfig({
				STONEWRIGHT_WP_URL: 'http://mcp-test.local',
				STONEWRIGHT_WP_ROOT: wpRoot,
				STONEWRIGHT_CREDENTIAL_STORE: storePath,
				STONEWRIGHT_WP_CLI_BIN: 'wp',
			}, runner);

			expect(config?.username).toBe('admin');
			expect(config?.password).toBe('test test test test test test');
			expect(config?.credentialSource).toBe('generated');
			expect(commands).toHaveLength(2);
			expect(commands[1]).toEqual(expect.arrayContaining(['user', 'application-password', 'create', 'admin']));
			expect(JSON.parse(readFileSync(storePath, 'utf8'))).toMatchObject({
				url: 'http://mcp-test.local/wp-json/mcp/stonewright',
				username: 'admin',
				password: 'test test test test test test',
			});
		} finally {
			rmSync(temp, { recursive: true, force: true });
		}
	});

	it('does not auto-create credentials for remote sites unless explicitly enabled', async () => {
		const temp = mkdtempSync(join(tmpdir(), 'stonewright-credential-remote-'));
		try {
			const storePath = join(temp, 'credential.json');
			const wpRoot = join(temp, 'wordpress');
			mkdirSync(wpRoot, { recursive: true });
			let called = false;
			const runner: ExecFileRunner = () => {
				called = true;
				return Promise.resolve({ stdout: 'admin\n', stderr: '', exitCode: 0 });
			};

			const config = await resolveWordPressMcpConfig({
				STONEWRIGHT_WP_URL: 'https://example.com',
				STONEWRIGHT_WP_ROOT: wpRoot,
				STONEWRIGHT_CREDENTIAL_STORE: storePath,
				STONEWRIGHT_WP_CLI_BIN: 'wp',
			}, runner);

			expect(config?.username).toBeUndefined();
			expect(config?.password).toBeUndefined();
			expect(called).toBe(false);
		} finally {
			rmSync(temp, { recursive: true, force: true });
		}
	});
});
