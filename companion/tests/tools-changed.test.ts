/**
 * tools_changed handling: detect profile switches, re-register proxied tools,
 * and emit notifications/tools/list_changed.
 */
import { describe, expect, it, vi } from 'vitest';
import type { McpServer } from '@modelcontextprotocol/sdk/server/mcp.js';
import {
	applyRefreshToLiveState,
	emitToolListChanged,
	handleToolsChangedResponse,
	mcpToolNamesFromStructured,
	parseStructuredFromContent,
	structuredIndicatesToolsChanged,
	surfaceRevisionFromStructured,
	type ToolsChangedRefreshResult,
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

describe('surface_revision propagation', () => {
	it('extracts surface_revision from a structured gateway result', () => {
		expect(surfaceRevisionFromStructured({ surface_revision: 7 })).toBe(7);
		expect(surfaceRevisionFromStructured({})).toBeNull();
		expect(surfaceRevisionFromStructured(null)).toBeNull();
	});

	it('treats a newer revision as a tools-changed signal even without the flag', () => {
		expect(structuredIndicatesToolsChanged({ surface_revision: 5 }, 3)).toBe(true);
		expect(structuredIndicatesToolsChanged({ surface_revision: 5 }, 5)).toBe(false);
		expect(structuredIndicatesToolsChanged({ tools_changed: true }, 5)).toBe(true);
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
	it('applyRefreshToLiveState reflects the post-refresh registry', () => {
		const enabled = { enabled: true, enable: () => {}, disable: () => {} };
		const disabled = { enabled: false, enable: () => {}, disable: () => {} };
		const registered = new Map([
			['stonewright-tool-profile', { handle: enabled, tool: { name: 'stonewright-tool-profile' } }],
			['stonewright-elementor-page-digest', { handle: disabled, tool: { name: 'stonewright-elementor-page-digest' } }],
		]);
		const liveState = {
			profile: 'bootstrap' as const,
			surfaceRevision: null as number | null,
			enabledToolNames: [] as string[],
			registeredToolCount: 0,
			lastRefreshAt: null as string | null,
			lastRefresh: null as ToolsChangedRefreshResult | null,
		};
		const refresh: ToolsChangedRefreshResult = {
			notified: true,
			refreshed: true,
			added: [],
			removed: ['stonewright-elementor-page-digest'],
			profile: 'essential',
			desiredCount: 1,
		};

		applyRefreshToLiveState(liveState, refresh, registered);

		expect(liveState.profile).toBe('essential');
		expect(liveState.enabledToolNames).toEqual(['stonewright-tool-profile']);
		expect(liveState.registeredToolCount).toBe(2);
		expect(liveState.lastRefreshAt).toBeTruthy();
		expect(liveState.lastRefresh).toBe(refresh);
	});

	const makeHandle = () => {
		const handle = {
			enabled: true,
			enable: vi.fn(() => { handle.enabled = true; }),
			disable: vi.fn(() => { handle.enabled = false; }),
		};
		return handle;
	};

	it('never disables pinned gateway tools even when an authoritative resolve omits them', async () => {
		const server = { server: { sendToolListChanged: vi.fn() } } as unknown as McpServer;
		const toolProfileHandle = makeHandle();
		const phpExecuteHandle = makeHandle();
		const digestHandle = makeHandle();
		const registered = new Map([
			['stonewright-tool-profile', { handle: toolProfileHandle, tool: { name: 'stonewright-tool-profile' } }],
			['stonewright-php-execute', { handle: phpExecuteHandle, tool: { name: 'stonewright-php-execute' } }],
			['stonewright-elementor-page-digest', { handle: digestHandle, tool: { name: 'stonewright-elementor-page-digest' } }],
		]);

		const result = await handleToolsChangedResponse({
			server,
			client: {
				listTools: vi.fn(() => Promise.resolve([
					{ name: 'stonewright-tool-profile' },
					{ name: 'stonewright-php-execute' },
					{ name: 'stonewright-elementor-v3-batch-mutate' },
				])),
				callTool: vi.fn(() => Promise.resolve({
					structuredContent: {
						ok: true,
						source: 'plugin',
						tools: ['stonewright-elementor-v3-batch-mutate'],
					},
				})),
			},
			structured: { tools_changed: true, session_tool_profile: 'essential' },
			activeProfile: 'bootstrap',
			maxTools: null,
			registered,
			registerProxyTool: vi.fn(),
		});

		expect(toolProfileHandle.disable).not.toHaveBeenCalled();
		expect(phpExecuteHandle.disable).not.toHaveBeenCalled();
		expect(digestHandle.disable).toHaveBeenCalledOnce();
		expect(result.removed).toEqual(['stonewright-elementor-page-digest']);
	});

	it('treats advisory recommended_mcp_tools as additive and disables nothing without an authoritative resolve', async () => {
		const server = { server: { sendToolListChanged: vi.fn() } } as unknown as McpServer;
		const digestHandle = makeHandle();
		const registered = new Map([
			['stonewright-elementor-page-digest', { handle: digestHandle, tool: { name: 'stonewright-elementor-page-digest' } }],
		]);

		const result = await handleToolsChangedResponse({
			server,
			client: {
				listTools: vi.fn(() => Promise.resolve([
					{ name: 'stonewright-elementor-page-digest' },
					{ name: 'stonewright-elementor-v3-batch-mutate' },
				])),
				callTool: vi.fn(() => Promise.reject(new Error('unreachable'))),
			},
			structured: {
				tools_changed: true,
				recommended_mcp_tools: ['stonewright-elementor-v3-batch-mutate'],
			},
			activeProfile: 'essential',
			maxTools: null,
			registered,
			registerProxyTool: vi.fn(),
		});

		expect(digestHandle.disable).not.toHaveBeenCalled();
		expect(result.removed).toEqual([]);
		expect(result.added).toContain('stonewright-elementor-v3-batch-mutate');
	});

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

	it('registers advisory tools without disabling existing ones, and notifies', async () => {
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
		expect(result.removed).toEqual([]);
		expect(disable).not.toHaveBeenCalled();
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
