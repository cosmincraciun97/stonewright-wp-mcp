/**
 * MCP server for the Stonewright companion.
 *
 * WordPress-facing helpers such as WP-CLI are registered here.
 */

import { McpServer } from '@modelcontextprotocol/sdk/server/mcp.js';
import { z } from 'zod';
import { runWpCli, wpCliDiscover, wpCliStatus, type WpCliResult, type WpCliRunInput } from './wp-cli.js';
import { loadWordPressMcpConfig, registerWordPressMcpTools } from './wordpress-mcp.js';

export interface CreateMcpServerOptions {
	env?: NodeJS.ProcessEnv;
	fetchImpl?: typeof fetch;
}

export async function createMcpServer(options: CreateMcpServerOptions = {}): Promise<McpServer> {
	const server = new McpServer({
		name: 'stonewright-companion',
		version: '1.0.0-alpha.1',
	});

	const commonInput = {
		cwd: z.string().optional(),
		path: z.string().optional(),
		url: z.string().optional(),
		user: z.string().optional(),
		context: z.string().optional(),
		timeoutMs: z.number().int().positive().optional(),
	};

	server.registerTool(
		'companion_wp_cli_status',
		{
			description: 'Check whether WP-CLI is available and return wp cli info diagnostics.',
			inputSchema: commonInput,
		},
		async (input) => toolResponse(await wpCliStatus(toWpCliInput(input))),
	);

	server.registerTool(
		'companion_wp_cli_discover',
		{
			description: 'Dump installed WP-CLI command metadata with wp cli cmd-dump.',
			inputSchema: commonInput,
		},
		async (input) => toolResponse(await wpCliDiscover(toWpCliInput(input))),
	);

	server.registerTool(
		'companion_wp_cli_run',
		{
			description: 'Run a tokenized WP-CLI command through execFile. Allows WordPress write commands while blocking arbitrary PHP and shell entry points.',
			inputSchema: {
				...commonInput,
				command: z.array(z.string()).min(1),
				parseJson: z.boolean().optional(),
			},
		},
		async (input) => toolResponse(await runWpCli(toWpCliInput(input) as WpCliRunInput)),
	);

	const wpMcpConfig = loadWordPressMcpConfig(options.env ?? process.env);
	if (wpMcpConfig) {
		await registerWordPressMcpTools(server, wpMcpConfig, options.fetchImpl ?? fetch);
	}

	return server;
}

function toWpCliInput(input: Record<string, unknown>): Partial<WpCliRunInput> {
	return Object.fromEntries(Object.entries(input).filter(([, value]) => value !== undefined)) as Partial<WpCliRunInput>;
}

function toolResponse(result: WpCliResult): {
	content: Array<{ type: 'text'; text: string }>;
	structuredContent: WpCliResult;
} {
	return {
		content: [
			{
				type: 'text',
				text: JSON.stringify(result, null, 2),
			},
		],
		structuredContent: result,
	};
}
