import { execFile } from 'node:child_process';
import { createHash } from 'node:crypto';
import { chmodSync, existsSync, mkdirSync, readdirSync, renameSync, statSync, unlinkSync, writeFileSync } from 'node:fs';
import { homedir } from 'node:os';
import { dirname, join, resolve, sep } from 'node:path';

export interface WpCliRunInput {
	command: string[];
	cwd?: string;
	path?: string;
	url?: string;
	user?: string;
	context?: string;
	timeoutMs?: number;
	parseJson?: boolean;
}

export interface WpCliBatchRunInput extends Omit<WpCliRunInput, 'command'> {
	commands: string[][];
	stopOnError?: boolean;
}

export interface ExecFileOptions {
	cwd: string;
	timeout: number;
	maxBuffer: number;
	windowsHide: boolean;
	shell: false;
	env: NodeJS.ProcessEnv;
}

export interface ExecFileResult {
	stdout: string;
	stderr: string;
	exitCode: number;
	errorMessage?: string;
	errorCode?: string | number;
}

export type ExecFileRunner = (
	file: string,
	args: string[],
	options: ExecFileOptions,
) => Promise<ExecFileResult>;

export interface WpCliResult extends Record<string, unknown> {
	ok: boolean;
	available: boolean;
	command: string[];
	cwd: string;
	stdout: string;
	stderr: string;
	exit_code: number;
	duration_ms: number;
	wp_cli_source: string;
	parsed_json?: unknown;
	error?: string;
}

export interface WpCliBatchResult extends Record<string, unknown> {
	ok: boolean;
	count: number;
	succeeded: number;
	failed: number;
	stopped: boolean;
	results: WpCliResult[];
}

export interface WpCliInvocation {
	executable: string;
	prefixArgs: string[];
	source: string;
}

export interface WpCliInstallInput {
	installDir?: string;
	force?: boolean;
	expectedSha256?: string;
	timeoutMs?: number;
}

export interface WpCliInstallResult extends Record<string, unknown> {
	ok: boolean;
	installed: boolean;
	path: string;
	url: string;
	bytes: number;
	sha256?: string;
	skipped?: boolean;
	error?: string;
}

const DEFAULT_TIMEOUT_MS = 60_000;
const DEFAULT_MAX_BUFFER = 10 * 1024 * 1024;
const BLOCKED_COMMAND_GROUPS = new Set(['eval', 'eval-file', 'shell', 'package']);
const BLOCKED_GLOBAL_FLAGS = ['--exec', '--require', '--prompt'];
const WP_CLI_PHAR_URL = 'https://raw.githubusercontent.com/wp-cli/builds/gh-pages/phar/wp-cli.phar';

export function validateWpCliCommand(command: string[]): string[] {
	if (!Array.isArray(command) || command.length === 0) {
		throw new Error('WP-CLI command must be a non-empty argv array.');
	}

	const clean = command.map((token) => {
		if (typeof token !== 'string') {
			throw new Error('WP-CLI command tokens must be strings.');
		}
		const trimmed = token.trim();
		if (trimmed === '' || trimmed.includes('\0')) {
			throw new Error('WP-CLI command tokens must be non-empty and cannot contain NUL bytes.');
		}
		return trimmed;
	});

	for (const token of clean) {
		for (const flag of BLOCKED_GLOBAL_FLAGS) {
			if (token === flag || token.startsWith(`${flag}=`)) {
				throw new Error(`Blocked WP-CLI flag: ${flag}`);
			}
		}
	}

	const commandGroup = clean.find((token) => !token.startsWith('-')) ?? '';
	if (BLOCKED_COMMAND_GROUPS.has(commandGroup)) {
		throw new Error(`Blocked WP-CLI command group: ${commandGroup}`);
	}

	return clean;
}

export function buildWpCliArgs(input: WpCliRunInput): string[] {
	const args: string[] = [];

	if (input.path) args.push(`--path=${input.path}`);
	if (input.url) args.push(`--url=${input.url}`);
	if (input.user) args.push(`--user=${input.user}`);
	if (input.context) args.push(`--context=${input.context}`);

	return [...args, ...validateWpCliCommand(input.command)];
}

