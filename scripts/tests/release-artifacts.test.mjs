import assert from 'node:assert/strict';
import { execFileSync } from 'node:child_process';
import { createHash } from 'node:crypto';
import fs from 'node:fs';
import os from 'node:os';
import path from 'node:path';
import test from 'node:test';
import {
	assertExactAssetNames,
	assertSha256SumsForArchives,
	expectedReleaseAssetNames,
	inspectCompanionTgz,
	inspectGitHubReleaseMetadata,
	inspectLocalReleaseDir,
	inspectPluginZip,
} from '../check-release-artifacts.mjs';
import { generateSigningKey, signManifest } from '../release-signature.mjs';

const version = '1.0.0-beta.13.3';

// Archive contents are synthetic: without --inspect only names, digests and the signature are checked.
function writeUnsignedRelease(dir) {
	const zipName = `stonewright-${version}.zip`;
	const tgzName = `stonewright-companion-${version}.tgz`;
	fs.writeFileSync(path.join(dir, zipName), 'synthetic plugin zip');
	fs.writeFileSync(path.join(dir, tgzName), 'synthetic companion tgz');
	const sha = (name) => createHash('sha256').update(fs.readFileSync(path.join(dir, name))).digest('hex');
	fs.writeFileSync(path.join(dir, 'SHA256SUMS.txt'), `${sha(zipName)}  ${zipName}\n${sha(tgzName)}  ${tgzName}\n`);
	return fs.readFileSync(path.join(dir, 'SHA256SUMS.txt'));
}

test('expected assets are exactly zip, companion tgz, and SHA256SUMS', () => {
	assert.deepEqual(expectedReleaseAssetNames(version), [
		'SHA256SUMS.txt',
		`stonewright-${version}.zip`,
		`stonewright-companion-${version}.tgz`,
	]);
});

test('exact asset names reject extras and omissions', () => {
	assert.doesNotThrow(() =>
		assertExactAssetNames(
			[`stonewright-companion-${version}.tgz`, 'SHA256SUMS.txt', `stonewright-${version}.zip`],
			version,
		),
	);
	assert.throws(
		() =>
			assertExactAssetNames(
				[
					`stonewright-${version}.zip`,
					`stonewright-companion-${version}.tgz`,
					`stonewright-visual-${version}.tgz`,
					'SHA256SUMS.txt',
				],
				version,
			),
		/exactly/,
	);
	assert.throws(
		() => assertExactAssetNames([`stonewright-${version}.zip`, 'SHA256SUMS.txt'], version),
		/exactly/,
	);
});

test('SHA256SUMS covers only the two archives', () => {
	const text = [
		`aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa  stonewright-${version}.zip`,
		`bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb  stonewright-companion-${version}.tgz`,
		'',
	].join('\n');
	assert.equal(assertSha256SumsForArchives(text, version).length, 2);
	assert.throws(
		() =>
			assertSha256SumsForArchives(
				`${text}cccccccccccccccccccccccccccccccccccccccccccccccccccccccccccccccc  SHA256SUMS.txt\n`,
				version,
			),
		/must not checksum itself/,
	);
	assert.throws(
		() =>
			assertSha256SumsForArchives(
				`${text}dddddddddddddddddddddddddddddddddddddddddddddddddddddddddddddddd  stonewright-visual-${version}.tgz\n`,
				version,
			),
		/Visual archive/,
	);
});

test('GitHub metadata requires supported prerelease false and exact assets', () => {
	const release = {
		tag_name: `v${version}`,
		prerelease: false,
		assets: expectedReleaseAssetNames(version).map((name) => ({ name })),
	};
	assert.doesNotThrow(() => inspectGitHubReleaseMetadata(release, version));
	assert.throws(
		() => inspectGitHubReleaseMetadata({ ...release, prerelease: true }, version),
		/prerelease:false/,
	);
	assert.throws(
		() =>
			inspectGitHubReleaseMetadata(
				{
					...release,
					assets: [...release.assets, { name: `stonewright-visual-${version}.tgz` }],
				},
				version,
			),
		/exactly/,
	);
});

