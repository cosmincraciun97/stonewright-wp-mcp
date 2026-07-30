#!/usr/bin/env node

import { spawnSync } from 'node:child_process';
import fs from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';

const repoRoot = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '..');
const args = process.argv.slice(2);
const rootFlag = args.indexOf('--root');
const scanRoot = path.resolve(rootFlag >= 0 ? args[rootFlag + 1] ?? '' : repoRoot);
const requirePrivateTerms = args.includes('--require-private-terms');
const scanHistory = args.includes('--history');
const excludedDirectories = new Set([
	'.git',
	'.worktrees',
	'coverage',
	'dist',
	'node_modules',
	'vendor',
]);
const privateTerms = (process.env.STONEWRIGHT_PRIVATE_TERMS ?? '')
	.split(/\r?\n/)
	.map((term) => term.trim())
	.filter((term) => term.length >= 3);
const findings = [];

function fail(message) {
	findings.push(message);
}

function escapeRegex(value) {
	return value.replace(/[.*+?^${}()|[\]\\]/g, '\\$&');
}

function isProbablyText(buffer) {
	return !buffer.subarray(0, Math.min(buffer.length, 8192)).includes(0);
}

function lineForOffset(body, offset) {
	return body.slice(0, offset).split('\n').length;
}

function safeCredentialFixture(value) {
	const normalized = value.trim().replace(/^["']|["']$/g, '');
	if (normalized.length < 12) return true;
	if (normalized.includes('${')) return true;
	if (/^(?:<[^>]+>|\$\{[^}]+\}|\$[A-Z_][A-Z0-9_]*|\[?redacted\]?)$/i.test(normalized)) {
		return true;
	}
	return /^(?:(?:Basic|Bearer)\s+(?:test|fixture|sentinel|example)[-_A-Z0-9]+|(?:x{4}|test)(?:\s+(?:x{4}|test))*|a{12,}|(?:your|example|fixture|dummy|test|fake|placeholder|sentinel|do-not-log|not-a-real|token-value|loopback)(?:[-_ ].*)?)$/i.test(normalized);
}

function inspectSecrets(body, relativePath) {
	const privateKey = /-----BEGIN (?:RSA |EC |OPENSSH )?PRIVATE KEY-----/i.exec(body);
	if (privateKey) {
		fail(`${relativePath}:${lineForOffset(body, privateKey.index)} contains private-key material`);
	}

	const appPasswordPattern = /\b(?:[A-Za-z0-9]{4}\s+){5}[A-Za-z0-9]{4}\b/g;
	for (const match of body.matchAll(appPasswordPattern)) {
		if (!safeCredentialFixture(match[0])) {
			fail(
				`${relativePath}:${lineForOffset(body, match.index ?? 0)} contains possible WordPress Application Password material`,
			);
			break;
		}
	}

	const credentialName =
		'(?:password|user_pass|pass|app_?password|application_password|wp_app_password|api[_ -]?key|client_secret|access_token|refresh_token|confirmation_token|bearer_token|authorization|secret|cookie|STONEWRIGHT_WP_APP_PASSWORD|WP_API_PASSWORD|STONEWRIGHT_MCP_AUTHORIZATION)';
	const literalPatterns = [
		new RegExp(
			`["']?\\b${credentialName}\\b["']?\\s*[:=]\\s*(?:"([^"\\r\\n]+)"|'([^'\\r\\n]+)'|\`([^\`\\r\\n]+)\`)`,
			'gi',
		),
		new RegExp(
			`["']\\b${credentialName}\\b["']\\s*=>\\s*(?:"([^"\\r\\n]+)"|'([^'\\r\\n]+)')`,
			'gi',
		),
	];
	for (const pattern of literalPatterns) {
		for (const match of body.matchAll(pattern)) {
			const value = String(match[1] || match[2] || match[3] || '');
			if (!safeCredentialFixture(value)) {
				fail(
					`${relativePath}:${lineForOffset(body, match.index ?? 0)} contains possible committed credential material`,
				);
				return;
			}
		}
	}

	const configLike = /(?:^|\/)(?:\.env(?:\.[^/]+)?|[^/]+\.(?:ya?ml|toml|ini|conf|properties))$/i.test(
		relativePath,
	);
	if (configLike) {
		const unquotedConfigPattern = new RegExp(
			`^\\s*${credentialName}\\s*[:=]\\s*([^\\s#"'\\[{][^\\s#]*)`,
			'gim',
		);
		for (const match of body.matchAll(unquotedConfigPattern)) {
			if (!safeCredentialFixture(String(match[1] || ''))) {
				fail(
					`${relativePath}:${lineForOffset(body, match.index ?? 0)} contains possible committed credential material`,
				);
				return;
			}
		}
	}
}

function inspectFile(absolutePath) {
	const relativePath = path.relative(scanRoot, absolutePath) || path.basename(absolutePath);
	if (relativePath === '.git') return;
	const lowerPath = relativePath.toLocaleLowerCase('en-US');

	for (const [index, term] of privateTerms.entries()) {
		if (lowerPath.includes(term.toLocaleLowerCase('en-US'))) {
			fail(`${relativePath}: path contains private term #${index + 1}`);
		}
	}

	const buffer = fs.readFileSync(absolutePath);
	if (!isProbablyText(buffer)) return;
	const body = buffer.toString('utf8');
	const lowerBody = body.toLocaleLowerCase('en-US');
	inspectSecrets(body, relativePath);
	const absolutePathPatterns = [
		/\/Users\/(?!me\/|example\/)[A-Za-z0-9._-]+\/[^ \t\r\n"'`]+/g,
		/\/home\/(?!user\/|example\/)[A-Za-z0-9._-]+\/[^ \t\r\n"'`]+/g,
		/[A-Za-z]:\\Users\\(?!me\\|example\\)[A-Za-z0-9._-]+\\[^ \t\r\n"'`]+/g,
	];
	for (const pattern of absolutePathPatterns) {
		const match = pattern.exec(body);
		if (match) {
			fail(`${relativePath}:${lineForOffset(body, match.index)} contains a maintainer-local absolute path`);
		}
	}

	for (const [index, term] of privateTerms.entries()) {
		const offset = lowerBody.indexOf(term.toLocaleLowerCase('en-US'));
		if (offset >= 0) {
			fail(
				`${relativePath}:${lineForOffset(body, offset)} contains private term #${index + 1}`,
			);
		}
	}
}

function walk(directory) {
	for (const entry of fs.readdirSync(directory, { withFileTypes: true })) {
		if (
			entry.isDirectory() &&
			excludedDirectories.has(entry.name) &&
			(entry.name !== 'dist' || scanRoot === repoRoot)
		) continue;
		const absolutePath = path.join(directory, entry.name);
		if (entry.isDirectory()) walk(absolutePath);
		else if (entry.isFile()) inspectFile(absolutePath);
	}
}

if (!fs.existsSync(scanRoot) || !fs.statSync(scanRoot).isDirectory()) {
	process.stderr.write('Public hygiene scan root must be an existing directory.\n');
	process.exit(2);
}

if (requirePrivateTerms && privateTerms.length === 0) {
	process.stderr.write(
		'Public hygiene scan requires STONEWRIGHT_PRIVATE_TERMS with one private term per line.\n',
	);
	process.exit(2);
}

walk(scanRoot);

if (scanHistory) {
	if (scanRoot !== repoRoot) {
		process.stderr.write('--history can only scan the repository root.\n');
		process.exit(2);
	}
	for (const [index, term] of privateTerms.entries()) {
		const escaped = escapeRegex(term);
		const contentResult = spawnSync(
			'git',
			['log', '--all', '--regexp-ignore-case', `-G${escaped}`, '--format=%H', '--'],
			{ cwd: repoRoot, encoding: 'utf8' },
		);
		const messageResult = spawnSync(
			'git',
			['log', '--all', '--regexp-ignore-case', '--fixed-strings', `--grep=${term}`, '--format=%H'],
			{ cwd: repoRoot, encoding: 'utf8' },
		);
		const objectResult = spawnSync('git', ['rev-list', '--objects', '--all'], {
			cwd: repoRoot,
			encoding: 'utf8',
		});
		const refResult = spawnSync('git', ['for-each-ref', '--format=%(refname)'], {
			cwd: repoRoot,
			encoding: 'utf8',
		});
		if (
			contentResult.status !== 0 ||
			messageResult.status !== 0 ||
			objectResult.status !== 0 ||
			refResult.status !== 0
		) {
			process.stderr.write('Unable to scan Git history for private terms.\n');
			process.exit(2);
		}
		const commitMatches = new Set(
			`${contentResult.stdout}\n${messageResult.stdout}`
				.trim()
				.split(/\r?\n/)
				.filter(Boolean),
		);
		if (commitMatches.size > 0) {
			fail(`Git history contains private term #${index + 1} in ${commitMatches.size} commit(s)`);
		}
		const lowerTerm = term.toLocaleLowerCase('en-US');
		const pathMatches = objectResult.stdout
			.split(/\r?\n/)
			.filter((line) => line.includes(' '))
			.filter((line) => line.slice(line.indexOf(' ') + 1).toLocaleLowerCase('en-US').includes(lowerTerm));
		if (pathMatches.length > 0) {
			fail(`Git history contains private term #${index + 1} in ${pathMatches.length} path(s)`);
		}
		const refMatches = refResult.stdout
			.split(/\r?\n/)
			.filter((ref) => ref.toLocaleLowerCase('en-US').includes(lowerTerm));
		if (refMatches.length > 0) {
			fail(`Git references contain private term #${index + 1} in ${refMatches.length} ref(s)`);
		}
	}
}

if (findings.length > 0) {
	for (const finding of findings) process.stderr.write(`${finding}\n`);
	process.stderr.write(`Public hygiene failed with ${findings.length} finding(s).\n`);
	process.exit(1);
}

process.stdout.write(
	`Public hygiene OK (${privateTerms.length} private term${privateTerms.length === 1 ? '' : 's'} checked).\n`,
);