export async function runWpCli(
	input: WpCliRunInput,
	runner: ExecFileRunner = defaultExecFileRunner,
	env: NodeJS.ProcessEnv = process.env,
): Promise<WpCliResult> {
	const started = Date.now();
	const cwdFromPath = input.cwd === undefined && input.path ? resolve(input.path) : undefined;
	const cwd = resolveWorkingDirectory(input.cwd ?? cwdFromPath, env);
	const safeInput = {
		...input,
		...(input.path ? { path: cwdFromPath ?? resolveAllowedPath(input.path, env, cwd) } : {}),
	};
	const invocation = resolveWpCliInvocation(env, cwd);
	const args = [...invocation.prefixArgs, ...buildWpCliArgs(safeInput)];

	const options: ExecFileOptions = {
		cwd,
		timeout: normaliseTimeout(input.timeoutMs),
		maxBuffer: DEFAULT_MAX_BUFFER,
		windowsHide: true,
		shell: false,
		env: { ...process.env, ...env },
	};

	const result = await runner(invocation.executable, args, options);
	const parsed = input.parseJson ? parseJson(result.stdout) : undefined;
	const unavailable = result.errorCode === 'ENOENT';

	return {
		ok: result.exitCode === 0 && !unavailable,
		available: !unavailable,
		command: [invocation.executable, ...args],
		cwd,
		stdout: result.stdout,
		stderr: result.stderr,
		exit_code: result.exitCode,
		duration_ms: Date.now() - started,
		wp_cli_source: invocation.source,
		...(parsed !== undefined ? { parsed_json: parsed } : {}),
		...(result.errorMessage ? { error: result.errorMessage } : {}),
	};
}

export async function runWpCliBatch(
	input: WpCliBatchRunInput,
	runner: ExecFileRunner = defaultExecFileRunner,
	env: NodeJS.ProcessEnv = process.env,
): Promise<WpCliBatchResult> {
	if (!Array.isArray(input.commands) || input.commands.length === 0) {
		throw new Error('WP-CLI batch requires a non-empty commands array.');
	}
	if (input.commands.length > 100) {
		throw new Error('WP-CLI batch supports at most 100 commands.');
	}

	const { commands, stopOnError = true, ...sharedInput } = input;
	const results: WpCliResult[] = [];

	for (const command of commands) {
		const result = await runWpCli(
			{
				...sharedInput,
				command,
			},
			runner,
			env,
		);
		results.push(result);

		if (!result.ok && stopOnError) {
			break;
		}
	}

	const failed = results.filter((result) => !result.ok).length;

	return {
		ok: failed === 0 && results.length === commands.length,
		count: commands.length,
		succeeded: results.length - failed,
		failed,
		stopped: results.length < commands.length,
		results,
	};
}

export function resolveWpCliInvocation(env: NodeJS.ProcessEnv, cwd: string): WpCliInvocation {
	const explicitPhp = cleanEnvPath(env['STONEWRIGHT_WP_CLI_PHP_BIN']);
	const explicitPhar = cleanEnvPath(env['STONEWRIGHT_WP_CLI_PHAR_PATH']);
	if (explicitPhp && explicitPhar) {
		return phpPharInvocation(explicitPhp, explicitPhar, cleanEnvPath(env['STONEWRIGHT_WP_CLI_PHP_INI']), 'env_php_phar');
	}

	const explicitBin = cleanEnvPath(env['STONEWRIGHT_WP_CLI_BIN']);
	if (explicitBin) {
		return {
			executable: explicitBin,
			prefixArgs: [],
			source: 'env_bin',
		};
	}

	const discoveredPhar = explicitPhar ?? discoverWpCliPhar(env, cwd);
	if (discoveredPhar) {
		const discoveredPhp = explicitPhp ?? discoverPhpBinary(env);
		if (discoveredPhp) {
			return phpPharInvocation(
				discoveredPhp,
				discoveredPhar,
				cleanEnvPath(env['STONEWRIGHT_WP_CLI_PHP_INI']) ?? discoverPhpIni(cwd),
				'discovered_php_phar',
			);
		}
	}

	return {
		executable: 'wp',
		prefixArgs: [],
		source: 'path_wp',
	};
}

