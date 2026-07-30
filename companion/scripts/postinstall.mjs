#!/usr/bin/env node
/**
 * Stonewright companion postinstall — downloads wp-cli.phar into cache.
 *
 * Runs automatically after `npm install`. Safe to run repeatedly (idempotent):
 * if the phar already exists it is skipped without re-downloading.
 *
 * Never throws — failures are logged as warnings so `npm install` always
 * exits 0, even in offline or restricted environments.
 */
import { homedir } from 'node:os';
import { join } from 'node:path';
import { existsSync, mkdirSync, renameSync, unlinkSync, writeFileSync } from 'node:fs';
import { createHash } from 'node:crypto';

const WP_CLI_PHAR_URL =
	'https://raw.githubusercontent.com/wp-cli/builds/gh-pages/phar/wp-cli.phar';

function resolveInstallDir() {
	const explicit = process.env['STONEWRIGHT_WP_CLI_INSTALL_DIR'];
	if (explicit) return explicit;
	const localAppData = process.env['LOCALAPPDATA'];
	if (localAppData) return join(localAppData, 'Stonewright', 'wp-cli');
	return join(homedir(), '.stonewright', 'wp-cli');
}

async function main() {
	const installDir = resolveInstallDir();
	const pharPath = join(installDir, 'wp-cli.phar');

	if (existsSync(pharPath)) {
		console.log(`[stonewright] WP-CLI phar already present at: ${pharPath}`);
		return;
	}

	console.log(`[stonewright] Downloading WP-CLI phar to: ${pharPath}`);
	mkdirSync(installDir, { recursive: true });

	const tempPath = `${pharPath}.tmp-${process.pid}`;
	try {
		const response = await fetch(WP_CLI_PHAR_URL, {
			signal: AbortSignal.timeout(60_000),
		});
		if (!response.ok) {
			throw new Error(`HTTP ${response.status}`);
		}
		const buffer = Buffer.from(await response.arrayBuffer());
		const sha256 = createHash('sha256').update(buffer).digest('hex');

		writeFileSync(tempPath, buffer, { flag: 'w' });
		try {
			const { chmodSync } = await import('node:fs');
			chmodSync(tempPath, 0o755);
		} catch {
			// Windows does not need executable bits for phar execution via PHP.
		}
		renameSync(tempPath, pharPath);

		console.log(
			`[stonewright] WP-CLI phar installed (${buffer.length} bytes, sha256=${sha256.slice(0, 16)}...)`,
		);
	} catch (err) {
		try {
			unlinkSync(tempPath);
		} catch {
			// No partial file to clean up — ignore.
		}
		console.warn(
			`[stonewright] WARNING: Could not download WP-CLI phar: ${err instanceof Error ? err.message : String(err)}`,
		);
		console.warn(
			'[stonewright] WP-CLI will be discovered from PATH or LocalWP at runtime.',
		);
	}
}

main();
