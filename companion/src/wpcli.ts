/**
 * WP-CLI bridge — runs wp-cli commands via execFile (no shell).
 * Command names are validated against WPCLI_ALLOWLIST before execution.
 */
import { execFile } from 'node:child_process';
import { promisify } from 'node:util';

const execFileAsync = promisify(execFile);

/** Commands permitted through the bridge — anything not listed is rejected. */
export const WPCLI_ALLOWLIST = [
	'cache', 'comment', 'config', 'core', 'cron', 'db',
	'eval-file', 'export', 'i18n', 'import', 'language',
	'media', 'menu', 'option', 'package', 'plugin', 'post',
	'rewrite', 'role', 'search-replace', 'sidebar', 'site',
	'taxonomy', 'term', 'theme', 'transient', 'user', 'widget',
] as const;

export type AllowedWpCliCommand = (typeof WPCLI_ALLOWLIST)[number];

export interface WpCliResult {
	stdout: string;
	stderr: string;
	exit_code: number;
}

/**
 * Validate that the first token of `command` is in the allowlist.
 * Returns the error string, or null if valid.
 */
export function validateCommand(command: string): string | null {
	const first = command.trim().split(/\s+/)[0] ?? '';
	if (!(WPCLI_ALLOWLIST as readonly string[]).includes(first)) {
		return `WP-CLI command '${first}' is not permitted. Allowed: ${WPCLI_ALLOWLIST.join(', ')}`;
	}
	return null;
}

/**
 * Run a WP-CLI command via execFile (no shell) and return stdout/stderr/exit_code.
 *
 * Resolves the WP-CLI binary from the `WPCLI_PATH` environment variable,
 * falling back to `wp` (assumed to be on PATH).
 *
 * @param wpArgs - full argument list, e.g. ['option', 'get', 'siteurl']
 * @param cwd    - WordPress root directory; defaults to process.cwd()
 */
export async function runWpCli(wpArgs: string[], cwd?: string): Promise<WpCliResult> {
	const wpBin = process.env['WPCLI_PATH'] ?? 'wp';
	try {
		const { stdout, stderr } = await execFileAsync(wpBin, [...wpArgs, '--no-color'], {
			cwd: cwd ?? process.cwd(),
			env: { ...process.env },
			timeout: 30_000,
			maxBuffer: 5 * 1024 * 1024, // 5 MB
		});
		return { stdout, stderr, exit_code: 0 };
	} catch (err: unknown) {
		// execFile rejects on non-zero exit; the exit code lives on the error object.
		const e = err as NodeJS.ErrnoException & { stdout?: string; stderr?: string; code?: number };
		return {
			stdout: e.stdout ?? '',
			stderr: e.stderr ?? String(err),
			exit_code: typeof e.code === 'number' ? e.code : 1,
		};
	}
}
