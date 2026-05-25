/**
 * MCP server for the Stonewright companion.
 *
 * WordPress-facing helpers such as WP-CLI are registered here.
 */

import { McpServer } from '@modelcontextprotocol/sdk/server/mcp.js';
import { z } from 'zod';
import {
	runWpCli,
	wpCliDiscover,
	wpCliInstall,
	wpCliStatus,
	type WpCliInstallInput,
	type WpCliRunInput,
} from './wp-cli.js';
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
	const env = options.env ?? process.env;

	const commonInput = {
		cwd: z.string().optional(),
		path: z.string().optional(),
		url: z.string().optional(),
		user: z.string().optional(),
		context: z.string().optional(),
		timeoutMs: z.number().int().positive().optional(),
	};

	registerWpCliTools(server, commonInput, env);

	const wpMcpConfig = loadWordPressMcpConfig(env);
	if (wpMcpConfig) {
		await registerWordPressMcpTools(server, wpMcpConfig, options.fetchImpl ?? fetch);
	}

	return server;
}

function registerWpCliTools(
	server: McpServer,
	commonInput: Record<string, z.ZodOptional<z.ZodString> | z.ZodOptional<z.ZodNumber>>,
	env: NodeJS.ProcessEnv,
): void {
	for (const name of ['companion_wp_cli_status', 'stonewright-wp-cli-status']) {
		server.registerTool(
			name,
			{
				description: 'Check whether WP-CLI is available and return wp cli info diagnostics. This runs directly inside the Stonewright companion.',
				inputSchema: commonInput,
			},
			async (input) => toolResponse(await wpCliStatus(toWpCliInput(input), undefined, env)),
		);
	}

	for (const name of ['companion_wp_cli_discover', 'stonewright-wp-cli-discover']) {
		server.registerTool(
			name,
			{
				description: 'Dump installed WP-CLI command metadata with wp cli cmd-dump. This runs directly inside the Stonewright companion.',
				inputSchema: commonInput,
			},
			async (input) => toolResponse(await wpCliDiscover(toWpCliInput(input), undefined, env)),
		);
	}

	for (const name of ['companion_wp_cli_run', 'stonewright-wp-cli-run']) {
		server.registerTool(
			name,
			{
				description: 'Run a tokenized WP-CLI command directly through the Stonewright companion with execFile. Allows WordPress write commands while blocking arbitrary PHP and shell entry points.',
				inputSchema: {
					...commonInput,
					command: z.array(z.string()).min(1),
					parseJson: z.boolean().optional(),
				},
			},
			async (input) => toolResponse(await runWpCli(toWpCliInput(input) as WpCliRunInput, undefined, env)),
		);
	}

	for (const name of ['companion_wp_cli_install', 'stonewright-wp-cli-install']) {
		server.registerTool(
			name,
			{
				description: 'Download the official WP-CLI phar into Stonewright companion cache so future stonewright-wp-cli-* calls can run even when wp is not on PATH.',
				inputSchema: {
					installDir: z.string().optional(),
					force: z.boolean().optional(),
					expectedSha256: z.string().optional(),
					timeoutMs: z.number().int().positive().optional(),
				},
			},
			async (input) => toolResponse(await wpCliInstall(input as WpCliInstallInput, fetch, env)),
		);
	}
}

function toWpCliInput(input: Record<string, unknown>): Partial<WpCliRunInput> {
	return Object.fromEntries(Object.entries(input).filter(([, value]) => value !== undefined)) as Partial<WpCliRunInput>;
}

function toolResponse<T extends Record<string, unknown>>(result: T): {
	content: Array<{ type: 'text'; text: string }>;
	structuredContent: T;
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