test('local dist inspects versions inside zip and companion tgz', () => {
	const dir = fs.mkdtempSync(path.join(os.tmpdir(), 'stonewright-artifacts-'));
	try {
		const pluginRoot = path.join(dir, 'stonewright');
		fs.mkdirSync(path.join(pluginRoot, 'skills/agent-operating-rules'), { recursive: true });
		fs.mkdirSync(path.join(pluginRoot, 'skills/playbooks'), { recursive: true });
		fs.mkdirSync(path.join(pluginRoot, 'assets/visual'), { recursive: true });
		fs.writeFileSync(
			path.join(pluginRoot, 'stonewright.php'),
			` * Version: ${version}\ndefine( 'STONEWRIGHT_VERSION', '${version}' );\n`,
		);
		fs.writeFileSync(path.join(pluginRoot, 'skills/agent-operating-rules/SKILL.md'), '# skill\n');
		fs.writeFileSync(path.join(pluginRoot, 'skills/playbooks/demo.md'), '# playbook\n');
		fs.writeFileSync(path.join(pluginRoot, 'assets/visual/workspace-browser.js'), 'console.log(1);\n');
		execFileSync('zip', ['-qr', `stonewright-${version}.zip`, 'stonewright'], { cwd: dir });

		const pkgRoot = path.join(dir, 'package');
		fs.mkdirSync(path.join(pkgRoot, 'dist'), { recursive: true });
		fs.writeFileSync(path.join(pkgRoot, 'package.json'), JSON.stringify({ name: '@stonewright/companion', version }));
		fs.writeFileSync(path.join(pkgRoot, 'dist/version.js'), `export const APP_VERSION = '${version}';\n`);
		execFileSync('tar', ['-czf', `stonewright-companion-${version}.tgz`, 'package'], { cwd: dir });

		const zipName = `stonewright-${version}.zip`;
		const tgzName = `stonewright-companion-${version}.tgz`;
		const zipHash = createHash('sha256').update(fs.readFileSync(path.join(dir, zipName))).digest('hex');
		const tgzHash = createHash('sha256').update(fs.readFileSync(path.join(dir, tgzName))).digest('hex');
		fs.writeFileSync(path.join(dir, 'SHA256SUMS.txt'), `${zipHash}  ${zipName}\n${tgzHash}  ${tgzName}\n`);

		inspectLocalReleaseDir(dir, version, { inspect: true });
		inspectPluginZip(path.join(dir, zipName), version);
		inspectCompanionTgz(path.join(dir, tgzName), version);
	} finally {
		fs.rmSync(dir, { recursive: true, force: true });
	}
});

test('plugin zip inspection rejects a bundled .github folder', () => {
	const dir = fs.mkdtempSync(path.join(os.tmpdir(), 'stonewright-artifacts-'));
	try {
		const pluginRoot = path.join(dir, 'stonewright');
		fs.mkdirSync(path.join(pluginRoot, 'skills/agent-operating-rules'), { recursive: true });
		fs.mkdirSync(path.join(pluginRoot, 'skills/playbooks'), { recursive: true });
		fs.mkdirSync(path.join(pluginRoot, 'assets/visual'), { recursive: true });
		fs.mkdirSync(path.join(pluginRoot, '.github/workflows'), { recursive: true });
		fs.writeFileSync(
			path.join(pluginRoot, 'stonewright.php'),
			` * Version: ${version}\ndefine( 'STONEWRIGHT_VERSION', '${version}' );\n`,
		);
		fs.writeFileSync(path.join(pluginRoot, 'skills/agent-operating-rules/SKILL.md'), '# skill\n');
		fs.writeFileSync(path.join(pluginRoot, 'skills/playbooks/demo.md'), '# playbook\n');
		fs.writeFileSync(path.join(pluginRoot, 'assets/visual/workspace-browser.js'), 'console.log(1);\n');
		fs.writeFileSync(path.join(pluginRoot, '.github/workflows/ci.yml'), 'name: ci\n');
		execFileSync('zip', ['-qr', 'with-github.zip', 'stonewright'], { cwd: dir });
		assert.throws(() => inspectPluginZip(path.join(dir, 'with-github.zip'), version), /\.github/);

		fs.rmSync(path.join(pluginRoot, '.github'), { recursive: true, force: true });
		execFileSync('zip', ['-qr', 'without-github.zip', 'stonewright'], { cwd: dir });
		assert.doesNotThrow(() => inspectPluginZip(path.join(dir, 'without-github.zip'), version));
	} finally {
		fs.rmSync(dir, { recursive: true, force: true });
	}
});

