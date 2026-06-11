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
			STONEWRIGHT_WP_APP_PASSWORD: 'app password',
		});

		expect(config).toEqual({
			url: 'http://mcp-test.local/wp-json/mcp/stonewright',
			username: 'admin',
			password: 'app password',
			timeoutMs: 30_000,
		});
	});

	it('does not append an endpoint when the URL already points at MCP', () => {
		const config = loadWordPressMcpConfig({
			STONEWRIGHT_WP_URL: 'https://example.com/wp-json/mcp/stonewright',
		});

		expect(config?.url).toBe('https://example.com/wp-json/mcp/stonewright');
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
				password: 'stored app password',
			}));

			const config = loadWordPressMcpConfig({
				STONEWRIGHT_WP_URL: 'http://mcp-test.local',
				STONEWRIGHT_CREDENTIAL_STORE: storePath,
			});

			expect(config).toEqual({
				url: 'http://mcp-test.local/wp-json/mcp/stonewright',
				username: 'admin',
				password: 'stored app password',
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
					return Promise.resolve({ stdout: 'abcd efgh ijkl mnop qrst uvwx\n', stderr: '', exitCode: 0 });
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
			expect(config?.password).toBe('abcd efgh ijkl mnop qrst uvwx');
			expect(config?.credentialSource).toBe('generated');
			expect(commands).toHaveLength(2);
			expect(commands[1]).toEqual(expect.arrayContaining(['user', 'application-password', 'create', 'admin']));
			expect(JSON.parse(readFileSync(storePath, 'utf8'))).toMatchObject({
				url: 'http://mcp-test.local/wp-json/mcp/stonewright',
				username: 'admin',
				password: 'abcd efgh ijkl mnop qrst uvwx',
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
