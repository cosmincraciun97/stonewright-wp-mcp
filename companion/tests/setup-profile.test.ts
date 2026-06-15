import { describe, expect, it } from 'vitest';
import { buildSetupProfile } from '../src/setup-profile.js';
import { createMcpServer } from '../src/mcp-server.js';

describe('buildSetupProfile', () => {
	it('builds a copy-paste npx MCP config for local macOS installs without requiring a manual app password', () => {
		const profile = buildSetupProfile(
			{
				STONEWRIGHT_WP_URL: 'http://mcp-test.local/',
				STONEWRIGHT_WP_ROOT: '/Users/me/Local Sites/mcp-test/app/public',
			},
			'darwin',
		);

		expect(profile.ok).toBe(true);
		expect(profile.platform).toBe('darwin');
		expect(profile.mcp_server.command).toBe('npx');
		expect(profile.mcp_server.args).toEqual(['-y', '@stonewright/companion@latest']);
		expect(profile.mcp_server.env).toMatchObject({
			STONEWRIGHT_WP_URL: 'http://mcp-test.local',
			STONEWRIGHT_WP_ROOT: '/Users/me/Local Sites/mcp-test/app/public',
			STONEWRIGHT_WP_APP_PASSWORD_AUTO: 'local-only',
		});
		expect(profile.checks).toContainEqual(
			expect.objectContaining({
				id: 'credentials',
				status: 'ok',
			}),
		);
	});

	it('preserves Windows paths and recommends cmd-safe env-only config', () => {
		const profile = buildSetupProfile(
			{
				STONEWRIGHT_WP_URL: 'http://mcp-test.local',
				STONEWRIGHT_WP_ROOT: 'D:\\Sites\\mcp-test\\app\\public',
				STONEWRIGHT_WP_USERNAME: 'admin',
			},
			'win32',
		);

		expect(profile.platform).toBe('win32');
		expect(profile.mcp_server.env.STONEWRIGHT_WP_ROOT).toBe('D:\\Sites\\mcp-test\\app\\public');
		expect(profile.mcp_server.env.STONEWRIGHT_WP_USERNAME).toBe('admin');
		expect(profile.install_command).toBe('npm install -g @stonewright/companion');
		expect(profile.notes.join('\n')).toContain('No shell script wrapper required');
	});

	it('warns for remote sites without credentials instead of enabling local credential generation', () => {
		const profile = buildSetupProfile(
			{
				STONEWRIGHT_WP_URL: 'https://example.com',
			},
			'linux',
		);

		expect(profile.ok).toBe(false);
		expect(profile.mcp_server.env.STONEWRIGHT_WP_APP_PASSWORD_AUTO).toBe('never');
		expect(profile.checks).toContainEqual(
			expect.objectContaining({
				id: 'credentials',
				status: 'warning',
				message: 'Remote sites need STONEWRIGHT_WP_USERNAME plus STONEWRIGHT_WP_APP_PASSWORD, or STONEWRIGHT_MCP_AUTHORIZATION.',
			}),
		);
	});
});

describe('setup profile MCP tool', () => {
	it('registers stonewright-setup-profile for one-call onboarding diagnostics', async () => {
		const server = await createMcpServer();
		const names = Object.keys((server as { _registeredTools?: Record<string, unknown> })._registeredTools ?? {});

		expect(names).toContain('stonewright-setup-profile');
	});
});
