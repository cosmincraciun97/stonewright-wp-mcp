/**
 * MCP server — registers all companion tools and wires them to the
 * underlying modules. Each tool validates its input with Zod before
 * calling into figma-bridge, playwright-runner, or pixel-diff.
 *
 * Transport: stdio (always) + optional Streamable HTTP (when PORT is set).
 */

import { McpServer } from '@modelcontextprotocol/sdk/server/mcp.js';
import { z } from 'zod';
import { log } from './lib/log.js';
import { parseUrl, fetchNode, exportImages } from './figma-bridge.js';
import { screenshot } from './playwright-runner.js';
import { diff } from './pixel-diff.js';

// ---------------------------------------------------------------------------
// Schema definitions
// ---------------------------------------------------------------------------

const FigmaFetchSchema = z.object({
	/** A Figma share URL or a raw "fileKey:nodeId" string. */
	url: z.string().describe('Figma share URL or "fileKey:nodeId" pair'),
	/** Override the Figma token for this request. Falls back to FIGMA_TOKEN env var. */
	token: z.string().optional().describe('Figma personal access token (optional override)'),
});

const ScreenshotSchema = z.object({
	url: z.string().url().describe('URL to screenshot'),
	viewport_width: z.number().int().min(320).max(3840).default(1280),
	viewport_height: z.number().int().min(240).max(2160).default(800),
	full_page: z.boolean().default(false),
	wait_for: z.enum(['load', 'domcontentloaded', 'networkidle', 'commit']).default('networkidle'),
	selector: z.string().optional().describe('CSS selector — screenshot only this element'),
	delay_ms: z.number().int().min(0).max(10_000).optional(),
});

const PixelDiffSchema = z.object({
	reference_path: z.string().describe('Absolute path to the reference PNG'),
	actual_path: z.string().describe('Absolute path to the actual PNG'),
	threshold: z.number().min(0).max(1).default(0.1),
	ignore_regions: z
		.array(z.object({ x: z.number(), y: z.number(), width: z.number(), height: z.number() }))
		.optional(),
	diff_output_path: z.string().optional(),
});

// ---------------------------------------------------------------------------
// Server factory
// ---------------------------------------------------------------------------

