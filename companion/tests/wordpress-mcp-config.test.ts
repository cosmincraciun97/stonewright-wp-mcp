import { describe, expect, it } from 'vitest';
import { loadWordPressMcpConfig } from '../src/wordpress-mcp.js';

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
});