export async function wpCliInstall(
	input: WpCliInstallInput = {},
	fetchImpl: typeof fetch = fetch,
	env: NodeJS.ProcessEnv = process.env,
): Promise<WpCliInstallResult> {
	const installDir = resolveWpCliInstallDir(input.installDir, env);
	const pharPath = join(installDir, 'wp-cli.phar');
	const force = input.force === true;

	if (!force && existsSync(pharPath)) {
		const stats = statSync(pharPath);
		return {
			ok: true,
			installed: false,
			path: resolve(pharPath),
			url: WP_CLI_PHAR_URL,
			bytes: stats.size,
			skipped: true,
		};
	}

	mkdirSync(installDir, { recursive: true });
	const controller = new AbortController();
	const timeout = setTimeout(() => controller.abort(), normaliseTimeout(input.timeoutMs));
	let tempPath = '';
	try {
		const response = await fetchImpl(WP_CLI_PHAR_URL, { signal: controller.signal });
		if (!response.ok) {
			throw new Error(`WP-CLI download failed with HTTP ${response.status}.`);
		}
		const buffer = Buffer.from(await response.arrayBuffer());
		const sha256 = createHash('sha256').update(buffer).digest('hex');
		if (input.expectedSha256 && sha256.toLowerCase() !== input.expectedSha256.toLowerCase()) {
			throw new Error('WP-CLI download checksum did not match expectedSha256.');
		}

		tempPath = `${pharPath}.tmp-${process.pid}-${Date.now()}`;
		writeFileSync(tempPath, buffer, { flag: 'w' });
		try {
			chmodSync(tempPath, 0o755);
		} catch {
			// Windows does not need executable bits for phar execution through PHP.
		}
		renameSync(tempPath, pharPath);

		return {
			ok: true,
			installed: true,
			path: resolve(pharPath),
			url: WP_CLI_PHAR_URL,
			bytes: buffer.length,
			sha256,
		};
	} catch (err) {
		if (tempPath) {
			try {
				unlinkSync(tempPath);
			} catch {
				// Keep installer idempotent if no partial file exists.
			}
		}
		return {
			ok: false,
			installed: false,
			path: resolve(pharPath),
			url: WP_CLI_PHAR_URL,
			bytes: 0,
			error: err instanceof Error ? err.message : String(err),
		};
	} finally {
		clearTimeout(timeout);
	}
}

export async function wpCliStatus(
	input: Partial<WpCliRunInput> = {},
	runner?: ExecFileRunner,
	env: NodeJS.ProcessEnv = process.env,
): Promise<WpCliResult> {
	return runWpCli(
		{
			...input,
			command: ['cli', 'info', '--format=json'],
			parseJson: true,
		},
		runner,
		env,
	);
}

export async function wpCliDiscover(
	input: Partial<WpCliRunInput> = {},
	runner?: ExecFileRunner,
	env: NodeJS.ProcessEnv = process.env,
): Promise<WpCliResult> {
	return runWpCli(
		{
			...input,
			command: ['cli', 'cmd-dump'],
			parseJson: true,
		},
		runner,
		env,
	);
}

export interface WpCliEnsureReadyInput {
	runner?: ExecFileRunner;
	env?: NodeJS.ProcessEnv;
	fetchImpl?: typeof fetch;
	timeoutMs?: number;
}

export interface WpCliEnsureReadyResult {
	ensured: boolean;
	source: 'already_available' | 'installed' | 'install_failed' | 'status_error';
	installed: boolean;
	installPath?: string;
	error?: string;
}

/**
 * Ensures WP-CLI is available for use by the companion.
 *
 * 1. Runs `wp cli info` to check current availability.
 * 2. If unavailable (ENOENT), downloads wp-cli.phar into the Stonewright
 *    companion cache and re-checks.
 * 3. Returns a structured result indicating whether WP-CLI is ready.
 *
 * This is called once at companion startup and is safe to call repeatedly
 * (wpCliInstall is idempotent when the phar already exists).
 */
export async function wpCliEnsureReady(
	input: WpCliEnsureReadyInput = {},
): Promise<WpCliEnsureReadyResult> {
	const env = input.env ?? process.env;
	const runner = input.runner;
	const fetchImpl = input.fetchImpl ?? fetch;

	// Step 1: Check if WP-CLI is already reachable.
	const status = await wpCliStatus({}, runner, env);
	if (status.available) {
		return { ensured: true, source: 'already_available', installed: false };
	}

	// Step 2: WP-CLI not on PATH / not found — try installing phar into cache.
	const installResult = await wpCliInstall(
		{ ...(input.timeoutMs !== undefined ? { timeoutMs: input.timeoutMs } : {}) },
		fetchImpl,
		env,
	);

	if (!installResult.ok) {
		const result: WpCliEnsureReadyResult = {
			ensured: false,
			source: 'install_failed',
			installed: false,
		};
		if (installResult.error !== undefined) result.error = installResult.error;
		return result;
	}

	// Step 3: Re-check — now the phar is in the install dir so discovery picks it up.
	const recheck = await wpCliStatus({}, runner, env);
	return {
		ensured: recheck.available,
		source: 'installed',
		installed: true,
		installPath: installResult.path,
		...(recheck.available ? {} : { error: recheck.error }),
	};
}

