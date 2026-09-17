#!/usr/bin/env node
/**
 * Verify published Stonewright release assets.
 *
 * Checks local dist files or sanitized GitHub release metadata. Never reads
 * credentials. Extra or missing assets fail closed.
 *
 *   node scripts/check-release-artifacts.mjs --version 1.0.0-beta.13.3 --dir dist
 *   node scripts/check-release-artifacts.mjs --version 1.0.0-beta.13.3 --github-json release.json
 */

import { createHash } from 'node:crypto';
import { execFileSync } from 'node:child_process';
import fs from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';
import process from 'node:process';

export function expectedReleaseAssetNames(version) {
	return [`stonewright-${version}.zip`, `stonewright-companion-${version}.tgz`, 'SHA256SUMS.txt'].sort();
}

export function expectedArchiveNames(version) {
	return [`stonewright-${version}.zip`, `stonewright-companion-${version}.tgz`].sort();
}

export function assertExactAssetNames(names, version) {
	const expected = expectedReleaseAssetNames(version);
	const actual = [...names].sort();
	if (actual.length !== expected.length || actual.some((name, index) => name !== expected[index])) {
		throw new Error(
			`Published assets must be exactly ${expected.join(', ')}; got ${actual.join(', ') || '(none)'}.`,
		);
	}
}

export function parseSha256Sums(text) {
	const rows = [];
	for (const raw of text.split(/\r?\n/)) {
		const line = raw.trim();
		if (line === '') {
			continue;
		}
		const match = /^([a-fA-F0-9]{64})\s+\*?(\S+)$/.exec(line);
		if (!match) {
			throw new Error(`Malformed SHA256SUMS line: ${line}`);
		}
		rows.push({ hash: match[1].toLowerCase(), name: path.basename(match[2]) });
	}
	return rows;
}

export function assertSha256SumsForArchives(text, version) {
	const rows = parseSha256Sums(text);
	const names = rows.map((row) => row.name).sort();
	if (names.includes('SHA256SUMS.txt')) {
		throw new Error('SHA256SUMS.txt must not checksum itself.');
	}
	if (names.some((name) => /visual/i.test(name))) {
		throw new Error('SHA256SUMS.txt must not include a Visual archive.');
	}
	const expected = expectedArchiveNames(version);
	if (names.length !== expected.length || names.some((name, index) => name !== expected[index])) {
		throw new Error(`SHA256SUMS.txt must cover exactly ${expected.join(', ')}; got ${names.join(', ') || '(none)'}.`);
	}
	return rows;
}

export function inspectGitHubReleaseMetadata(release, version) {
	if (!release || typeof release !== 'object') {
		throw new Error('GitHub release metadata is missing.');
	}
	if (release.tag_name !== `v${version}`) {
		throw new Error(`Expected tag v${version}; got ${String(release.tag_name ?? '')}.`);
	}
	if (release.prerelease !== false) {
		throw new Error('supported public beta must publish with prerelease:false.');
	}
	const assets = Array.isArray(release.assets) ? release.assets : [];
	assertExactAssetNames(
		assets.map((asset) => (asset && typeof asset === 'object' ? String(asset.name ?? '') : '')),
		version,
	);
}

function listPublishedFiles(dir) {
	return fs
		.readdirSync(dir, { withFileTypes: true })
		.filter((entry) => entry.isFile())
		.map((entry) => entry.name)
		.filter((name) => /^(stonewright-.*\.(zip|tgz)|SHA256SUMS\.txt)$/.test(name));
}

export function inspectLocalReleaseDir(dir, version, options = {}) {
	const published = listPublishedFiles(dir);
	assertExactAssetNames(published, version);
	const sumsPath = path.join(dir, 'SHA256SUMS.txt');
	const sums = fs.readFileSync(sumsPath, 'utf8');
	const rows = assertSha256SumsForArchives(sums, version);
	for (const row of rows) {
		const filePath = path.join(dir, row.name);
		const actual = createHash('sha256').update(fs.readFileSync(filePath)).digest('hex');
		if (actual !== row.hash) {
			throw new Error(`${row.name} SHA-256 ${actual} does not match SHA256SUMS.txt.`);
		}
		const size = fs.statSync(filePath).size;
		if (size < 1) {
			throw new Error(`${row.name} is empty.`);
		}
	}
	if (options.inspect) {
		inspectPluginZip(path.join(dir, `stonewright-${version}.zip`), version, options);
		inspectCompanionTgz(path.join(dir, `stonewright-companion-${version}.tgz`), version);
	}
}

