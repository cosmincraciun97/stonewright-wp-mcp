import { describe, expect, it } from 'vitest';
import { existsSync, mkdirSync, mkdtempSync, readFileSync, rmSync, writeFileSync } from 'node:fs';
import { tmpdir } from 'node:os';
import { join, resolve } from 'node:path';
import {
	buildWpCliArgs,
	runWpCli,
	validateWpCliCommand,
	wpCliInstall,
	wpCliDiscover,
	wpCliEnsureReady,
	wpCliStatus,
	type ExecFileRunner,
	type WpCliResult,
} from '../src/wp-cli.js';

describe('WP-CLI runner', () => {
	it('builds argv tokens for write commands without using a shell string', () => {
		const args = buildWpCliArgs({
			command: ['post', 'create', '--post_type=page', '--post_title=Home', '--porcelain'],
			path: 'D:/Sites/example',
			url: 'https://example.test',
			user: 'admin',
		});

		expect(args).toEqual([
			'--path=D:/Sites/example',
			'--url=https://example.test',
			'--user=admin',
			'post',
			'create',
			'--post_type=page',
			'--post_title=Home',
			'--porcelain',
		]);
	});

	it('uses the typed wp_cli_context enum before legacy custom context strings', () => {
		expect(buildWpCliArgs({
			command: ['post', 'list'],
			wp_cli_context: 'admin',
		})).toEqual(['--context=admin', 'post', 'list']);

		expect(buildWpCliArgs({
			command: ['post', 'list'],
			wp_cli_context: 'frontend',
			context: 'custom-loader',
		})).toEqual(['--context=frontend', 'post', 'list']);

		expect(() => buildWpCliArgs({
			command: ['post', 'list'],
			wp_cli_context: 'not-real' as 'admin',
		})).toThrow(/wp_cli_context/i);
	});

	it('blocks arbitrary PHP and interactive shell entry points', () => {
		expect(() => validateWpCliCommand(['eval', 'echo 1;'])).toThrow(/blocked/i);
		expect(() => validateWpCliCommand(['eval-file', 'script.php'])).toThrow(/blocked/i);
		expect(() => validateWpCliCommand(['shell'])).toThrow(/blocked/i);
		expect(() => validateWpCliCommand(['package', 'install', 'x'])).toThrow(/blocked/i);
		expect(() => validateWpCliCommand(['post', 'list', '--exec=echo 1;'])).toThrow(/blocked/i);
		expect(() => validateWpCliCommand(['post', 'list', '--require=bootstrap.php'])).toThrow(/blocked/i);
		expect(() => validateWpCliCommand(['option', 'get', 'home', '--exec=phpinfo()'])).toThrow(/blocked/i);
		expect(() => validateWpCliCommand(['plugin', 'list', '--require=evil.php'])).toThrow(/blocked/i);
	});

	it('allows tokenized read-only WP-CLI examples', () => {
		expect(validateWpCliCommand(['core', 'version'])).toEqual(['core', 'version']);
		expect(validateWpCliCommand(['plugin', 'list', '--format=json'])).toEqual([
			'plugin',
			'list',
			'--format=json',
		]);
		expect(validateWpCliCommand(['option', 'get', 'home'])).toEqual(['option', 'get', 'home']);
	});

	it('runs through execFile options with shell disabled', async () => {
		const calls: Array<{ file: string; args: string[]; shell: unknown }> = [];
		const runner: ExecFileRunner = (file, args, options) => {
			calls.push({ file, args, shell: options.shell });
			return Promise.resolve({ stdout: '42\n', stderr: '', exitCode: 0 });
		};

		const result = await runWpCli(
			{
				command: ['post', 'create', '--post_type=page', '--post_title=Home', '--porcelain'],
				path: process.cwd(),
			},
			runner,
			{ STONEWRIGHT_WP_CLI_BIN: 'wp' } as NodeJS.ProcessEnv,
		);

		expect(result.ok).toBe(true);
		expect(result.stdout).toBe('42\n');
		expect(calls).toHaveLength(1);
		expect(calls[0]?.file).toBe('wp');
		expect(calls[0]?.args).toContain('post');
		expect(calls[0]?.shell).toBe(false);
	});

	it('can summarize a single command response for token efficiency', async () => {
		const runner: ExecFileRunner = () => Promise.resolve({
			stdout: JSON.stringify({ id: 42, payload: 'x'.repeat(4000) }),
			stderr: 'notice '.repeat(50),
			exitCode: 0,
		});

		const result = await runWpCli(
			{
				command: ['post', 'get', '42', '--format=json'],
				parseJson: true,
				responseMode: 'summary',
			},
			runner,
			{ STONEWRIGHT_WP_CLI_BIN: 'wp' } as NodeJS.ProcessEnv,
		);

		expect(result).toMatchObject({
			ok: true,
			available: true,
			exit_code: 0,
			stderr_bytes: 350,
		});
		expect(typeof result.stdout_bytes).toBe('number');
		expect(result).not.toHaveProperty('stdout');
		expect(result).not.toHaveProperty('stderr');
		expect(result).toHaveProperty('parsed_json');
	});

	it('auto-discovers LocalWP PHP and WP-CLI phar when wp is not on PATH', async () => {
		const temp = mkdtempSync(join(tmpdir(), 'stonewright-wpcli-'));
		try {
			const wpRoot = join(temp, 'workspace', 'site', 'app', 'public');
			const pharPath = join(
				temp,
				'workspace',
				'LocalWP',
				'resources',
				'extraResources',
				'bin',
				'wp-cli',
				'wp-cli.phar',
			);
			const phpPath = join(
				temp,
				'roaming',
				'Local',
				'lightning-services',
				'php-8.2.29+0',
				'bin',
				'win64',
				'php.exe',
			);
			mkdirSync(wpRoot, { recursive: true });
			mkdirSync(resolve(pharPath, '..'), { recursive: true });
			mkdirSync(resolve(phpPath, '..'), { recursive: true });
			writeFileSync(pharPath, 'wp-cli-phar');
			writeFileSync(phpPath, 'php-bin');

			const calls: Array<{ file: string; args: string[] }> = [];
			const runner: ExecFileRunner = (file, args) => {
				calls.push({ file, args });
				return Promise.resolve({ stdout: '{}', stderr: '', exitCode: 0 });
			};

			const result = await runWpCli(
				{
					command: ['cli', 'info', '--format=json'],
					path: wpRoot,
				},
				runner,
				{
					APPDATA: join(temp, 'roaming'),
					STONEWRIGHT_WP_ROOT: wpRoot,
				} as NodeJS.ProcessEnv,
			);

			expect(result.ok).toBe(true);
			expect(calls[0]?.file).toBe(phpPath);
			expect(calls[0]?.args[0]).toBe(pharPath);
			expect(calls[0]?.args).toContain('cli');
			expect(result.command[0]).toBe(phpPath);
		} finally {
			rmSync(temp, { recursive: true, force: true });
		}
	});

	it('discovers the LocalWP PHP binary from the site php.ini extension_dir', async () => {
		const temp = mkdtempSync(join(tmpdir(), 'stonewright-wpcli-ini-phpbin-'));
		try {
			const wpRoot = join(temp, 'site', 'app', 'public');
			const pharPath = join(temp, 'LocalWP', 'resources', 'extraResources', 'bin', 'wp-cli', 'wp-cli.phar');
			const phpPath = join(temp, 'php-service', 'bin', 'win64', 'php.exe');
			const extDir = join(temp, 'php-service', 'bin', 'win64', 'ext');
			const iniPath = join(temp, 'site', 'conf', 'php', 'php.ini');
			mkdirSync(wpRoot, { recursive: true });
			mkdirSync(resolve(pharPath, '..'), { recursive: true });
			mkdirSync(extDir, { recursive: true });
			mkdirSync(resolve(iniPath, '..'), { recursive: true });
			writeFileSync(pharPath, 'wp-cli-phar');
			writeFileSync(phpPath, 'php-bin');
			writeFileSync(iniPath, `extension_dir="${extDir.replaceAll('\\', '/')}"\nextension=php_mysqli.dll\n`);

			const calls: Array<{ file: string; args: string[] }> = [];
			const runner: ExecFileRunner = (file, args) => {
				calls.push({ file, args });
				return Promise.resolve({ stdout: '{}', stderr: '', exitCode: 0 });
			};

			await runWpCli(
				{
					command: ['cli', 'info', '--format=json'],
					path: wpRoot,
				},
				runner,
				{ STONEWRIGHT_WP_ROOT: wpRoot } as NodeJS.ProcessEnv,
			);

			expect(calls[0]?.file).toBe(phpPath);
			expect(calls[0]?.args).toContain(pharPath);
		} finally {
			rmSync(temp, { recursive: true, force: true });
		}
	});

	it('returns an actionable mysqli diagnostic when selected PHP cannot boot WordPress', async () => {
		const runner: ExecFileRunner = () => Promise.resolve({
			stdout: '',
			stderr: 'Error: Your PHP installation appears to be missing the MySQL extension which is required by WordPress. Please check that the mysqli PHP extension is installed and enabled.',
			exitCode: 1,
		});

		const result = await runWpCli(
			{
				command: ['plugin', 'list'],
				path: process.cwd(),
				responseMode: 'summary',
			},
			runner,
			{ STONEWRIGHT_WP_CLI_BIN: 'php-without-mysqli' } as NodeJS.ProcessEnv,
		);

		expect(result.ok).toBe(false);
		expect(result.diagnostics).toEqual([
			expect.objectContaining({
				code: 'php_missing_mysqli',
				hints: expect.arrayContaining([
					expect.stringContaining('STONEWRIGHT_WP_CLI_PHP_BIN'),
					expect.stringContaining('stonewright-wp-cli-status'),
				]),
			}),
		]);
	});

	it('warns when WP-CLI launches with no php.ini because WordPress commands may still fail', async () => {
		const runner: ExecFileRunner = () => Promise.resolve({
			stdout: JSON.stringify({
				php_binary_path: 'C:\\Users\\me\\AppData\\Roaming\\Local\\lightning-services\\php-8.2.29+0\\bin\\win64\\php.exe',
				php_ini_used: false,
				wp_cli_version: '2.12.0',
			}),
			stderr: '',
			exitCode: 0,
		});

		const result = await wpCliStatus(
			{
				path: process.cwd(),
			},
			runner,
			{
				STONEWRIGHT_WP_CLI_BIN: 'php-without-ini',
				STONEWRIGHT_WP_ROOT: process.cwd(),
			} as NodeJS.ProcessEnv,
		);

		expect(result.ok).toBe(true);
		expect(result.available).toBe(true);
		expect(result.diagnostics).toEqual([
			expect.objectContaining({
				code: 'php_ini_not_loaded',
				severity: 'warning',
				hints: expect.arrayContaining([
					expect.stringContaining('STONEWRIGHT_WP_CLI_PHP_INI'),
					expect.stringContaining('mysqli/MySQL'),
					expect.stringContaining('restart the MCP client'),
				]),
			}),
		]);
	});

	it('prefers a LocalWP phar near the WordPress root over the generic companion cache', async () => {
		const temp = mkdtempSync(join(tmpdir(), 'stonewright-wpcli-priority-'));
		try {
			const wpRoot = join(temp, 'workspace', 'site', 'app', 'public');
			const localPharPath = join(
				temp,
				'workspace',
				'LocalWP',
				'resources',
				'extraResources',
				'bin',
				'wp-cli',
				'wp-cli.phar',
			);
			const cachePharPath = join(temp, 'cache', 'wp-cli.phar');
			const phpPath = join(
				temp,
				'roaming',
				'Local',
				'lightning-services',
				'php-8.2.29+0',
				'bin',
				'win64',
				'php.exe',
			);
			mkdirSync(wpRoot, { recursive: true });
			mkdirSync(resolve(localPharPath, '..'), { recursive: true });
			mkdirSync(resolve(cachePharPath, '..'), { recursive: true });
			mkdirSync(resolve(phpPath, '..'), { recursive: true });
			writeFileSync(localPharPath, 'localwp phar');
			writeFileSync(cachePharPath, 'cache phar');
			writeFileSync(phpPath, 'php-bin');

			const calls: Array<{ file: string; args: string[] }> = [];
			const runner: ExecFileRunner = (file, args) => {
				calls.push({ file, args });
				return Promise.resolve({ stdout: '{}', stderr: '', exitCode: 0 });
			};

			await runWpCli(
				{
					command: ['cli', 'info', '--format=json'],
					path: wpRoot,
				},
				runner,
				{
					APPDATA: join(temp, 'roaming'),
					STONEWRIGHT_WP_ROOT: wpRoot,
					STONEWRIGHT_WP_CLI_INSTALL_DIR: join(temp, 'cache'),
				} as NodeJS.ProcessEnv,
			);

			expect(calls[0]?.file).toBe(phpPath);
			expect(calls[0]?.args[0]).toBe(localPharPath);
		} finally {
			rmSync(temp, { recursive: true, force: true });
		}
	});

	it('uses a sanitized php.ini copy when LocalWP references missing extensions', async () => {
		const temp = mkdtempSync(join(tmpdir(), 'stonewright-wpcli-ini-'));
		try {
			const wpRoot = join(temp, 'site', 'app', 'public');
			const pharPath = join(temp, 'LocalWP', 'resources', 'extraResources', 'bin', 'wp-cli', 'wp-cli.phar');
			const phpPath = join(temp, 'php', 'php.exe');
			const iniPath = join(temp, 'conf', 'php', 'php.ini');
			const extDir = join(temp, 'php', 'ext');
			mkdirSync(wpRoot, { recursive: true });
			mkdirSync(resolve(pharPath, '..'), { recursive: true });
			mkdirSync(resolve(phpPath, '..'), { recursive: true });
			mkdirSync(resolve(iniPath, '..'), { recursive: true });
			mkdirSync(extDir, { recursive: true });
			writeFileSync(pharPath, 'wp-cli-phar');
			writeFileSync(phpPath, 'php-bin');
			writeFileSync(join(extDir, 'php_mysqli.dll'), 'mysqli');
			writeFileSync(
				iniPath,
				[
					`extension_dir="${extDir.replaceAll('\\', '/')}"`,
					'extension=php_mysqli.dll',
					'extension=php_imagick.dll',
				].join('\n'),
			);

			const calls: Array<{ args: string[] }> = [];
			const runner: ExecFileRunner = (_file, args) => {
				calls.push({ args });
				return Promise.resolve({ stdout: '{}', stderr: '', exitCode: 0 });
			};

			await runWpCli(
				{
					command: ['cli', 'info', '--format=json'],
					path: wpRoot,
				},
				runner,
				{
					STONEWRIGHT_WP_ROOT: wpRoot,
					STONEWRIGHT_WP_CLI_PHP_BIN: phpPath,
					STONEWRIGHT_WP_CLI_PHAR_PATH: pharPath,
					STONEWRIGHT_WP_CLI_PHP_INI: iniPath,
					STONEWRIGHT_WP_CLI_INSTALL_DIR: join(temp, 'cache'),
				} as NodeJS.ProcessEnv,
			);

			const usedIni = calls[0]?.args[1];
			expect(calls[0]?.args[0]).toBe('-c');
			expect(usedIni).not.toBe(iniPath);
			expect(readFileSync(String(usedIni), 'utf8')).toContain('extension=php_mysqli.dll');
			expect(readFileSync(String(usedIni), 'utf8')).not.toContain('extension=php_imagick.dll');
			expect(calls[0]?.args[2]).toBe(pharPath);
		} finally {
			rmSync(temp, { recursive: true, force: true });
		}
	});

	it('adds LocalWP database ini hints when a wp-config DB_HOST port is detected', async () => {
		const temp = mkdtempSync(join(tmpdir(), 'stonewright-wpcli-localwp-ini-'));
		try {
			const wpRoot = join(temp, 'site', 'app', 'public');
			const pharPath = join(temp, 'LocalWP', 'resources', 'extraResources', 'bin', 'wp-cli', 'wp-cli.phar');
			const phpPath = join(temp, 'php', 'php.exe');
			const iniPath = join(temp, 'conf', 'php', 'php.ini');
			mkdirSync(wpRoot, { recursive: true });
			mkdirSync(resolve(pharPath, '..'), { recursive: true });
			mkdirSync(resolve(phpPath, '..'), { recursive: true });
			mkdirSync(resolve(iniPath, '..'), { recursive: true });
			writeFileSync(join(wpRoot, 'wp-config.php'), "<?php\ndefine('DB_HOST', '127.0.0.1:10015');\n");
			writeFileSync(pharPath, 'wp-cli-phar');
			writeFileSync(phpPath, 'php-bin');
			writeFileSync(iniPath, 'memory_limit=256M');

			const calls: Array<{ args: string[] }> = [];
			const runner: ExecFileRunner = (_file, args) => {
				calls.push({ args });
				return Promise.resolve({ stdout: '{}', stderr: '', exitCode: 0 });
			};

			await runWpCli(
				{
					command: ['cli', 'info', '--format=json'],
					path: wpRoot,
				},
				runner,
				{
					STONEWRIGHT_WP_ROOT: wpRoot,
					STONEWRIGHT_WP_CLI_PHP_BIN: phpPath,
					STONEWRIGHT_WP_CLI_PHAR_PATH: pharPath,
					STONEWRIGHT_WP_CLI_PHP_INI: iniPath,
					STONEWRIGHT_WP_CLI_INSTALL_DIR: join(temp, 'cache'),
				} as NodeJS.ProcessEnv,
			);

			const usedIni = calls[0]?.args[1];
			expect(calls[0]?.args[0]).toBe('-c');
			expect(usedIni).not.toBe(iniPath);
			const contents = readFileSync(String(usedIni), 'utf8');
			expect(contents).toContain('mysqli.default_port=10015');
			expect(contents).toContain('pdo_mysql.default_socket=');
		} finally {
			rmSync(temp, { recursive: true, force: true });
		}
	});

	it('installs WP-CLI phar into the Stonewright cache and reuses it for future commands', async () => {
		const temp = mkdtempSync(join(tmpdir(), 'stonewright-wpcli-install-'));
		try {
			const installDir = join(temp, 'cache');
			const pharBytes = Buffer.from('fake wp-cli phar');
			const fetchImpl = (): Promise<Response> => Promise.resolve(new Response(pharBytes));

			const install = await wpCliInstall(
				{ installDir },
				fetchImpl,
				{ STONEWRIGHT_WP_CLI_INSTALL_DIR: installDir } as NodeJS.ProcessEnv,
			);

			const pharPath = join(installDir, 'wp-cli.phar');
			expect(install.ok).toBe(true);
			expect(install.installed).toBe(true);
			expect(install.path).toBe(resolve(pharPath));
			expect(existsSync(pharPath)).toBe(true);
			expect(readFileSync(pharPath)).toEqual(pharBytes);

			const calls: Array<{ file: string; args: string[] }> = [];
			const runner: ExecFileRunner = (file, args) => {
				calls.push({ file, args });
				return Promise.resolve({ stdout: '', stderr: '', exitCode: 0 });
			};

			await runWpCli(
				{ command: ['cli', 'info'], cwd: temp },
				runner,
				{
					STONEWRIGHT_WP_CLI_INSTALL_DIR: installDir,
					STONEWRIGHT_WP_CLI_PHP_BIN: 'php-custom',
				} as NodeJS.ProcessEnv,
			);

			expect(calls[0]?.file).toBe('php-custom');
			expect(calls[0]?.args[0]).toBe(resolve(pharPath));
		} finally {
			rmSync(temp, { recursive: true, force: true });
		}
	});

	it('keeps an existing WP-CLI phar when forced reinstall download fails', async () => {
		const temp = mkdtempSync(join(tmpdir(), 'stonewright-wpcli-install-fail-'));
		try {
			const installDir = join(temp, 'cache');
			const pharPath = join(installDir, 'wp-cli.phar');
			mkdirSync(installDir, { recursive: true });
			writeFileSync(pharPath, 'existing phar');
			const fetchImpl = (): Promise<Response> => Promise.resolve(new Response('nope', { status: 500 }));

			const install = await wpCliInstall(
				{ installDir, force: true },
				fetchImpl,
				{ STONEWRIGHT_WP_CLI_INSTALL_DIR: installDir } as NodeJS.ProcessEnv,
			);

			expect(install.ok).toBe(false);
			expect(readFileSync(pharPath, 'utf8')).toBe('existing phar');
		} finally {
			rmSync(temp, { recursive: true, force: true });
		}
	});

	it('accepts comma-separated allowed roots and validates --path', async () => {
		const cwd = process.cwd();
		const calls: Array<{ args: string[] }> = [];
		const runner: ExecFileRunner = (_file, args) => {
			calls.push({ args });
			return Promise.resolve({ stdout: '', stderr: '', exitCode: 0 });
		};

		await runWpCli(
			{
				command: ['option', 'get', 'home'],
				cwd,
				path: cwd,
			},
			runner,
			{ STONEWRIGHT_WP_ALLOWED_ROOTS: `${cwd},D:\\StonewrightOtherRoot` } as NodeJS.ProcessEnv,
		);

		expect(calls[0]?.args).toContain(`--path=${resolve(cwd)}`);
		await expect(
			runWpCli(
				{
					command: ['option', 'get', 'home'],
					cwd,
					path: resolve(cwd, '..'),
				},
				runner,
				{ STONEWRIGHT_WP_ALLOWED_ROOTS: cwd } as NodeJS.ProcessEnv,
			),
		).rejects.toThrow(/--path is outside/i);
	});

	it('uses --path as cwd and allowed root when cwd/env root are omitted', async () => {
		const temp = mkdtempSync(join(tmpdir(), 'stonewright-wproot-'));
		try {
			const wpRoot = join(temp, 'app', 'public');
			mkdirSync(wpRoot, { recursive: true });
			const calls: Array<{ cwd: string; args: string[] }> = [];
			const runner: ExecFileRunner = (_file, args, options) => {
				calls.push({ cwd: options.cwd, args });
				return Promise.resolve({ stdout: '', stderr: '', exitCode: 0 });
			};

			await runWpCli(
				{
					command: ['plugin', 'list'],
					path: wpRoot,
				},
				runner,
				{ STONEWRIGHT_WP_CLI_BIN: 'wp' } as NodeJS.ProcessEnv,
			);

			expect(calls[0]?.cwd).toBe(resolve(wpRoot));
			expect(calls[0]?.args).toContain(`--path=${resolve(wpRoot)}`);
		} finally {
			rmSync(temp, { recursive: true, force: true });
		}
	});

	it('returns deep WP-CLI health diagnostics with PHP, WordPress, DB, and LocalWP signals', async () => {
		const temp = mkdtempSync(join(tmpdir(), 'stonewright-wpcli-health-'));
		try {
			const wpRoot = join(temp, 'site', 'app', 'public');
			const phpPath = join(temp, 'php', 'php.exe');
			const pharPath = join(temp, 'wp-cli.phar');
			const iniPath = join(temp, 'conf', 'php.ini');
			mkdirSync(wpRoot, { recursive: true });
			mkdirSync(resolve(phpPath, '..'), { recursive: true });
			mkdirSync(resolve(iniPath, '..'), { recursive: true });
			writeFileSync(join(wpRoot, 'wp-config.php'), "<?php\ndefine('DB_HOST', 'localhost:10015');\n");
			writeFileSync(phpPath, 'php-bin');
			writeFileSync(pharPath, 'wp-cli-phar');
			writeFileSync(iniPath, 'memory_limit=256M');

			const runner: ExecFileRunner = (file, args) => {
				if (file === phpPath && args[0] === '--ini') {
					return Promise.resolve({
						stdout: `Loaded Configuration File: ${iniPath}\nScan for additional .ini files in: (none)\n`,
						stderr: '',
						exitCode: 0,
					});
				}
				if (file === phpPath && args[0] === '-m') {
					return Promise.resolve({ stdout: "Core\njson\nmysqli\n", stderr: '', exitCode: 0 });
				}
				if (args.includes('core') && args.includes('is-installed')) {
					return Promise.resolve({ stdout: '', stderr: '', exitCode: 0 });
				}
				if (args.includes('db') && args.includes('check')) {
					return Promise.resolve({
						stdout: '',
						stderr: 'Error establishing a database connection',
						exitCode: 1,
					});
				}
				return Promise.resolve({
					stdout: JSON.stringify({
						wp_cli_version: '2.12.0',
						php_binary_path: phpPath,
						php_ini_loaded_file: iniPath,
					}),
					stderr: '',
					exitCode: 0,
				});
			};

			const result = await wpCliStatus(
				{ path: wpRoot, deep: true },
				runner,
				{
					STONEWRIGHT_WP_ROOT: wpRoot,
					STONEWRIGHT_WP_CLI_PHP_BIN: phpPath,
					STONEWRIGHT_WP_CLI_PHAR_PATH: pharPath,
					STONEWRIGHT_WP_CLI_PHP_INI: iniPath,
				} as NodeJS.ProcessEnv,
			) as WpCliResult;

			expect(result.ok).toBe(true);
			expect(result.default_wp_root).toBe(resolve(wpRoot));
			expect(result.diagnostics).toMatchObject({
				php: {
					ini_loaded: iniPath,
					extensions: {
						mysqli: true,
						pdo_mysql: false,
					},
				},
				wordpress: {
					root_ok: true,
					boot_ok: true,
					db_ok: false,
				},
				localwp: {
					db_host: {
						raw: 'localhost:10015',
						port: 10015,
					},
				},
			});
			expect(result.recommendations).toContain('Enable the pdo_mysql PHP extension for WP-CLI if database-backed commands fail.');
			expect(result.recommendations).toContain('Fix the WordPress database connection shown by wp db check before running write commands.');
		} finally {
			rmSync(temp, { recursive: true, force: true });
		}
	});

	it('caches a successful status WP root as the default path for later commands', async () => {
		const temp = mkdtempSync(join(tmpdir(), 'stonewright-wpcli-cache-root-'));
		try {
			const wpRoot = join(temp, 'app', 'public');
			mkdirSync(wpRoot, { recursive: true });
			writeFileSync(join(wpRoot, 'wp-config.php'), '<?php');
			const calls: Array<{ cwd: string; args: string[] }> = [];
			const runner: ExecFileRunner = (_file, args, options) => {
				calls.push({ cwd: options.cwd, args });
				return Promise.resolve({ stdout: '{}', stderr: '', exitCode: 0 });
			};

			await wpCliStatus(
				{
					path: wpRoot,
					deep: false,
				},
				runner,
				{ STONEWRIGHT_WP_CLI_BIN: 'wp' } as NodeJS.ProcessEnv,
			);
			await runWpCli(
				{ command: ['plugin', 'list'] },
				runner,
				{ STONEWRIGHT_WP_CLI_BIN: 'wp' } as NodeJS.ProcessEnv,
			);

			expect(calls[1]?.cwd).toBe(resolve(wpRoot));
			expect(calls[1]?.args).toContain(`--path=${resolve(wpRoot)}`);
		} finally {
			rmSync(temp, { recursive: true, force: true });
		}
	});

	it('explains when the selected cwd is not a WordPress root', async () => {
		const temp = mkdtempSync(join(tmpdir(), 'stonewright-wpcli-not-root-'));
		try {
			const result = await wpCliStatus(
				{ cwd: temp, deep: false },
				() => Promise.resolve({ stdout: '{}', stderr: '', exitCode: 0 }),
				{ STONEWRIGHT_WP_CLI_BIN: 'wp' } as NodeJS.ProcessEnv,
			) as WpCliResult;

			expect(result.diagnostics).toMatchObject({
				wordpress: {
					root_ok: false,
					root: '',
				},
			});
			expect(result.recommendations).toContain('Set path or STONEWRIGHT_WP_ROOT to the WordPress directory containing wp-config.php.');
		} finally {
			rmSync(temp, { recursive: true, force: true });
		}
	});

	it('discovers installed command metadata as a compact summary by default', async () => {
		const runner: ExecFileRunner = (_file, args) => {
			expect(args.slice(-2)).toEqual(['cli', 'cmd-dump']);
			return Promise.resolve({
				stdout: JSON.stringify({ name: 'wp', subcommands: [{ name: 'post' }] }),
				stderr: '',
				exitCode: 0,
			});
		};

		const result = await wpCliDiscover({}, runner);

		expect(result.ok).toBe(true);
		expect(result.command_paths).toEqual(['wp', 'wp post']);
		expect(result).not.toHaveProperty('stdout');
		expect(result).not.toHaveProperty('parsed_json');
	});

	it('can return the raw wp cli cmd-dump tree when explicitly requested', async () => {
		const runner: ExecFileRunner = (_file, args) => {
			expect(args.slice(-2)).toEqual(['cli', 'cmd-dump']);
			return Promise.resolve({
				stdout: JSON.stringify({ name: 'wp', subcommands: [{ name: 'post' }] }),
				stderr: '',
				exitCode: 0,
			});
		};

		const result = await wpCliDiscover({ responseMode: 'full' }, runner);

		expect(result.ok).toBe(true);
		expect(result.parsed_json).toEqual({ name: 'wp', subcommands: [{ name: 'post' }] });
	});

	it('summarizes command discovery for token-efficient plugin command planning', async () => {
		const runner: ExecFileRunner = () => Promise.resolve({
			stdout: JSON.stringify({
				name: 'wp',
				subcommands: [
					{ name: 'post', subcommands: [{ name: 'create' }, { name: 'meta' }] },
					{ name: 'acf', subcommands: [{ name: 'field-group' }, { name: 'field' }] },
					{ name: 'plugin', subcommands: [{ name: 'list' }] },
				],
			}),
			stderr: '',
			exitCode: 0,
		});

		const result = await wpCliDiscover(
			{
				responseMode: 'summary',
				commandFilter: ['acf', 'post meta'],
				maxCommands: 4,
			},
			runner,
			{ STONEWRIGHT_WP_CLI_BIN: 'wp' } as NodeJS.ProcessEnv,
		);

		expect(result).toMatchObject({
			ok: true,
			available: true,
			command_count: 9,
			returned_command_count: 4,
			truncated: false,
			command_filter: ['acf', 'post meta'],
		});
		expect(result.command_paths).toEqual(['wp acf', 'wp acf field', 'wp acf field-group', 'wp post meta']);
		expect(result.root_commands).toEqual(['wp']);
		expect(result).not.toHaveProperty('stdout');
		expect(result).not.toHaveProperty('parsed_json');
	});
});