const defaultExecFileRunner: ExecFileRunner = async (file, args, options) =>
	new Promise<ExecFileResult>((resolveResult) => {
		execFile(
			file,
			args,
			{
				cwd: options.cwd,
				timeout: options.timeout,
				maxBuffer: options.maxBuffer,
				windowsHide: options.windowsHide,
				shell: options.shell,
				env: options.env,
				encoding: 'utf8',
			},
			(error, stdout, stderr) => {
				const err = error as NodeJS.ErrnoException | null;
				resolveResult({
					stdout: typeof stdout === 'string' ? stdout : String(stdout ?? ''),
					stderr: typeof stderr === 'string' ? stderr : String(stderr ?? ''),
					exitCode: typeof err?.code === 'number' ? err.code : err ? 1 : 0,
					...(err?.message ? { errorMessage: err.message } : {}),
					...(err?.code !== undefined ? { errorCode: err.code } : {}),
				});
			},
		);
	});

function parseJson(stdout: string): unknown {
	const trimmed = stdout.trim();
	if (trimmed === '') return undefined;
	try {
		return JSON.parse(trimmed);
	} catch {
		return undefined;
	}
}

function normaliseTimeout(timeoutMs: number | undefined): number {
	if (timeoutMs === undefined) return DEFAULT_TIMEOUT_MS;
	if (!Number.isFinite(timeoutMs) || timeoutMs <= 0) return DEFAULT_TIMEOUT_MS;
	return Math.min(Math.floor(timeoutMs), 10 * 60_000);
}

function resolveWorkingDirectory(rawCwd: string | undefined, env: NodeJS.ProcessEnv): string {
	const cwd = resolve(rawCwd ?? env['STONEWRIGHT_WP_ROOT'] ?? process.cwd());
	const allowed = allowedRoots(env, cwd);
	if (!allowed.some((root) => isInside(cwd, root))) {
		throw new Error(`WP-CLI cwd is outside the allowed roots: ${cwd}`);
	}
	return cwd;
}

function resolveAllowedPath(rawPath: string, env: NodeJS.ProcessEnv, cwd: string): string {
	const path = resolve(cwd, rawPath);
	const allowed = allowedRoots(env, cwd);
	if (!allowed.some((root) => isInside(path, root))) {
		throw new Error(`WP-CLI --path is outside the allowed roots: ${path}`);
	}
	return path;
}

function allowedRoots(env: NodeJS.ProcessEnv, fallback: string): string[] {
	const raw = env['STONEWRIGHT_WP_ALLOWED_ROOTS'] ?? env['STONEWRIGHT_WP_ROOT'] ?? fallback;
	return raw
		.split(/[;,]/)
		.map((item) => item.trim())
		.filter(Boolean)
		.map((item) => resolve(item));
}

function isInside(candidate: string, root: string): boolean {
	const rootWithSep = root.endsWith(sep) ? root : root + sep;
	return candidate === root || candidate.startsWith(rootWithSep);
}

function cleanEnvPath(value: string | undefined): string | undefined {
	const trimmed = value?.trim();
	return trimmed ? trimmed : undefined;
}

function phpPharInvocation(phpBin: string, pharPath: string, phpIni: string | undefined, source: string): WpCliInvocation {
	const prefixArgs: string[] = [];
	if (phpIni) {
		prefixArgs.push('-c', phpIni);
	}
	prefixArgs.push(pharPath);
	return {
		executable: phpBin,
		prefixArgs,
		source,
	};
}

function discoverWpCliPhar(env: NodeJS.ProcessEnv, cwd: string): string | undefined {
	return firstExisting([
		...candidatePharsNearWordPressRoot(cwd),
		...candidateLocalWpPhars(env),
		join(resolveWpCliInstallDir(undefined, env), 'wp-cli.phar'),
	]);
}

function candidatePharsNearWordPressRoot(cwd: string): string[] {
	const candidates: string[] = [];
	for (const root of ancestorDirectories(cwd)) {
		candidates.push(
			join(root, 'LocalWP', 'resources', 'extraResources', 'bin', 'wp-cli', 'wp-cli.phar'),
			join(root, 'Local', 'resources', 'extraResources', 'bin', 'wp-cli', 'wp-cli.phar'),
		);
	}
	return candidates;
}

