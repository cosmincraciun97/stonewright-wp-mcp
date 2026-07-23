import { describe, expect, it } from 'vitest';
import { mkdtempSync } from 'node:fs';
import { tmpdir } from 'node:os';
import { join } from 'node:path';
import { createMcpServer } from '../src/mcp-server.js';
import { DIRECT_TOOL_NAMES } from '../src/direct/registry.js';

function registeredToolNames(server: unknown): string[] {
	return Object.keys(
		(server as { _registeredTools?: Record<string, unknown> })._registeredTools ?? {},
	);
}

type ToolMap = Record<
	string,
	{ handler?: (input: unknown) => Promise<{ content: Array<{ text: string }> }> }
>;

async function callTool(tools: ToolMap, name: string, input: unknown) {
	const tool = tools[name];
	expect(tool?.handler).toBeTypeOf('function');
	const response = await tool.handler!(input);
	const text = response.content[0]?.text ?? '';
	return JSON.parse(text) as Record<string, unknown>;
}

describe('direct self-improve protocol e2e (zero WordPress)', () => {
	it('runs full skill/memory loop without WP credentials', async () => {
		const stateDir = mkdtempSync(join(tmpdir(), 'sw-e2e-state-'));
		const server = await createMcpServer({
			env: {
				STONEWRIGHT_MODE: 'direct',
				STONEWRIGHT_MCP_TOOL_PROFILE: 'full',
				STONEWRIGHT_STATE_DIR: stateDir,
				STONEWRIGHT_SITES_FILE: join(stateDir, 'missing-sites.json'),
				// Explicitly no STONEWRIGHT_WP_* credentials
			},
		});
		const names = registeredToolNames(server);
		for (const n of [
			'stonewright-task-start',
			'stonewright-skill-list',
			'stonewright-skill-get',
			'stonewright-skill-save',
			'stonewright-skill-delete',
			'stonewright-memory-list',
			'stonewright-learning-record',
		]) {
			expect(names).toContain(n);
		}
		expect(DIRECT_TOOL_NAMES.length).toBeGreaterThanOrEqual(98);

		const tools = (server as { _registeredTools?: ToolMap })._registeredTools ?? {};

		await callTool(tools, 'stonewright-skill-save', {
			slug: 'wc-image-fix',
			name: 'WC image fix',
			description: 'Fix product images',
			triggers: ['woocommerce', 'product image'],
			body: '# Steps\n1. Check featured image\n',
		});

		const start = await callTool(tools, 'stonewright-task-start', {
			task: 'fix woocommerce product images on the catalog',
		});
		expect(start.mode).toBe('direct');
		expect(start.surface_revision).toBe(0);
		const matched = start.matched_skills as Array<{ slug: string }>;
		expect(matched.some((s) => s.slug === 'wc-image-fix')).toBe(true);

		const skill = await callTool(tools, 'stonewright-skill-get', { slug: 'wc-image-fix' });
		expect(String(skill.body)).toContain('# Steps');

		await callTool(tools, 'stonewright-learning-record', {
			text: 'Always set product image alt text before publish',
			kind: 'correction',
			tags: ['woocommerce'],
		});
		const mem = await callTool(tools, 'stonewright-memory-list', { limit: 5 });
		const items = mem.items as Array<{ text: string }>;
		expect(items.some((i) => i.text.includes('alt text'))).toBe(true);
	});

	it('starts on bootstrap and unlocks only the task profile for this session', async () => {
		const stateDir = mkdtempSync(join(tmpdir(), 'sw-e2e-bootstrap-'));
		const server = await createMcpServer({
			env: {
				STONEWRIGHT_MODE: 'direct',
				STONEWRIGHT_STATE_DIR: stateDir,
				STONEWRIGHT_SITES_FILE: join(stateDir, 'missing-sites.json'),
			},
		});
		const tools = (server as { _registeredTools?: ToolMap & Record<string, { enabled?: boolean }> })._registeredTools ?? {};
		expect(tools['stonewright-task-start']?.enabled).toBe(true);
		expect(tools['stonewright-elementor-data-update']?.enabled).toBe(false);
		expect(tools['stonewright-comment-list']?.enabled).toBe(false);

		const start = await callTool(tools, 'stonewright-task-start', {
			task: 'Implement a Figma design in Elementor',
			surface: 'elementor',
		});
		expect(start).toMatchObject({
			configured_mcp_surface: 'bootstrap',
			session_tool_profile: 'elementor-design',
			surface_revision: 1,
			tools_changed: true,
		});
		const status = await callTool(tools, 'stonewright-wordpress-mcp-status', {});
		expect(status.surface_revision).toBe(1);
		expect(status.tool_profile).toBe('elementor-design');
		expect(tools['stonewright-elementor-data-update']?.enabled).toBe(true);
		expect(tools['stonewright-comment-list']?.enabled).toBe(false);
	});
});
