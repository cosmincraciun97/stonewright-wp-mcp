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
		expect(profile.mcp_server.args).toEqual([
			'-y',
			'--package',
			'https://github.com/cosmincraciun97/stonewright-wp-mcp/releases/download/v1.0.0-alpha.62/stonewright-companion-1.0.0-alpha.62.tgz',
			'stonewright-mcp',
		]);
		expect(profile.mcp_server.env).toMatchObject({
			STONEWRIGHT_WP_URL: 'http://mcp-test.local',
			STONEWRIGHT_WP_ROOT: '/Users/me/Local Sites/mcp-test/app/public',
			STONEWRIGHT_WP_APP_PASSWORD_AUTO: 'local-only',
			STONEWRIGHT_MCP_TOOL_PROFILE: 'essential',
		});
		expect(profile.first_calls).toEqual([
			'stonewright-context-bootstrap',
			'stonewright-workflow-preflight',
		]);
		expect(profile.tool_visibility_checks).toEqual([
			'stonewright-context-bootstrap',
			'stonewright-workflow-preflight',
			'stonewright-tool-profile',
			'stonewright-php-execute',
			'stonewright-skills-get',
			'stonewright-wordpress-mcp-status',
			'stonewright-wp-cli-status',
			'stonewright-wp-cli-discover',
			'stonewright-wp-cli-run',
			'stonewright-wp-cli-batch-run',
			'stonewright-wp-cli-job-start',
			'stonewright-wp-cli-job-status',
			'stonewright-wp-cli-install',
		]);
		expect(profile.tool_inventory.profile).toBe('essential');
		expect(profile.tool_inventory.first_call_tool_names).toEqual([
			'stonewright-context-bootstrap',
			'stonewright-workflow-preflight',
		]);
		expect(profile.tool_inventory.direct_wp_cli_tool_names).toEqual(expect.arrayContaining([
			'stonewright-wp-cli-status',
			'stonewright-wp-cli-discover',
			'stonewright-wp-cli-run',
			'stonewright-wp-cli-batch-run',
			'stonewright-wp-cli-job-start',
			'stonewright-wp-cli-job-status',
			'stonewright-wp-cli-install',
		]));
		expect(profile.tool_inventory.proxied_profile_tool_groups.elementor_design).toContain('stonewright-elementor-v3-build-page-from-spec');
		expect(profile.tool_inventory.proxied_profile_tool_groups.runtime).toContain('stonewright-php-execute');
		expect(profile.notes.join('\n')).toContain('Use stonewright-wordpress-mcp-status if proxied WordPress tools are missing');
		expect(profile.notes.join('\n')).toContain('Verify the MCP tool list includes stonewright-context-bootstrap before starting WordPress work');
		expect(profile.notes.join('\n')).toContain('Use fast_path.tool_profile from stonewright-workflow-preflight before making a separate stonewright-tool-profile call');
		expect(profile.notes.join('\n')).toContain('STONEWRIGHT_MCP_TOOL_PROFILE=essential keeps new MCP sessions compact');
		expect(profile.notes.join('\n')).toContain('Profile aliases such as elementor, design, acf, cpt-ui, fse, and wp cli normalize to compact canonical profiles.');
		expect(profile.notes.join('\n')).toContain('Leave PORT unset for stdio-only MCP clients. To run the optional HTTP bridge, set STONEWRIGHT_HTTP_ENABLE=1 plus PORT.');
		expect(profile.notes.join('\n')).toContain('GitHub release tarball');
		expect(profile.notes.join('\n')).toContain('npx -y --package');
		expect(profile.notes.join('\n')).toContain('Do not point IDE MCP configs at companion/dist/index.js');
		expect(profile.notes.join('\n')).toContain('For source development, use npm --prefix <repo>/companion run mcp:source');
		expect(profile.notes.join('\n')).toContain('Do not configure generic WordPress MCP adapters such as @automattic/mcp-wordpress-remote');
		expect(profile.notes.join('\n')).toContain('Use stonewright-php-execute for direct full WordPress runtime snippets');
		expect(profile.notes.join('\n')).toContain('Do not treat local client skills or repository files as a substitute for live Stonewright MCP tools');
		expect(profile.notes.join('\n')).toContain('Do not inspect private AI-client config files');
		expect(profile.notes.join('\n')).toContain('Do not create scratch scripts such as query-mcp.js or run-ability.js');
		expect(profile.notes.join('\n')).toContain('Do not create helper JSON argument files such as bootstrap-args.json, cli_command.json, or get_structure.json');
		expect(profile.notes.join('\n')).toContain('Do not launch the Stonewright companion from ad hoc shell scripts such as query-local-stonewright.js');
		expect(profile.notes.join('\n')).toContain('Do not create or modify action scripts such as run-loop-mutate.js or run-bootstrap-and-mutate.js');
		expect(profile.notes.join('\n')).toContain('Do not inspect plugin or companion source code to reverse-engineer tool schemas');
		expect(profile.notes.join('\n')).toContain('Do not hand-roll JSON-RPC calls');
		expect(profile.notes.join('\n')).toContain('Do not call /wp-json/stonewright/v1/abilities/run from shell as an MCP workaround');
		expect(profile.agent_do_not_use).toContain('Do not run wp cli info, wp plugin activate, wp option update, or other wp commands in a normal shell as Stonewright recovery.');
		expect(profile.agent_do_not_use).toContain('Do not inspect private AI-client config files to find or call Stonewright.');
		expect(profile.agent_do_not_use).toContain('Do not create scratch scripts such as query-mcp.js or run-ability.js to bypass the MCP client tool surface.');
		expect(profile.agent_do_not_use).toContain('Do not create helper JSON argument files such as bootstrap-args.json, cli_command.json, or get_structure.json to bypass typed MCP tool input.');
		expect(profile.agent_do_not_use).toContain('Do not launch the Stonewright companion from ad hoc shell scripts such as query-local-stonewright.js to bypass the MCP client tool list.');
		expect(profile.agent_do_not_use).toContain('Do not create or modify action scripts such as run-loop-mutate.js or run-bootstrap-and-mutate.js to bypass typed Stonewright tool calls.');
		expect(profile.agent_do_not_use).toContain('Do not inspect plugin or companion source code to reverse-engineer tool schemas during WordPress implementation tasks.');
		expect(profile.agent_do_not_use).toContain('Do not hand-roll JSON-RPC calls to /mcp or /wp-json/mcp/stonewright as an MCP workaround.');
		expect(profile.agent_use_instead).toEqual(expect.arrayContaining([
			'stonewright-php-execute',
			'stonewright-wp-cli-status',
			'stonewright-wp-cli-discover',
			'stonewright-wp-cli-run',
			'stonewright-wp-cli-batch-run',
			'stonewright-wp-cli-job-start',
			'stonewright-wp-cli-job-status',
			'stonewright-wp-cli-install',
		]));
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
		expect(profile.install_command).toBe('npm install -g https://github.com/cosmincraciun97/stonewright-wp-mcp/releases/download/v1.0.0-alpha.62/stonewright-companion-1.0.0-alpha.62.tgz');
		expect(profile.notes.join('\n')).toContain('No shell script wrapper required');
	});

	it('preserves a low-tools companion profile for strict client caps', () => {
		const profile = buildSetupProfile(
			{
				STONEWRIGHT_WP_URL: 'http://mcp-test.local',
				STONEWRIGHT_MCP_TOOL_PROFILE: 'low-tools',
			},
			'win32',
		);

		expect(profile.mcp_server.env.STONEWRIGHT_MCP_TOOL_PROFILE).toBe('low-tools');
		expect(profile.notes.join('\n')).toContain('Use STONEWRIGHT_MCP_TOOL_PROFILE=low-tools for Antigravity, Gemini API, or other strict tool-cap clients');
		expect(profile.notes.join('\n')).toContain('php-execute plus direct WP-CLI batch and background-job tools stay visible');
		expect(profile.tool_visibility_checks).not.toContain('stonewright-wp-cli-install');
		expect(profile.agent_use_instead).not.toContain('stonewright-wp-cli-install');
		expect(profile.tool_visibility_checks).toContain('stonewright-wp-cli-batch-run');
		expect(profile.tool_visibility_checks).toContain('stonewright-wp-cli-job-start');
		expect(profile.tool_visibility_checks).toContain('stonewright-wp-cli-job-status');
		expect(profile.agent_use_instead).toContain('stonewright-wp-cli-job-start');
		expect(profile.agent_use_instead).toContain('stonewright-wp-cli-job-status');
		expect(profile.tool_inventory.profile).toBe('low-tools');
		expect(profile.tool_inventory.startup_budget.under_low_tools_cap).toBe(true);
		expect(profile.tool_inventory.direct_wp_cli_tool_names).not.toContain('stonewright-wp-cli-install');
		expect(profile.tool_inventory.direct_wp_cli_long_running_tool_names).toEqual([
			'stonewright-wp-cli-job-start',
			'stonewright-wp-cli-job-status',
		]);
	});

	it('normalizes legacy proxy profile input into the emitted tool profile', () => {
		const profile = buildSetupProfile(
			{
				STONEWRIGHT_WP_URL: 'http://mcp-test.local',
				STONEWRIGHT_MCP_PROXY_PROFILE: 'antigravity',
			},
			'win32',
		);

		expect(profile.mcp_server.env.STONEWRIGHT_MCP_TOOL_PROFILE).toBe('antigravity');
		expect(profile.tool_visibility_checks).not.toContain('stonewright-wp-cli-install');
		expect(profile.tool_visibility_checks).toContain('stonewright-wp-cli-job-start');
		expect(profile.tool_visibility_checks).toContain('stonewright-wp-cli-job-status');
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
