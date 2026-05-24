/**
 * First-run readiness checks for the Stonewright companion.
 *
 * Called once at startup. If any required dependency is missing the function
 * logs a structured, human-readable message and returns a list of setup
 * commands the user (or LLM) must run before continuing.
 *
 * Contract:
 *  - NEVER throws — always returns { ok, instructions }.
 *  - When ok === false the caller should print instructions and exit non-zero.
 *  - When ok === true the caller may proceed normally.
 */

import { execSync } from 'node:child_process';
import { log } from './lib/log.js';

export interface ReadinessResult {
	ok: boolean;
	missing: string[];
	/** Human-readable setup instructions (empty when ok === true). */
	instructions: string;
}

// ---------------------------------------------------------------------------
// Helpers
// ---------------------------------------------------------------------------

function nodeVersionOk(): boolean {
	const [major] = process.versions.node.split('.').map(Number);
	return typeof major === 'number' && major >= 20;
}



/**
 * Synchronous variant that avoids dynamic import complications.
 * Shells out to `playwright install --dry-run` and checks the exit code.
 */
function playwrightBrowsersInstalledSync(): boolean {
	try {
		// `playwright install --dry-run` exits 0 if nothing needs installing,
		// non-zero otherwise (Playwright ≥ 1.38).
		execSync('npx playwright install --dry-run chromium', {
			stdio: 'pipe',
			timeout: 15_000,
		});
		return true;
	} catch {
		return false;
	}
}

// ---------------------------------------------------------------------------
// Public API
// ---------------------------------------------------------------------------

export function checkReadiness(): ReadinessResult {
	const missing: string[] = [];

	// -----------------------------------------------------------------------
	// 1. Node.js version
	// -----------------------------------------------------------------------
	if (!nodeVersionOk()) {
		missing.push(`Node.js ≥ 20 required (found ${process.versions.node})`);
	}

	// -----------------------------------------------------------------------
	// 2. Playwright browsers
	// -----------------------------------------------------------------------
	const skipPlaywright = process.env['STONEWRIGHT_SKIP_PLAYWRIGHT'] === '1';
	if (!skipPlaywright && !playwrightBrowsersInstalledSync()) {
		missing.push('Playwright Chromium browser not installed');
	}

	// -----------------------------------------------------------------------
	// Build instructions
	// -----------------------------------------------------------------------
	if (missing.length === 0) {
		log.info('Stonewright companion: all readiness checks passed');
		return { ok: true, missing: [], instructions: '' };
	}

	const lines: string[] = [
		'',
		'╔══════════════════════════════════════════════════════════════════╗',
		'║        Stonewright Companion — First-Run Setup Required          ║',
		'╚══════════════════════════════════════════════════════════════════╝',
		'',
		'The following dependencies are missing or misconfigured:',
		...missing.map((m) => `  • ${m}`),
		'',
		'Run the commands below to fix this (copy and paste into your terminal):',
		'',
	];

	if (missing.some((m) => m.includes('Playwright'))) {
		lines.push(
			'  # Install Playwright Chromium browser (one-time setup):',
			'  npx playwright install chromium',
			'',
			'  # If you want to skip QA screenshot features entirely, set:',
			'  STONEWRIGHT_SKIP_PLAYWRIGHT=1',
			'',
		);
	}

	if (missing.some((m) => m.includes('Node.js'))) {
		lines.push(
			'  # Upgrade Node.js to version 20 or later:',
			'  # Visit https://nodejs.org or use nvm:',
			'  nvm install 20 && nvm use 20',
			'',
		);
	}

	lines.push(
		'After running the above, restart the companion.',
		'',
		'For LLM agents: paste the above commands into the terminal and run them.',
		'The companion will exit now with code 1.',
		'',
	);

	const instructions = lines.join('\n');

	log.warn('Stonewright companion: readiness checks failed', { missing });

	return { ok: false, missing, instructions };
}
