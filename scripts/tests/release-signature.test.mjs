import assert from 'node:assert/strict';
import { spawnSync } from 'node:child_process';
import { copyFileSync, existsSync, mkdirSync, mkdtempSync, readFileSync, rmSync, writeFileSync } from 'node:fs';
import os from 'node:os';
import path from 'node:path';
import test from 'node:test';
import { fileURLToPath } from 'node:url';
import {
	SIGNING_KEY_ENV,
	generateSigningKey,
	parsePublicKeys,
	publicKeyOf,
	readEmbeddedPublicKeys,
	signManifest,
	signRelease,
	verifyManifestSignature,
} from '../release-signature.mjs';

// Every signing key here is a throwaway pair generated inside the test and never
// stored; the RFC 8032 vectors carry public data only.
const scriptsDir = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '..');
const manifest = Buffer.from(
	`${'a'.repeat(64)}  stonewright-1.3.0-beta.30.zip\n${'b'.repeat(64)}  stonewright-companion-1.3.0-beta.30.tgz\n`,
);

function phpKeyList(keys) {
	const list = keys.length === 0 ? '[]' : `[\n${keys.map((key) => `\t\t'${key}',\n`).join('')}\t]`;
	return `<?php\nfinal class ReleaseSignature {\n\t/** @var list<string> */\n\tpublic const PUBLIC_KEYS = ${list};\n}\n`;
}

test('the PUBLIC_KEYS list shipped with the plugin parses and holds valid keys', () => {
	const keys = readEmbeddedPublicKeys();
	assert.ok(Array.isArray(keys));
	assert.ok(keys.length === 0 || keys.length >= 2, 'ship no key or the active key and a backup');
	for (const key of keys) {
		assert.equal(Buffer.from(key, 'base64').length, 32);
	}
});

