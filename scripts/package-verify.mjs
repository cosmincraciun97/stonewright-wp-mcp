#!/usr/bin/env node
/**
 * Dry-run packaging smoke for the plugin release ZIP layout.
 *
 * Does not build a full release. Checks that:
 * - plugin bootstrap and production vendor autoload exist (or would after composer --no-dev)
 * - required runtime paths are present
 * - development-only paths would be excluded from the ZIP (tests, bin, phpunit, …)
 *
 * Mirrors .github/workflows/release.yml rsync excludes.
 *
 * Usage:
 *   node scripts/package-verify.mjs
 *   node scripts/package-verify.mjs --strict-vendor   # fail if vendor/ missing
 */

import fs from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';

const repoRoot = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '..');
const pluginRoot = path.join(repoRoot, 'plugin');
const strictVendor = process.argv.includes('--strict-vendor');
const errors = [];
const warnings = [];

function exists(rel) {
	return fs.existsSync(path.join(pluginRoot, rel));
}

function fail(msg) {
	errors.push(msg);
}

function warn(msg) {
	warnings.push(msg);
}

// Required runtime entrypoints.
for (const rel of ['stonewright.php', 'includes/Core/PluginRegistration.php', 'uninstall.php']) {
	if (!exists(rel)) fail(`Missing required plugin path: plugin/${rel}`);
}

// Composer production install evidence.
if (!exists('composer.json')) {
	fail('Missing plugin/composer.json');
} else {
	const composer = JSON.parse(fs.readFileSync(path.join(pluginRoot, 'composer.json'), 'utf8'));
	if (!composer.require || !composer.require['wordpress/mcp-adapter']) {
		fail('plugin/composer.json must require wordpress/mcp-adapter for production installs.');
	}
}

const vendorAutoload = path.join(pluginRoot, 'vendor', 'autoload.php');
if (!fs.existsSync(vendorAutoload)) {
	const msg =
		'plugin/vendor/autoload.php missing — run `cd plugin && composer install --no-dev` before packaging.';
	if (strictVendor) fail(msg);
	else warn(msg);
} else {
	// Spot-check that production deps landed (not just an empty vendor).
	const mcp = path.join(pluginRoot, 'vendor', 'wordpress');
	if (!fs.existsSync(mcp)) {
		warn('plugin/vendor/wordpress not found; confirm production Composer packages are installed.');
	}
}

// Paths that MUST be excluded from the release ZIP (dev-only).
const mustExclude = [
	'tests',
	'bin',
	'composer.json',
	'composer.lock',
	'phpstan.neon',
	'phpcs.xml',
	'phpunit.xml',
];

// Simulated include set: everything under plugin/ except excludes (like rsync).
const excludeSet = new Set(mustExclude);
function walkIncluded(dir, base = '') {
	const out = [];
	if (!fs.existsSync(dir)) return out;
	for (const entry of fs.readdirSync(dir, { withFileTypes: true })) {
		const rel = base ? `${base}/${entry.name}` : entry.name;
		const top = rel.split('/')[0];
		if (excludeSet.has(top) || excludeSet.has(rel)) continue;
		if (entry.name === '.phpunit.cache' || entry.name === '.phpunit.result.cache') continue;
		const abs = path.join(dir, entry.name);
		if (entry.isDirectory()) {
			out.push(...walkIncluded(abs, rel));
		} else {
			out.push(rel);
		}
	}
	return out;
}

const included = walkIncluded(pluginRoot);
const forbidden = included.filter(
	(rel) =>
		rel.startsWith('tests/') ||
		rel.startsWith('bin/') ||
		/^(phpunit|phpstan|phpcs)/.test(rel) ||
		rel === 'composer.lock' ||
		rel === 'composer.json',
);

if (forbidden.length > 0) {
	fail(`Simulated package includes forbidden paths: ${forbidden.slice(0, 8).join(', ')}`);
}

// Must include runtime after filtering.
for (const need of ['stonewright.php', 'includes/Core/PluginRegistration.php']) {
	if (!included.includes(need) && !included.some((r) => r === need || r.startsWith('includes/'))) {
		fail(`Simulated package missing required runtime path: ${need}`);
	}
}

if (!included.includes('stonewright.php')) {
	fail('Simulated package missing stonewright.php');
}

// Vendor should be included when present (release workflow runs composer --no-dev first).
if (fs.existsSync(vendorAutoload)) {
	const vendorFiles = included.filter((r) => r.startsWith('vendor/'));
	if (vendorFiles.length === 0) {
		fail('vendor/ present on disk but excluded from simulated package — packaging bug.');
	} else if (!included.includes('vendor/autoload.php')) {
		fail('Simulated package missing vendor/autoload.php');
	}
}

// Dev paths should still exist in the repo (excluded, not deleted).
for (const rel of ['tests', 'bin', 'phpunit.xml']) {
	if (!exists(rel)) warn(`Expected development path missing from checkout: plugin/${rel}`);
}

const report = {
	ok: errors.length === 0,
	pluginRoot: path.relative(repoRoot, pluginRoot),
	included_file_count: included.length,
	vendor_present: fs.existsSync(vendorAutoload),
	excludes: mustExclude,
	warnings,
	errors,
};

process.stdout.write(`${JSON.stringify(report, null, 2)}\n`);
if (!report.ok) {
	process.stderr.write(`package-verify failed with ${errors.length} error(s)\n`);
	process.exit(1);
}
process.stderr.write(
	`package-verify ok (${included.length} files would ship; vendor ${report.vendor_present ? 'present' : 'absent'})\n`,
);