describe('wpCliEnsureReady', () => {
	it('returns {ensured: true, source: "already_available"} when wp is found', async () => {
		const runner: ExecFileRunner = () =>
			Promise.resolve({ stdout: '{"wp_cli_version":"2.10.0"}', stderr: '', exitCode: 0 });
		const env = { STONEWRIGHT_WP_CLI_BIN: 'wp', STONEWRIGHT_WP_ROOT: process.cwd() } as NodeJS.ProcessEnv;

		const result = await wpCliEnsureReady({ runner, env });

		expect(result.ensured).toBe(true);
		expect(result.source).toBe('already_available');
		expect(result.installed).toBe(false);
	});

	it('downloads phar and returns {ensured: true, source: "installed"} when wp is unavailable', async () => {
		const temp = mkdtempSync(join(tmpdir(), 'stonewright-ensure-'));
		try {
			const installDir = join(temp, 'cache');
			let callCount = 0;
			const runner: ExecFileRunner = () => {
				callCount++;
				if (callCount === 1) {
					// First call (status check) — simulate ENOENT
					return Promise.resolve({
						stdout: '',
						stderr: '',
						exitCode: 1,
						errorCode: 'ENOENT' as string | number,
						errorMessage: 'wp: not found',
					});
				}
				// Second call (re-check after install) — success
				return Promise.resolve({ stdout: '{"wp_cli_version":"2.10.0"}', stderr: '', exitCode: 0 });
			};
			const pharBytes = Buffer.from('fake phar');
			const fetchImpl = (): Promise<Response> => Promise.resolve(new Response(pharBytes));
			const env = {
				STONEWRIGHT_WP_CLI_INSTALL_DIR: installDir,
				STONEWRIGHT_WP_ROOT: process.cwd(),
			} as NodeJS.ProcessEnv;

			const result = await wpCliEnsureReady({ runner, env, fetchImpl });

			expect(result.ensured).toBe(true);
			expect(result.source).toBe('installed');
			expect(result.installed).toBe(true);
			expect(existsSync(join(installDir, 'wp-cli.phar'))).toBe(true);
		} finally {
			rmSync(temp, { recursive: true, force: true });
		}
	});

	it('returns {ensured: false} when wp is unavailable and phar download fails', async () => {
		const temp = mkdtempSync(join(tmpdir(), 'stonewright-ensure-fail-'));
		try {
			const installDir = join(temp, 'cache');
			const runner: ExecFileRunner = () =>
				Promise.resolve({ stdout: '', stderr: '', exitCode: 1, errorCode: 'ENOENT' as string | number, errorMessage: 'not found' });
			const fetchImpl = (): Promise<Response> => Promise.resolve(new Response('fail', { status: 500 }));
			const env = {
				STONEWRIGHT_WP_CLI_INSTALL_DIR: installDir,
				STONEWRIGHT_WP_ROOT: process.cwd(),
			} as NodeJS.ProcessEnv;

			const result = await wpCliEnsureReady({ runner, env, fetchImpl });

			expect(result.ensured).toBe(false);
			expect(result.installed).toBe(false);
		} finally {
			rmSync(temp, { recursive: true, force: true });
		}
	});
});
