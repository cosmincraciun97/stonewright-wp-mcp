import { describe, expect, it } from 'vitest';
import {
	runWpCli,
	runWpCliBatch,
	runWpCliOperator,
	startWpCliJob,
	validateWpCliBatchCommand,
	validateWpCliCommand,
	type ExecFileRunner,
} from '../src/wp-cli.js';

const okRunner: ExecFileRunner = () => Promise.resolve({ stdout: '', stderr: '', exitCode: 0 });
const env = { STONEWRIGHT_WP_CLI_BIN: 'wp' } as NodeJS.ProcessEnv;

describe('WP-CLI command deny-list', () => {
	const blocked: Array<[string, string[]]> = [
		['plugin install', ['plugin', 'install', 'some-plugin']],
		['plugin install --activate', ['plugin', 'install', 'some-plugin', '--activate']],
		['plugin update', ['plugin', 'update', '--all']],
		['plugin delete', ['plugin', 'delete', 'some-plugin']],
		['plugin activate', ['plugin', 'activate', 'some-plugin']],
		['plugin uninstall', ['plugin', 'uninstall', 'some-plugin']],
		['theme install', ['theme', 'install', 'some-theme']],
		['theme update', ['theme', 'update', '--all']],
		['theme delete', ['theme', 'delete', 'some-theme']],
		['theme activate', ['theme', 'activate', 'some-theme']],
		['db query', ['db', 'query', 'SELECT 1']],
		['db import', ['db', 'import', 'dump.sql']],
		['db reset', ['db', 'reset', '--yes']],
		['db drop', ['db', 'drop', '--yes']],
		['db export', ['db', 'export', 'dump.sql']],
		['db cli', ['db', 'cli']],
		['bare db', ['db']],
		['config get', ['config', 'get', 'DB_PASSWORD']],
		['config set', ['config', 'set', 'WP_DEBUG', 'true']],
		['user create', ['user', 'create', 'x', 'x@example.test', '--role=administrator']],
		['user update', ['user', 'update', '1', '--user_pass=secret']],
		['user delete', ['user', 'delete', '2', '--yes']],
		['user add-role', ['user', 'add-role', '2', 'administrator']],
		['user set-role', ['user', 'set-role', '2', 'administrator']],
		['user meta update', ['user', 'meta', 'update', '2', 'wp_capabilities', '{}']],
		['cron event run', ['cron', 'event', 'run', 'some_hook']],
		['search-replace', ['search-replace', 'old', 'new']],
		['search-replace dry-run=false', ['search-replace', 'old', 'new', '--dry-run=false']],
		['core download', ['core', 'download']],
		['core update', ['core', 'update']],
		['package install', ['package', 'install', 'x']],
		['scaffold plugin', ['scaffold', 'plugin', 'x']],
		['language install', ['language', 'core', 'install', 'ro_RO']],
		['cli update', ['cli', 'update']],
		['role create', ['role', 'create', 'x', 'X']],
		['cap add', ['cap', 'add', 'editor', 'manage_options']],
		['sensitive option update', ['option', 'update', 'default_role', 'administrator']],
		['option update users_can_register', ['option', 'update', 'users_can_register', '1']],
		['option update plugin setting', ['option', 'update', 'stonewright_mode', 'development']],
		['option patch', ['option', 'patch', 'update', 'wp_user_roles', 'administrator', 'x']],
		['--config flag', ['post', 'list', '--config=other.yml']],
		['--ssh flag', ['post', 'list', '--ssh=host']],
	];

	it.each(blocked)('refuses %s', (_label, argv) => {
		expect(() => validateWpCliCommand(argv)).toThrow(/blocked/i);
	});

	const bypassShapes: Array<[string, string[]]> = [
		['leading @alias', ['@prod', 'plugin', 'install', 'x']],
		['leading @alias before a global flag', ['@prod', '--path=/srv/site', 'eval', '1']],
		['global flags before the command', ['--path=/srv/site', '--url=https://example.test', '--user=1', 'db', 'query', 'SELECT 1']],
		['global flag with a separate value', ['--path', '/srv/site', 'plugin', 'install', 'x']],
		['--user with a separate value', ['--user', '1', 'user', 'create', 'x', 'x@example.test']],
		['double-quoted group', ['"plugin"', '"install"', 'x']],
		['single-quoted group', ["'eval'", 'echo 1;']],
		['quoted group after flags', ['--path=/srv/site', "'eval-file'", 'x.php']],
		['upper case', ['PLUGIN', 'Install', 'x']],
		['padded tokens', ['  plugin  ', ' install ', 'x']],
		['command in one token', ['plugin install x']],
		['quoted command in one token', ['"db query" "SELECT 1"']],
		['flags between words', ['plugin', '--format=json', 'install', 'x']],
		['flag after the command words', ['cron', 'event', '--quiet', 'run', 'some_hook']],
		['alias and flags and quoting together', ['@staging', '--url=https://a.example.test', "'Plugin'", '"Update"', '--all']],
		['trailing alias-like value does not hide group', ['--skip-plugins', 'plugin', 'install', 'x']],
	];

	it.each(bypassShapes)('refuses the %s shape', (_label, argv) => {
		expect(() => validateWpCliCommand(argv)).toThrow(/blocked/i);
	});

	const allowed: Array<[string, string[]]> = [
		['plugin list', ['plugin', 'list', '--format=json']],
		['plugin deactivate', ['plugin', 'deactivate', 'some-plugin']],
		['theme list', ['theme', 'list']],
		['db check', ['db', 'check']],
		['user list', ['user', 'list', '--format=json']],
		['user get', ['user', 'get', '1']],
		['cron event list', ['cron', 'event', 'list']],
		['search-replace --dry-run', ['search-replace', 'old', 'new', '--dry-run']],
		['post create', ['post', 'create', '--post_title=Home']],
		['option get', ['option', 'get', 'home']],
		['ordinary option update', ['option', 'update', 'blogdescription', 'Tagline']],
		['alias with a read command', ['@staging', 'post', 'list']],
		['global flags with a read command', ['--path=/srv/site', '--url=https://example.test', 'post', 'list']],
		['core version', ['core', 'version']],
		['cache flush', ['cache', 'flush']],
		['elementor flush-css', ['elementor', 'flush-css']],
	];

	it.each(allowed)('keeps allowing %s', (_label, argv) => {
		expect(() => validateWpCliCommand(argv)).not.toThrow();
	});

	it('returns trimmed original-case tokens', () => {
		expect(validateWpCliCommand([' post ', 'list'])).toEqual(['post', 'list']);
	});

	it('applies the deny-list to every command of a job batch', () => {
		expect(() => startWpCliJob({ commands: [['post', 'list'], ['plugin', 'install', 'x']] }, okRunner, env)).toThrow(/blocked/i);
	});

	it('applies the deny-list to a single-command job', () => {
		expect(() => startWpCliJob({ command: ['@prod', 'db', 'query', 'SELECT 1'] }, okRunner, env)).toThrow(/blocked/i);
	});
});