function candidateLocalWpPhars(env: NodeJS.ProcessEnv): string[] {
	return [
		env['LOCALAPPDATA']
			? join(env['LOCALAPPDATA'], 'Programs', 'Local', 'resources', 'extraResources', 'bin', 'wp-cli', 'wp-cli.phar')
			: undefined,
		env['PROGRAMFILES'] ? join(env['PROGRAMFILES'], 'Local', 'resources', 'extraResources', 'bin', 'wp-cli', 'wp-cli.phar') : undefined,
		env['ProgramFiles(x86)']
			? join(env['ProgramFiles(x86)'], 'Local', 'resources', 'extraResources', 'bin', 'wp-cli', 'wp-cli.phar')
			: undefined,
		'/Applications/Local.app/Contents/Resources/extraResources/bin/wp-cli/wp-cli.phar',
	].filter((path): path is string => Boolean(path));
}

function discoverPhpBinary(env: NodeJS.ProcessEnv): string | undefined {
	const explicitPhp = cleanEnvPath(env['STONEWRIGHT_WP_CLI_PHP_BIN']);
	if (explicitPhp) {
		return explicitPhp;
	}

	const candidates: string[] = [];
	const appData = cleanEnvPath(env['APPDATA']);
	const localAppData = cleanEnvPath(env['LOCALAPPDATA']);
	const home = cleanEnvPath(env['HOME']) ?? homedir();

	if (appData) {
		candidates.push(...candidateLocalWpPhpBins(join(appData, 'Local', 'lightning-services')));
	}
	if (localAppData) {
		candidates.push(...candidateLocalWpPhpBins(join(localAppData, 'Local', 'lightning-services')));
		candidates.push(...candidateLocalWpPhpBins(join(localAppData, 'Programs', 'Local', 'lightning-services')));
	}
	if (home) {
		candidates.push(
			...candidateLocalWpPhpBins(join(home, 'Library', 'Application Support', 'Local', 'lightning-services')),
			...candidateLocalWpPhpBins(join(home, 'Library', 'Application Support', 'Local by Flywheel', 'lightning-services')),
			...candidateLocalWpPhpBins(join(home, '.config', 'Local', 'lightning-services')),
		);
	}

	return firstExisting(candidates) ?? 'php';
}

function candidateLocalWpPhpBins(baseDir: string): string[] {
	let entries: string[] = [];
	try {
		entries = readdirSync(baseDir, { withFileTypes: true })
			.filter((entry) => entry.isDirectory() && entry.name.startsWith('php-'))
			.map((entry) => entry.name)
			.sort()
			.reverse();
	} catch {
		return [];
	}

	return entries.flatMap((entry) => {
		const dir = join(baseDir, entry);
		return [
			join(dir, 'bin', 'win64', 'php.exe'),
			join(dir, 'bin', 'darwin', 'bin', 'php'),
			join(dir, 'bin', 'linux', 'bin', 'php'),
			join(dir, 'bin', 'php'),
		];
	});
}

function discoverPhpIni(cwd: string): string | undefined {
	const candidates: string[] = [];
	for (const root of ancestorDirectories(cwd)) {
		candidates.push(join(root, 'conf', 'php', 'php.ini'));
		if (root.endsWith(`${sep}app`)) {
			candidates.push(join(dirname(root), 'conf', 'php', 'php.ini'));
		}
	}
	return firstExisting(candidates);
}

function ancestorDirectories(start: string): string[] {
	const roots: string[] = [];
	let current = resolve(start);
	for (;;) {
		roots.push(current);
		const parent = dirname(current);
		if (parent === current) {
			return roots;
		}
		current = parent;
	}
}

function firstExisting(candidates: Array<string | undefined>): string | undefined {
	for (const candidate of candidates) {
		if (candidate && existsSync(candidate)) {
			return candidate;
		}
	}
	return undefined;
}

function resolveWpCliInstallDir(rawInstallDir: string | undefined, env: NodeJS.ProcessEnv): string {
	return resolve(
		rawInstallDir ??
		cleanEnvPath(env['STONEWRIGHT_WP_CLI_INSTALL_DIR']) ??
		(env['LOCALAPPDATA'] ? join(env['LOCALAPPDATA'], 'Stonewright', 'wp-cli') : join(homedir(), '.stonewright', 'wp-cli')),
	);
}