test('plugin zip inspection rejects a bundled stubs folder', () => {
	const dir = fs.mkdtempSync(path.join(os.tmpdir(), 'stonewright-artifacts-'));
	try {
		const pluginRoot = path.join(dir, 'stonewright');
		fs.mkdirSync(path.join(pluginRoot, 'skills/agent-operating-rules'), { recursive: true });
		fs.mkdirSync(path.join(pluginRoot, 'skills/playbooks'), { recursive: true });
		fs.mkdirSync(path.join(pluginRoot, 'assets/visual'), { recursive: true });
		fs.mkdirSync(path.join(pluginRoot, 'stubs'), { recursive: true });
		fs.writeFileSync(
			path.join(pluginRoot, 'stonewright.php'),
			` * Version: ${version}\ndefine( 'STONEWRIGHT_VERSION', '${version}' );\n`,
		);
		fs.writeFileSync(path.join(pluginRoot, 'skills/agent-operating-rules/SKILL.md'), '# skill\n');
		fs.writeFileSync(path.join(pluginRoot, 'skills/playbooks/demo.md'), '# playbook\n');
		fs.writeFileSync(path.join(pluginRoot, 'assets/visual/workspace-browser.js'), 'console.log(1);\n');
		fs.writeFileSync(path.join(pluginRoot, 'stubs/elementor.stub.php'), '<?php\n');
		execFileSync('zip', ['-qr', 'with-stubs.zip', 'stonewright'], { cwd: dir });
		assert.throws(() => inspectPluginZip(path.join(dir, 'with-stubs.zip'), version), /stubs/);

		fs.rmSync(path.join(pluginRoot, 'stubs'), { recursive: true, force: true });
		execFileSync('zip', ['-qr', 'without-stubs.zip', 'stonewright'], { cwd: dir });
		assert.doesNotThrow(() => inspectPluginZip(path.join(dir, 'without-stubs.zip'), version));
	} finally {
		fs.rmSync(dir, { recursive: true, force: true });
	}
});

test('an unsigned release stays exactly the two archives and SHA256SUMS.txt', () => {
	const dir = fs.mkdtempSync(path.join(os.tmpdir(), 'stonewright-artifacts-'));
	try {
		writeUnsignedRelease(dir);
		assert.doesNotThrow(() => inspectLocalReleaseDir(dir, version));
		fs.writeFileSync(path.join(dir, 'SHA256SUMS.txt.sig'), 'unexpected\n');
		assert.throws(() => inspectLocalReleaseDir(dir, version), /exactly/);
	} finally {
		fs.rmSync(dir, { recursive: true, force: true });
	}
});

test('a signed release needs SHA256SUMS.txt.sig that verifies against the shipped keys', () => {
	const dir = fs.mkdtempSync(path.join(os.tmpdir(), 'stonewright-artifacts-'));
	try {
		const manifest = writeUnsignedRelease(dir);
		const active = generateSigningKey();
		const backup = generateSigningKey();
		const publicKeys = [active.publicKey, backup.publicKey];
		const signaturePath = path.join(dir, 'SHA256SUMS.txt.sig');

		// The package job checks before the publish job signs.
		assert.doesNotThrow(() => inspectLocalReleaseDir(dir, version, { publicKeys, beforeSigning: true }));
		assert.throws(() => inspectLocalReleaseDir(dir, version, { publicKeys }), /exactly/);

		fs.writeFileSync(signaturePath, signManifest(manifest, generateSigningKey().privateKeyPem));
		assert.throws(() => inspectLocalReleaseDir(dir, version, { publicKeys }), /does not verify/);

		fs.writeFileSync(signaturePath, signManifest(Buffer.concat([manifest, Buffer.from('\n')]), active.privateKeyPem));
		assert.throws(() => inspectLocalReleaseDir(dir, version, { publicKeys }), /does not verify/);

		fs.writeFileSync(signaturePath, signManifest(manifest, backup.privateKeyPem));
		assert.doesNotThrow(() => inspectLocalReleaseDir(dir, version, { publicKeys }));
		assert.throws(() => inspectLocalReleaseDir(dir, version, { publicKeys, beforeSigning: true }), /exactly/);
	} finally {
		fs.rmSync(dir, { recursive: true, force: true });
	}
});

test('GitHub metadata of a signed release lists SHA256SUMS.txt.sig', () => {
	const publicKeys = [generateSigningKey().publicKey, generateSigningKey().publicKey];
	const unsigned = {
		tag_name: `v${version}`,
		prerelease: false,
		assets: expectedReleaseAssetNames(version).map((name) => ({ name })),
	};
	const signed = { ...unsigned, assets: expectedReleaseAssetNames(version, { signed: true }).map((name) => ({ name })) };

	assert.deepEqual(expectedReleaseAssetNames(version, { signed: true }), [
		'SHA256SUMS.txt',
		'SHA256SUMS.txt.sig',
		`stonewright-${version}.zip`,
		`stonewright-companion-${version}.tgz`,
	]);
	assert.doesNotThrow(() => inspectGitHubReleaseMetadata(signed, version, { publicKeys }));
	assert.throws(() => inspectGitHubReleaseMetadata(unsigned, version, { publicKeys }), /exactly/);
	assert.throws(() => inspectGitHubReleaseMetadata(signed, version), /exactly/);
});