describe('WP-CLI batch allowlist', () => {
	const allowed: Array<[string, string[]]> = [
		['post list', ['post', 'list', '--format=json']],
		['post get', ['post', 'get', '42', '--format=json']],
		['post meta list', ['post', 'meta', 'list', '42', '--format=json']],
		['term list', ['term', 'list', 'category']],
		['option get', ['option', 'get', 'home']],
		['plugin list', ['plugin', 'list', '--format=json']],
		['theme list', ['theme', 'list']],
		['user list', ['user', 'list']],
		['core version', ['core', 'version']],
		['db check', ['db', 'check']],
		['cron event list', ['cron', 'event', 'list']],
		['cache flush', ['cache', 'flush']],
		['rewrite flush', ['rewrite', 'flush']],
		['elementor flush-css', ['elementor', 'flush-css']],
		['search-replace --dry-run', ['search-replace', 'old', 'new', '--dry-run']],
		['mixed case and padding', [' Post ', 'LIST']],
	];

	it.each(allowed)('allows %s', (_label, argv) => {
		expect(() => validateWpCliBatchCommand(argv)).not.toThrow();
	});

	const refused: Array<[string, string[]]> = [
		['post create', ['post', 'create', '--post_title=Home']],
		['post update', ['post', 'update', '42', '--post_status=publish']],
		['post delete', ['post', 'delete', '42', '--force']],
		['post meta update', ['post', 'meta', 'update', '42', 'k', 'v']],
		['term create', ['term', 'create', 'category', 'Name']],
		['option update', ['option', 'update', 'blogdescription', 'x']],
		['plugin install', ['plugin', 'install', 'x']],
		['db query', ['db', 'query', 'SELECT 1']],
		['user create', ['user', 'create', 'x', 'x@example.test']],
		['user meta update', ['user', 'meta', 'update', '2', 'wp_capabilities', '{}']],
		['search-replace', ['search-replace', 'old', 'new']],
		['eval', ['eval', 'echo 1;']],
		['unknown group', ['some-plugin-command', 'run']],
		['leading alias', ['@prod', 'post', 'list']],
		['leading global flag', ['--path=/srv/site', 'post', 'list']],
		['flag with a separate value before the command', ['--user', '1', 'post', 'create']],
		['quoted write', ['"post"', '"create"']],
		['one-token write', ['post create --post_title=x']],
		['flags between words', ['post', '--format=json', 'create']],
	];

	it.each(refused)('refuses %s', (_label, argv) => {
		expect(() => validateWpCliBatchCommand(argv)).toThrow(/blocked|read-oriented|not allowed/i);
	});

	it('refuses the whole batch before any command runs', async () => {
		const calls: string[][] = [];
		const runner: ExecFileRunner = (_file, args) => {
			calls.push(args);
			return Promise.resolve({ stdout: '', stderr: '', exitCode: 0 });
		};

		await expect(
			runWpCliBatch({ commands: [['post', 'list'], ['post', 'create', '--post_title=x'], ['post', 'list']] }, runner, env),
		).rejects.toThrow(/read-oriented|not allowed|blocked/i);
		expect(calls).toHaveLength(0);
	});

	it('runs a batch of allowed read commands', async () => {
		const calls: string[][] = [];
		const runner: ExecFileRunner = (_file, args) => {
			calls.push(args);
			return Promise.resolve({ stdout: '[]', stderr: '', exitCode: 0 });
		};

		const result = await runWpCliBatch(
			{ commands: [['post', 'list', '--format=json'], ['option', 'get', 'home']] },
			runner,
			env,
		);

		expect(result.ok).toBe(true);
		expect(calls).toHaveLength(2);
	});

	it('leaves the job queue on the general deny-list so long write batches still run', async () => {
		const calls: string[][] = [];
		const runner: ExecFileRunner = (_file, args) => {
			calls.push(args);
			return Promise.resolve({ stdout: '', stderr: '', exitCode: 0 });
		};

		const status = startWpCliJob({ commands: [['post', 'create', '--post_title=x'], ['term', 'create', 'category', 'Name']] }, runner, env);
		expect(status.status).toBe('running');
		await new Promise((resolve) => setTimeout(resolve, 50));
		expect(calls).toHaveLength(2);
	});
});

describe('WP-CLI operator runner', () => {
	it('runs the operator tooling commands the agent runner refuses', async () => {
		const calls: string[][] = [];
		const runner: ExecFileRunner = (_file, args) => {
			calls.push(args);
			return Promise.resolve({ stdout: 'secret-value', stderr: '', exitCode: 0 });
		};

		const result = await runWpCliOperator({ command: ['user', 'application-password', 'create', 'admin', 'Companion', '--porcelain'] }, runner, env);

		expect(result.ok).toBe(true);
		expect(calls).toHaveLength(1);
		await expect(runWpCli({ command: ['user', 'application-password', 'create', 'admin', 'Companion'] }, runner, env)).rejects.toThrow(/blocked/i);
	});

	it('still refuses the code-running entry points, however they are spelled', async () => {
		for (const command of [['eval', 'echo 1;'], ['@prod', '--path=/srv/site', "'eval-file'", 'x.php'], ['post', 'list', '--exec=echo 1;'], ['package', 'install', 'x']]) {
			await expect(runWpCliOperator({ command }, okRunner, env)).rejects.toThrow(/blocked/i);
		}
	});
});
