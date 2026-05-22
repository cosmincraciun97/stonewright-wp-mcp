/**
 * Smoke test: verify the MCP server registers the expected tools.
 * We don't invoke tool handlers here (that would need live Figma / Playwright).
 */

import { describe, it, expect } from 'vitest';
import { createMcpServer } from '../src/mcp-server.js';

describe('createMcpServer', () => {
	it('returns an McpServer instance without throwing', () => {
		expect(() => createMcpServer()).not.toThrow();
	});

	it('creates a server with the correct name', () => {
		const server = createMcpServer();
		// _serverInfo lives on the inner Server instance (SDK internal).
		// eslint-disable-next-line @typescript-eslint/no-explicit-any, @typescript-eslint/no-unsafe-member-access -- SDK internals
		const info = (server as any).server._serverInfo as { name: string; version: string };
		expect(info.name).toBe('stonewright-companion');
		expect(info.version).toBe('1.0.0-alpha.1');
	});
});
