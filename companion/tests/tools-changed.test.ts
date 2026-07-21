/**
 * tools_changed handling: detect profile switches, re-register proxied tools,
 * and emit notifications/tools/list_changed.
 */
import { describe, expect, it, vi } from 'vitest';
import type { McpServer } from '@modelcontextprotocol/sdk/server/mcp.js';
import {
	emitToolListChanged,
	handleToolsChangedResponse,
	mcpToolNamesFromStructured,
	parseStructuredFromContent,
	structuredIndicatesToolsChanged,
} from '../src/wordpress-mcp.js';

describe('parseStructuredFromContent', () => {
	it('parses JSON ability payloads from content text when structuredContent is missing', () => {
		const parsed = parseStructuredFromContent([
			{
				type: 'text',
				text: JSON.stringify({
					ok: true,
					tools_changed: true,
					session_tool_profile: 'full',
					re_list_instruction: 'Re-list tools now (tools/list).',
				}),
			},
		]);
		expect(parsed?.tools_changed).toBe(true);
		expect(parsed?.session_tool_profile).toBe('full');
	});

	it('returns null for non-JSON content', () => {
		expect(parseStructuredFromContent([{ type: 'text', text: 'not json' }])).toBeNull();
		expect(parseStructuredFromContent(undefined)).toBeNull();
	});
});

describe('structuredIndicatesToolsChanged', () => {
	it('detects tools_changed: true', () => {
		expect(structuredIndicatesToolsChanged({ tools_changed: true })).toBe(true);
		expect(structuredIndicatesToolsChanged({ tools_changed: false, re_list_instruction: 'Re-list tools now' })).toBe(true);
	});

	it('detects non-empty re_list_instruction', () => {
		expect(
			structuredIndicatesToolsChanged({
				tools_changed: false,
				re_list_instruction: 'Re-list tools now (tools/list).',
			}),
		).toBe(true);
	});

	it('ignores empty or missing signals', () => {
		expect(structuredIndicatesToolsChanged(null)).toBe(false);
		expect(structuredIndicatesToolsChanged({})).toBe(false);
		expect(structuredIndicatesToolsChanged({ tools_changed: false, re_list_instruction: '' })).toBe(false);
		expect(structuredIndicatesToolsChanged({ re_list_instruction: '   ' })).toBe(false);
	});
});

describe('mcpToolNamesFromStructured', () => {
	it('prefers recommended_mcp_tools', () => {
		expect(
			mcpToolNamesFromStructured({
				recommended_mcp_tools: ['stonewright-task-start', 'stonewright-blueprint-apply'],
				tools: [{ mcp_tool: 'stonewright-ignored' }],
			}),
		).toEqual(['stonewright-task-start', 'stonewright-blueprint-apply']);
	});

	it('falls back to tools[].mcp_tool', () => {
		expect(
			mcpToolNamesFromStructured({
				tools: [
					{ ability: 'stonewright/php-execute', mcp_tool: 'stonewright-php-execute' },
					'stonewright-tool-profile',
				],
			}),
		).toEqual(['stonewright-php-execute', 'stonewright-tool-profile']);
	});
});

describe('emitToolListChanged', () => {
	it('awaits protocol server sendToolListChanged', async () => {
		const sendToolListChanged = vi.fn(() => Promise.resolve());
		const server = {
			server: { sendToolListChanged },
		} as unknown as McpServer;
		await expect(emitToolListChanged(server)).resolves.toBe(true);
		expect(sendToolListChanged).toHaveBeenCalledOnce();
	});

	it('falls back to high-level sendToolListChanged', async () => {
		const sendToolListChanged = vi.fn();
		const server = { sendToolListChanged } as unknown as McpServer;
		await expect(emitToolListChanged(server)).resolves.toBe(true);
		expect(sendToolListChanged).toHaveBeenCalledOnce();
	});
});

describe('handleToolsChangedResponse', () => {
	it('prefers the session task profile over the saved bootstrap surface', async () => {
		const server = { server: { sendToolListChanged: vi.fn() } } as unknown as McpServer;
		const result = await handleToolsChangedResponse({
			server,
			client: {
				listTools: vi.fn(() => Promise.resolve([{ name: 'stonewright-elementor-v3-batch-mutate' }])),
				callTool: vi.fn(),
			},
			structured: {
				configured_mcp_surface: 'bootstrap',
				session_tool_profile: 'elementor-design',
				tools_changed: true,
				recommended_mcp_tools: ['stonewright-elementor-v3-batch-mutate'],
			},
			activeProfile: 'bootstrap',
			maxTools: null,
			registered: new Map(),
			registerProxyTool: vi.fn(),
		});
		expect(result.profile).toBe('elementor-design');
	});

	it('registers newly desired tools, disables removed ones, and notifies', async () => {
		const sendToolListChanged = vi.fn(() => Promise.resolve());
		const server = {
			server: { sendToolListChanged },
		} as unknown as McpServer;

		const disable = vi.fn();
		const enable = vi.fn();
		const registered = new Map([
			[
				'stonewright-old-tool',
				{
					handle: { enable, disable, enabled: true },
					tool: { name: 'stonewright-old-tool' },
				},
			],
			[
				'stonewright-task-start',
				{
					handle: { enable: vi.fn(), disable: vi.fn(), enabled: true },
					tool: { name: 'stonewright-task-start' },
				},
			],
		]);

		const newlyRegistered: string[] = [];
		const client = {
			listTools: vi.fn(() =>
				Promise.resolve([
					{ name: 'stonewright-task-start', description: 'start' },
					{ name: 'stonewright-blueprint-apply', description: 'apply' },
					{ name: 'stonewright-old-tool', description: 'old' },
				]),
			),
			callTool: vi.fn(),
		};

		const result = await handleToolsChangedResponse({
			server,
			client,
			structured: {
				tools_changed: true,
				profile: 'elementor-design',
				re_list_instruction: 'Re-list tools now (tools/list).',
				recommended_mcp_tools: [
					'stonewright-task-start',
					'stonewright-blueprint-apply',
				],
			},
			activeProfile: 'essential',
			maxTools: null,
			registered,
			registerProxyTool: (tool) => {
				newlyRegistered.push(tool.name);
				registered.set(tool.name, {
					handle: { enable: vi.fn(), disable: vi.fn(), enabled: true },
					tool,
				});
			},
		});

		expect(result.refreshed).toBe(true);
		expect(result.notified).toBe(true);
		expect(result.profile).toBe('elementor-design');
		expect(result.added).toContain('stonewright-blueprint-apply');
		expect(result.removed).toContain('stonewright-old-tool');
		expect(disable).toHaveBeenCalledOnce();
		expect(newlyRegistered).toEqual(['stonewright-blueprint-apply']);
		expect(sendToolListChanged).toHaveBeenCalled();
		expect(client.listTools).toHaveBeenCalledOnce();
	});

	it('still emits list_changed when listTools fails', async () => {
		const sendToolListChanged = vi.fn(() => Promise.resolve());
		const server = {
			server: { sendToolListChanged },
		} as unknown as McpServer;

		const result = await handleToolsChangedResponse({
			server,
			client: {
				listTools: vi.fn(() => Promise.reject(new Error('upstream down'))),
				callTool: vi.fn(),
			},
			structured: {
				tools_changed: true,
				re_list_instruction: 'Re-list tools now.',
			},
			activeProfile: 'essential',
			maxTools: null,
			registered: new Map(),
			registerProxyTool: vi.fn(),
		});

		expect(result.refreshed).toBe(false);
		expect(result.notified).toBe(true);
		expect(sendToolListChanged).toHaveBeenCalledOnce();
	});
});
