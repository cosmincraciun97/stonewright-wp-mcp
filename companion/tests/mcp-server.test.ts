/**
 * Smoke test: verify the MCP server registers the expected tools.
 * Tool handlers are covered by the runner tests.
 */

import { describe, it, expect } from 'vitest';
import { createMcpServer } from '../src/mcp-server.js';
import { proxyToolNamesForProfile } from '../src/wordpress-mcp.js';

describe('createMcpServer', () => {
	it('returns an McpServer instance without throwing', async () => {
		await expect(createMcpServer()).resolves.toBeTruthy();
	});

	it('creates a server with the correct name', async () => {
		const server = await createMcpServer();
		// _serverInfo lives on the inner Server instance (SDK internal).
		// eslint-disable-next-line @typescript-eslint/no-explicit-any, @typescript-eslint/no-unsafe-member-access -- SDK internals
		const info = (server as any).server._serverInfo as { name: string; version: string };
		expect(info.name).toBe('stonewright-companion');
		expect(info.version).toBe('1.0.0-alpha.62');
	});

	it('publishes compact handshake instructions before any tool is called', async () => {
		const server = await createMcpServer({
			env: {
				STONEWRIGHT_MCP_TOOL_PROFILE: 'antigravity',
			},
		});
		// _instructions lives on the inner Server instance (SDK internal).
		// eslint-disable-next-line @typescript-eslint/no-explicit-any, @typescript-eslint/no-unsafe-member-access -- SDK internals
		const instructions = (server as any).server._instructions as string | undefined;

		expect(instructions).toContain('stonewright-setup-profile');
		expect(instructions).toContain('stonewright-context-bootstrap');
		expect(instructions).toContain('stonewright-workflow-preflight');
		expect(instructions).toContain('stonewright-php-execute');
		expect(instructions).toContain('fast_path.tool_profile');
		expect(instructions).toContain('stonewright-wordpress-mcp-status');
		expect(instructions).toContain('stonewright-wp-cli-batch-run');
		expect(instructions).toContain('STONEWRIGHT_MCP_TOOL_PROFILE=low-tools');
		expect(instructions).toContain('If stonewright-context-bootstrap is not visible, stop');
		expect(instructions).toContain('Do not inspect private AI-client config files');
		expect(instructions).toContain('Do not create scratch scripts such as query-mcp.js or run-ability.js');
		expect(instructions).toContain('Do not create helper JSON argument files such as bootstrap-args.json, cli_command.json, or get_structure.json');
		expect(instructions).toContain('Do not launch the Stonewright companion from ad hoc shell scripts such as query-local-stonewright.js');
		expect(instructions).toContain('Do not create or modify action scripts such as run-loop-mutate.js or run-bootstrap-and-mutate.js');
		expect(instructions).toContain('Do not inspect plugin or companion source code to reverse-engineer tool schemas');
		expect(instructions).toContain('Do not hand-roll JSON-RPC calls');
		expect(instructions).toContain('Do not call /wp-json/stonewright/v1/abilities/run from shell as an MCP workaround');
		expect(instructions).toContain('Do not run wp commands in a normal shell');
		expect(instructions).not.toContain('companion_wp_cli_run');
	});

	it('registers WP-CLI tools', async () => {
		const server = await createMcpServer();
		const toolNames = registeredToolNames(server);

		expect(toolNames).toEqual(
			expect.arrayContaining([
				'companion_wp_cli_status',
				'companion_wp_cli_run',
				'companion_wp_cli_batch_run',
				'companion_wp_cli_discover',
				'companion_wp_cli_install',
				'stonewright-wp-cli-status',
				'stonewright-wp-cli-run',
				'stonewright-wp-cli-batch-run',
				'stonewright-wp-cli-discover',
				'stonewright-wp-cli-install',
				'stonewright-wp-cli-job-start',
				'stonewright-wp-cli-job-status',
			]),
		);
	});

	it('returns compact text content for MCP tool responses', async () => {
		const server = await createMcpServer({
			env: {
				STONEWRIGHT_WP_CLI_BIN: 'missing-stonewright-wp',
			},
		});
		const tools = (server as { _registeredTools?: Record<string, { handler?: (input: unknown) => Promise<unknown> }> })._registeredTools ?? {};
		const tool = tools['stonewright-wp-cli-status'];
		expect(tool?.handler).toBeTypeOf('function');

		const response = await tool.handler?.({ cwd: process.cwd() }) as { content: Array<{ text: string }> };

		expect(response.content[0]?.text).not.toContain('\n  "');
		expect(response.content[0]?.text).toMatch(/^\{"ok":/);
	});

	it('registers proxied WordPress MCP tools when endpoint env is configured', async () => {
		const server = await createMcpServer({
			env: {
				STONEWRIGHT_MCP_URL: 'https://example.com/wp-json/mcp/stonewright',
				WP_API_USERNAME: 'admin',
				WP_API_PASSWORD: 'pw',
			},
			fetchImpl: stonewrightMcpFetch([
				{
					name: 'stonewright-context-bootstrap',
					description: 'Bootstrap agent context.',
					inputSchema: { type: 'object', properties: { task: { type: 'string' } } },
				},
			]),
		});

		expect(registeredToolNames(server)).toContain('stonewright-context-bootstrap');
		expect(registeredPromptNames(server)).toContain('stonewright-skill-figma-quality-rules');
	});

	it('keeps local setup and WP-CLI tools available when the WordPress MCP endpoint fails', async () => {
		const server = await createMcpServer({
			env: {
				STONEWRIGHT_MCP_URL: 'https://example.com/wp-json/mcp/stonewright',
				WP_API_USERNAME: 'admin',
				WP_API_PASSWORD: 'pw',
				STONEWRIGHT_MCP_TOOL_PROFILE: 'elementor',
			},
			fetchImpl: () => Promise.reject(new Error('network down')),
		});

		const names = registeredToolNames(server);

		expect(names).toEqual(expect.arrayContaining([
			'stonewright-setup-profile',
			'stonewright-wp-cli-status',
			'stonewright-wp-cli-batch-run',
			'stonewright-wordpress-mcp-status',
		]));
		expect(names).not.toContain('stonewright-context-bootstrap');

		const tools = (server as { _registeredTools?: Record<string, { handler?: (input: unknown) => Promise<unknown> }> })._registeredTools ?? {};
		const response = await tools['stonewright-wordpress-mcp-status']?.handler?.({}) as {
			structuredContent?: {
				ok?: boolean;
				connected?: boolean;
				error?: { message?: string };
				tool_profile?: string | null;
				profile_expected_tool_count?: number;
				profile_missing_tool_names?: string[];
				local_tool_names?: string[];
				recovery?: string[];
				agent_do_not_use?: string[];
				agent_use_instead?: string[];
			};
		};

		expect(response.structuredContent?.ok).toBe(false);
		expect(response.structuredContent?.connected).toBe(false);
		expect(response.structuredContent?.error?.message).toContain('network down');
		expect(response.structuredContent?.tool_profile).toBe('elementor-design');
		expect(response.structuredContent?.profile_expected_tool_count).toBeGreaterThan(20);
		expect(response.structuredContent?.profile_missing_tool_names).toContain('stonewright-elementor-v3-build-page-from-spec');
		expect(response.structuredContent?.profile_missing_tool_names).not.toContain('stonewright-wp-cli-batch-run');
		expect(response.structuredContent?.local_tool_names).toEqual(expect.arrayContaining([
			'stonewright-setup-profile',
			'stonewright-wordpress-mcp-status',
			'stonewright-wp-cli-status',
			'stonewright-wp-cli-discover',
			'stonewright-wp-cli-run',
			'stonewright-wp-cli-batch-run',
			'stonewright-wp-cli-install',
			'companion_wp_cli_status',
			'companion_wp_cli_discover',
			'companion_wp_cli_run',
			'companion_wp_cli_batch_run',
			'companion_wp_cli_install',
		]));
		expect(response.structuredContent?.agent_do_not_use).toContain('Do not run wp cli info, wp plugin activate, wp option update, or other wp commands in a normal shell as Stonewright recovery.');
		expect(response.structuredContent?.agent_do_not_use).toContain('Do not inspect private AI-client config files to find or call Stonewright.');
		expect(response.structuredContent?.agent_do_not_use).toContain('Do not create scratch scripts such as query-mcp.js or run-ability.js to bypass the MCP client tool surface.');
		expect(response.structuredContent?.agent_do_not_use).toContain('Do not create helper JSON argument files such as bootstrap-args.json, cli_command.json, or get_structure.json to bypass typed MCP tool input.');
		expect(response.structuredContent?.agent_do_not_use).toContain('Do not launch the Stonewright companion from ad hoc shell scripts such as query-local-stonewright.js to bypass the MCP client tool list.');
		expect(response.structuredContent?.agent_do_not_use).toContain('Do not create or modify action scripts such as run-loop-mutate.js or run-bootstrap-and-mutate.js to bypass typed Stonewright tool calls.');
		expect(response.structuredContent?.agent_do_not_use).toContain('Do not inspect plugin or companion source code to reverse-engineer tool schemas during WordPress implementation tasks.');
		expect(response.structuredContent?.agent_do_not_use).toContain('Do not hand-roll JSON-RPC calls to /mcp or /wp-json/mcp/stonewright as an MCP workaround.');
		expect(response.structuredContent?.agent_use_instead).toEqual(expect.arrayContaining([
			'stonewright-wp-cli-status',
			'stonewright-wp-cli-discover',
			'stonewright-wp-cli-run',
			'stonewright-wp-cli-batch-run',
			'stonewright-wp-cli-install',
		]));
		expect(response.structuredContent?.recovery).toContain('Verify STONEWRIGHT_WP_URL or STONEWRIGHT_MCP_URL points to /wp-json/mcp/stonewright.');
	});

	it('keeps local tools available when WordPress MCP config resolution fails', async () => {
		const server = await createMcpServer({
			env: {
				STONEWRIGHT_WP_URL: 'not a url',
			},
		});

		const names = registeredToolNames(server);

		expect(names).toEqual(expect.arrayContaining([
			'stonewright-setup-profile',
			'stonewright-wordpress-mcp-status',
			'stonewright-wp-cli-status',
		]));
		expect(names).not.toContain('stonewright-context-bootstrap');

		const tools = (server as { _registeredTools?: Record<string, { handler?: (input: unknown) => Promise<unknown> }> })._registeredTools ?? {};
		const response = await tools['stonewright-wordpress-mcp-status']?.handler?.({}) as {
			structuredContent?: {
				ok?: boolean;
				configured?: boolean;
				connected?: boolean;
				error?: { message?: string };
			};
		};

		expect(response.structuredContent?.ok).toBe(false);
		expect(response.structuredContent?.configured).toBe(true);
		expect(response.structuredContent?.connected).toBe(false);
		expect(response.structuredContent?.error?.message).toMatch(/Invalid URL|Invalid WordPress MCP URL/);
	});

	it('filters proxied WordPress MCP tools to the configured compact profile before registration', async () => {
		const server = await createMcpServer({
			env: {
				STONEWRIGHT_MCP_URL: 'https://example.com/wp-json/mcp/stonewright',
				WP_API_USERNAME: 'admin',
				WP_API_PASSWORD: 'pw',
				STONEWRIGHT_MCP_TOOL_PROFILE: 'elementor-design',
			},
			fetchImpl: stonewrightMcpFetch([
				{ name: 'stonewright-context-bootstrap' },
				{ name: 'stonewright-workflow-preflight' },
				{ name: 'stonewright-tool-profile' },
				{ name: 'stonewright-design-implementation-contract' },
				{ name: 'stonewright-elementor-v3-container-schema' },
				{ name: 'stonewright-elementor-v3-build-page-from-spec' },
				{ name: 'stonewright-elementor-v3-batch-mutate' },
				{ name: 'stonewright-wp-cli-batch-run' },
				{ name: 'stonewright-sandbox-write' },
				{ name: 'stonewright-memory-list' },
				{ name: 'stonewright-experimental-heavy-tool' },
			]),
		});

		const names = registeredToolNames(server);

		expect(names).toEqual(expect.arrayContaining([
			'stonewright-context-bootstrap',
			'stonewright-workflow-preflight',
			'stonewright-tool-profile',
			'stonewright-design-implementation-contract',
			'stonewright-elementor-v3-container-schema',
			'stonewright-elementor-v3-build-page-from-spec',
			'stonewright-elementor-v3-batch-mutate',
			'stonewright-wp-cli-batch-run',
		]));
		expect(names).not.toContain('stonewright-sandbox-write');
		expect(names).not.toContain('stonewright-memory-list');
		expect(names).not.toContain('stonewright-experimental-heavy-tool');
	});

	it('normalizes common compact profile aliases before filtering proxied tools', async () => {
		const server = await createMcpServer({
			env: {
				STONEWRIGHT_MCP_URL: 'https://example.com/wp-json/mcp/stonewright',
				WP_API_USERNAME: 'admin',
				WP_API_PASSWORD: 'pw',
				STONEWRIGHT_MCP_TOOL_PROFILE: 'elementor',
			},
			fetchImpl: stonewrightMcpFetch([
				{ name: 'stonewright-context-bootstrap' },
				{ name: 'stonewright-workflow-preflight' },
				{ name: 'stonewright-skills-get' },
				{ name: 'stonewright-elementor-v4-status' },
				{ name: 'stonewright-experimental-heavy-tool' },
			]),
		});

		const names = registeredToolNames(server);

		expect(names).toContain('stonewright-elementor-v4-status');
		expect(names).not.toContain('stonewright-experimental-heavy-tool');

		const tools = (server as { _registeredTools?: Record<string, { handler?: (input: unknown) => Promise<unknown> }> })._registeredTools ?? {};
		const response = await tools['stonewright-wordpress-mcp-status']?.handler?.({}) as {
			structuredContent?: {
				tool_profile?: string;
				startup_ready?: boolean;
			};
		};

		expect(response.structuredContent?.tool_profile).toBe('elementor-design');
		expect(response.structuredContent?.startup_ready).toBe(true);
	});

	it('keeps design fast-path tools in the essential proxied profile', async () => {
		const server = await createMcpServer({
			env: {
				STONEWRIGHT_MCP_URL: 'https://example.com/wp-json/mcp/stonewright',
				WP_API_USERNAME: 'admin',
				WP_API_PASSWORD: 'pw',
				STONEWRIGHT_MCP_TOOL_PROFILE: 'essential',
			},
			fetchImpl: stonewrightMcpFetch([
				{ name: 'stonewright-context-bootstrap' },
				{ name: 'stonewright-security-create-one-time-link' },
				{ name: 'stonewright-design-implementation-contract' },
				{ name: 'stonewright-media-list' },
				{ name: 'stonewright-media-upload' },
				{ name: 'stonewright-media-upload-batch' },
				{ name: 'stonewright-elementor-v3-get-kit-globals' },
				{ name: 'stonewright-elementor-v3-container-schema' },
				{ name: 'stonewright-elementor-v3-build-page-from-spec' },
				{ name: 'stonewright-content-bulk-upsert-posts' },
				{ name: 'stonewright-experimental-heavy-tool' },
			]),
		});

		const names = registeredToolNames(server);

		expect(names).toEqual(expect.arrayContaining([
			'stonewright-context-bootstrap',
			'stonewright-security-create-one-time-link',
			'stonewright-design-implementation-contract',
			'stonewright-media-list',
			'stonewright-media-upload-batch',
			'stonewright-elementor-v3-get-kit-globals',
			'stonewright-elementor-v3-container-schema',
			'stonewright-elementor-v3-build-page-from-spec',
			'stonewright-content-bulk-upsert-posts',
		]));
		expect(names).not.toContain('stonewright-media-upload');
		expect(names).not.toContain('stonewright-experimental-heavy-tool');
	});

	it('keeps media discovery visible in Elementor and content-model proxied profiles', async () => {
		const tools = [
			{ name: 'stonewright-context-bootstrap' },
			{ name: 'stonewright-workflow-preflight' },
			{ name: 'stonewright-tool-profile' },
			{ name: 'stonewright-skills-get' },
			{ name: 'stonewright-media-list' },
			{ name: 'stonewright-media-upload-batch' },
			{ name: 'stonewright-content-bulk-upsert-posts' },
			{ name: 'stonewright-elementor-v3-build-page-from-spec' },
			{ name: 'stonewright-experimental-heavy-tool' },
		];

		const elementorServer = await createMcpServer({
			env: {
				STONEWRIGHT_MCP_URL: 'https://example.com/wp-json/mcp/stonewright',
				WP_API_USERNAME: 'admin',
				WP_API_PASSWORD: 'pw',
				STONEWRIGHT_MCP_TOOL_PROFILE: 'elementor-design',
			},
			fetchImpl: stonewrightMcpFetch(tools),
		});
		const contentServer = await createMcpServer({
			env: {
				STONEWRIGHT_MCP_URL: 'https://example.com/wp-json/mcp/stonewright',
				WP_API_USERNAME: 'admin',
				WP_API_PASSWORD: 'pw',
				STONEWRIGHT_MCP_TOOL_PROFILE: 'content-model',
			},
			fetchImpl: stonewrightMcpFetch(tools),
		});

		expect(registeredToolNames(elementorServer)).toEqual(expect.arrayContaining([
			'stonewright-skills-get',
			'stonewright-media-list',
			'stonewright-media-upload-batch',
			'stonewright-elementor-v3-build-page-from-spec',
		]));
		expect(registeredToolNames(contentServer)).toEqual(expect.arrayContaining([
			'stonewright-skills-get',
			'stonewright-media-list',
			'stonewright-media-upload-batch',
			'stonewright-content-bulk-upsert-posts',
		]));
	});

	it('supports a low-tools proxied profile for strict client tool caps', async () => {
		const server = await createMcpServer({
			env: {
				STONEWRIGHT_MCP_URL: 'https://example.com/wp-json/mcp/stonewright',
				WP_API_USERNAME: 'admin',
				WP_API_PASSWORD: 'pw',
				STONEWRIGHT_MCP_TOOL_PROFILE: 'antigravity',
			},
			fetchImpl: stonewrightMcpFetch([
				...proxyToolNamesForProfile('low-tools').map((name) => ({ name })),
				{ name: 'stonewright-elementor-describe-widget' },
				{ name: 'stonewright-memory-list' },
				{ name: 'stonewright-sandbox-write' },
			]),
		});

		const names = registeredToolNames(server);
		const tools = (server as { _registeredTools?: Record<string, { handler?: (input: unknown) => Promise<unknown> }> })._registeredTools ?? {};
		const response = await tools['stonewright-wordpress-mcp-status']?.handler?.({}) as {
			structuredContent?: {
				tool_profile?: string;
				profile_expected_tool_count?: number;
				client_visible_expected_tool_count?: number;
				local_tool_names?: string[];
				tool_inventory?: {
					profile?: string;
					startup_budget?: { under_low_tools_cap?: boolean };
					direct_wp_cli_tool_names?: string[];
					direct_wp_cli_long_running_tool_names?: string[];
					proxied_profile_tool_groups?: Record<string, string[]>;
				};
			};
		};

		expect(response.structuredContent?.tool_profile).toBe('low-tools');
		expect(response.structuredContent?.profile_expected_tool_count).toBeLessThanOrEqual(24);
		expect(response.structuredContent?.client_visible_expected_tool_count).toBeLessThanOrEqual(30);
		expect(names.length).toBeLessThanOrEqual(30);
		expect(response.structuredContent?.local_tool_names).toEqual(expect.not.arrayContaining([
			'companion_wp_cli_run',
			'companion_wp_cli_batch_run',
			'stonewright-wp-cli-install',
		]));
		expect(response.structuredContent?.local_tool_names).toEqual(expect.arrayContaining([
			'stonewright-wp-cli-job-start',
			'stonewright-wp-cli-job-status',
		]));
		expect(response.structuredContent?.tool_inventory?.profile).toBe('low-tools');
		expect(response.structuredContent?.tool_inventory?.startup_budget?.under_low_tools_cap).toBe(true);
		expect(response.structuredContent?.tool_inventory?.direct_wp_cli_tool_names).toEqual(expect.arrayContaining([
			'stonewright-wp-cli-batch-run',
			'stonewright-wp-cli-job-start',
			'stonewright-wp-cli-job-status',
		]));
		expect(response.structuredContent?.tool_inventory?.direct_wp_cli_long_running_tool_names).toEqual([
			'stonewright-wp-cli-job-start',
			'stonewright-wp-cli-job-status',
		]);
		expect(response.structuredContent?.tool_inventory?.proxied_profile_tool_groups?.elementor_design).toContain('stonewright-elementor-v3-build-page-from-spec');
		expect(names).toEqual(expect.arrayContaining([
			'stonewright-setup-profile',
			'stonewright-wordpress-mcp-status',
			'stonewright-wp-cli-status',
			'stonewright-wp-cli-discover',
			'stonewright-wp-cli-run',
			'stonewright-wp-cli-batch-run',
			'stonewright-context-bootstrap',
			'stonewright-workflow-preflight',
			'stonewright-tool-profile',
			'stonewright-php-execute',
			'stonewright-skills-get',
			'stonewright-content-bulk-upsert-posts',
			'stonewright-media-upload-batch',
			'stonewright-design-implementation-contract',
			'stonewright-elementor-v3-get-kit-globals',
			'stonewright-elementor-v3-build-page-from-spec',
			'stonewright-elementor-v3-batch-mutate',
			'stonewright-gutenberg-apply-to-post',
			'stonewright-wp-cli-job-start',
			'stonewright-wp-cli-job-status',
		]));
		expect(names).not.toContain('companion_wp_cli_run');
		expect(names).not.toContain('companion_wp_cli_batch_run');
		expect(names).not.toContain('companion_wp_cli_install');
		expect(names).not.toContain('stonewright-wp-cli-install');
		expect(names).not.toContain('stonewright-elementor-describe-widget');
		expect(names).not.toContain('stonewright-memory-list');
		expect(names).not.toContain('stonewright-sandbox-write');
	});

	it('defaults proxied WordPress MCP tools to the essential profile', async () => {
		const server = await createMcpServer({
			env: {
				STONEWRIGHT_MCP_URL: 'https://example.com/wp-json/mcp/stonewright',
				WP_API_USERNAME: 'admin',
				WP_API_PASSWORD: 'pw',
			},
			fetchImpl: stonewrightMcpFetch([
				{ name: 'stonewright-context-bootstrap' },
				{ name: 'stonewright-workflow-preflight' },
				{ name: 'stonewright-tool-profile' },
				{ name: 'stonewright-design-implementation-contract' },
				{ name: 'stonewright-elementor-v3-build-page-from-spec' },
				{ name: 'stonewright-experimental-heavy-tool' },
			]),
		});

		const names = registeredToolNames(server);

		expect(names).toEqual(expect.arrayContaining([
			'stonewright-context-bootstrap',
			'stonewright-workflow-preflight',
			'stonewright-tool-profile',
			'stonewright-design-implementation-contract',
			'stonewright-elementor-v3-build-page-from-spec',
		]));
		expect(names).not.toContain('stonewright-experimental-heavy-tool');
	});

	it('reports proxied tool count after compact profile filtering', async () => {
		const server = await createMcpServer({
			env: {
				STONEWRIGHT_MCP_URL: 'https://example.com/wp-json/mcp/stonewright',
				WP_API_USERNAME: 'admin',
				WP_API_PASSWORD: 'pw',
				STONEWRIGHT_MCP_TOOL_PROFILE: 'essential',
			},
			fetchImpl: stonewrightMcpFetch([
				{ name: 'stonewright-context-bootstrap' },
				{ name: 'stonewright-workflow-preflight' },
				{ name: 'stonewright-tool-profile' },
				{ name: 'stonewright-wp-cli-status' },
				{ name: 'stonewright-experimental-heavy-tool' },
			]),
		});

		const tools = (server as { _registeredTools?: Record<string, { handler?: (input: unknown) => Promise<unknown> }> })._registeredTools ?? {};
		const response = await tools['stonewright-wordpress-mcp-status']?.handler?.({}) as {
			structuredContent?: {
				tool_profile?: string;
				remote_tool_count?: number;
				proxied_tool_count?: number;
				profile_filtered_tool_count?: number;
				profile_filtered_tool_names?: string[];
				startup_ready?: boolean;
				startup_required_tool_names?: string[];
				startup_missing_tool_names?: string[];
				companion_version?: string;
				expected_companion_package?: string;
				refresh_required_tool_names?: string[];
				local_recovery_tool_names?: string[];
				local_tool_names?: string[];
				recovery?: string[];
			};
		};

		expect(response.structuredContent?.tool_profile).toBe('essential');
		expect(response.structuredContent?.companion_version).toBe('1.0.0-alpha.62');
		expect(response.structuredContent?.expected_companion_package).toBe('https://github.com/cosmincraciun97/stonewright-wp-mcp/releases/download/v1.0.0-alpha.62/stonewright-companion-1.0.0-alpha.62.tgz');
		expect(response.structuredContent?.refresh_required_tool_names).toEqual([
			'stonewright-context-bootstrap',
			'stonewright-workflow-preflight',
			'stonewright-php-execute',
		]);
		expect(response.structuredContent?.remote_tool_count).toBe(5);
		expect(response.structuredContent?.proxied_tool_count).toBe(3);
		expect(response.structuredContent?.profile_filtered_tool_count).toBe(1);
		expect(response.structuredContent?.profile_filtered_tool_names).toEqual(['stonewright-experimental-heavy-tool']);
		expect(response.structuredContent?.startup_ready).toBe(false);
		expect(response.structuredContent?.startup_required_tool_names).toEqual([
			'stonewright-context-bootstrap',
			'stonewright-workflow-preflight',
			'stonewright-skills-get',
		]);
		expect(response.structuredContent?.startup_missing_tool_names).toEqual(['stonewright-skills-get']);
		expect(response.structuredContent?.local_recovery_tool_names).toEqual([
			'stonewright-setup-profile',
			'stonewright-wordpress-mcp-status',
			'stonewright-wp-cli-status',
			'stonewright-wp-cli-discover',
			'stonewright-wp-cli-run',
			'stonewright-wp-cli-batch-run',
			'stonewright-wp-cli-job-start',
			'stonewright-wp-cli-job-status',
			'stonewright-wp-cli-install',
		]);
		expect(response.structuredContent?.local_tool_names).toEqual(expect.arrayContaining([
			'stonewright-wp-cli-run',
			'stonewright-wp-cli-install',
			'companion_wp_cli_run',
			'companion_wp_cli_install',
		]));
		expect(response.structuredContent?.recovery).toContain('If a needed WordPress MCP tool is absent and profile_filtered_tool_count is greater than 0, switch STONEWRIGHT_MCP_TOOL_PROFILE to a narrower task profile or full, then restart the MCP session.');
		expect(response.structuredContent?.recovery).toContain('If startup_ready is false, update/enable the missing startup tools in the WordPress Stonewright plugin, then restart the MCP session.');
	});

	it('reports startup ready when compact first-call tools are proxied', async () => {
		const server = await createMcpServer({
			env: {
				STONEWRIGHT_MCP_URL: 'https://example.com/wp-json/mcp/stonewright',
				WP_API_USERNAME: 'admin',
				WP_API_PASSWORD: 'pw',
				STONEWRIGHT_MCP_TOOL_PROFILE: 'essential',
			},
			fetchImpl: stonewrightMcpFetch([
				{ name: 'stonewright-context-bootstrap' },
				{ name: 'stonewright-workflow-preflight' },
				{ name: 'stonewright-skills-get' },
			]),
		});

		const tools = (server as { _registeredTools?: Record<string, { handler?: (input: unknown) => Promise<unknown> }> })._registeredTools ?? {};
		const response = await tools['stonewright-wordpress-mcp-status']?.handler?.({}) as {
			structuredContent?: {
				startup_ready?: boolean;
				startup_missing_tool_names?: string[];
			};
		};

		expect(response.structuredContent?.startup_ready).toBe(true);
		expect(response.structuredContent?.startup_missing_tool_names).toEqual([]);
	});

	it('reports missing selected profile tools after startup tools are ready', async () => {
		const server = await createMcpServer({
			env: {
				STONEWRIGHT_MCP_URL: 'https://example.com/wp-json/mcp/stonewright',
				WP_API_USERNAME: 'admin',
				WP_API_PASSWORD: 'pw',
				STONEWRIGHT_MCP_TOOL_PROFILE: 'elementor-design',
			},
			fetchImpl: stonewrightMcpFetch([
				{ name: 'stonewright-context-bootstrap' },
				{ name: 'stonewright-workflow-preflight' },
				{ name: 'stonewright-tool-profile' },
				{ name: 'stonewright-skills-get' },
				{ name: 'stonewright-site-info' },
				{ name: 'stonewright-site-plugins-list' },
				{ name: 'stonewright-design-implementation-contract' },
				{ name: 'stonewright-elementor-v3-container-schema' },
			]),
		});

		const tools = (server as { _registeredTools?: Record<string, { handler?: (input: unknown) => Promise<unknown> }> })._registeredTools ?? {};
		const response = await tools['stonewright-wordpress-mcp-status']?.handler?.({}) as {
			structuredContent?: {
				startup_ready?: boolean;
				profile_expected_tool_count?: number;
				profile_missing_tool_names?: string[];
				recovery?: string[];
			};
		};

		expect(response.structuredContent?.startup_ready).toBe(true);
		expect(response.structuredContent?.profile_expected_tool_count).toBeGreaterThan(20);
		expect(response.structuredContent?.profile_missing_tool_names).toContain('stonewright-elementor-v3-build-page-from-spec');
		expect(response.structuredContent?.profile_missing_tool_names).not.toContain('stonewright-wp-cli-batch-run');
		expect(response.structuredContent?.recovery).toContain('If profile_missing_tool_names is not empty, update or enable those WordPress Stonewright tools, or switch STONEWRIGHT_MCP_TOOL_PROFILE to full for specialist recovery.');
	});
});

function registeredToolNames(server: unknown): string[] {
	return Object.keys((server as { _registeredTools?: Record<string, unknown> })._registeredTools ?? {});
}

function registeredPromptNames(server: unknown): string[] {
	return Object.keys((server as { _registeredPrompts?: Record<string, unknown> })._registeredPrompts ?? {});
}

function stonewrightMcpFetch(tools: Array<{ name: string; description?: string; inputSchema?: Record<string, unknown> }>): typeof fetch {
	return (_url: string | URL | Request, init?: RequestInit): Promise<Response> => {
		const url = String(_url);
		if (url.endsWith('/wp-json/stonewright/v1/skills?mode=prompt&enabled_only=1')) {
			return Promise.resolve(
				new Response(JSON.stringify({
					skills: [
						{
							slug: 'figma-quality-rules',
							title: 'Figma Quality Rules',
							description: 'Use after Figma import.',
							content: '# Figma Quality Rules\n\nBuild one section at a time.',
						},
					],
				}), { headers: { 'content-type': 'application/json' } }),
			);
		}

		const body = JSON.parse(String(init?.body ?? '{}')) as { method?: string };
		if (body.method === 'initialize') {
			return Promise.resolve(
				new Response(JSON.stringify({ jsonrpc: '2.0', id: 1, result: { protocolVersion: '2025-06-18' } }), {
					headers: { 'mcp-session-id': 'session-1', 'content-type': 'application/json' },
				}),
			);
		}
		if (body.method === 'notifications/initialized') {
			return Promise.resolve(new Response('', { status: 202 }));
		}
		if (body.method === 'tools/list') {
			return Promise.resolve(
				new Response(JSON.stringify({
					jsonrpc: '2.0',
					id: 2,
					result: {
						tools: tools.map((tool) => ({
							description: 'Proxied Stonewright test tool.',
							inputSchema: { type: 'object', properties: {} },
							...tool,
						})),
					},
				}), { headers: { 'content-type': 'application/json' } }),
			);
		}
		return Promise.resolve(
			new Response(JSON.stringify({ jsonrpc: '2.0', id: 3, result: {} }), {
				headers: { 'content-type': 'application/json' },
			}),
		);
	};
}
