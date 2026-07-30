import { mkdirSync, mkdtempSync, writeFileSync } from 'node:fs';
import { tmpdir } from 'node:os';
import { dirname, join, resolve } from 'node:path';
import { fileURLToPath } from 'node:url';
import { spawnSync } from 'node:child_process';
import { describe, expect, it } from 'vitest';

const repoRoot = resolve(dirname(fileURLToPath(import.meta.url)), '..', '..');
const scanner = join(repoRoot, 'scripts', 'check-public-hygiene.mjs');

function scan(files: Record<string, string>, env: NodeJS.ProcessEnv = {}) {
	const root = mkdtempSync(join(tmpdir(), 'sw-public-hygiene-'));
	for (const [name, body] of Object.entries(files)) {
		const target = join(root, name);
		mkdirSync(dirname(target), { recursive: true });
		writeFileSync(target, body, 'utf8');
	}
	return spawnSync(process.execPath, [scanner, '--root', root], {
		encoding: 'utf8',
		env: { ...process.env, ...env },
	});
}

describe('public hygiene credential scanning', () => {
	it('allows placeholders and runtime expressions', () => {
		const result = scan({
			'config.example.json':
				'{"password":"<your-application-password>","authorization":"Bearer ${TOKEN}"}',
			'client.ts': "const headers = { Authorization: `Basic ${auth}` };",
		});

		expect(result.status, result.stderr).toBe(0);
	});

	it('blocks literal credentials without echoing their value', () => {
		const marker = 'real-private-value-123';
		const result = scan({
			'private.json': `{"client_secret":"${marker}"}`,
		});

		expect(result.status).toBe(1);
		expect(result.stderr).toContain('possible committed credential material');
		expect(result.stderr).not.toContain(marker);
	});

	it('does not whitelist a credential merely because its value says secret', () => {
		const marker = ['super', 'secret', 'value', '123'].join('-');
		const result = scan({
			'private.json': JSON.stringify({ password: marker }),
		});

		expect(result.status).toBe(1);
		expect(result.stderr).toContain('possible committed credential material');
		expect(result.stderr).not.toContain(marker);
	});

	it('blocks WordPress Application Password material', () => {
		const appPassword = ['qwer', 'tyui', 'opas', 'dfgh', 'jklz', 'xcvb'].join(' ');
		const result = scan({
			'notes.txt': `Credential: ${appPassword}`,
		});

		expect(result.status).toBe(1);
		expect(result.stderr).toContain('possible WordPress Application Password material');
		expect(result.stderr).not.toContain(appPassword);
	});
});