export function createMcpServer(): McpServer {
	const server = new McpServer({
		name: 'stonewright-companion',
		version: '1.0.0-alpha.1',
	});

	// ------------------------------------------------------------------
	// Tool: companion_figma_fetch
	// ------------------------------------------------------------------
	server.tool(
		'companion_figma_fetch',
		'Fetch a Figma node and map it to WordPress-friendly section/block descriptors.',
		FigmaFetchSchema.shape,
		async (args) => {
			const params = FigmaFetchSchema.parse(args);
			const token = params.token ?? process.env['FIGMA_TOKEN'] ?? '';
			if (!token) {
				return {
					content: [{ type: 'text', text: JSON.stringify({ error: 'FIGMA_TOKEN is not set' }) }],
					isError: true,
				};
			}

			try {
				const ref = parseUrl(params.url);
				if (!ref.nodeId) {
					return {
						content: [{ type: 'text', text: JSON.stringify({ error: 'No node-id found in URL' }) }],
						isError: true,
					};
				}
				const result = await fetchNode(ref.fileKey, ref.nodeId, token);
				log.info('companion_figma_fetch succeeded', { fileKey: ref.fileKey, nodeId: ref.nodeId });
				return {
					content: [{ type: 'text', text: JSON.stringify(result, null, 2) }],
				};
			} catch (err) {
				const msg = err instanceof Error ? err.message : String(err);
				log.error('companion_figma_fetch failed', { error: msg });
				return {
					content: [{ type: 'text', text: JSON.stringify({ error: msg }) }],
					isError: true,
				};
			}
		},
	);

	// ------------------------------------------------------------------
	// Tool: companion_figma_export
	// ------------------------------------------------------------------
	server.tool(
		'companion_figma_export',
		'Export images for a list of Figma node IDs.',
		{
			file_key: z.string().describe('Figma file key'),
			node_ids: z.array(z.string()).min(1).describe('List of node IDs to export'),
			token: z.string().optional(),
		},
		async (args) => {
			const schema = z.object({
				file_key: z.string(),
				node_ids: z.array(z.string()).min(1),
				token: z.string().optional(),
			});
			const params = schema.parse(args);
			const token = params.token ?? process.env['FIGMA_TOKEN'] ?? '';
			if (!token) {
				return {
					content: [{ type: 'text', text: JSON.stringify({ error: 'FIGMA_TOKEN is not set' }) }],
					isError: true,
				};
			}
			try {
				const result = await exportImages(params.file_key, params.node_ids, token);
				return { content: [{ type: 'text', text: JSON.stringify(result, null, 2) }] };
			} catch (err) {
				const msg = err instanceof Error ? err.message : String(err);
				return {
					content: [{ type: 'text', text: JSON.stringify({ error: msg }) }],
					isError: true,
				};
			}
		},
	);

	// ------------------------------------------------------------------
	// Tool: companion_screenshot
	// ------------------------------------------------------------------
	server.tool(
		'companion_screenshot',
		'Take a Playwright screenshot of a URL and return base64-encoded PNG + metadata.',
		ScreenshotSchema.shape,
		async (args) => {
			const params = ScreenshotSchema.parse(args);
			try {
				// Build options without undefined-valued optional keys to satisfy
				// exactOptionalPropertyTypes — only include selector / delay_ms when set.
				const screenshotOpts = {
					viewport: { width: params.viewport_width, height: params.viewport_height },
					full_page: params.full_page,
					wait_for: params.wait_for,
					...(params.selector !== undefined ? { selector: params.selector } : {}),
					...(params.delay_ms !== undefined ? { delay_ms: params.delay_ms } : {}),
				};
				const result = await screenshot(params.url, screenshotOpts);

				const response = {
					url: result.url,
					width: result.width,
					height: result.height,
					tookMs: result.tookMs,
					sizeBytes: result.png.length,
					png_base64: result.png.toString('base64'),
				};
				log.info('companion_screenshot succeeded', { url: params.url, tookMs: result.tookMs });
				return { content: [{ type: 'text', text: JSON.stringify(response) }] };
			} catch (err) {
				const msg = err instanceof Error ? err.message : String(err);
				log.error('companion_screenshot failed', { error: msg, url: params.url });
				return {
					content: [{ type: 'text', text: JSON.stringify({ error: msg }) }],
					isError: true,
				};
			}
		},
	);

	// ------------------------------------------------------------------
	// Tool: companion_pixel_diff
	// ------------------------------------------------------------------
	server.tool(
		'companion_pixel_diff',
		'Compare two PNG files pixel-by-pixel and return mismatch stats + diff image path.',
		PixelDiffSchema.shape,
		async (args) => {
			const params = PixelDiffSchema.parse(args);
			try {
				// Build options without undefined-valued optional keys to satisfy
				// exactOptionalPropertyTypes.
				const diffOpts = {
					threshold: params.threshold,
					...(params.ignore_regions !== undefined ? { ignore_regions: params.ignore_regions } : {}),
					...(params.diff_output_path !== undefined ? { diff_output_path: params.diff_output_path } : {}),
				};
				const result = await diff(params.reference_path, params.actual_path, diffOpts);
				log.info('companion_pixel_diff succeeded', { ratio: result.ratio });
				return { content: [{ type: 'text', text: JSON.stringify(result, null, 2) }] };
			} catch (err) {
				const msg = err instanceof Error ? err.message : String(err);
				log.error('companion_pixel_diff failed', { error: msg });
				return {
					content: [{ type: 'text', text: JSON.stringify({ error: msg }) }],
					isError: true,
				};
			}
		},
	);

	return server;
}
