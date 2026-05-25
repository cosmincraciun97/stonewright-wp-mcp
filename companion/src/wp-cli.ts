import { execFile } from 'node:child_process';
import { resolve, sep } from 'node:path';

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
	parsed_json?: unknown;
	error?: string;
}

const DEFAULT_TIMEOUT_MS = 60_000;
const DEFAULT_MAX_BUFFER = 10 * 1024 * 1024;
const BLOCKED_COMMAND_GROUPS = new Set(['eval', 'eval-file', 'shell', 'package']);
const BLOCKED_GLOBAL_FLAGS = ['--exec', '--require', '--prompt'];

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
	const cwd = resolveWorkingDirectory(input.cwd, env);
	let executable = (env['STONEWRIGHT_WP_CLI_BIN'] ?? 'wp').trim() || 'wp';
	const safeInput = {
		...input,
		...(input.path ? { path: resolveAllowedPath(input.path, env, cwd) } : {}),
	};
	let args = buildWpCliArgs(safeInput);

	if (env['STONEWRIGHT_WP_CLI_PHP_BIN'] && env['STONEWRIGHT_WP_CLI_PHAR_PATH']) {
		executable = env['STONEWRIGHT_WP_CLI_PHP_BIN'].trim();
		const pharPath = env['STONEWRIGHT_WP_CLI_PHAR_PATH'].trim();
		const phpIni = env['STONEWRIGHT_WP_CLI_PHP_INI']?.trim();
		const phpArgs: string[] = [];
		if (phpIni) {
			phpArgs.push('-c', phpIni);
		}
		phpArgs.push(pharPath);
		args = [...phpArgs, ...args];
	}

	const options: ExecFileOptions = {
		cwd,
		timeout: normaliseTimeout(input.timeoutMs),
		maxBuffer: DEFAULT_MAX_BUFFER,
		windowsHide: true,
		shell: false,
		env: { ...process.env, ...env },
	};

	const result = await runner(executable, args, options);
	const parsed = input.parseJson ? parseJson(result.stdout) : undefined;
	const unavailable = result.errorCode === 'ENOENT';

	return {
		ok: result.exitCode === 0 && !unavailable,
		available: !unavailable,
		command: [executable, ...args],
		cwd,
		stdout: result.stdout,
		stderr: result.stderr,
		exit_code: result.exitCode,
		duration_ms: Date.now() - started,
		...(parsed !== undefined ? { parsed_json: parsed } : {}),
		...(result.errorMessage ? { error: result.errorMessage } : {}),
	};
}

export async function wpCliStatus(
	input: Partial<WpCliRunInput> = {},
	runner?: ExecFileRunner,
): Promise<WpCliResult> {
	return runWpCli(
		{
			...input,
			command: ['cli', 'info', '--format=json'],
			parseJson: true,
		},
		runner,
	);
}

export async function wpCliDiscover(
	input: Partial<WpCliRunInput> = {},
	runner?: ExecFileRunner,
): Promise<WpCliResult> {
	return runWpCli(
		{
			...input,
			command: ['cli', 'cmd-dump'],
			parseJson: true,
		},
		runner,
	);
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
