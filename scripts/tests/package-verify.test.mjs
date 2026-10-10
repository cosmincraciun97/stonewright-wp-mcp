import assert from 'node:assert/strict';
import { spawnSync } from 'node:child_process';
import { copyFileSync, mkdirSync, mkdtempSync, readFileSync, rmSync, writeFileSync } from 'node:fs';
import os from 'node:os';
import path from 'node:path';
import test from 'node:test';
import { fileURLToPath } from 'node:url';

const scriptsDir = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '..');
const releaseWorkflow = readFileSync(path.join(scriptsDir, '..', '.github', 'workflows', 'release.yml'), 'utf8');

function write(root, rel, body = 'x\n') {
	const file = path.join(root, rel);
	mkdirSync(path.dirname(file), { recursive: true });
	writeFileSync(file, body);
}

// package-verify resolves the plugin folder relative to its own location, so the
// script runs from a throwaway repository layout that holds a minimal plugin.
function runPackageVerify(extraPluginFiles) {
	const root = mkdtempSync(path.join(os.tmpdir(), 'stonewright-package-verify-'));
	try {
		mkdirSync(path.join(root, 'scripts'));
		copyFileSync(path.join(scriptsDir, 'package-verify.mjs'), path.join(root, 'scripts', 'package-verify.mjs'));
		const plugin = path.join(root, 'plugin');
		for (const rel of [
			'stonewright.php',
			'includes/Core/PluginRegistration.php',
			'uninstall.php',
			'data/global-rules.json',
			'data/ability-traits.php',
			'data/elementor-native-contracts/manage-default-styles.json',
			'tests/Unit/ExampleTest.php',
			'bin/verify.php',
			'phpunit.xml',
			'phpstan.neon',
			'phpcs.xml',
			'assets/visual/workspace-browser.js',
		]) {
			write(plugin, rel);
		}
		write(
			plugin,
			'composer.json',
			JSON.stringify({ require: { 'wordpress/mcp-adapter': '*', 'automattic/jetpack-autoloader': '*' } }),
		);
		for (const rel of extraPluginFiles) write(plugin, rel);
		const result = spawnSync(process.execPath, [path.join(root, 'scripts', 'package-verify.mjs')], {
			encoding: 'utf8',
		});
		return { status: result.status, report: JSON.parse(result.stdout) };
	} finally {
		rmSync(root, { recursive: true, force: true });
	}
}

test('package dry run leaves out a .github folder at any depth', () => {
	const withoutGithub = runPackageVerify([]);
	const withGithub = runPackageVerify([
		'.github/workflows/ci.yml',
		'vendor/example/package/.github/FUNDING.yml',
	]);
	assert.equal(withGithub.status, 0);
	assert.ok(withGithub.report.excludes.includes('.github'));
	assert.equal(withGithub.report.included_file_count, withoutGithub.report.included_file_count);
});

test('release workflow excludes .github and rejects it in the built plugin zip', () => {
	assert.match(releaseWorkflow, /--exclude \.github(\s|$)/);
	assert.match(releaseWorkflow, /grep -E '\^stonewright\/\\\.github\(\/\|\$\)'/);
});