export function inspectPluginZip(zipPath, version, options = {}) {
	const listing = execFileSync('unzip', ['-Z1', zipPath], { encoding: 'utf8' })
		.split('\n')
		.map((line) => line.trim())
		.filter(Boolean);
	const required = [
		'stonewright/stonewright.php',
		'stonewright/assets/visual/workspace-browser.js',
		'stonewright/skills/agent-operating-rules/SKILL.md',
	];
	for (const rel of required) {
		if (!listing.includes(rel)) {
			throw new Error(`Plugin ZIP is missing ${rel}.`);
		}
	}
	if (!listing.some((rel) => rel.startsWith('stonewright/skills/playbooks/'))) {
		throw new Error('Plugin ZIP is missing bundled playbooks.');
	}
	const forbidden = listing.filter(
		(rel) =>
			rel.startsWith('stonewright/tests/') ||
			rel.startsWith('stonewright/bin/') ||
			rel.includes('/fixtures/') ||
			rel.includes('synthetic-mcp-provider') ||
			rel === 'stonewright/composer.json' ||
			rel === 'stonewright/composer.lock',
	);
	if (forbidden.length > 0) {
		throw new Error(`Plugin ZIP contains development-only paths: ${forbidden.slice(0, 8).join(', ')}`);
	}
	const bootstrap = execFileSync('unzip', ['-p', zipPath, 'stonewright/stonewright.php'], { encoding: 'utf8' });
	if (!bootstrap.includes(`Version: ${version}`) || !bootstrap.includes(`'${version}'`)) {
		throw new Error(`Plugin ZIP bootstrap version is not ${version}.`);
	}
	if (options.requireProductionVendor && !listing.some((rel) => rel.startsWith('stonewright/vendor/'))) {
		throw new Error('Plugin ZIP is missing production vendor.');
	}
}

export function inspectCompanionTgz(tgzPath, version) {
	const listing = execFileSync('tar', ['-tzf', tgzPath], { encoding: 'utf8' })
		.split('\n')
		.map((line) => line.trim())
		.filter(Boolean);
	if (!listing.includes('package/package.json')) {
		throw new Error('Companion TGZ is missing package/package.json.');
	}
	const pkg = JSON.parse(execFileSync('tar', ['-xOf', tgzPath, 'package/package.json'], { encoding: 'utf8' }));
	if (pkg.version !== version) {
		throw new Error(`Companion package.json version ${pkg.version ?? 'missing'} is not ${version}.`);
	}
	const compiled = listing.find((rel) => rel === 'package/dist/version.js' || rel.endsWith('/version.js'));
	if (compiled) {
		const source = execFileSync('tar', ['-xOf', tgzPath, compiled], { encoding: 'utf8' });
		if (!source.includes(version)) {
			throw new Error(`Companion compiled version.js does not contain ${version}.`);
		}
	}
}

function printUsage() {
	process.stderr.write(
		'Usage: node scripts/check-release-artifacts.mjs --version <semver> (--dir <path> | --github-json <path>) [--inspect] [--require-production-vendor]\n',
	);
}

if (process.argv[1] && fileURLToPath(import.meta.url) === process.argv[1]) {
	try {
		const args = process.argv.slice(2);
		const versionIndex = args.indexOf('--version');
		const dirIndex = args.indexOf('--dir');
		const githubIndex = args.indexOf('--github-json');
		const version = versionIndex >= 0 ? args[versionIndex + 1] : '';
		if (!version) {
			throw new Error('Expected --version <semver>.');
		}
		const inspect = args.includes('--inspect');
		const requireProductionVendor = args.includes('--require-production-vendor');
		if (dirIndex >= 0) {
			inspectLocalReleaseDir(args[dirIndex + 1], version, { inspect, requireProductionVendor });
		} else if (githubIndex >= 0) {
			inspectGitHubReleaseMetadata(JSON.parse(fs.readFileSync(args[githubIndex + 1], 'utf8')), version);
		} else {
			printUsage();
			throw new Error('Expected --dir or --github-json.');
		}
	} catch (error) {
		process.stderr.write(`${error instanceof Error ? error.message : String(error)}\n`);
		process.exitCode = 1;
	}
}