test('PUBLIC_KEYS parsing accepts comments and keys that contain //', () => {
	const slashes = Buffer.alloc(32, 0xff).toString('base64');
	const other = generateSigningKey().publicKey;
	assert.match(slashes, /\/\//);
	const source = `<?php\n\tpublic const PUBLIC_KEYS = [\r\n\t\t// Active key.\r\n\t\t'${slashes}',\r\n\r\n\t\t'${other}', // Backup key, kept offline.\r\n\t];\r\n`;
	assert.deepEqual(parsePublicKeys(source), [slashes, other]);
	assert.deepEqual(parsePublicKeys(phpKeyList([])), []);
});

test('PUBLIC_KEYS parsing refuses anything but one quoted key per line', () => {
	const key = generateSigningKey().publicKey;
	const cases = [
		['<?php\n\tpublic const PUBLIC_KEYS = array();\n', /no `public const PUBLIC_KEYS/],
		[phpKeyList(['short']), /not a base64 Ed25519 public key/],
		[phpKeyList([key, key]), /same key twice/],
		[phpKeyList(Array.from({ length: 5 }, () => generateSigningKey().publicKey)), /at most 4/],
		[`<?php\n\tpublic const PUBLIC_KEYS = [\n\t\t'${key}', '${key}',\n\t];\n`, /not one quoted key/],
		[`<?php\n\tpublic const PUBLIC_KEYS = [\n\t\tself::OTHER,\n\t];\n`, /not one quoted key/],
	];
	for (const [source, message] of cases) {
		assert.throws(() => parsePublicKeys(source), message);
	}
});

test('a release is signed with a listed key and verifies against the list', () => {
	const active = generateSigningKey();
	const backup = generateSigningKey();
	const keys = [active.publicKey, backup.publicKey];

	assert.equal(publicKeyOf(active.privateKeyPem), active.publicKey);
	const byActive = signRelease(manifest, keys, active.privateKeyPem);
	const byBackup = signRelease(manifest, keys, backup.privateKeyPem);
	assert.match(byActive, /^[A-Za-z0-9+/]{86}==\n$/);
	assert.equal(verifyManifestSignature(manifest, byActive, keys), 0);
	assert.equal(verifyManifestSignature(manifest, byBackup, keys), 1);
});

test('signing is off while the plugin ships no public key', () => {
	assert.equal(signRelease(manifest, [], generateSigningKey().privateKeyPem), null);
	assert.equal(signRelease(manifest, [], undefined), null);
});

test('signing fails closed without a usable key that the plugin trusts', () => {
	const active = generateSigningKey();
	const backup = generateSigningKey();
	const stranger = generateSigningKey();
	const keys = [active.publicKey, backup.publicKey];
	const garbage = 'not a key, but it must never be echoed: SECRETMARKER';

	assert.throws(() => signRelease(manifest, [active.publicKey], active.privateKeyPem), /active key and a backup key/);
	assert.throws(() => signRelease(manifest, keys, undefined), new RegExp(`${SIGNING_KEY_ENV} is not set`));
	assert.throws(() => signRelease(manifest, keys, '  \n'), new RegExp(`${SIGNING_KEY_ENV} is not set`));
	assert.throws(() => signRelease(manifest, keys, stranger.privateKeyPem), /not one of the public keys/);
	assert.throws(
		() => signRelease(manifest, keys, garbage),
		(error) => /not a readable PEM private key/.test(error.message) && !error.message.includes('SECRETMARKER'),
	);
});

test('verification refuses changed bytes, untrusted keys, and malformed files', () => {
	const trusted = generateSigningKey();
	const untrusted = generateSigningKey();
	const signature = signManifest(manifest, trusted.privateKeyPem);

	assert.throws(() => verifyManifestSignature(Buffer.concat([manifest, Buffer.from('\n')]), signature, [trusted.publicKey]), /does not verify/);
	assert.throws(() => verifyManifestSignature(manifest, signManifest(manifest, untrusted.privateKeyPem), [trusted.publicKey]), /does not verify/);
	assert.throws(() => verifyManifestSignature(manifest, signature, []), /no release public key/);
	assert.throws(() => verifyManifestSignature(manifest, '', [trusted.publicKey]), /no well-formed signature/);
	assert.throws(() => verifyManifestSignature(manifest, '<html>Not Found</html>\n', [trusted.publicKey]), /no well-formed signature/);
	assert.throws(() => verifyManifestSignature(manifest, signature + '#\n'.repeat(2048), [trusted.publicKey]), /larger than 4096 bytes/);
});

test('verification skips lines that are not a signature, as the updater does', () => {
	const old = generateSigningKey();
	const current = generateSigningKey();
	const file = `# key change\r\n${signManifest(manifest, old.privateKeyPem).trim()}\r\nlater format line\r\n${signManifest(manifest, current.privateKeyPem)}`;
	assert.equal(verifyManifestSignature(manifest, file, [current.publicKey]), 0);
});

test('verification accepts the RFC 8032 Ed25519 test vectors the PHP ReleaseSignature test also checks', () => {
	// RFC 8032 section 7.1, TEST 1 (empty message) and TEST 2 (one byte, 0x72): public data only.
	assert.equal(
		verifyManifestSignature(
			Buffer.alloc(0),
			'5VZDAMNgrHKQhuLMgG6CioSHfx645dl02HPgZSJJAVVfuIIVkKM7rMYeOXAc+bRr0lv18FlbviRlUUFDjnoQCw==\n',
			['11qYAYKxCrfVS/7TyWQHOg7hcvPapiMlrwIaaPcHURo='],
		),
		0,
	);
	assert.equal(
		verifyManifestSignature(
			Buffer.from([0x72]),
			'kqAJqfDUyrhyDoILX2QlQKKye1QWUD+Ps3YiI+vbadoIWsHkPhWZbkWPNhPQ8R2MOHsurrQwKu6wDSkWErsMAA==\n',
			['PUAXw+hDiVqStwqnTRt+vJyYLM8uxJaMwM1V8Sr0Zgw='],
		),
		0,
	);
});

// The script reads PUBLIC_KEYS relative to its own location, so the CLI runs
// from a throwaway repository layout.
function withScriptLayout(keys, run) {
	const root = mkdtempSync(path.join(os.tmpdir(), 'stonewright-release-signature-'));
	try {
		mkdirSync(path.join(root, 'scripts'));
		copyFileSync(path.join(scriptsDir, 'release-signature.mjs'), path.join(root, 'scripts', 'release-signature.mjs'));
		mkdirSync(path.join(root, 'plugin', 'includes', 'Core'), { recursive: true });
		writeFileSync(path.join(root, 'plugin', 'includes', 'Core', 'ReleaseSignature.php'), phpKeyList(keys));
		mkdirSync(path.join(root, 'dist'));
		writeFileSync(path.join(root, 'dist', 'SHA256SUMS.txt'), manifest);
		const cli = (args, env = {}) =>
			spawnSync(process.execPath, [path.join(root, 'scripts', 'release-signature.mjs'), ...args], {
				cwd: root,
				encoding: 'utf8',
				env: { ...process.env, [SIGNING_KEY_ENV]: '', ...env },
			});
		run({ root, cli });
	} finally {
		rmSync(root, { recursive: true, force: true });
	}
}

test('the sign command writes nothing while the plugin ships no public key', () => {
	withScriptLayout([], ({ root, cli }) => {
		const result = cli(['sign', '--manifest', 'dist/SHA256SUMS.txt', '--out', 'dist/SHA256SUMS.txt.sig'], {
			[SIGNING_KEY_ENV]: generateSigningKey().privateKeyPem,
		});
		assert.equal(result.status, 0, result.stderr);
		assert.match(result.stdout, /Release signing is off/);
		assert.equal(existsSync(path.join(root, 'dist', 'SHA256SUMS.txt.sig')), false);
	});
});

test('the sign command signs with the key from the environment and never prints it', () => {
	const active = generateSigningKey();
	const backup = generateSigningKey();
	withScriptLayout([active.publicKey, backup.publicKey], ({ root, cli }) => {
		const missing = cli(['sign', '--manifest', 'dist/SHA256SUMS.txt', '--out', 'dist/SHA256SUMS.txt.sig']);
		assert.equal(missing.status, 1);
		assert.match(missing.stderr, new RegExp(`${SIGNING_KEY_ENV} is not set`));
		assert.equal(existsSync(path.join(root, 'dist', 'SHA256SUMS.txt.sig')), false);

		const signed = cli(['sign', '--manifest', 'dist/SHA256SUMS.txt', '--out', 'dist/SHA256SUMS.txt.sig'], {
			[SIGNING_KEY_ENV]: active.privateKeyPem,
		});
		assert.equal(signed.status, 0, signed.stderr);
		const pemBody = active.privateKeyPem.split('\n')[1];
		assert.equal(`${signed.stdout}${signed.stderr}`.includes(pemBody), false);

		const verified = cli(['verify', '--manifest', 'dist/SHA256SUMS.txt', '--signature', 'dist/SHA256SUMS.txt.sig']);
		assert.equal(verified.status, 0, verified.stderr);
		assert.match(verified.stdout, /public key 1 of 2/);
		const signature = readFileSync(path.join(root, 'dist', 'SHA256SUMS.txt.sig'), 'utf8');
		assert.equal(verifyManifestSignature(manifest, signature, [active.publicKey]), 0);
	});
});

test('keygen writes the private key only outside the repository and prints the public key', () => {
	withScriptLayout([], ({ root, cli }) => {
		const inside = cli(['keygen', '--out', path.join(root, 'plugin', 'release.pem')]);
		assert.equal(inside.status, 1);
		assert.match(inside.stderr, /outside the repository/);

		const outsideDir = mkdtempSync(path.join(os.tmpdir(), 'stonewright-release-key-'));
		try {
			const out = path.join(outsideDir, 'release.pem');
			const made = cli(['keygen', '--out', out]);
			assert.equal(made.status, 0, made.stderr);
			const pem = readFileSync(out, 'utf8');
			assert.equal(made.stdout.includes(pem.split('\n')[1]), false);
			assert.ok(made.stdout.includes(publicKeyOf(pem)));
			const again = cli(['keygen', '--out', out]);
			assert.equal(again.status, 1, 'an existing key file is never overwritten');
			assert.equal(readFileSync(out, 'utf8'), pem);
		} finally {
			rmSync(outsideDir, { recursive: true, force: true });
		}
	});
});
